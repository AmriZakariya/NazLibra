<?php

namespace App\Services\Catalogue;

use App\Models\Item;
use App\Models\ItemVariant;
use App\Models\ItemVariantValue;
use App\Models\OptionType;
use App\Models\OptionValue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Builds and maintains an article's variants.
 *
 * A variant is a thing with its OWN STOCK. That single rule is what keeps the
 * model from rotting: an option with no stock of its own ("sans glace", "avec
 * emballage cadeau") is a modifier and must never become a row here, or a
 * burger with eight toppings turns into 256 sub-products.
 */
class VariantService
{
    /** Past this, a shop has mis-ticked rather than meant it. */
    public const MAX_COMBINATIONS = 200;

    /**
     * Generates the combinations of the chosen axes, keeping what exists.
     *
     * Re-running after adding a colour adds only the new combinations:
     * regenerating from scratch would throw away the stock, barcodes and
     * prices already attached to the others.
     *
     * @param  array<int, array<int, int>>  $valuesByType  option_type_id => option_value ids
     */
    public function generateMatrix(Item $item, array $valuesByType): Collection
    {
        return DB::transaction(function () use ($item, $valuesByType): Collection {
            $tenantId = (int) $item->tenant_id;
            $axes = $this->resolveAxes($tenantId, $valuesByType);

            if ($axes->isEmpty()) {
                throw ValidationException::withMessages([
                    'options' => 'Choisissez au moins une option et une valeur.',
                ]);
            }

            $combinations = $this->cartesian($axes->values()->all());

            if (count($combinations) > self::MAX_COMBINATIONS) {
                throw ValidationException::withMessages([
                    'options' => 'Cette combinaison produirait '.count($combinations)
                        .' déclinaisons. Réduisez les options choisies ('
                        .self::MAX_COMBINATIONS.' maximum).',
                ]);
            }

            $this->syncAxes($item, $axes->keys()->all());

            $existing = $item->variants()->get()->keyBy('combination_key');

            foreach ($combinations as $combination) {
                $key = $this->combinationKey($combination);
                if ($existing->has($key)) {
                    continue;
                }

                $variant = $item->variants()->create([
                    'tenant_id' => $tenantId,
                    'name' => $this->nameFor($combination),
                    'attributes' => $this->attributesFor($combination),
                    'combination_key' => $key,
                    'sale_price' => $item->sale_price,
                    'purchase_price' => $item->purchase_price,
                    'stock_quantity' => 0,
                    'is_active' => true,
                    'status' => 'active',
                ]);

                foreach ($combination as $value) {
                    ItemVariantValue::create([
                        'tenant_id' => $tenantId,
                        'item_variant_id' => $variant->id,
                        'option_type_id' => $value->option_type_id,
                        'option_value_id' => $value->id,
                    ]);
                }
            }

            $this->refreshCount($item);

            return $item->variants()->with(['values.optionValue', 'values.optionType'])->get();
        });
    }

    /**
     * Applies a till's edit of an article's variants in one go: updates,
     * additions and removals together, so a sheet saved offline replays as a
     * single step.
     *
     * Each row is matched by `id`; a row without one that names an existing
     * active variant updates it instead of adding a twin, which makes a retry
     * after a lost response harmless. A removed variant is ARCHIVED, never
     * deleted: sales, stock movements and appointments point at it, and
     * archiving is also what tells the other tills to drop it (a hard delete
     * leaves no trace to sync).
     *
     * @param  array<int, array<string, mixed>>  $rows  name, sale_price, [id, local_id, duration_minutes, barcode, sku]
     * @param  array<int, int>  $removeIds
     * @return array{variants: Collection<int, ItemVariant>, removed: array<int, int>, map: array<string, int>}
     */
    public function sync(Item $item, array $rows, array $removeIds = []): array
    {
        return DB::transaction(function () use ($item, $rows, $removeIds): array {
            $existing = $item->variants()->get()->keyBy('id');
            $remove = collect($removeIds)->map(fn ($id) => (int) $id)
                ->filter(fn (int $id) => $existing->has($id))->unique()->values();
            $isService = $item->type === \App\Enums\ItemType::Service->value;

            // Resolve every row to a variant (or a new one) before writing.
            $plan = [];
            $taken = [];
            foreach (array_values($rows) as $index => $row) {
                $id = isset($row['id']) ? (int) $row['id'] : null;
                if ($id !== null && ! $existing->has($id)) {
                    throw ValidationException::withMessages(["variants.$index.id" => 'Déclinaison introuvable.']);
                }
                $name = trim((string) $row['name']);
                $match = $id !== null
                    ? $existing->get($id)
                    : $existing->first(fn (ItemVariant $v) => $v->is_active && ! $remove->contains($v->id)
                        && mb_strtolower($v->name) === mb_strtolower($name));
                if ($match && $remove->contains($match->id)) {
                    throw ValidationException::withMessages(["variants.$index.id" => 'Une déclinaison ne peut être modifiée et retirée à la fois.']);
                }
                $key = mb_strtolower($name);
                if (isset($taken[$key])) {
                    throw ValidationException::withMessages(["variants.$index.name" => 'Deux déclinaisons portent le même nom.']);
                }
                $taken[$key] = true;
                $plan[] = [$index, $match, $name, $row];
            }

            // A name must stay unique among the variants that stay on sale.
            $touched = collect($plan)->pluck(1)->filter()->pluck('id');
            foreach ($existing as $variant) {
                if ($variant->is_active && ! $remove->contains($variant->id) && ! $touched->contains($variant->id)
                    && isset($taken[mb_strtolower($variant->name)])) {
                    $index = collect($plan)->first(fn ($p) => mb_strtolower($p[2]) === mb_strtolower($variant->name))[0];
                    throw ValidationException::withMessages(["variants.$index.name" => 'Une déclinaison porte déjà ce nom.']);
                }
            }

            $map = [];
            foreach ($plan as $order => [$index, $match, $name, $row]) {
                $price = round((float) $row['sale_price'], 2);
                $attributes = [
                    'name' => $name,
                    'sale_price' => $price,
                    // The price typed here IS this variant's price: without
                    // an override the till would still sell the article's own.
                    'sale_price_override' => $price,
                    'sort_order' => $order,
                    'is_active' => true,
                    'status' => 'active',
                ];
                if ($isService) {
                    // The length belongs to the variant of a service, and a
                    // product has none.
                    if (array_key_exists('duration_minutes', $row)) {
                        $attributes['duration_minutes'] = $row['duration_minutes'] !== null ? (int) $row['duration_minutes'] : null;
                    }
                } else {
                    foreach (['barcode', 'sku'] as $field) {
                        if (array_key_exists($field, $row)) {
                            $attributes[$field] = ($row[$field] ?? '') !== '' ? $row[$field] : null;
                        }
                    }
                }

                if ($match) {
                    $match->update($attributes);
                    $variant = $match;
                } else {
                    $variant = $item->variants()->create($attributes + [
                        'tenant_id' => $item->tenant_id,
                        'attributes' => [],
                        'purchase_price' => $item->purchase_price,
                        'stock_quantity' => 0,
                    ]);
                }
                if (! empty($row['local_id'])) {
                    $map[(string) $row['local_id']] = $variant->id;
                }
            }

            foreach ($remove as $id) {
                $existing->get($id)->update(['is_active' => false]);
            }

            $this->refreshCount($item);

            return [
                'variants' => $item->variants()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get(),
                'removed' => $remove->all(),
                'map' => $map,
            ];
        });
    }

    /**
     * Keeps `items.variant_count` true.
     *
     * Stored rather than joined so the till can ask "does this need a
     * chooser?" on every catalogue row without a subquery; a test holds it to
     * the real count.
     */
    public function refreshCount(Item $item): void
    {
        $item->forceFill([
            'variant_count' => $item->variants()->where('is_active', true)->count(),
        ])->save();
    }

    /**
     * The variant a till should sell, given the values the cashier chose.
     *
     * @param  array<int, int>  $optionValueIds
     */
    public function findByValues(Item $item, array $optionValueIds): ?ItemVariant
    {
        $values = OptionValue::whereIn('id', array_filter($optionValueIds))->get();

        if ($values->isEmpty()) {
            return null;
        }

        return $item->variants()
            ->where('combination_key', $this->combinationKey($values->all()))
            ->first();
    }

    /** @param array<int, int> $typeIds */
    private function syncAxes(Item $item, array $typeIds): void
    {
        $payload = [];
        foreach (array_values($typeIds) as $index => $typeId) {
            $payload[$typeId] = ['tenant_id' => $item->tenant_id, 'sort_order' => $index];
        }

        $item->optionTypes()->sync($payload);
    }

    /**
     * @param  array<int, array<int, int>>  $valuesByType
     * @return Collection<int, Collection<int, OptionValue>>
     */
    private function resolveAxes(int $tenantId, array $valuesByType): Collection
    {
        $types = OptionType::where('tenant_id', $tenantId)
            ->whereIn('id', array_keys($valuesByType))
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        return $types->mapWithKeys(function (OptionType $type) use ($valuesByType, $tenantId): array {
            $wanted = array_filter((array) ($valuesByType[$type->id] ?? []));
            if ($wanted === []) {
                return [];
            }

            $values = OptionValue::where('tenant_id', $tenantId)
                ->where('option_type_id', $type->id)
                ->whereIn('id', $wanted)
                ->where('is_active', true)
                ->orderBy('sort_order')->orderBy('id')
                ->get();

            return $values->isEmpty() ? [] : [$type->id => $values];
        });
    }

    /**
     * Every combination of one value per axis.
     *
     * @param  array<int, Collection<int, OptionValue>>  $axes
     * @return array<int, array<int, OptionValue>>
     */
    private function cartesian(array $axes): array
    {
        $result = [[]];

        foreach ($axes as $values) {
            $next = [];
            foreach ($result as $partial) {
                foreach ($values as $value) {
                    $next[] = [...$partial, $value];
                }
            }
            $result = $next;
        }

        return $result;
    }

    /**
     * The combination as a stable string, so a unique index can stop the same
     * pairing existing twice. Sorted by value id, so "Rouge / L" and "L /
     * Rouge" are recognised as one thing however they were entered.
     *
     * @param  array<int, OptionValue>  $combination
     */
    public function combinationKey(array $combination): string
    {
        return collect($combination)->pluck('id')->sort()->values()->join('-');
    }

    /** @param array<int, OptionValue> $combination */
    private function nameFor(array $combination): string
    {
        return collect($combination)->pluck('value')->join(' / ');
    }

    /** @param array<int, OptionValue> $combination */
    private function attributesFor(array $combination): array
    {
        return collect($combination)
            ->mapWithKeys(fn (OptionValue $value) => [
                $value->optionType?->name ?? (string) $value->option_type_id => $value->value,
            ])->all();
    }
}
