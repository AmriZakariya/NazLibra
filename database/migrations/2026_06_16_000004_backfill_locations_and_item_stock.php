<?php

use App\Models\Item;
use App\Models\ItemVariant;
use App\Models\Location;
use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        foreach (Tenant::query()->cursor() as $tenant) {
            $stores = collect(data_get($tenant->settings, 'stores', []))
                ->map(function ($store): array {
                    if (! is_array($store)) {
                        $store = ['name' => (string) $store];
                    }
                    $name = trim((string) ($store['name'] ?? 'Magasin principal'));

                    return [
                        'name' => $name,
                        'type' => (string) ($store['type'] ?? 'store'),
                        'address' => $store['address'] ?? null,
                        'phone' => $store['phone'] ?? null,
                        'manager_name' => $store['manager'] ?? null,
                        'is_active' => (bool) ($store['is_active'] ?? true),
                    ];
                })
                ->filter(fn (array $store) => $store['name'] !== '')
                ->values();

            if ($stores->isEmpty()) {
                $stores = collect([
                    ['name' => 'Magasin principal', 'type' => 'store', 'address' => $tenant->address, 'phone' => $tenant->phone, 'manager_name' => null, 'is_active' => true],
                    ['name' => 'Dépôt', 'type' => 'warehouse', 'address' => null, 'phone' => null, 'manager_name' => null, 'is_active' => true],
                    ['name' => 'Rayon scolaire', 'type' => 'area', 'address' => null, 'phone' => null, 'manager_name' => null, 'is_active' => true],
                ]);
            }

            $locationIds = [];
            foreach ($stores as $index => $store) {
                $location = Location::firstOrCreate(
                    ['tenant_id' => $tenant->id, 'name' => $store['name']],
                    [
                        'type' => in_array($store['type'], ['store', 'warehouse', 'branch', 'stockroom', 'online', 'temporary', 'area'], true)
                            ? $store['type']
                            : 'store',
                        'address' => $store['address'],
                        'phone' => $store['phone'],
                        'manager_name' => $store['manager_name'],
                        'is_active' => $store['is_active'],
                        'is_default' => $index === 0,
                    ]
                );
                $locationIds[] = $location->id;
            }

            $defaultLocationId = $locationIds[0] ?? null;
            if (! $defaultLocationId) {
                continue;
            }

            // Ensure only one default per tenant
            Location::query()
                ->where('tenant_id', $tenant->id)
                ->where('id', '!=', $defaultLocationId)
                ->update(['is_default' => false]);

            // Backfill item stock into default location
            foreach (Item::query()->where('tenant_id', $tenant->id)->cursor() as $item) {
                DB::table('item_location_stock')->insert([
                    'tenant_id' => $tenant->id,
                    'item_id' => $item->id,
                    'variant_id' => null,
                    'location_id' => $defaultLocationId,
                    'quantity' => max(0, (int) $item->stock_quantity),
                    'reserved_quantity' => 0,
                    'incoming_quantity' => 0,
                    'damaged_quantity' => 0,
                    'returned_quantity' => 0,
                    'transferred_quantity' => 0,
                    'awaiting_confirmation_quantity' => 0,
                    'min_stock' => max(0, (int) $item->min_stock_threshold),
                    'max_stock' => null,
                    'reorder_point' => max(0, (int) $item->min_stock_threshold),
                    'preferred_stock_level' => null,
                    'average_cost' => (float) ($item->purchase_price ?? 0),
                    'last_purchase_cost' => (float) ($item->purchase_price ?? 0),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Backfill variant stock into default location
            foreach (ItemVariant::query()->where('tenant_id', $tenant->id)->cursor() as $variant) {
                DB::table('item_location_stock')->insert([
                    'tenant_id' => $tenant->id,
                    'item_id' => $variant->item_id,
                    'variant_id' => $variant->id,
                    'location_id' => $defaultLocationId,
                    'quantity' => max(0, (int) $variant->stock_quantity),
                    'reserved_quantity' => 0,
                    'incoming_quantity' => 0,
                    'damaged_quantity' => 0,
                    'returned_quantity' => 0,
                    'transferred_quantity' => 0,
                    'awaiting_confirmation_quantity' => 0,
                    'min_stock' => max(0, (int) $variant->min_stock_threshold),
                    'max_stock' => null,
                    'reorder_point' => max(0, (int) $variant->min_stock_threshold),
                    'preferred_stock_level' => null,
                    'average_cost' => (float) ($variant->purchase_price ?? 0),
                    'last_purchase_cost' => (float) ($variant->purchase_price ?? 0),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('item_location_stock')->truncate();
        Location::query()->truncate();
    }
};
