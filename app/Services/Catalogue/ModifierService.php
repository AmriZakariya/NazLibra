<?php

namespace App\Services\Catalogue;

use App\Models\Item;
use App\Models\Modifier;
use App\Models\SaleItem;
use App\Models\SaleItemModifier;
use App\Services\Inventory\InventoryMovementType;
use App\Services\Inventory\InventoryService;
use App\Services\Inventory\MovementDTO;
use Illuminate\Support\Collection;

/**
 * The options chosen on a sale line, and what they cost.
 *
 * Modifiers are not sub-products: they carry no stock of their own. Some of
 * them do eat into ANOTHER article's stock — a scoop of ice cream, a shot of
 * syrup, extra cheese — and that is the only place inventory is touched here.
 */
class ModifierService
{
    public function __construct(private readonly InventoryService $inventory)
    {
    }

    /**
     * Checks a line's chosen modifiers against the groups the article offers.
     *
     * Throws rather than silently dropping: a cashier who picked something
     * impossible must be told, not quietly given a different order.
     *
     * @param  array<int, int>  $modifierIds
     * @return Collection<int, Modifier>
     */
    public function resolve(Item $item, array $modifierIds): Collection
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $modifierIds))));

        $chosen = Modifier::with('group')
            ->where('tenant_id', $item->tenant_id)
            ->where('is_active', true)
            ->whereIn('id', $ids)
            ->get();

        if ($chosen->count() !== count($ids)) {
            throw new \RuntimeException('Une option choisie est introuvable ou désactivée.');
        }

        $offered = $item->modifierGroups()->with('modifiers')->get();
        $offeredIds = $offered->pluck('id');

        foreach ($chosen as $modifier) {
            if (! $offeredIds->contains($modifier->modifier_group_id)) {
                throw new \RuntimeException(
                    '« '.$modifier->name.' » n’est pas proposé pour « '.$item->title.' ».',
                );
            }
        }

        // The group rules, checked per group rather than over the whole line:
        // "choose exactly one cuisson" and "up to three suppléments" are
        // different questions about different sets.
        foreach ($offered as $group) {
            $count = $chosen->where('modifier_group_id', $group->id)->count();

            if ($count < $group->min_select) {
                throw new \RuntimeException(
                    'Choisissez au moins '.$group->min_select.' option(s) dans « '.$group->name.' ».',
                );
            }
            if ($group->max_select !== null && $count > $group->max_select) {
                throw new \RuntimeException(
                    'Pas plus de '.$group->max_select.' option(s) dans « '.$group->name.' ».',
                );
            }
        }

        return $chosen;
    }

    /** What the chosen options add to (or take off) one unit's price. */
    public function priceDelta(Collection $modifiers): float
    {
        return round((float) $modifiers->sum(fn (Modifier $m) => (float) $m->price_delta), 2);
    }

    /**
     * Records what was chosen and eats into any linked stock.
     *
     * The name and the price are snapshotted: a shop renaming a supplement or
     * changing its price next month must not rewrite what a customer was
     * charged for last week.
     *
     * @param  Collection<int, Modifier>  $modifiers
     */
    public function attach(SaleItem $line, Collection $modifiers, int $tenantId, float $quantity, ?int $locationId): void
    {
        foreach ($modifiers as $modifier) {
            SaleItemModifier::create([
                'tenant_id' => $tenantId,
                'sale_item_id' => $line->id,
                'modifier_id' => $modifier->id,
                'name' => $modifier->name,
                'price_delta' => $modifier->price_delta,
                'quantity' => $quantity,
            ]);

            if (! $modifier->consumesStock() || $locationId === null) {
                continue;
            }

            // Scaled by the line quantity: three burgers with extra cheese eat
            // three portions, not one.
            $this->inventory->move(new MovementDTO(
                tenantId: $tenantId,
                itemId: (int) $modifier->linked_item_id,
                variantId: null,
                locationId: $locationId,
                type: InventoryMovementType::SALE,
                quantityChanged: (int) round((float) $modifier->consumes_quantity * $quantity),
                referenceType: SaleItem::class,
                referenceId: $line->id,
                note: 'Option « '.$modifier->name.' »',
                // A kitchen that runs out mid-service must not be stopped from
                // selling; the shortfall shows as negative and is counted.
                allowNegative: true,
            ));
        }
    }
}
