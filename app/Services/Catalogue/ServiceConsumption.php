<?php

namespace App\Services\Catalogue;

use App\Support\ContentUnits;
use App\Enums\ItemType;
use App\Models\Item;
use App\Models\ItemComponent;
use App\Models\ItemVariant;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleItemConsumption;
use App\Models\SaleReturn;
use App\Services\Inventory\InventoryLedgerService;
use App\Services\Inventory\InventoryMovementType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * « Produits utilisés »: what a service eats into, in fractions of the unit a
 * product is stocked in.
 *
 * A recipe is written in the product's CONTENT unit (10 ml of a 200 ml bottle);
 * stock stays in stocked units, so the sale deducts 10 / 200 = 0.05 bottle.
 *
 * Rules, all of them decided here and nowhere else:
 *  - effective recipe = the service's own rows, then the variant's rows on top
 *    (same product => the variant's quantity wins, 0 removes it; a product only
 *    the variant lists is added);
 *  - rows sharing a choice group are alternatives: exactly one is used per line,
 *    the chosen one, else the flagged default, else the first;
 *  - a line may adjust a quantity (per unit of service); only for a product of
 *    the recipe;
 *  - a sale is NEVER refused for a low bottle: the movement is recorded, the
 *    part the shelf did not have is noted on the snapshot, and the product's
 *    stock status flips to out of stock so the usual alerts show it.
 */
class ServiceConsumption
{
    public function __construct(private readonly InventoryLedgerService $ledger)
    {
    }

    /**
     * The recipe that applies to a service (and, when given, one of its variants).
     *
     * @return Collection<int, array{component_item_id: int, quantity: float, choice_group: ?string, is_default: bool, sort_order: int, variant_id: ?int}>
     */
    public function recipe(int $tenantId, int $itemId, ?int $variantId = null): Collection
    {
        $rows = ItemComponent::query()
            ->where('tenant_id', $tenantId)
            ->where('item_id', $itemId)
            ->where(fn ($q) => $q->whereNull('variant_id')->when($variantId, fn ($q2) => $q2->orWhere('variant_id', $variantId)))
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        $effective = [];
        foreach ($rows->whereNull('variant_id') as $row) {
            $effective[(int) $row->component_item_id] = $this->row($row);
        }
        foreach ($rows->whereNotNull('variant_id') as $row) {
            $effective[(int) $row->component_item_id] = $this->row($row);
        }

        return collect($effective)
            ->filter(fn (array $r) => $r['quantity'] > 0)
            ->sortBy([['sort_order', 'asc'], ['component_item_id', 'asc']])
            ->values();
    }

    /**
     * What the web till needs to offer « Produits utilisés » without a round
     * trip: every service's own recipe (quantities > 0, in the product's
     * content unit) and the products they name. The till sells no variant, so
     * the base rows are the effective recipe.
     *
     * @return array{recipes: array<int, list<array{product_id: int, quantity: float, group: ?string, is_default: bool}>>, products: array<int, array{name: string, unit: string}>}
     */
    public function tillData(int $tenantId): array
    {
        $rows = ItemComponent::query()
            ->where('tenant_id', $tenantId)->whereNull('variant_id')
            ->orderBy('sort_order')->orderBy('id')->get();

        $recipes = [];
        foreach ($rows as $row) {
            $r = $this->row($row);
            if ($r['quantity'] <= 0) {
                continue;
            }
            $recipes[(int) $row->item_id][] = [
                'product_id' => $r['component_item_id'],
                'quantity' => $r['quantity'],
                'group' => $r['choice_group'],
                'is_default' => $r['is_default'],
            ];
        }

        $products = [];
        $named = Item::query()->where('tenant_id', $tenantId)
            ->whereIn('id', $rows->pluck('component_item_id')->unique()->all())
            ->get(['id', 'title', 'content_quantity', 'content_unit']);
        foreach ($named as $product) {
            $products[(int) $product->id] = [
                'name' => $product->title,
                // « ml », « pièces »: what the quantity is counted in; empty for a product stocked in whole units.
                'unit' => $product->hasCapacity() ? \App\Support\ContentUnits::label($product->content_unit, 2) : '',
            ];
        }

        return ['recipes' => $recipes, 'products' => $products];
    }

    /** @return array{component_item_id: int, quantity: float, choice_group: ?string, is_default: bool, sort_order: int, variant_id: ?int} */
    private function row(ItemComponent $row): array
    {
        return [
            'component_item_id' => (int) $row->component_item_id,
            'quantity' => round((float) $row->quantity, 3),
            'choice_group' => $row->choice_group !== null && trim($row->choice_group) !== '' ? trim($row->choice_group) : null,
            'is_default' => (bool) $row->is_default,
            'sort_order' => (int) $row->sort_order,
            'variant_id' => $row->variant_id !== null ? (int) $row->variant_id : null,
        ];
    }

    /**
     * What one line of the service will consume, before anything is written.
     *
     * @param  array<int|string, mixed>  $choices    group => product id, or [{group, component_item_id}]
     * @param  array<int, array<string, mixed>>  $overrides  [{component_item_id, quantity}] per unit of service
     * @param  list<array<string, mixed>>  $ignored  filled with every choice or adjustment that named a product (or group)
     *                                                 the recipe no longer has: a till that synced before the recipe was edited
     *                                                 must not have its sale refused, so these are dropped (the default applies)
     *                                                 and the caller records them
     * @return list<array{component: Item, component_item_id: int, choice_group: ?string, quantity_content: float, quantity_stock: float, content_unit: ?string}>
     */
    public function plan(Item $service, ?ItemVariant $variant, int $lineQuantity, array $choices = [], array $overrides = [], array &$ignored = []): array
    {
        if ($service->type !== ItemType::Service->value || $lineQuantity < 1) {
            return [];
        }

        $recipe = $this->recipe((int) $service->tenant_id, (int) $service->id, $variant?->id);
        if ($recipe->isEmpty()) {
            // Nothing to consume — and adjustments to a recipe that does not
            // exist are a till out of date, not a stock question.
            if ($choices !== [] || $overrides !== []) {
                $this->pickedChoices($recipe, $choices, $ignored);
                $this->adjustments($recipe, $overrides, $ignored);
            }

            return [];
        }

        $picked = $this->pickedChoices($recipe, $choices, $ignored);
        $adjust = $this->adjustments($recipe, $overrides, $ignored);

        $used = $recipe->filter(function (array $r) use ($picked): bool {
            return $r['choice_group'] === null || ($picked[$r['choice_group']] ?? null) === $r['component_item_id'];
        });

        $products = Item::query()
            ->where('tenant_id', $service->tenant_id)
            ->whereIn('id', $used->pluck('component_item_id')->all())
            ->get()->keyBy('id');

        $plan = [];
        foreach ($used as $r) {
            $product = $products->get($r['component_item_id']);
            if (! $product) {
                continue; // The product was deleted since: nothing to deduct.
            }
            $perUnit = array_key_exists($r['component_item_id'], $adjust) ? $adjust[$r['component_item_id']] : $r['quantity'];
            if ($perUnit <= 0) {
                continue;
            }
            $content = round($perUnit * $lineQuantity, 4);
            $stock = self::toStocked($product, $content);
            if ($stock <= 0) {
                continue;
            }
            $plan[] = [
                'component' => $product,
                'component_item_id' => (int) $product->id,
                'choice_group' => $r['choice_group'],
                'quantity_content' => $content,
                'quantity_stock' => $stock,
                'content_unit' => $product->hasCapacity() ? $product->content_unit : null,
            ];
        }

        return $plan;
    }

    /** 10 ml of a 200 ml bottle = 0.05 bottle; a product with no capacity is already in stocked units. */
    public static function toStocked(Item $product, float $content): float
    {
        $capacity = (float) $product->content_quantity;

        return round($product->hasCapacity() && $capacity > 0 ? $content / $capacity : $content, 4);
    }

    /** group => the product id used for it. @return array<string, int> */
    private function pickedChoices(Collection $recipe, array $choices, array &$ignored = []): array
    {
        $given = [];
        foreach ($choices as $key => $value) {
            if (is_array($value)) {
                $group = (string) ($value['group'] ?? '');
                $id = (int) ($value['component_item_id'] ?? 0);
            } else {
                $group = (string) $key;
                $id = (int) $value;
            }
            if ($group !== '') {
                $given[$group] = $id;
            }
        }

        $picked = [];
        foreach ($recipe->whereNotNull('choice_group')->groupBy('choice_group') as $group => $members) {
            $ids = $members->pluck('component_item_id')->all();
            if (isset($given[$group])) {
                if (in_array($given[$group], $ids, true)) {
                    $picked[$group] = $given[$group];

                    continue;
                }
                $ignored[] = ['kind' => 'choice', 'group' => $group, 'component_item_id' => $given[$group]];
            }
            // The default; an app that predates choices lands here.
            $default = $members->firstWhere('is_default', true) ?? $members->first();
            $picked[$group] = (int) $default['component_item_id'];
        }

        foreach ($given as $group => $id) {
            if (! $recipe->contains('choice_group', $group)) {
                $ignored[] = ['kind' => 'choice', 'group' => $group, 'component_item_id' => $id];
            }
        }

        return $picked;
    }

    /** component id => quantity per unit of service. @return array<int, float> */
    private function adjustments(Collection $recipe, array $overrides, array &$ignored = []): array
    {
        $known = $recipe->pluck('component_item_id')->all();
        $out = [];
        foreach ($overrides as $override) {
            $id = (int) ($override['component_item_id'] ?? 0);
            if (! in_array($id, $known, true)) {
                $ignored[] = ['kind' => 'override', 'component_item_id' => $id, 'quantity' => (float) ($override['quantity'] ?? 0)];

                continue;
            }
            $out[$id] = round(max(0, (float) ($override['quantity'] ?? 0)), 3);
        }

        return $out;
    }

    /**
     * Writes the consumption of one sale line: a stock movement per product,
     * the snapshot row, and the line's cost.
     *
     * @param  list<array<string, mixed>>  $plan   from plan()
     * @param  array{userId?: ?int, virtualDeviceId?: ?int, actorName?: ?string, terminalName?: ?string}  $actor
     * @return float the consumed cost, to add to the line's cost
     */
    public function record(Sale $sale, SaleItem $line, array $plan, int $locationId, array $actor = []): float
    {
        $total = 0.0;

        foreach ($plan as $entry) {
            /** @var Item $product */
            $product = $entry['component'];
            $quantity = (float) $entry['quantity_stock'];

            // A stock typed in before the ledger knew about it would read as zero.
            $this->ledger->reconcilePositiveStockCacheShortfall(
                (int) $sale->tenant_id, (int) $product->id, null, $locationId,
                $actor['userId'] ?? null, $actor['virtualDeviceId'] ?? null,
                $actor['actorName'] ?? null, $actor['terminalName'] ?? null,
            );
            $available = $this->ledger->availableQuantity((int) $sale->tenant_id, (int) $product->id, null, $locationId);
            $shortfall = round(max(0.0, $quantity - $available), 4);
            // What the shelf lacks is still a cost: priced at the product's
            // purchase price (per stocked unit), the best figure there is.
            $fallbackUnit = (float) ($product->purchase_price ?? 0);

            $result = $this->ledger->createOutgoingMovement([
                'tenantId' => (int) $sale->tenant_id,
                'itemId' => (int) $product->id,
                'variantId' => null,
                'locationId' => $locationId,
                'type' => InventoryMovementType::SERVICE_CONSUMPTION,
                'quantity' => $quantity,
                // The ledger's own convention for sales: now(), never sold_at.
                'occurredAt' => now()->utc(),
                'syncedAt' => null,
                'userId' => $actor['userId'] ?? null,
                'idempotencyKey' => 'svc-cons-'.$line->id.'-'.$product->id,
                'referenceType' => SaleItem::class,
                'referenceId' => $line->id,
                'referenceNumber' => $sale->number,
                'reason' => 'Consommation interne',
                'note' => 'Consommation interne · '.$line->name.' · '.$sale->number,
                'virtualDeviceId' => $actor['virtualDeviceId'] ?? null,
                'actorNameSnapshot' => $actor['actorName'] ?? null,
                'terminalNameSnapshot' => $actor['terminalName'] ?? null,
                'allowNegative' => true,
            ]);

            $cost = round((float) $result['cogs'] + $shortfall * $fallbackUnit, 4);
            $total += $cost;

            // An empty shelf still cost money: the journal shows the full
            // value (purchase price for the part with no stock) and says so.
            // `cogs` stays the layers' figure, so the stock value is unchanged.
            if ($shortfall > 0 && isset($result['movement'])) {
                $result['movement']->forceFill([
                    'total_cost' => $cost,
                    'unit_cost' => $quantity > 0 ? round($cost / $quantity, 4) : 0,
                    'note' => $result['movement']->note.' · sans stock : '.ContentUnits::describeStock($product, $shortfall),
                ])->save();
            }

            SaleItemConsumption::create([
                'tenant_id' => $sale->tenant_id,
                'sale_id' => $sale->id,
                'sale_item_id' => $line->id,
                'service_item_id' => $line->item_id,
                'component_item_id' => $product->id,
                'location_id' => $locationId,
                'choice_group' => $entry['choice_group'],
                'content_unit' => $entry['content_unit'],
                'quantity_content' => $entry['quantity_content'],
                'quantity_stock' => $quantity,
                'unit_cost' => $quantity > 0 ? round($cost / $quantity, 4) : 0,
                'total_cost' => $cost,
                'shortfall_stock' => $shortfall,
                'returned_stock' => 0,
                'movement_id' => $result['movement']->id,
            ]);
        }

        $total = round($total, 4);
        if ($plan !== []) {
            $qty = max(1, (int) $line->quantity);
            $line->forceFill([
                'unit_cost' => round((float) $line->unit_cost + $total / $qty, 4),
                'total_cost' => round((float) $line->total_cost + $total, 4),
            ])->save();
        }

        return $total;
    }

    /**
     * Gives back, proportionally, what a refunded line consumed.
     *
     * @param  int  $quantity   service units refunded now
     * @param  int  $remaining  service units of the line still refundable BEFORE this return
     * @param  array{userId?: ?int, virtualDeviceId?: ?int, actorName?: ?string, terminalName?: ?string}  $actor
     * @return float the stocked quantity given back (all products together, for tests and logs)
     */
    public function reverse(int $tenantId, int $saleItemId, int $quantity, int $remaining, SaleReturn $return, int $locationId, array $actor = []): float
    {
        if ($quantity < 1 || $remaining < 1) {
            return 0.0;
        }

        $given = 0.0;
        $rows = SaleItemConsumption::where('tenant_id', $tenantId)->where('sale_item_id', $saleItemId)->lockForUpdate()->get();

        foreach ($rows as $row) {
            $left = round((float) $row->quantity_stock - (float) $row->returned_stock, 4);
            if ($left <= 0) {
                continue;
            }
            // Proportional to what is still to come back: the last unit
            // returns exactly what remains, whatever the rounding before.
            $back = $quantity >= $remaining ? $left : min($left, round($left * $quantity / $remaining, 4));
            if ($back <= 0) {
                continue;
            }

            $this->ledger->createIncomingMovement([
                'tenantId' => $tenantId,
                'itemId' => (int) $row->component_item_id,
                'variantId' => null,
                'locationId' => $locationId,
                'type' => InventoryMovementType::SERVICE_CONSUMPTION_RETURN,
                'quantity' => $back,
                'unitCost' => (float) $row->unit_cost,
                'occurredAt' => now()->utc(),
                'syncedAt' => null,
                'userId' => $actor['userId'] ?? null,
                'idempotencyKey' => 'svc-cons-ret-'.$return->id.'-'.$row->id,
                'referenceType' => SaleReturn::class,
                'referenceId' => $return->id,
                'referenceNumber' => $return->number,
                'reason' => 'Retour de consommation',
                'note' => 'Retour de consommation · '.$return->number,
                'virtualDeviceId' => $actor['virtualDeviceId'] ?? null,
                'actorNameSnapshot' => $actor['actorName'] ?? null,
                'terminalNameSnapshot' => $actor['terminalName'] ?? null,
            ]);

            $row->forceFill(['returned_stock' => round((float) $row->returned_stock + $back, 4)])->save();
            $given += $back;
        }

        return round($given, 4);
    }

    /**
     * The estimated cost of one unit of a service from its recipe, at the
     * products' current average cost (purchase price when never stocked).
     * Used where no sale exists yet: the catalogue's « coût / marge ».
     *
     * @return array{cost: float, lines: list<array<string, mixed>>}
     */
    public function estimate(Item $service, ?ItemVariant $variant = null, ?int $locationId = null): array
    {
        $recipe = $this->recipe((int) $service->tenant_id, (int) $service->id, $variant?->id);
        $products = Item::query()->where('tenant_id', $service->tenant_id)
            ->whereIn('id', $recipe->pluck('component_item_id')->all())->get()->keyBy('id');

        // Alternatives of one group cost the same on average: the default's cost counts once.
        $picked = $this->pickedChoices($recipe, []);
        $lines = [];
        $cost = 0.0;
        foreach ($recipe as $r) {
            $product = $products->get($r['component_item_id']);
            if (! $product) {
                continue;
            }
            $unitCost = $this->unitCost($product, $locationId);
            $stocked = self::toStocked($product, $r['quantity']);
            $lineCost = round($stocked * $unitCost, 4);
            $counted = $r['choice_group'] === null || ($picked[$r['choice_group']] ?? null) === $r['component_item_id'];
            if ($counted) {
                $cost += $lineCost;
            }
            $lines[] = [
                'component_item_id' => $r['component_item_id'],
                'title' => $product->title,
                'choice_group' => $r['choice_group'],
                'quantity' => $r['quantity'],
                'content_unit' => $product->hasCapacity() ? $product->content_unit : null,
                'cost' => $lineCost,
                'counted' => $counted,
            ];
        }

        return ['cost' => round($cost, 2), 'lines' => $lines];
    }

    /** Cost of one STOCKED unit: the weighted average at the location, else the purchase price. */
    public function unitCost(Item $product, ?int $locationId = null): float
    {
        $average = 0.0;
        if ($locationId) {
            $average = $this->ledger->averageCost((int) $product->tenant_id, (int) $product->id, $locationId);
        } else {
            $row = DB::table('item_location_stock')
                ->where('tenant_id', $product->tenant_id)->where('item_id', $product->id)
                ->where('average_cost', '>', 0)->orderByDesc('quantity')->first(['average_cost']);
            $average = (float) ($row->average_cost ?? 0);
        }

        return $average > 0 ? $average : (float) ($product->purchase_price ?? 0);
    }
}
