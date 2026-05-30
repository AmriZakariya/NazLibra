<?php

namespace Tests\Feature;

use App\Models\AccountTransaction;
use App\Models\FinancialAccount;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountsSectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_financial_account_can_be_created_and_listed(): void
    {
        $this->seed();
        $tenant = Tenant::firstOrFail();

        $this->post(route('accounts.store'), [
            'name' => 'Caisse principale',
            'type' => 'cash',
            'store_key' => 'magasin-principal',
            'holder_name' => 'Librairie Atlas',
            'opening_balance' => 500,
            'is_active' => '1',
        ])->assertRedirect(route('module', ['module' => 'finance', 'section' => 'accounts']));

        $account = FinancialAccount::where('tenant_id', $tenant->id)->where('name', 'Caisse principale')->firstOrFail();

        $this->assertSame(500.0, (float) $account->current_balance);
        $this->assertDatabaseHas('account_transactions', [
            'tenant_id' => $tenant->id,
            'financial_account_id' => $account->id,
            'type' => 'opening',
            'direction' => 'in',
        ]);

        $this->get(route('module', ['module' => 'finance', 'section' => 'accounts']))
            ->assertOk()
            ->assertSee('Caisse principale');
    }

    public function test_deposit_and_transfer_update_account_balances(): void
    {
        $this->seed();
        $tenant = Tenant::firstOrFail();

        $cash = FinancialAccount::create([
            'tenant_id' => $tenant->id,
            'store_key' => 'magasin-principal',
            'name' => 'Caisse',
            'type' => 'cash',
            'opening_balance' => 100,
            'current_balance' => 100,
        ]);
        $bank = FinancialAccount::create([
            'tenant_id' => $tenant->id,
            'store_key' => 'magasin-principal',
            'name' => 'Banque',
            'type' => 'bank',
            'opening_balance' => 1000,
            'current_balance' => 1000,
        ]);

        $this->post(route('accounts.deposits.store'), [
            'financial_account_id' => $cash->id,
            'amount' => 250,
            'payment_method' => 'cash',
            'reference' => 'DEP-TEST',
        ])->assertRedirect(route('module', ['module' => 'finance', 'section' => 'deposits']));

        $this->assertSame(350.0, (float) $cash->refresh()->current_balance);

        $this->post(route('accounts.transfers.store'), [
            'from_account_id' => $cash->id,
            'to_account_id' => $bank->id,
            'amount' => 150,
            'reference' => 'TR-TEST',
        ])->assertRedirect(route('module', ['module' => 'finance', 'section' => 'transfers']));

        $this->assertSame(200.0, (float) $cash->refresh()->current_balance);
        $this->assertSame(1150.0, (float) $bank->refresh()->current_balance);
        $this->assertSame(3, AccountTransaction::where('tenant_id', $tenant->id)->count());
    }
}
