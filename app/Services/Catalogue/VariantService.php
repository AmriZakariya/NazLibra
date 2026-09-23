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
