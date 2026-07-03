<?php

namespace Database\Seeders;

use App\Models\FinancialAccount;
use App\Models\Role;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ClientTenantSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production') && (! env('CLIENT_OWNER_EMAIL') || ! env('CLIENT_OWNER_PASSWORD'))) {
            throw new \RuntimeException('CLIENT_OWNER_EMAIL and CLIENT_OWNER_PASSWORD are required before seeding production.');
        }

        DB::transaction(function (): void {
            $name = (string) env('CLIENT_STORE_NAME', env('APP_NAME', 'LibrairePro Client'));
            $slug = (string) env('APP_TENANT_SLUG', Str::slug($name));
            $storeKey = (string) env('CLIENT_STORE_KEY', $slug ?: 'magasin-principal');
            $timezone = (string) env('CLIENT_TIMEZONE', 'Africa/Casablanca');
            $currency = strtoupper((string) env('CLIENT_CURRENCY', 'MAD'));
            $phone = env('CLIENT_PHONE');
            $email = env('CLIENT_EMAIL');
            $address = env('CLIENT_ADDRESS');

            $store = [
                'key' => $storeKey,
                'name' => $name,
                'type' => 'store',
                'address' => $address,
                'phone' => $phone,
                'manager' => '',
                'is_active' => true,
            ];

            $companyProfile = [
                'store_code' => (string) env('CLIENT_STORE_CODE', Str::upper(Str::substr($slug, 0, 8))),
                'store_name' => $name,
                'business_mode' => (string) env('CLIENT_BUSINESS_MODE', 'bookstore'),
                'mobile' => $phone,
                'email' => $email,
                'phone' => $phone,
                'country' => (string) env('CLIENT_COUNTRY', 'Maroc'),
                'city' => (string) env('CLIENT_CITY', ''),
                'address' => $address,
                'timezone' => $timezone,
                'date_format' => 'dd/mm/yyyy',
                'time_format' => '24',
                'currency' => $currency,
                'currency_placement' => 'Right',
                'decimals' => 2,
                'qty_decimals' => 2,
                'language_id' => (string) env('CLIENT_LANGUAGE', 'fr'),
            ];

            $tenant = Tenant::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'mode' => $companyProfile['business_mode'],
                    'plan' => (string) env('CLIENT_PLAN', 'pro'),
                    'currency' => $currency,
                    'locale' => $companyProfile['language_id'] === 'ar' ? 'ar_MA' : 'fr_MA',
                    'timezone' => $timezone,
                    'phone' => $phone,
                    'email' => $email,
                    'ice' => env('CLIENT_ICE'),
                    'address' => $address,
                    'settings' => [
                        'receipt_header' => $name,
                        'languages' => ['fr', 'ar'],
                        'current_store' => $storeKey,
                        'stores' => [$store],
                        'company_profile' => $companyProfile,
                        'number_format' => [
                            'currency_placement' => 'Right',
                            'decimals' => 2,
                            'qty_decimals' => 2,
                            'round_off' => false,
                        ],
                        'payment_types' => [
                            ['key' => 'cash', 'name' => 'Espèces', 'code' => 'cash', 'description' => 'Paiement comptoir', 'is_active' => true],
                            ['key' => 'card', 'name' => 'Carte', 'code' => 'card', 'description' => 'Paiement TPE', 'is_active' => true],
                            ['key' => 'transfer', 'name' => 'Virement', 'code' => 'transfer', 'description' => 'Paiement bancaire', 'is_active' => true],
                            ['key' => 'advance', 'name' => 'Avance client', 'code' => 'advance', 'description' => 'Solde client', 'is_active' => true],
                        ],
                        'pos' => [
                            'editable_price' => true,
                            'allow_sale_edit' => true,
                            'allow_oversell' => false,
                            'confirm_cart_line_removal' => true,
                            'show_out_of_stock' => false,
                            'show_cash_drawer_navbar' => true,
                            'require_adjustment_reason' => true,
                            'update_cost_on_purchase' => true,
                            'low_stock_dashboard' => true,
                            'auto_reorder_draft' => false,
                            'inventory_cycle_days' => 30,
                            'default_min_stock_threshold' => 3,
                        ],
                    ],
                ],
            );

            $owner = User::updateOrCreate(
                ['email' => (string) env('CLIENT_OWNER_EMAIL', 'owner@'.$slug.'.test')],
                [
                    'current_tenant_id' => $tenant->id,
                    'name' => (string) env('CLIENT_OWNER_NAME', 'Owner'),
                    'phone' => env('CLIENT_OWNER_PHONE'),
                    'password' => Hash::make((string) env('CLIENT_OWNER_PASSWORD', 'ChangeMe!2026')),
                    'avatar_color' => '#3157D5',
                    'is_active' => true,
                ],
            );

            // is_system = true on the owner role makes it immutable (cannot be deleted or edited).
            $roles = [
                ['Owner', 'owner', ['*'], true],
                ['Manager', 'manager', ['dashboard.view', 'items.*', 'sales.*', 'online_orders.*', 'purchases.*', 'contacts.*', 'finance.*', 'reports.view', 'settings.theme'], false],
                ['Caissier', 'cashier', ['dashboard.view', 'sales.view', 'sales.create', 'online_orders.view', 'online_orders.create', 'contacts.create', 'items.view'], false],
                ['Stockiste', 'stockist', ['dashboard.view', 'items.*', 'stock.adjust', 'stock.transfer', 'purchases.view', 'purchases.receive'], false],
            ];

            foreach ($roles as [$roleName, $roleKey, $permissions, $isSystem]) {
                Role::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'key' => $roleKey],
                    ['name' => $roleName, 'permissions' => $permissions, 'is_system' => $isSystem],
                );
            }

            $tenant->users()->syncWithoutDetaching([
                $owner->id => ['role' => 'owner', 'store_access' => json_encode([$store['name']])],
            ]);

            foreach ([['Pièce', 'Unité standard'], ['Pack', 'Lot composé'], ['Boîte', 'Conditionnement'], ['Service', 'Prestation non physique']] as [$unitName, $description]) {
                Unit::firstOrCreate(['tenant_id' => $tenant->id, 'name' => $unitName], ['description' => $description]);
            }

            foreach ([['Sans TVA', 0, true], ['TVA 20%', 20, true], ['TVA 7%', 7, false]] as [$taxName, $rate, $active]) {
                Tax::firstOrCreate(['tenant_id' => $tenant->id, 'name' => $taxName], ['rate' => $rate, 'is_active' => $active]);
            }

            FinancialAccount::firstOrCreate(
                ['tenant_id' => $tenant->id, 'name' => 'Caisse principale'],
                [
                    'store_key' => $storeKey,
                    'type' => 'cash',
                    'holder_name' => $name,
                    'opening_balance' => 0,
                    'current_balance' => 0,
                    'description' => 'Compte caisse initial.',
                    'is_active' => true,
                ],
            );
        });
    }
}
