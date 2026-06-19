<?php

namespace Tests\Feature;

use App\Models\CashRegisterMovement;
use App\Models\CashRegisterSession;
use App\Models\FinancialAccount;
use App\Models\Item;
use App\Models\Sale;
use App\Models\Tenant;
use App\Services\Documents\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashRegisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_cash_register_can_open_move_and_close_drawer(): void
    {
        $this->seed();
        $tenant = Tenant::firstOrFail();
        $account = FinancialAccount::create([
            'tenant_id' => $tenant->id,
            'store_key' => 'magasin-principal',
            'name' => 'Tiroir test',
            'type' => 'cash',
            'opening_balance' => 0,
            'current_balance' => 0,
            'is_active' => true,
        ]);

        $this->get(route('module', 'cash-register'))
            ->assertOk()
            ->assertSee('Suivi du tiroir espèces')
            ->assertSee('Ouvrir le tiroir');

        $this->post(route('cash-register.open'), [
            'financial_account_id' => $account->id,
            'store_key' => 'magasin-principal',
            'opening_amount' => 100,
            'note' => 'Ouverture test',
        ])->assertRedirect(route('module', 'cash-register'));

        $session = CashRegisterSession::firstOrFail();
        $this->assertSame('open', $session->status);
        $this->assertSame(100.0, (float) $session->expected_cash_amount);

        $this->post(route('cash-register.movements.store'), [
            'type' => 'cash_in',
            'amount' => 20,
            'reference' => 'APP',
            'note' => 'Appoint monnaie',
        ])->assertRedirect(route('module', 'cash-register'));

        $this->post(route('cash-register.movements.store'), [
            'type' => 'cash_out',
            'amount' => 5,
            'reference' => 'RET',
            'note' => 'Retrait test',
        ])->assertRedirect(route('module', 'cash-register'));

        $this->assertSame(115.0, (float) $session->refresh()->expected_cash_amount);

        $this->post(route('cash-register.close', $session), [
            'counted_cash_amount' => 114,
            'closing_note' => 'Il manque 1 DH',
        ])->assertRedirect(route('module', 'cash-register'));

        $this->assertSame('closed', $session->refresh()->status);
        $this->assertSame(-1.0, (float) $session->difference_amount);
        $this->assertSame(4, CashRegisterMovement::where('tenant_id', $tenant->id)->count());
    }

    public function test_pos_cash_sale_is_recorded_in_open_cash_register(): void
    {
        $this->seed();
        $tenant = Tenant::firstOrFail();
        $item = Item::where('type', '!=', 'service')->where('stock_quantity', '>', 0)->firstOrFail();
        $price = round((float) $item->sale_price, 2);

        $this->post(route('cash-register.open'), [
            'store_key' => 'magasin-principal',
            'opening_amount' => 50,
        ])->assertRedirect(route('module', 'cash-register'));

        $session = CashRegisterSession::firstOrFail();

        $this->post(route('pos.store'), [
            'cart' => json_encode([
                ['id' => $item->id, 'quantity' => 1],
            ]),
            'cash_amount' => $price + 100,
        ])->assertRedirect();

        $sale = Sale::orderByDesc('id')->firstOrFail();
        $movement = CashRegisterMovement::where('type', 'sale_cash')->firstOrFail();

        $this->assertSame($sale->id, $movement->sale_id);
        $this->assertSame($session->id, $movement->cash_register_session_id);
        $this->assertSame($price, (float) $movement->amount);
        $this->assertSame(50.0 + $price, (float) $session->refresh()->expected_cash_amount);
        $this->assertSame($session->id, $sale->metadata['cash_register']['session_id']);
        $this->assertSame($movement->id, $sale->metadata['cash_register']['movement_id']);
    }

    public function test_cash_invoice_payment_is_recorded_in_open_cash_register(): void
    {
        $this->seed();
        $tenant = Tenant::firstOrFail();

        $this->post(route('cash-register.open'), [
            'store_key' => 'magasin-principal',
            'opening_amount' => 20,
        ])->assertRedirect(route('module', 'cash-register'));

        $session = CashRegisterSession::firstOrFail();
        $invoice = app(InvoiceService::class)->create($tenant, [
            'status' => 'sent',
            'issue_date' => '2026-06-15',
            'due_date' => '2026-06-30',
            'lines' => [['name' => 'Service abonnement', 'quantity' => 1, 'unit_price' => '80.00']],
        ]);

        $payment = app(InvoiceService::class)->recordPayment($invoice, [
            'amount' => '80.00',
            'method' => 'cash',
            'idempotency_key' => 'invoice-cash-1',
        ]);
        app(InvoiceService::class)->recordPayment($invoice->fresh(), [
            'amount' => '80.00',
            'method' => 'cash',
            'idempotency_key' => 'invoice-cash-1',
        ]);

        $movement = CashRegisterMovement::where('type', 'invoice_cash')->firstOrFail();
        $this->assertSame($session->id, $movement->cash_register_session_id);
        $this->assertSame(80.0, (float) $movement->amount);
        $this->assertSame('cash', $movement->payment_method);
        $this->assertSame($invoice->number, $movement->reference);
        $this->assertSame($invoice->id, $movement->metadata['invoice_id']);
        $this->assertSame($payment->id, $movement->metadata['invoice_payment_id']);
        $this->assertSame($movement->id, $payment->refresh()->metadata['cash_register']['movement_id']);
        $this->assertSame(100.0, (float) $session->refresh()->expected_cash_amount);
        $this->assertSame(1, CashRegisterMovement::where('type', 'invoice_cash')->count());
    }

    public function test_cash_drawer_navbar_indicator_can_be_hidden_from_settings(): void
    {
        $this->seed();

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('topbar-cashdrawer', false);

        $this->post(route('settings.pos.update'), [
            'editable_price' => '1',
            'allow_sale_edit' => '1',
            'allow_oversell' => '0',
            'show_out_of_stock' => '0',
            'show_cash_drawer_navbar' => '0',
            'require_adjustment_reason' => '1',
            'update_cost_on_purchase' => '1',
            'low_stock_dashboard' => '1',
            'auto_reorder_draft' => '0',
            'inventory_cycle_days' => 30,
            'default_min_stock_threshold' => 3,
        ])->assertRedirect();

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('topbar-cashdrawer', false);
    }
}
