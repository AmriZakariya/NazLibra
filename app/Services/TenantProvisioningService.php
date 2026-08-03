<?php

namespace App\Services;

use App\Models\Category;
use App\Models\FinancialAccount;
use App\Models\Location;
use App\Models\Role;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Support\BusinessMode;
use App\Support\ItemTypes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Creates a fully-working tenant (settings, roles, owner user, locations,
 * categories and default catalogue data) inside the CURRENT database.
 *
 * This is the reusable core previously trapped in SetupController::createFresh().
 * On a freshly-provisioned client install it is invoked once, via
 * `php artisan castlit:install-tenant`, to seed that install's single tenant
 * from the approved subscription.
 */
class TenantProvisioningService
{
    /**
     * @param  array{name:string,business_mode?:?string,activity?:?string,currency?:string,timezone?:string,language?:string,phone?:?string,email?:?string,address?:?string,costing_method?:?string}  $store
     * @param  array{name:string,email:string,password:string}  $owner
     * @return array{tenant:Tenant,owner:User}
     */
    public function install(array $store, array $owner): array
    {
        return DB::transaction(function () use ($store, $owner): array {
            $store['business_mode'] = BusinessMode::normalize(
                $store['business_mode'] ?? $store['activity'] ?? null
            );
            $store['currency'] = $store['currency'] ?? 'MAD';
            $store['timezone'] = $store['timezone'] ?? 'Africa/Casablanca';
            $store['language'] = $store['language'] ?? 'fr';

            [$tenant, $slug, $storeNames] = $this->buildTenant($store);

            foreach ($this->defaultRoles() as [$roleName, $roleKey, $permissions, $isSystem]) {
                Role::create([
                    'tenant_id'   => $tenant->id,
                    'name'        => $roleName,
                    'key'         => $roleKey,
                    'permissions' => $permissions,
                    'is_system'   => $isSystem,
                ]);
            }

            $ownerUser = User::create([
                'current_tenant_id' => $tenant->id,
                'name'              => $owner['name'],
                'email'             => $owner['email'],
                'password'          => Hash::make($owner['password']),
                'avatar_color'      => '#3157D5',
                'is_active'         => true,
            ]);

            $tenant->users()->attach($ownerUser->id, [
                'role'         => 'owner',
                'store_access' => json_encode(array_values($storeNames)),
            ]);

            Location::create([
                'tenant_id'  => $tenant->id,
                'name'       => $store['name'],
                'type'       => 'store',
                'address'    => $store['address'] ?? null,
                'phone'      => $store['phone'] ?? null,
                'is_default' => true,
                'is_active'  => true,
            ]);

            foreach ($this->defaultCategoriesForMode($store['business_mode']) as $catName) {
                if (empty($catName)) {
                    continue;
                }
                Category::create([
                    'tenant_id' => $tenant->id,
                    'name'      => $catName,
                    'slug'      => Str::slug($catName),
                ]);
            }

            $this->seedDefaults($tenant, $slug, $store['business_mode']);

            return ['tenant' => $tenant, 'owner' => $ownerUser];
        });
    }

    /**
     * @return array{0:Tenant,1:string,2:array<int,string>}
     */
    private function buildTenant(array $store): array
    {
        $businessMode = BusinessMode::get($store['business_mode']);
        $slug     = $this->uniqueTenantSlug(Str::slug($store['name']) ?: 'store');
        $locale   = $store['language'] === 'ar' ? 'ar_MA' : 'fr_MA';
        $storeKey = $slug;

        $locationsList = [[
            'key' => $storeKey, 'name' => $store['name'], 'type' => 'store',
            'address' => $store['address'] ?? null, 'phone' => $store['phone'] ?? null,
            'manager' => '', 'is_active' => true,
        ]];
        $storeNames = array_column($locationsList, 'name');

        $tenant = Tenant::create([
            'name'          => $store['name'],
            'slug'          => $slug,
            'mode'          => $store['business_mode'],
            'business_mode' => $store['business_mode'],
            'plan'          => 'pro',
            'currency'      => $store['currency'],
            'locale'        => $locale,
            'timezone'      => $store['timezone'],
            'phone'         => $store['phone'] ?? null,
            'email'         => $store['email'] ?? null,
            'address'       => $store['address'] ?? null,
            'settings'      => [
                'receipt_header'  => $store['name'],
                'languages'       => $store['language'] === 'ar' ? ['ar', 'fr'] : ['fr', 'ar'],
                'current_store'   => $storeKey,
                'stores'          => $locationsList,
                'company_profile' => [
                    'store_code' => strtoupper(substr($slug, 0, 8)), 'store_name' => $store['name'],
                    'business_mode' => $store['business_mode'], 'email' => $store['email'] ?? null,
                    'phone' => $store['phone'] ?? null, 'country' => 'Maroc',
                    'timezone' => $store['timezone'], 'date_format' => 'dd/mm/yyyy', 'time_format' => '24',
                    'currency' => $store['currency'], 'currency_placement' => 'Right',
                    'decimals' => 2, 'qty_decimals' => 2, 'language_id' => $store['language'],
                ],
                'catalog' => [
                    'label' => $businessMode['catalog_label'], 'primary_item' => $businessMode['primary_item'],
                    'search_placeholder' => $businessMode['search_placeholder'], 'type_labels' => $businessMode['type_labels'],
                ],
                'number_format' => ['currency_placement' => 'Right', 'decimals' => 2, 'qty_decimals' => 2, 'round_off' => false],
                'payment_types' => [
                    ['key' => 'cash', 'name' => 'Espèces', 'code' => 'cash', 'description' => 'Paiement comptoir', 'is_active' => true],
                    ['key' => 'card', 'name' => 'Carte', 'code' => 'card', 'description' => 'Paiement TPE', 'is_active' => true],
                    ['key' => 'transfer', 'name' => 'Virement', 'code' => 'transfer', 'description' => 'Paiement bancaire', 'is_active' => true],
                    ['key' => 'advance', 'name' => 'Avance client', 'code' => 'advance', 'description' => 'Solde client', 'is_active' => true],
                ],
                'features'  => ['virtual_devices' => true],
                'inventory' => ['costing_method' => $store['costing_method'] ?? 'lifo'],
                'store'     => ['business_activity' => ItemTypes::activityFromBusinessMode($store['business_mode'])],
                'pos'       => [
                    'editable_price' => true, 'allow_sale_edit' => true, 'allow_oversell' => false,
                    'confirm_cart_line_removal' => true, 'show_out_of_stock' => false,
                    'show_cash_drawer_navbar' => true, 'require_adjustment_reason' => true,
                    'update_cost_on_purchase' => true, 'low_stock_dashboard' => true,
                    'auto_reorder_draft' => false, 'inventory_cycle_days' => 30, 'default_min_stock_threshold' => 3,
                ],
            ],
        ]);

        return [$tenant, $slug, $storeNames];
    }

    private function defaultRoles(): array
    {
        return [
            ['Owner',     'owner',    ['*'],                                                                                                                                     true],
            ['Manager',   'manager',  ['dashboard.view', 'items.*', 'sales.*', 'online_orders.*', 'purchases.*', 'contacts.*', 'finance.*', 'reports.view', 'settings.theme'], false],
            ['Caissier',  'cashier',  ['dashboard.view', 'sales.view', 'sales.create', 'online_orders.view', 'online_orders.create', 'contacts.create', 'items.view'],         false],
            ['Stockiste', 'stockist', ['dashboard.view', 'items.*', 'stock.adjust', 'stock.transfer', 'purchases.view', 'purchases.receive'],                                   false],
        ];
    }

    private function seedDefaults(Tenant $tenant, string $storeKey, ?string $mode = null): void
    {
        $unitDefaults = match (BusinessMode::normalize($mode)) {
            'restaurant', 'coffee' => [
                ['Pièce', 'Unité standard'], ['Portion', 'Portion servie'],
                ['Menu', 'Formule ou menu composé'], ['Service', 'Prestation non physique'],
            ],
            default => [
                ['Pièce', 'Unité standard'], ['Pack', 'Lot composé'],
                ['Boîte', 'Conditionnement'], ['Service', 'Prestation non physique'],
            ],
        };

        foreach ($unitDefaults as [$n, $d]) {
            Unit::firstOrCreate(['tenant_id' => $tenant->id, 'name' => $n], ['description' => $d]);
        }
        foreach ([['Sans TVA', 0, true], ['TVA 20%', 20, true], ['TVA 7%', 7, false]] as [$n, $rate, $active]) {
            Tax::firstOrCreate(['tenant_id' => $tenant->id, 'name' => $n], ['rate' => $rate, 'is_active' => $active]);
        }
        FinancialAccount::firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'Caisse principale'],
            ['store_key' => $storeKey, 'type' => 'cash', 'holder_name' => $tenant->name, 'opening_balance' => 0, 'current_balance' => 0, 'description' => 'Compte caisse initial.', 'is_active' => true],
        );
    }

    private function defaultCategoriesForMode(?string $mode): array
    {
        return match (BusinessMode::normalize($mode)) {
            'restaurant' => ['Entrées', 'Plats', 'Desserts', 'Boissons'],
            'coffee'     => ['Boissons chaudes', 'Boissons fraîches', 'Snacks', 'Pâtisseries'],
            'pharmacy'   => ['Médicaments', 'Parapharmacie', 'Hygiène', 'Divers'],
            default      => ['Général'],
        };
    }

    private function uniqueTenantSlug(string $base): string
    {
        $slug = $base;
        $suffix = 2;
        while (Tenant::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
