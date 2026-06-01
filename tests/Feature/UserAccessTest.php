<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_be_created_with_role_permissions_and_store_access(): void
    {
        $this->seed();
        $tenant = Tenant::firstOrFail();

        $this->post(route('settings.users.store'), [
            'name' => 'Responsable Stock',
            'email' => 'stock@example.test',
            'phone' => '+212600000000',
            'password' => 'password-test',
            'role' => 'stockist',
            'permissions' => ['items.view', 'stock.adjust'],
            'store_access' => ['Magasin principal', 'Dépôt'],
            'is_active' => '1',
        ])->assertRedirect(route('module', ['module' => 'settings', 'section' => 'users']));

        $user = User::where('email', 'stock@example.test')->firstOrFail();
        $pivot = $tenant->users()->whereKey($user->id)->firstOrFail()->pivot;

        $this->assertSame('stockist', $pivot->role);
        $this->assertSame(['items.view', 'stock.adjust'], json_decode($pivot->permissions, true));
        $this->assertSame(['Magasin principal', 'Dépôt'], json_decode($pivot->store_access, true));
    }

    public function test_role_can_be_created_with_permissions(): void
    {
        $this->seed();

        $this->post(route('settings.roles.store'), [
            'name' => 'Manager caisse',
            'key' => 'manager_caisse',
            'permissions' => ['sales.view', 'sales.create', 'sales.refund'],
        ])->assertRedirect(route('module', ['module' => 'settings', 'section' => 'roles']));

        $this->assertDatabaseHas('roles', [
            'key' => 'manager_caisse',
            'name' => 'Manager caisse',
        ]);
    }

    public function test_store_can_be_created_and_selected_as_current_store(): void
    {
        $this->seed();
        $tenant = Tenant::firstOrFail();

        $this->post(route('settings.stores.store'), [
            'name' => 'Boutique Maarif',
            'type' => 'branch',
            'phone' => '+212522000000',
            'manager' => 'Amina',
            'address' => 'Maarif, Casablanca',
            'is_active' => '1',
        ])->assertRedirect(route('module', ['module' => 'settings', 'section' => 'warehouses']));

        $tenant->refresh();
        $store = collect($tenant->settings['stores'])->firstWhere('name', 'Boutique Maarif');

        $this->assertNotNull($store);

        $this->post(route('settings.current-store.update'), [
            'current_store' => $store['key'],
        ])->assertRedirect();

        $tenant->refresh();
        $this->assertSame($store['key'], $tenant->settings['current_store']);
    }

    public function test_company_profile_settings_can_be_updated(): void
    {
        $this->seed();

        $this->get(route('module', ['module' => 'settings', 'section' => 'company']))
            ->assertOk()
            ->assertSee('Profil société / magasin principal')
            ->assertSee('Préfixes de numérotation');

        $this->post(route('settings.company.update'), [
            'store_code' => 'ATLAS-CASA',
            'store_name' => 'Librairie Atlas Centre',
            'mobile' => '+212 661 00 00 00',
            'email' => 'centre@atlas.test',
            'phone' => '+212 522 00 00 00',
            'cnss' => 'CNSS-123',
            'rc' => 'RC-456',
            'gst_no' => 'ICE-999',
            'vat_no' => 'TVA-20',
            'pan_no' => 'IF-333',
            'store_website' => 'https://atlas.test',
            'show_signature' => '1',
            'signature' => '/signatures/owner.png',
            'bank_details' => 'Banque test - RIB 123',
            'country' => 'Maroc',
            'state' => 'Casablanca-Settat',
            'city' => 'Casablanca',
            'postcode' => '20000',
            'address' => 'Avenue Mohammed V',
            'store_logo' => '/logos/atlas.png',
            'timezone' => 'Africa/Casablanca',
            'date_format' => 'dd/mm/yyyy',
            'time_format' => '24',
            'currency' => 'MAD',
            'currency_placement' => 'Right',
            'decimals' => '2',
            'qty_decimals' => '2',
            'language_id' => 'fr',
            'round_off' => '1',
            'default_account_id' => 'cash-main',
            'sales_discount' => '5',
            'sales_invoice_format_id' => '3',
            'pos_invoice_format_id' => '1',
            'mrp_column' => '1',
            'change_return' => '1',
            'previous_balance_bit' => '1',
            'number_to_words' => 'Default',
            'sales_invoice_footer_text' => 'Merci pour votre achat.',
            't_and_c_status' => '1',
            't_and_c_status_pos' => '1',
            'invoice_terms' => 'Articles non repris après 48h.',
            'toggle_header_footer' => '1',
            'category_init' => 'CAT',
            'item_init' => 'IT',
            'supplier_init' => 'SUP',
            'purchase_init' => 'PUR',
            'purchase_return_init' => 'PR',
            'customer_init' => 'CUS',
            'sales_init' => 'SAL',
            'sales_return_init' => 'SR',
            'expense_init' => 'EXP',
            'accounts_init' => 'ACC',
            'quotation_init' => 'QUO',
            'money_transfer_init' => 'MT',
            'sales_payment_init' => 'SP',
            'sales_return_payment_init' => 'SRP',
            'purchase_payment_init' => 'PP',
            'purchase_return_payment_init' => 'PRP',
            'expense_payment_init' => 'EP',
            'cust_advance_init' => 'ADV',
        ])->assertRedirect(route('module', ['module' => 'settings', 'section' => 'company']));

        $tenant = Tenant::firstOrFail()->fresh();

        $this->assertSame('Librairie Atlas Centre', $tenant->name);
        $this->assertSame('ICE-999', $tenant->ice);
        $this->assertSame('ATLAS-CASA', $tenant->settings['company_profile']['store_code']);
        $this->assertTrue($tenant->settings['company_profile']['show_signature']);
        $this->assertSame('CAT', $tenant->settings['company_profile']['category_init']);
        $this->assertSame(2, $tenant->settings['number_format']['decimals']);
    }
}
