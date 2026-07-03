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

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $companyProfile = [
                'store_code' => 'ST0002',
                'store_name' => 'Oubra Store',
                'mobile' => '0609240487',
                'email' => 'oubrastore@gmail.com',
                'phone' => '0520689024',
                'cnss' => '',
                'rc' => '',
                'gst_no' => '',
                'vat_no' => '',
                'pan_no' => '',
                'store_website' => '',
                'show_signature' => false,
                'signature' => '',
                'bank_details' => '',
                'country' => 'Morocco',
                'state' => '',
                'city' => 'Casablanca',
                'postcode' => '20220',
                'address' => '75 BD ANFA ANGLE RUE CLOS DE PROVENCE 8EME ETAGE APPT B103 CASABLANCA',
                'store_logo' => '',
                'timezone' => 'Africa/Casablanca',
                'date_format' => 'dd-mm-yyyy',
                'time_format' => '12',
                'currency' => 'MAD',
                'currency_placement' => 'Right',
                'decimals' => 2,
                'qty_decimals' => 2,
                'language_id' => '6',
                'round_off' => false,
                'default_account_id' => '1',
                'sales_discount' => 0,
                'sales_invoice_format_id' => '3',
                'pos_invoice_format_id' => '1',
                'mrp_column' => false,
                'change_return' => true,
                'previous_balance_bit' => true,
                'number_to_words' => 'Default',
                'sales_invoice_footer_text' => '',
                't_and_c_status' => true,
                't_and_c_status_pos' => true,
                'invoice_terms' => '',
                'toggle_header_footer' => false,
                'category_init' => 'CT',
                'item_init' => 'IT02',
                'supplier_init' => 'FR',
                'purchase_init' => 'PU',
                'purchase_return_init' => 'PR',
                'customer_init' => 'CL',
                'sales_init' => 'BL',
                'sales_return_init' => 'RV',
                'expense_init' => 'EX',
                'accounts_init' => 'AC',
                'quotation_init' => 'DV',
                'money_transfer_init' => 'MT',
                'sales_payment_init' => 'SP',
                'sales_return_payment_init' => 'SRP',
                'purchase_payment_init' => 'PP',
                'purchase_return_payment_init' => 'PRP',
                'expense_payment_init' => 'XP',
                'cust_advance_init' => 'ADV',
            ];

            $stores = [
                [
                    'key' => 'oubra-store',
                    'name' => 'Oubra Store',
                    'type' => 'store',
                    'address' => $companyProfile['address'],
                    'phone' => $companyProfile['phone'],
                    'manager' => 'Administration',
                    'is_active' => true,
                ],
            ];

            $tenant = Tenant::create([
                'name' => 'Oubra Store',
                'slug' => 'oubra-store',
                'mode' => 'hybrid',
                'plan' => 'pro',
                'currency' => 'MAD',
                'locale' => 'fr_MA',
                'timezone' => 'Africa/Casablanca',
                'phone' => $companyProfile['phone'],
                'email' => $companyProfile['email'],
                'ice' => null,
                'address' => $companyProfile['address'],
                'settings' => [
                    'receipt_header' => 'Oubra Store - Casablanca',
                    'tax_rate' => 0.2,
                    'languages' => ['fr', 'ar'],
                    'pos_offline_cache_days' => 14,
                    'current_store' => 'oubra-store',
                    'stores' => $stores,
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
                    'countries' => [
                        ['key' => 'morocco', 'name' => 'Morocco', 'code' => 'MA', 'description' => '', 'is_active' => true],
                    ],
                    'states' => [
                        ['key' => 'casablanca-settat', 'name' => 'Casablanca-Settat', 'code' => 'CS', 'country' => 'Morocco', 'description' => '', 'is_active' => true],
                    ],
                    'theme_preset' => 'default',
                    'theme' => [
                        'primary' => '#3157D5',
                        'accent' => '#0F9F8A',
                        'success' => '#16A34A',
                        'warning' => '#D97706',
                        'danger' => '#E11D48',
                        'info' => '#0284C7',
                        'background' => '#F4F7FB',
                        'surface_color' => '#FFFFFF',
                        'surface_muted' => '#EEF3F8',
                        'text' => '#101828',
                        'muted' => '#64748B',
                        'border' => '#D7DEE9',
                        'font_scale' => '1',
                        'density' => 'comfortable',
                        'radius' => '12',
                    ],
                    'pos' => [
                        'editable_price' => true,
                        'allow_sale_edit' => true,
                        'allow_oversell' => false,
                        'confirm_cart_line_removal' => true,
                        'show_out_of_stock' => false,
                    ],
                ],
            ]);

            $owner = User::create([
                'current_tenant_id' => $tenant->id,
                'name' => 'Oubra Admin',
                'email' => 'admin@oubra.test',
                'phone' => $companyProfile['mobile'],
                'password' => Hash::make('password'),
                'avatar_color' => '#3157D5',
                'is_active' => true,
            ]);

            $cashier = User::create([
                'current_tenant_id' => $tenant->id,
                'name' => 'Caisse Oubra',
                'email' => 'caisse@oubra.test',
                'phone' => $companyProfile['phone'],
                'password' => Hash::make('password'),
                'avatar_color' => '#0F9F8A',
                'is_active' => true,
            ]);

            $tenant->users()->sync([
                $owner->id => ['role' => 'owner', 'store_access' => json_encode(['Oubra Store'])],
                $cashier->id => ['role' => 'cashier', 'store_access' => json_encode(['Oubra Store'])],
            ]);

            foreach ([
                ['Owner', 'owner', ['*']],
                ['Manager', 'manager', ['dashboard.view', 'items.*', 'sales.*', 'online_orders.*', 'purchases.*', 'contacts.*', 'finance.*', 'reports.view']],
                ['Caissier', 'cashier', ['sales.view', 'sales.create', 'online_orders.view', 'online_orders.create', 'contacts.create', 'items.view']],
                ['Bibliothécaire', 'librarian', ['loans.*', 'contacts.view', 'items.view']],
                ['Stockiste', 'stockist', ['items.*', 'stock.adjust', 'stock.transfer', 'purchases.view', 'purchases.receive']],
            ] as [$name, $key, $permissions]) {
                Role::create([
                    'tenant_id' => $tenant->id,
                    'name' => $name,
                    'key' => $key,
                    'permissions' => $permissions,
                ]);
            }

            foreach ([['Pièce', 'Unité de vente standard'], ['Pack', 'Lot composé'], ['Boîte', 'Conditionnement'], ['Service', 'Prestation non physique']] as [$name, $description]) {
                Unit::create(['tenant_id' => $tenant->id, 'name' => $name, 'description' => $description]);
            }

            foreach ([['Sans TVA', 0, true], ['TVA 20%', 20, true], ['TVA 7%', 7, false]] as [$name, $rate, $active]) {
                Tax::create(['tenant_id' => $tenant->id, 'name' => $name, 'rate' => $rate, 'is_active' => $active]);
            }

            FinancialAccount::create([
                'tenant_id' => $tenant->id,
                'store_key' => 'oubra-store',
                'name' => 'Caisse principale',
                'type' => 'cash',
                'holder_name' => 'Oubra Store',
                'opening_balance' => 0,
                'current_balance' => 0,
                'description' => 'Compte de caisse initial pour la démo client.',
                'is_active' => true,
            ]);

            DB::table('audit_logs')->insert([
                'tenant_id' => $tenant->id,
                'user_id' => $owner->id,
                'action' => 'demo.reset',
                'subject_type' => Tenant::class,
                'subject_id' => $tenant->id,
                'properties' => json_encode(['source' => 'oubra_store_profile']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }
}
