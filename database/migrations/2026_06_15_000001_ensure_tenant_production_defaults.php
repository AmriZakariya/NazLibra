<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('tenants')->orderBy('id')->get()->each(function (object $tenant): void {
            $settings = json_decode((string) $tenant->settings, true) ?: [];
            $storeKey = (string) ($settings['current_store'] ?? Str::slug((string) ($tenant->slug ?: $tenant->name ?: 'magasin-principal')));
            $storeKey = $storeKey !== '' ? $storeKey : 'magasin-principal';

            $stores = collect($settings['stores'] ?? [])
                ->filter(fn ($store) => is_array($store) && trim((string) ($store['name'] ?? '')) !== '')
                ->map(function (array $store) use ($tenant): array {
                    $name = trim((string) ($store['name'] ?? $tenant->name ?? 'Magasin principal'));

                    return array_merge([
                        'key' => Str::slug($name) ?: 'magasin-principal',
                        'type' => 'store',
                        'address' => $tenant->address,
                        'phone' => $tenant->phone,
                        'manager' => '',
                        'is_active' => true,
                    ], $store, [
                        'name' => $name,
                        'is_active' => (bool) ($store['is_active'] ?? true),
                    ]);
                })
                ->values();

            if ($stores->isEmpty()) {
                $stores = collect([[
                    'key' => $storeKey,
                    'name' => $tenant->name ?: 'Magasin principal',
                    'type' => 'store',
                    'address' => $tenant->address,
                    'phone' => $tenant->phone,
                    'manager' => '',
                    'is_active' => true,
                ]]);
            }

            if (! $stores->contains(fn (array $store): bool => ($store['key'] ?? null) === $storeKey && ($store['is_active'] ?? false))) {
                $storeKey = (string) ($stores->firstWhere('is_active', true)['key'] ?? $stores->first()['key']);
            }

            $settings['stores'] = $stores->all();
            $settings['current_store'] = $storeKey;
            $settings['company_profile'] = array_merge([
                'store_code' => $tenant->slug ?: $storeKey,
                'store_name' => $tenant->name ?: 'Magasin principal',
                'business_mode' => $tenant->mode ?: 'bookstore',
                'mobile' => '',
                'email' => $tenant->email,
                'phone' => $tenant->phone,
                'country' => 'Maroc',
                'city' => '',
                'address' => $tenant->address,
                'timezone' => $tenant->timezone ?: 'Africa/Casablanca',
                'date_format' => 'dd/mm/yyyy',
                'time_format' => '24',
                'currency' => $tenant->currency ?: 'MAD',
                'currency_placement' => 'Right',
                'decimals' => 2,
                'qty_decimals' => 2,
                'language_id' => str_starts_with((string) $tenant->locale, 'ar') ? 'ar' : 'fr',
            ], $settings['company_profile'] ?? []);
            $settings['pos'] = array_merge([
                'editable_price' => true,
                'allow_sale_edit' => true,
                'allow_oversell' => false,
                'show_out_of_stock' => false,
                'show_cash_drawer_navbar' => true,
                'require_adjustment_reason' => true,
                'update_cost_on_purchase' => true,
                'low_stock_dashboard' => true,
                'auto_reorder_draft' => false,
                'inventory_cycle_days' => 30,
                'default_min_stock_threshold' => 3,
            ], $settings['pos'] ?? []);

            DB::table('tenants')->where('id', $tenant->id)->update([
                'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        // Non-destructive normalization only; keep tenant production settings intact.
    }
};
