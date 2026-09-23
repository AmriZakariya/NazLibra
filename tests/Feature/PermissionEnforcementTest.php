<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a role actually stops someone doing.
 *
 * The coverage test proves each route names a permission; these prove the
 * middleware then refuses without it and allows with it. The routes chosen
 * are the ones that were open to every user of the tenant regardless of role.
 */
class PermissionEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->tenant = Tenant::firstOrFail();
    }

    /** Signs in as somebody holding exactly [$permissions]. */
    private function actingAsRole(array $permissions): User
    {
        Role::updateOrCreate(
            ['tenant_id' => $this->tenant->id, 'key' => 'limited'],
            ['name' => 'Limité', 'permissions' => $permissions, 'is_system' => false],
        );

        $user = User::create([
            'name' => 'Employé limité',
            'email' => 'limite-'.uniqid().'@example.test',
            'password' => bcrypt('password-test'),
        ]);
        $this->tenant->users()->attach($user->id, ['role' => 'limited']);

        $this->actingAs($user);

        return $user;
    }

    public function test_the_cash_register_cannot_be_opened_without_the_permission(): void
    {
        // Was open to anyone signed in: a cashier could open and close the
        // register, and record cash movements, whatever their role said.
        $this->actingAsRole(['sales.view']);

        $this->post(route('cash-register.open'), ['opening_float' => 100])->assertForbidden();
    }

    public function test_the_cash_register_opens_with_the_permission(): void
    {
        $this->actingAsRole(['sales.view', 'cash_register.open']);

        $this->post(route('cash-register.open'), ['opening_float' => 100])
            ->assertStatus(302);
    }

    public function test_a_stocktake_cannot_be_started_without_the_permission(): void
    {
        // A stocktake rewrites stock directly — the most powerful thing in
        // the inventory module, and it was ungated.
        $this->actingAsRole(['items.view', 'stock.adjust']);

        $this->post(route('catalog.stocktakes.store'), ['items' => []])->assertForbidden();
    }

    public function test_a_transfer_cannot_be_cancelled_with_only_the_right_to_create_one(): void
    {
        // Creating and reversing are different rights: the reversal moves
        // stock back and closes the paperwork.
        // A real row, so a 404 from route-model binding cannot be mistaken
        // for the refusal this test is looking for.
        $transfer = \App\Models\StockTransfer::create([
            'tenant_id' => $this->tenant->id,
            'number' => 'TRS00001',
            'status' => 'completed',
            'total_quantity' => 1,
            'lines' => [],
            'transferred_at' => now(),
        ]);
        $this->actingAsRole(['stock.transfer']);

        $this->post(route('catalog.stock-transfers.cancel', $transfer), ['reason' => 'test'])
            ->assertForbidden();
    }

    public function test_another_users_pin_cannot_be_set_without_user_administration(): void
    {
        // Setting someone's PIN is taking over their identity at the till.
        $victim = User::create([
            'name' => 'Caissier',
            'email' => 'caissier-'.uniqid().'@example.test',
            'password' => bcrypt('password-test'),
        ]);
        $this->tenant->users()->attach($victim->id, ['role' => 'limited']);
        $this->actingAsRole(['sales.create']);

        $this->put(route('settings.users.pin', $victim), ['pin' => '1234'])->assertForbidden();
    }

    public function test_raising_an_invoice_from_a_sale_needs_the_right_to_invoice(): void
    {
        // It was gated by `sales.view`, so anyone who could merely READ the
        // sales list could issue a numbered invoice off the back of one.
        $sale = \App\Models\Sale::where('tenant_id', $this->tenant->id)->first();
        if (! $sale) {
            $this->markTestSkipped('the seeder has no sale to invoice');
        }
        $this->actingAsRole(['sales.view']);

        $this->post(route('sales.invoice.store', $sale))->assertForbidden();
    }

    public function test_demo_maintenance_cannot_be_run_by_a_cashier(): void
    {
        $this->actingAsRole(['sales.create']);

        $this->post(route('settings.demo-maintenance.run', 'clear_sales'))->assertForbidden();
    }

    public function test_purchase_payments_need_their_own_permission(): void
    {
        $this->actingAsRole(['purchases.view', 'purchases.create']);

        $this->post(route('purchases.payments.store'), [])->assertForbidden();
    }

    public function test_terminals_cannot_be_created_by_a_cashier(): void
    {
        $this->actingAsRole(['sales.create']);

        $this->post(route('devices.store'), ['name' => 'Caisse pirate'])->assertForbidden();
    }

    public function test_the_theme_permission_no_longer_opens_the_whole_settings_area(): void
    {
        // One checkbox labelled "thème" used to also grant the POS settings,
        // document templates, messaging and every reference list.
        $this->actingAsRole(['settings.company']);

        $this->post(route('settings.pos.update'), [])->assertForbidden();
        $this->post(route('settings.documents.update'), [])->assertForbidden();
        $this->post(route('settings.payment-types.store'), ['name' => 'Chèque'])->assertForbidden();
    }

    public function test_a_group_wildcard_grants_the_whole_section(): void
    {
        // How roles are written in practice.
        $this->actingAsRole(['cash_register.*']);

        $this->post(route('cash-register.open'), ['opening_float' => 100])->assertStatus(302);
    }

    public function test_the_owner_keeps_everything(): void
    {
        $owner = User::whereHas('tenants', fn ($q) => $q->whereKey($this->tenant->id))->firstOrFail();
        $this->actingAs($owner);

        $this->post(route('cash-register.open'), ['opening_float' => 100])->assertStatus(302);
    }

    public function test_a_user_with_no_role_is_refused(): void
    {
        $user = User::create([
            'name' => 'Sans rôle',
            'email' => 'sansrole-'.uniqid().'@example.test',
            'password' => bcrypt('password-test'),
        ]);
        $this->tenant->users()->attach($user->id, ['role' => '']);
        $this->actingAs($user);

        $this->post(route('cash-register.open'), ['opening_float' => 100])->assertForbidden();
    }
}
