<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\DeliveryOrder;
use App\Models\Item;
use App\Models\PosTicket;
use App\Models\Sale;
use App\Models\SaleInvoice;
use App\Models\SalePayment;
use App\Models\SaleReturn;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosTest extends TestCase
{
    use RefreshDatabase;

    public function test_pos_page_renders_cashier_filters_and_suggestions(): void
    {
        $this->seed();

        $this->get(route('pos'))
            ->assertOk()
            ->assertSee('data-command-menu', false)
            ->assertSee('Trouver une section, une action, un paramètre...')
            ->assertSee('data-command-input', false)
            ->assertSee('pos caisse encaisser barcode', false)
            ->assertSee('stock correction inventaire', false)
            ->assertSee('stock inventaire rupture alerte', false)
            ->assertSee('data-command-kind="Sous-module"', false)
            ->assertSee('data-command-module="Stock"', false)
            ->assertSee('href="http://localhost/stock"', false)
            ->assertSee('href="http://localhost/stock?panel=stock-adjustment-add"', false)
            ->assertSee('item produit livre isbn', false)
            ->assertSee('Ouvrir')
            ->assertDontSee('app-top-search', false)
            ->assertDontSee('name="q"', false)
            ->assertSee('Suggestions caisse')
            ->assertSee('Toutes les catégories')
            ->assertSee('Toutes les marques / éditeurs')
            ->assertSee('Plus vendus')
            ->assertSee('pos-favorite-star', false)
            ->assertSee('type="button" aria-label="Basculer favori"', false)
            ->assertSee('role="button"', false)
            ->assertSee('Effacer')
            ->assertSee('Espèces')
            ->assertSee('50/50')
            ->assertSee('data-price-editable="1"', false)
            ->assertSee('data-allow-oversell="0"', false)
            ->assertDontSee('name="receipt_channel"', false);
    }

    public function test_pos_can_hold_and_resume_ticket(): void
    {
        $this->seed();

        $item = Item::where('type', '!=', 'service')->where('stock_quantity', '>', 0)->firstOrFail();

        $response = $this->post(route('pos.tickets.store'), [
            'cart' => json_encode([
                ['id' => $item->id, 'quantity' => 1],
            ]),
            'discount_amount' => 0,
        ]);

        $ticket = PosTicket::firstOrFail();
        $response->assertRedirect(route('pos'));
        $this->assertSame('held', $ticket->status);
        $this->assertStringStartsWith('ATT', $ticket->number);

        $this->get(route('pos', ['ticket' => $ticket->id]))
            ->assertOk()
            ->assertSee($ticket->number)
            ->assertSee('Tickets en attente');
    }

    public function test_pos_can_create_sale_with_client_mixed_payment_and_stock_decrement(): void
    {
        $this->seed();

        $item = Item::where('type', '!=', 'service')->where('stock_quantity', '>', 2)->firstOrFail();
        $client = Contact::where('kind', 'client')->where('advance_balance', '>', 0)->firstOrFail();
        $initialStock = $item->stock_quantity;
        $initialAdvance = (float) $client->advance_balance;
        $total = (float) $item->sale_price * 2;
        $advance = min(10, $initialAdvance, max(1, $total / 2));
        $saleCount = Sale::count();

        $response = $this->post(route('pos.store'), [
            'contact_id' => $client->id,
            'cart' => json_encode([
                ['id' => $item->id, 'quantity' => 2],
            ]),
            'discount_amount' => 0,
            'cash_amount' => $total - $advance,
            'advance_amount' => $advance,
            'card_amount' => 0,
            'transfer_amount' => 0,
            'receipt_channel' => 'print',
        ]);

        $this->assertSame($saleCount + 1, Sale::count());

        $sale = Sale::orderByDesc('id')->firstOrFail();
        $response->assertRedirect(route('pos', ['sale' => $sale->id]));
        $this->assertStringStartsWith('BL', $sale->number);
        $this->assertNull($sale->invoice);
        $this->assertArrayNotHasKey('invoice_number', $sale->metadata);
        $this->assertSame($client->id, $sale->contact_id);
        $this->assertSame('cash+advance', $sale->payment_method);
        $this->assertSame($total, (float) $sale->total_amount);
        $this->assertSame(2, $sale->items()->firstOrFail()->quantity);
        $this->assertSame($initialStock - 2, $item->fresh()->stock_quantity);
        $this->assertSame($initialAdvance - $advance, (float) $client->fresh()->advance_balance);
    }

    public function test_pos_rejects_insufficient_payment_without_creating_sale(): void
    {
        $this->seed();

        $item = Item::where('type', '!=', 'service')->where('stock_quantity', '>', 0)->firstOrFail();

        $response = $this->from(route('pos'))->post(route('pos.store'), [
            'cart' => json_encode([
                ['id' => $item->id, 'quantity' => 1],
            ]),
            'cash_amount' => 0,
            'card_amount' => 0,
            'transfer_amount' => 0,
            'advance_amount' => 0,
        ]);

        $response->assertRedirect(route('pos'));
        $response->assertSessionHasErrors('cart');
    }

    public function test_pos_accepts_editable_line_price_and_tracks_cash_register(): void
    {
        $this->seed();

        $item = Item::where('type', '!=', 'service')->where('stock_quantity', '>', 2)->firstOrFail();
        $customPrice = 80.0;
        $cashReceived = 200.0;

        $response = $this->post(route('pos.store'), [
            'cart' => json_encode([
                ['id' => $item->id, 'quantity' => 1, 'price' => $customPrice, 'note' => 'Prix comptoir'],
            ]),
            'discount_amount' => 0,
            'cash_amount' => $cashReceived,
            'card_amount' => 0,
            'transfer_amount' => 0,
            'advance_amount' => 0,
        ]);

        $sale = Sale::orderByDesc('id')->firstOrFail();
        $response->assertRedirect(route('pos', ['sale' => $sale->id]));
        $this->assertSame($customPrice, (float) $sale->total_amount);
        $this->assertSame($customPrice, (float) $sale->items()->firstOrFail()->unit_price);
        $this->assertSame($cashReceived, (float) $sale->metadata['cash_register']['cash_received']);
        $this->assertSame(120.0, (float) $sale->metadata['cash_register']['cash_change']);
        $this->assertSame($customPrice, (float) $sale->metadata['cash_register']['cash_drawer_in']);
        $this->assertTrue($sale->metadata['line_adjustments'][0]['price_overridden']);
        $this->assertSame('Prix comptoir', $sale->metadata['line_adjustments'][0]['note']);
    }

    public function test_pos_ignores_line_price_when_setting_is_disabled(): void
    {
        $this->seed();

        $tenant = Tenant::firstOrFail();
        $tenant->update(['settings' => array_merge($tenant->settings ?? [], ['pos' => ['editable_price' => false]])]);
        $item = Item::where('type', '!=', 'service')->where('stock_quantity', '>', 0)->firstOrFail();
        $catalogPrice = (float) $item->sale_price;

        $this->post(route('pos.store'), [
            'cart' => json_encode([
                ['id' => $item->id, 'quantity' => 1, 'price' => 1],
            ]),
            'discount_amount' => 0,
            'cash_amount' => $catalogPrice,
        ])->assertRedirect();

        $sale = Sale::orderByDesc('id')->firstOrFail();
        $this->assertSame($catalogPrice, (float) $sale->total_amount);
        $this->assertSame($catalogPrice, (float) $sale->items()->firstOrFail()->unit_price);
        $this->assertFalse($sale->metadata['line_adjustments'][0]['price_overridden']);
    }

    public function test_settings_can_toggle_pos_editable_price(): void
    {
        $this->seed();

        $this->post(route('settings.pos.update'), [
            'editable_price' => '0',
            'allow_oversell' => '1',
            'show_out_of_stock' => '1',
        ])->assertRedirect();

        $settings = Tenant::firstOrFail()->fresh()->settings;
        $this->assertFalse((bool) data_get($settings, 'pos.editable_price'));
        $this->assertTrue((bool) data_get($settings, 'pos.allow_oversell'));
        $this->assertTrue((bool) data_get($settings, 'pos.show_out_of_stock'));
    }

    public function test_settings_store_section_groups_pos_and_stock_settings(): void
    {
        $this->seed();

        $this->get(route('module', ['module' => 'settings', 'section' => 'store']))
            ->assertOk()
            ->assertSeeText('Caisse, stock & comportement comptoir')
            ->assertSee('Autoriser la vente hors stock')
            ->assertSee('Afficher les articles hors stock dans la caisse')
            ->assertSee('Préférences magasin');
    }

    public function test_pos_hides_out_of_stock_items_by_default(): void
    {
        $this->seed();

        $tenant = Tenant::firstOrFail();
        $item = Item::create([
            'tenant_id' => $tenant->id,
            'type' => 'book',
            'status' => 'out_of_stock',
            'is_enabled' => true,
            'checkout_visible' => true,
            'item_code' => 'RUPTURE-TEST',
            'title' => 'Roman rupture test caisse',
            'barcode' => 'RUPTURE-POS-001',
            'purchase_price' => 10,
            'sale_price' => 25,
            'stock_quantity' => 0,
            'min_stock_threshold' => 2,
        ]);

        $this->get(route('pos', ['q' => 'RUPTURE-POS-001']))
            ->assertOk()
            ->assertDontSee($item->title);
    }

    public function test_pos_can_display_out_of_stock_items_as_unsellable_when_setting_is_enabled(): void
    {
        $this->seed();

        $tenant = Tenant::firstOrFail();
        $settings = $tenant->settings ?? [];
        $settings['pos'] = array_merge($settings['pos'] ?? [], ['show_out_of_stock' => true, 'allow_oversell' => false]);
        $tenant->update(['settings' => $settings]);

        $item = Item::create([
            'tenant_id' => $tenant->id,
            'type' => 'book',
            'status' => 'out_of_stock',
            'is_enabled' => true,
            'checkout_visible' => true,
            'item_code' => 'RUPTURE-VISIBLE',
            'title' => 'Manuel visible rupture caisse',
            'barcode' => 'RUPTURE-POS-002',
            'purchase_price' => 12,
            'sale_price' => 30,
            'stock_quantity' => 0,
            'min_stock_threshold' => 2,
        ]);

        $this->get(route('pos', ['q' => 'RUPTURE-POS-002']))
            ->assertOk()
            ->assertSee($item->title)
            ->assertSee('Rupture')
            ->assertSee('data-sellable="0"', false)
            ->assertSee('Article non disponible')
            ->assertSee('stock_q=RUPTURE-POS-002', false);
    }

    public function test_pos_rejects_oversell_when_setting_is_disabled(): void
    {
        $this->seed();

        $item = Item::where('type', '!=', 'service')->where('stock_quantity', '>', 0)->firstOrFail();
        $quantity = $item->stock_quantity + 1;
        $total = (float) $item->sale_price * $quantity;

        $response = $this->from(route('pos'))->post(route('pos.store'), [
            'cart' => json_encode([
                ['id' => $item->id, 'quantity' => $quantity],
            ]),
            'discount_amount' => 0,
            'cash_amount' => $total,
        ]);

        $response->assertRedirect(route('pos'));
        $response->assertSessionHasErrors('cart');
    }

    public function test_pos_allows_oversell_when_setting_is_enabled(): void
    {
        $this->seed();

        $tenant = Tenant::firstOrFail();
        $tenant->update(['settings' => array_merge($tenant->settings ?? [], ['pos' => ['editable_price' => true, 'allow_oversell' => true]])]);
        $item = Item::where('type', '!=', 'service')->where('stock_quantity', '>', 0)->firstOrFail();
        $initialStock = $item->stock_quantity;
        $quantity = $initialStock + 2;
        $total = (float) $item->sale_price * $quantity;

        $this->post(route('pos.store'), [
            'cart' => json_encode([
                ['id' => $item->id, 'quantity' => $quantity],
            ]),
            'discount_amount' => 0,
            'cash_amount' => $total,
        ])->assertRedirect();

        $item->refresh();
        $this->assertSame($initialStock - $quantity, $item->stock_quantity);
        $this->assertSame('active', $item->status);
    }

    public function test_sales_module_uses_legacy_columns(): void
    {
        $this->seed();

        $this->get(route('module', 'sales'))
            ->assertOk()
            ->assertSee('N° facture')
            ->assertSee('Date de vente')
            ->assertSee("Date d'échéance", false)
            ->assertSee('Code de vente')
            ->assertSee('Numéro de référence')
            ->assertSee('Paiement payé')
            ->assertSee('Action')
            ->assertSee('Voir détail')
            ->assertSee('Facture')
            ->assertSee('Créer facture');
    }

    public function test_manual_sale_add_screen_renders(): void
    {
        $this->seed();

        $this->get(route('module', ['module' => 'sales', 'section' => 'add']))
            ->assertOk()
            ->assertSee('Ajouter une vente')
            ->assertSee('Articles de la vente')
            ->assertSee('Paiement')
            ->assertSee('Résumé vente')
            ->assertSee('data-manual-sale-form', false);
    }

    public function test_manual_sale_can_be_created_with_payment_and_stock_decrement(): void
    {
        $this->seed();

        $item = Item::where('type', '!=', 'service')->where('stock_quantity', '>', 3)->firstOrFail();
        $client = Contact::where('kind', 'client')->firstOrFail();
        $initialStock = $item->stock_quantity;
        $unitPrice = (float) $item->sale_price;

        $response = $this->post(route('sales.store'), [
            'contact_id' => $client->id,
            'sold_at' => now()->format('Y-m-d H:i:s'),
            'due_date' => now()->addDays(7)->toDateString(),
            'reference_number' => 'BC-TEST-001',
            'sale_status' => 'paid',
            'discount_amount' => 1,
            'other_charges' => 2,
            'cash_amount' => ($unitPrice * 2) + 1,
            'items' => [
                ['item_id' => $item->id, 'quantity' => 2, 'unit_price' => $unitPrice, 'discount_amount' => 1, 'tax_rate' => 20, 'description' => 'Test ligne'],
            ],
        ]);

        $sale = Sale::orderByDesc('id')->firstOrFail();
        $response->assertRedirect(route('module', ['module' => 'sales', 'section' => 'list', 'ticket' => $sale->id]));
        $this->assertStringStartsWith('BL', $sale->number);
        $this->assertNull($sale->invoice);
        $this->assertSame($client->id, $sale->contact_id);
        $this->assertSame('paid', $sale->status);
        $this->assertSame('manual_sale', $sale->metadata['source']);
        $this->assertSame('BC-TEST-001', $sale->metadata['reference_number']);
        $this->assertSame($initialStock - 2, $item->fresh()->stock_quantity);
        $this->assertSame(1, $sale->payments()->count());
    }

    public function test_sale_invoice_can_be_created_from_sales_list(): void
    {
        $this->seed();

        $sale = Sale::firstOrFail();
        $sale->update(['metadata' => []]);

        $response = $this->post(route('sales.invoice.store', $sale), [
            'due_date' => now()->addDays(15)->toDateString(),
            'invoice_note' => 'Facture test client',
        ]);

        $invoice = SaleInvoice::where('sale_id', $sale->id)->firstOrFail();

        $response->assertRedirect(route('module', ['module' => 'sales', 'section' => 'list', 'invoice' => $invoice->id]));
        $this->assertStringStartsWith('FAC', $invoice->number);
        $this->assertSame('Facture test client', $invoice->note);
        $this->assertSame((float) $sale->total_amount, (float) $invoice->total_amount);
    }

    public function test_sale_list_actions_can_update_and_cancel_sale(): void
    {
        $this->seed();

        $sale = Sale::firstOrFail();
        $client = Contact::where('kind', 'client')->firstOrFail();

        $this->patch(route('sales.update', $sale), [
            'contact_id' => $client->id,
            'reference_number' => 'REF-ACTION-001',
            'due_date' => now()->addDays(7)->toDateString(),
            'note' => 'Note action',
            'status' => 'partial',
        ])->assertRedirect();

        $sale->refresh();
        $this->assertSame($client->id, $sale->contact_id);
        $this->assertSame('partial', $sale->status);
        $this->assertSame('REF-ACTION-001', $sale->metadata['reference_number']);

        $this->delete(route('sales.destroy', $sale))->assertRedirect();
        $sale->refresh();
        $this->assertSame('cancelled', $sale->status);
        $this->assertNotEmpty($sale->metadata['cancelled']['cancelled_at']);
    }

    public function test_sales_subsections_render_payments_returns_and_delivery(): void
    {
        $this->seed();

        $this->get(route('module', ['module' => 'sales', 'section' => 'payments']))
            ->assertOk()
            ->assertSee('Paiements enregistrés')
            ->assertSee('Vente à payer');

        $this->get(route('module', ['module' => 'sales', 'section' => 'returns']))
            ->assertOk()
            ->assertSee('N° retour')
            ->assertSee('Remboursement');

        $this->get(route('module', ['module' => 'sales', 'section' => 'delivery']))
            ->assertOk()
            ->assertSee('Créer depuis une vente')
            ->assertSee('N° livraison');
    }

    public function test_can_add_partial_sale_payment(): void
    {
        $this->seed();

        $sale = Sale::firstOrFail();
        $sale->update([
            'status' => 'partial',
            'metadata' => array_merge($sale->metadata ?? [], ['paid_amount' => 10]),
        ]);
        $amount = 25;

        $response = $this->post(route('sales.payments.store'), [
            'sale_id' => $sale->id,
            'method' => 'cash',
            'amount' => $amount,
            'reference' => 'TEST-PAY',
        ]);

        $response->assertRedirect();
        $this->assertSame(1, SalePayment::count());
        $this->assertSame(35.0, (float) $sale->fresh()->metadata['paid_amount']);
    }

    public function test_sale_can_be_refunded_and_restocked(): void
    {
        $this->seed();

        $sale = Sale::with('items')->whereHas('items', fn ($query) => $query->whereNotNull('item_id'))->firstOrFail();
        $line = $sale->items->firstWhere('item_id', '!=', null);
        $item = Item::findOrFail($line->item_id);
        $stock = $item->stock_quantity;

        $response = $this->post(route('sales.refund', $sale), [
            'refund_method' => 'cash',
            'refund_reason' => 'Test remboursement',
            'restock' => 1,
        ]);

        $response->assertRedirect();
        $this->assertSame('refunded', $sale->fresh()->status);
        $this->assertSame(1, SaleReturn::count());
        $this->assertSame($stock + $line->quantity, $item->fresh()->stock_quantity);
        $this->assertSame('cash', $sale->fresh()->metadata['refund']['method']);
    }

    public function test_can_create_and_update_delivery_order(): void
    {
        $this->seed();

        $sale = Sale::firstOrFail();

        $response = $this->post(route('sales.deliveries.store'), [
            'sale_id' => $sale->id,
            'assigned_to' => 'Livreur test',
            'delivery_address' => 'Casablanca',
        ]);

        $delivery = DeliveryOrder::firstOrFail();
        $response->assertRedirect();
        $this->assertStringStartsWith('LIV', $delivery->number);
        $this->assertSame('pending', $delivery->status);

        $this->patch(route('sales.deliveries.update', $delivery), [
            'status' => 'delivered',
            'assigned_to' => 'Livreur test',
        ])->assertRedirect();

        $this->assertSame('delivered', $delivery->fresh()->status);
        $this->assertNotNull($delivery->fresh()->delivered_at);
    }
}
