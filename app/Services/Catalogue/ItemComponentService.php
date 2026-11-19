<?php

namespace App\Services\Catalogue;

use App\Enums\ItemType;
use App\Models\Item;
use App\Models\ItemComponent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Edits a service's recipe (« Produits utilisés »), for the back office and
 * the till alike.
 *
 * The whole recipe travels as one list and replaces the stored one: a sheet
 * saved offline replays as a single step, a retry after a lost response is
 * harmless, and a row left out is a row removed. A row is identified by what
 * it IS — (variant, product) — so two rows can never say it twice.
 */
class ItemComponentService
{
    public const MAX_ROWS = 120;

    /**
     * @param  array<int, array<string, mixed>>  $rows  [component_item_id, quantity, variant_id?, choice_group?, is_default?, sort_order?]
     * @return Collection<int, ItemComponent>
     *
     * @throws ValidationException
     */
    public function sync(Item $service, array $rows): Collection
    {
        if ($service->type !== ItemType::Service->value) {
            throw ValidationException::withMessages(['components' => 'Seul un service utilise des produits.']);
        }
        if (count($rows) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['components' => 'Trop de produits pour un seul service ('.self::MAX_ROWS.' au maximum).']);
        }

        $tenantId = (int) $service->tenant_id;
        $variantIds = $service->variants()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $productIds = collect($rows)->pluck('component_item_id')->map(fn ($id) => (int) $id)->unique()->all();
        $products = Item::where('tenant_id', $tenantId)->whereIn('id', $productIds)->get()->keyBy('id');

        $clean = [];
        $seen = [];
        $defaults = [];
        foreach (array_values($rows) as $i => $row) {
            $productId = (int) ($row['component_item_id'] ?? 0);
            $variantId = ($row['variant_id'] ?? null) !== null && $row['variant_id'] !== '' ? (int) $row['variant_id'] : null;
            $quantity = round((float) ($row['quantity'] ?? 0), 3);
            $group = isset($row['choice_group']) ? trim((string) $row['choice_group']) : '';
            $group = $group === '' ? null : mb_substr($group, 0, 80);
            $product = $products->get($productId);

            if (! $product || $product->type === ItemType::Service->value || $productId === (int) $service->id) {
                throw ValidationException::withMessages(['components' => 'Un produit utilisé est introuvable : choisissez un article en stock, pas un service.']);
            }
            if ($variantId !== null && ! in_array($variantId, $variantIds, true)) {
                throw ValidationException::withMessages(['components' => 'Une déclinaison de la liste n’appartient pas à ce service.']);
            }
            if ($quantity < 0 || $quantity > 999999) {
                throw ValidationException::withMessages(['components' => 'Une quantité de produit est invalide.']);
            }
            // 0 only means something on a variant: « not used for this one ».
            if ($quantity <= 0 && $variantId === null) {
                throw ValidationException::withMessages(['components' => 'La quantité d’un produit utilisé doit être supérieure à zéro.']);
            }
            $key = ($variantId ?? 0).'-'.$productId;
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(['components' => 'Un même produit ne peut figurer qu’une fois par service ou par déclinaison.']);
            }
            $seen[$key] = true;

            $isDefault = $group !== null && (bool) ($row['is_default'] ?? false);
            if ($isDefault) {
                $scope = ($variantId ?? 0).'|'.mb_strtolower($group);
                if (isset($defaults[$scope])) {
                    throw ValidationException::withMessages(['components' => 'Un seul produit par défaut est possible dans un groupe de choix.']);
                }
                $defaults[$scope] = true;
            }

            $clean[] = [
                'tenant_id' => $tenantId,
                'item_id' => (int) $service->id,
                'variant_id' => $variantId,
                'component_item_id' => $productId,
                'quantity' => $quantity,
                'choice_group' => $group,
                'is_default' => $isDefault,
                'sort_order' => (int) ($row['sort_order'] ?? $i),
            ];
        }

        return DB::transaction(function () use ($service, $clean): Collection {
            $existing = ItemComponent::where('tenant_id', $service->tenant_id)->where('item_id', $service->id)->get()
                ->keyBy(fn (ItemComponent $c) => ((int) $c->variant_id).'-'.$c->component_item_id);

            $kept = [];
            foreach ($clean as $row) {
                $key = ((int) $row['variant_id']).'-'.$row['component_item_id'];
                $kept[$key] = true;
                if ($current = $existing->get($key)) {
                    $current->fill($row)->save();
                } else {
                    ItemComponent::create($row);
                }
            }
            foreach ($existing as $key => $current) {
                if (! isset($kept[$key])) {
                    $current->delete();
                }
            }

            // The items feed is what tills page through: bumping the service
            // tells them its recipe moved.
            $service->touch();

            return $this->rows($service);
        });
    }

    /** @return Collection<int, ItemComponent> */
    public function rows(Item $service): Collection
    {
        return ItemComponent::where('tenant_id', $service->tenant_id)->where('item_id', $service->id)
            ->orderBy('variant_id')->orderBy('sort_order')->orderBy('id')->get();
    }

    /** The shape the till reads, here and in /sync/meta. */
    public static function payload(ItemComponent $row): array
    {
        return [
            'id' => (int) $row->id,
            'item_id' => (int) $row->item_id,
            'variant_id' => $row->variant_id !== null ? (int) $row->variant_id : null,
            'component_item_id' => (int) $row->component_item_id,
            // In the product's content unit (its `content_unit`), or in stocked units when it has none.
            'quantity' => (float) $row->quantity,
            'choice_group' => $row->choice_group,
            'is_default' => (bool) $row->is_default,
            'sort_order' => (int) $row->sort_order,
            'updated_at' => $row->updated_at?->toISOString(),
        ];
    }
}
