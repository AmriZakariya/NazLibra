<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\CashRegisterMovement;
use App\Models\CashRegisterSession;
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
            ->assertSee('data-fullscreen-toggle', false)
            ->assertSee('Mode plein écran')
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
            ->assertSee('pos-advanced-filters', false)
            ->assertSee('Filtres')
            ->assertSee('Famille, stock, catégorie, marque et unité')
            ->assertSee('pos-type-filter', false)
            ->assertSee('pos-stock-filter', false)
            ->assertSee('pos-category-filter', false)
            ->assertSee('Toutes les catégories')
            ->assertSee('Toutes les marques / éditeurs')
            ->assertSee('data-pos-search-url', false)
            ->assertSee('Plus vendus')
            ->assertSee('pos-favorite-star', false)
            ->assertSee('type="button" aria-label="Basculer favori"', false)
            ->assertSee('role="button"', false)
            ->assertSee('Effacer')
            ->assertSee('Espèces')
            ->assertSee('50/50')
            ->assertSee('Actions')
            ->assertSee('Client du ticket')
            ->assertSee('name="discount_type"', false)
            ->assertSee('name="discount_value"', false)
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
            'discount_type' => 'percentage',
            'discount_value' => 10,
        ]);

        $ticket = PosTicket::firstOrFail();
        $response->assertRedirect(route('pos'));
        $this->assertSame('held', $ticket->status);
        $this->assertStringStartsWith('ATT', $ticket->number);
        $this->assertSame('percentage', $ticket->discount_type);
        $this->assertSame(10.0, (float) $ticket->discount_value);

        $this->get(route('pos', ['ticket' => $ticket->id]))
            ->assertOk()
            ->assertSee($ticket->number)
            ->assertSee('value="10"', false)
            ->assertSee('value="percentage" selected', false)
            ->assertSee('Tickets en attente')
            ->assertSee('pos-held-count', false);
    }

    public function test_pos_can_hold_ticket_inline_without_redirect(): void
    {
        $this->seed();

        $item = Item::where('type', '!=', 'service')->where('stock_quantity', '>', 0)->firstOrFail();

        $response = $this->postJson(route('pos.tickets.store'), [
            'cart' => json_encode([
                ['id' => $item->id, 'quantity' => 1],
            ]),
            'discount_type' => 'fixed',
            'discount_value' => 0,
        ]);

        $ticket = PosTicket::firstOrFail();
        $response
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('ticket.number', $ticket->number)
            ->assertJsonPath('message', 'Ticket '.$ticket->number.' mis en attente.');

        $this->assertSame('held', $ticket->status);
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
            'note' => 'Note comptoir test',
        ]);

        $this->assertSame($saleCount + 1, Sale::count());

        $sale = Sale::orderByDesc('id')->firstOrFail();
        $response->assertRedirect(route('pos', ['sale' => $sale->id]));
        $this->assertStringStartsWith('BL', $sale->number);
        $this->assertNull($sale->invoice);
        $this->assertArrayNotHasKey('invoice_number', $sale->metadata);
        $this->assertSame($client->id, $sale->contact_id);
        $this->assertSame('cash+advance', $sale->payment_method);
        $this->assertStringContainsString('Note système: vente '.$sale->number, $sale->metadata['system_note']);
        $this->assertStringContainsString('caisse POS', $sale->metadata['system_note']);
        $this->assertSame('Note comptoir test', $sale->metadata['note']);
        $this->assertSame($total, (float) $sale->total_amount);
        $this->assertSame(2, $sale->items()->firstOrFail()->quantity);
        $this->assertSame($initialStock - 2, $item->fresh()->stock_quantity);
        $this->assertDatabaseHas('stock_movements', [
            'item_id' => $item->id,
            'type' => 'sale',
            'quantity_delta' => -2,
            'quantity_after' => $initialStock - 2,
            'reference_type' => Sale::class,
            'reference_id' => $sale->id,
        ]);
        $this->assertSame($initialAdvance - $advance, (float) $client->fresh()->advance_balance);

        $this->get(route('pos', ['sale' => $sale->id]))
            ->assertOk()
            ->assertSee('Note système')
            ->assertSee($sale->metadata['system_note'])
            ->assertSee('data-thermal-receipt', false)
            ->assertSee('PDF vente')
            ->assertSee(route('sales.pdf', $sale), false)
            ->assertDontSee('pos-print-pdf', false);

        $this->get(route('module', ['module' => 'sales', 'section' => 'list', 'ticket' => $sale->id]))
            ->assertOk()
            ->assertSee('Note manuelle')
            ->assertSee('Note comptoir test');
    }

    public function test_pos_sale_is_idempotent_with_client_key(): void
    {
        $this->seed();

        $item = Item::where('type', '!=', 'service')->where('stock_quantity', '>', 1)->firstOrFail();
        $initialStock = (int) $item->stock_quantity;
        $total = (float) $item->sale_price;
        $key = (string) \Illuminate\Support\Str::uuid();

        $payload = [
            'cart' => json_encode([['id' => $item->id, 'quantity' => 1]]),
            'cash_amount' => $total,
            '_idempotency_key' => $key,
        ];

        $this->post(route('pos.store'), $payload)->assertRedirect();
        $this->post(route('pos.store'), $payload)->assertRedirect();

        $this->assertSame(1, Sale::where('idempotency_key', $key)->count());
        $this->assertSame($initialStock - 1, (int) $item->fresh()->stock_quantity);
        $this->assertSame(1, \DB::table('stock_movements')
            ->where('item_id', $item->id)
            ->where('type', 'sale')
            ->where('reference_type', Sale::class)
            ->count());
    }

    public function test_pos_accepts_percentage_discount_and_tracks_it(): void
    {
        $this->seed();

        $item = Item::where('type', '!=', 'service')->where('stock_quantity', '>', 1)->firstOrFail();
        $subtotal = (float) $item->sale_price * 2;
        $expectedDiscount = round($subtotal * 0.15, 2);
        $expectedTotal = round($subtotal - $expectedDiscount, 2);

        $response = $this->post(route('pos.store'), [
            'cart' => json_encode([
                ['id' => $item->id, 'quantity' => 2],
            ]),
            'discount_type' => 'percentage',
            'discount_value' => 15,
            'discount_amount' => 999999,
            'cash_amount' => $expectedTotal,
        ]);

        $sale = Sale::orderByDesc('id')->firstOrFail();
        $response->assertRedirect(route('pos', ['sale' => $sale->id]));
        $this->assertSame($expectedDiscount, (float) $sale->discount_amount);
        $this->assertSame($expectedTotal, (float) $sale->total_amount);
        $this->assertSame('percentage', $sale->metadata['discount']['manual']['type']);
        $this->assertSame(15.0, (float) $sale->metadata['discount']['manual']['value']);
        $this->assertFalse((bool) $sale->metadata['discount']['manual']['capped']);
    }

    public function test_pos_caps_fixed_discount_to_subtotal(): void
    {
        $this->seed();

        $item = Item::where('type', '!=', 'service')->where('stock_quantity', '>', 0)->firstOrFail();
        $subtotal = (float) $item->sale_price;

        $this->post(route('pos.store'), [
            'cart' => json_encode([
                ['id' => $item->id, 'quantity' => 1],
            ]),
            'discount_type' => 'fixed',
            'discount_value' => $subtotal + 500,
            'cash_amount' => 0,
        ])->assertRedirect();

        $sale = Sale::orderByDesc('id')->firstOrFail();
        $this->assertSame($subtotal, (float) $sale->discount_amount);
        $this->assertSame(0.0, (float) $sale->total_amount);
        $this->assertTrue((bool) $sale->metadata['discount']['manual']['capped']);
        $this->assertSame($subtotal + 500, (float) $sale->metadata['discount']['manual']['requested_value']);
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
            'allow_sale_edit' => '0',
            'allow_oversell' => '1',
            'show_out_of_stock' => '1',
            'show_cash_drawer_navbar' => '0',
            'online_store_enabled' => '0',
            'online_pickup_store' => 'magasin-principal',
        ])->assertRedirect();

        $settings = Tenant::firstOrFail()->fresh()->settings;
        $this->assertFalse((bool) data_get($settings, 'pos.editable_price'));
        $this->assertFalse((bool) data_get($settings, 'pos.allow_sale_edit'));
        $this->assertTrue((bool) data_get($settings, 'pos.allow_oversell'));
        $this->assertTrue((bool) data_get($settings, 'pos.show_out_of_stock'));
        $this->assertFalse((bool) data_get($settings, 'pos.show_cash_drawer_navbar'));
        $this->assertFalse((bool) data_get($settings, 'online_store.enabled'));
        $this->assertSame('magasin-principal', data_get($settings, 'online_store.pickup_store'));
    }

    public function test_settings_store_section_groups_pos_and_stock_settings(): void
    {
        $this->seed();

        $this->get(route('module', ['module' => 'settings', 'section' => 'store']))
            ->assertOk()
            ->assertSeeText('Caisse, stock & comportement comptoir')
            ->assertSee('Autoriser la modification des ventes')
            ->assertSee('Autoriser la vente hors stock')
            ->assertSee('Afficher les articles hors stock dans la caisse')
            ->assertSee('Afficher le tiroir caisse dans la barre supérieure')
            ->assertSee('Boutique en ligne')
            ->assertSee('Activer la boutique publique')
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

    public function test_pos_search_endpoint_finds_service_by_name(): void
    {
        $this->seed();

        $tenant = Tenant::firstOrFail();
        $service = Item::create([
            'tenant_id' => $tenant->id,
            'type' => 'service',
            'status' => 'active',
            'is_enabled' => true,
            'checkout_visible' => true,
            'item_code' => 'SRV-PASSEPORT',
            'title' => 'Photo passeport biométrique',
            'barcode' => 'PASS-SERVICE-001',
            'purchase_price' => 0,
            'sale_price' => 35,
            'stock_quantity' => 0,
            'min_stock_threshold' => 0,
        ]);

        $this->getJson(route('pos.search', ['q' => 'passeport']))
            ->assertOk()
            ->assertJsonFragment([
                'id' => $service->id,
                'name' => 'Photo passeport biométrique',
                'type' => 'service',
                'sellable' => true,
            ]);
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

    public function test_pos_reports_location_stock_shortage_without_technical_exception(): void
    {
        $this->seed();

        $tenant = Tenant::firstOrFail();
        $inventoryService = app(\App\Services\Inventory\InventoryService::class);
        $locationId = $inventoryService->defaultLocationId($tenant->id);
        $item = Item::where('tenant_id', $tenant->id)->where('type', '!=', 'service')->firstOrFail();
        $item->update(['stock_quantity' => 10, 'status' => 'active']);
        \App\Models\ItemLocationStock::updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'item_id' => $item->id,
                'variant_id' => null,
                'location_id' => $locationId,
            ],
            [
                'quantity' => 1,
                'reserved_quantity' => 0,
            ],
        );

        $response = $this->from(route('pos'))->post(route('pos.store'), [
            'cart' => json_encode([
                ['id' => $item->id, 'quantity' => 2],
            ]),
            'discount_amount' => 0,
            'cash_amount' => (float) $item->sale_price * 2,
        ]);

        $response->assertRedirect(route('pos'));
        $response->assertSessionHasErrors('cart');
        $message = session('errors')->first('cart');

        $this->assertStringContainsString('Stock insuffisant pour '.$item->title, $message);
        $this->assertStringContainsString('disponible 1, demandé 2', $message);
        $this->assertStringNotContainsString('Insufficient stock', $message);
    }

    public function test_pos_search_uses_current_location_stock_instead_of_global_stock(): void
    {
        $this->seed();

        $tenant = Tenant::firstOrFail();
        $inventoryService = app(\App\Services\Inventory\InventoryService::class);
        $locationId = $inventoryService->defaultLocationId($tenant->id);
        $item = Item::where('tenant_id', $tenant->id)->where('type', '!=', 'service')->firstOrFail();
        $item->update([
            'title' => 'Produit stock magasin test',
            'stock_quantity' => 10,
            'status' => 'active',
            'is_enabled' => true,
            'checkout_visible' => true,
        ]);
        \App\Models\ItemLocationStock::updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'item_id' => $item->id,
                'variant_id' => null,
                'location_id' => $locationId,
            ],
            [
                'quantity' => 1,
                'reserved_quantity' => 0,
            ],
        );

        $this->getJson(route('pos.search', ['q' => 'Produit stock magasin test', 'stock' => 'all']))
            ->assertOk()
            ->assertJsonFragment([
                'id' => $item->id,
                'stock' => 1,
                'global_stock' => 10,
            ]);
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

    public function test_manual_sale_add_screen_redirects_to_pos(): void
    {
        $this->seed();

        $this->get(route('module', ['module' => 'sales', 'section' => 'add']))
            ->assertRedirect(route('pos'));
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
        $response->assertRedirect(route('module', ['module' => 'sales', 'section' => 'list', 'detail_sale' => $sale->id]));
        $this->assertStringStartsWith('BL', $sale->number);
        $this->assertNull($sale->invoice);
        $this->assertSame($client->id, $sale->contact_id);
        $this->assertSame('paid', $sale->status);
        $this->assertSame('manual_sale', $sale->metadata['source']);
        $this->assertSame('BC-TEST-001', $sale->metadata['reference_number']);
        $this->assertStringContainsString('Note système: vente '.$sale->number, $sale->metadata['system_note']);
        $this->assertStringContainsString('vente manuelle', $sale->metadata['system_note']);
        $this->assertSame($initialStock - 2, $item->fresh()->stock_quantity);
        $this->assertSame(1, $sale->payments()->count());

        $this->get(route('module', ['module' => 'sales', 'section' => 'list', 'ticket' => $sale->id]))
            ->assertOk()
            ->assertSee('Note système')
            ->assertSee($sale->metadata['system_note']);
    }

    public function test_manual_unpaid_sale_can_reserve_stock_and_convert_on_payment(): void
    {
        $this->seed();

        $tenant = Tenant::firstOrFail();
        $tenant->update(['settings' => array_merge($tenant->settings ?? [], ['pos' => ['reserve_stock_for_unpaid_sales' => true, 'allow_oversell' => false]])]);

        $item = Item::where('type', '!=', 'service')->where('stock_quantity', '>', 2)->firstOrFail();
        $initialStock = (int) $item->stock_quantity;

        $response = $this->post(route('sales.store'), [
            'sale_status' => 'unpaid',
            'items' => [
                ['item_id' => $item->id, 'quantity' => 2, 'unit_price' => (float) $item->sale_price, 'tax_rate' => 20],
            ],
        ]);

        $sale = Sale::orderByDesc('id')->firstOrFail();
        $response->assertRedirect();
        $this->assertSame('unpaid', $sale->status);
        $this->assertSame($initialStock, (int) $item->fresh()->stock_quantity);
        $this->assertDatabaseHas('stock_movements', [
            'item_id' => $item->id,
            'type' => 'reservation',
            'reference_type' => Sale::class,
            'reference_id' => $sale->id,
        ]);

        $this->post(route('sales.payments.store'), [
            'sale_id' => $sale->id,
            'method' => 'cash',
            'amount' => (float) $sale->total_amount,
        ])->assertRedirect();

        $sale->refresh();
        $this->assertSame('paid', $sale->status);
        $this->assertSame($initialStock - 2, (int) $item->fresh()->stock_quantity);
        $this->assertDatabaseHas('stock_movements', [
            'item_id' => $item->id,
            'type' => 'sale',
            'reference_type' => Sale::class,
            'reference_id' => $sale->id,
        ]);
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
        $originalStatus = $sale->status;

        $this->patch(route('sales.update', $sale), [
            'contact_id' => $client->id,
            'reference_number' => 'REF-ACTION-001',
            'due_date' => now()->addDays(7)->toDateString(),
            'note' => 'Note action',
        ])->assertRedirect();

        $sale->refresh();
        $this->assertSame($client->id, $sale->contact_id);
        $this->assertSame($originalStatus, $sale->status);
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

    public function test_cannot_add_payment_to_fully_paid_sale(): void
    {
        $this->seed();

        $sale = Sale::firstOrFail();
        $sale->update([
            'status' => 'paid',
            'metadata' => array_merge($sale->metadata ?? [], ['paid_amount' => (float) $sale->total_amount]),
        ]);

        $response = $this->post(route('sales.payments.store'), [
            'sale_id' => $sale->id,
            'method' => 'cash',
            'amount' => 1,
        ]);

        $response->assertSessionHasErrors('sale_id');
        $this->assertSame(0, SalePayment::count());
    }

    public function test_sale_payment_cannot_exceed_remaining_amount(): void
    {
        $this->seed();

        $sale = Sale::firstOrFail();
        $sale->update([
            'status' => 'partial',
            'metadata' => array_merge($sale->metadata ?? [], ['paid_amount' => 10]),
        ]);

        $response = $this->post(route('sales.payments.store'), [
            'sale_id' => $sale->id,
            'method' => 'cash',
            'amount' => (float) $sale->total_amount,
        ]);

        $response->assertSessionHasErrors('amount');
        $this->assertSame(0, SalePayment::count());
        $this->assertSame(10.0, (float) $sale->fresh()->metadata['paid_amount']);
    }

    public function test_sale_update_ignores_manual_status_and_keeps_payment_state(): void
    {
        $this->seed();

        $sale = Sale::firstOrFail();
        $sale->update([
            'status' => 'unpaid',
            'metadata' => [],
        ]);

        $response = $this->patch(route('sales.update', $sale), [
            'contact_id' => null,
            'reference_number' => 'BAD-STATUS',
            'due_date' => null,
            'note' => null,
            'status' => 'paid',
            'sale_edit_id' => $sale->id,
        ]);

        $response->assertRedirect();
        $this->assertSame('unpaid', $sale->fresh()->status);
        $this->assertSame('BAD-STATUS', $sale->fresh()->metadata['reference_number']);
    }

    public function test_sale_update_can_be_disabled_by_store_setting(): void
    {
        $this->seed();

        $tenant = Tenant::firstOrFail();
        $tenant->update(['settings' => array_merge($tenant->settings ?? [], [
            'pos' => array_merge(data_get($tenant->settings, 'pos', []), ['allow_sale_edit' => false]),
        ])]);
        $sale = Sale::firstOrFail();

        $response = $this->patch(route('sales.update', $sale), [
            'contact_id' => null,
            'reference_number' => 'LOCKED-EDIT',
            'due_date' => null,
            'note' => null,
            'sale_edit_id' => $sale->id,
        ]);

        $response->assertSessionHasErrors('sale_edit');
        $this->assertNotSame('LOCKED-EDIT', data_get($sale->fresh()->metadata, 'reference_number'));

        $this->get(route('module', ['module' => 'sales', 'section' => 'list']))
            ->assertOk()
            ->assertSee('Modification verrouillée')
            ->assertDontSee('Modifier vente</button>', false);
    }

    public function test_sale_edit_validation_keeps_dialog_context(): void
    {
        $this->seed();

        $sale = Sale::firstOrFail();

        $response = $this->patch(route('sales.update', $sale), [
            'contact_id' => null,
            'reference_number' => str_repeat('R', 121),
            'due_date' => 'not-a-date',
            'note' => null,
            'sale_edit_id' => $sale->id,
        ]);

        $response->assertSessionHasErrors(['reference_number', 'due_date']);
        $response->assertSessionHasInput('sale_edit_id', (string) $sale->id);
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
        $return = SaleReturn::firstOrFail();
        $this->assertSame($stock + $line->quantity, $item->fresh()->stock_quantity);
        $this->assertDatabaseHas('stock_movements', [
            'item_id' => $item->id,
            'type' => 'return',
            'quantity_delta' => $line->quantity,
            'quantity_after' => $stock + $line->quantity,
            'reference_type' => SaleReturn::class,
            'reference_id' => $return->id,
        ]);
        $this->assertSame('cash', $sale->fresh()->metadata['refund']['method']);
    }

    public function test_sale_can_be_partially_refunded_with_damaged_stock_and_cash_drawer_impact(): void
    {
        $this->seed();

        $item = Item::where('type', '!=', 'service')->where('stock_quantity', '>', 10)->firstOrFail();
        $initialStock = $item->stock_quantity;
        $unitPrice = round((float) $item->sale_price, 2);

        $this->post(route('sales.store'), [
            '_idempotency_key' => 'partial-refund-sale',
            'sold_at' => now()->format('Y-m-d H:i:s'),
            'sale_status' => 'paid',
            'cash_amount' => $unitPrice * 3,
            'items' => [
                ['item_id' => $item->id, 'quantity' => 3, 'unit_price' => $unitPrice, 'discount_amount' => 0, 'tax_rate' => 0],
            ],
        ])->assertRedirect();

        $sale = Sale::with('items')->latest('id')->firstOrFail();
        $line = $sale->items->firstOrFail();
        $this->assertSame($initialStock - 3, $item->fresh()->stock_quantity);

        $this->post(route('cash-register.open'), [
            'store_key' => 'magasin-principal',
            'opening_amount' => 100,
        ])->assertRedirect(route('module', 'cash-register'));

        $this->post(route('sales.refund', $sale), [
            '_idempotency_key' => 'partial-refund-return',
            'refund_method' => 'cash',
            'refund_reason' => 'Client retourne un exemplaire abîmé',
            'return_lines' => [
                $line->id => [
                    'sale_item_id' => $line->id,
                    'quantity' => 1,
                    'stock_action' => 'damaged',
                    'reason' => 'Couverture déchirée',
                ],
            ],
        ])->assertRedirect();

        $return = SaleReturn::firstOrFail();
        $sale->refresh();

        $this->assertSame('partially_refunded', $sale->status);
        $this->assertSame('partial', $return->refund_scope);
        $this->assertSame('damaged', $return->stock_disposition);
        $this->assertSame($unitPrice, (float) $return->total_amount);
        $this->assertSame($initialStock - 3, $item->fresh()->stock_quantity);
        $this->assertDatabaseHas('stock_movements', [
            'item_id' => $item->id,
            'type' => 'damage',
            'quantity_delta' => 0,
            'reference_type' => SaleReturn::class,
            'reference_id' => $return->id,
            'reason' => 'Couverture déchirée',
        ]);
        $movement = CashRegisterMovement::where('type', 'sale_refund_cash')->firstOrFail();
        $this->assertSame($sale->id, $movement->sale_id);
        $this->assertSame($unitPrice, (float) $movement->amount);
        $this->assertSame(100.0 - $unitPrice, (float) CashRegisterSession::firstOrFail()->expected_cash_amount);
    }

    public function test_can_create_and_update_delivery_order(): void
    {
        $this->seed();

        $sale = Sale::firstOrFail();

        $response = $this->post(route('sales.deliveries.store'), [
            'sale_id' => $sale->id,
            'assigned_to' => 'Livreur test',
            'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'delivery_address' => 'Casablanca',
            'note' => 'Appeler avant livraison',
            'delivery_sale_id' => $sale->id,
        ]);

        $delivery = DeliveryOrder::firstOrFail();
        $response->assertRedirect();
        $this->assertStringStartsWith('LIV', $delivery->number);
        $this->assertSame('pending', $delivery->status);
        $this->assertSame('Casablanca', $delivery->delivery_address);
        $this->assertSame('Appeler avant livraison', $delivery->note);

        $this->patch(route('sales.deliveries.update', $delivery), [
            'status' => 'delivered',
            'assigned_to' => 'Livreur test',
        ])->assertRedirect();

        $this->assertSame('delivered', $delivery->fresh()->status);
        $this->assertNotNull($delivery->fresh()->delivered_at);
    }

    public function test_delivery_creation_validates_required_address_and_closed_sale(): void
    {
        $this->seed();

        $sale = Sale::firstOrFail();

        $response = $this->post(route('sales.deliveries.store'), [
            'sale_id' => $sale->id,
            'delivery_address' => '',
            'delivery_sale_id' => $sale->id,
        ]);

        $response->assertSessionHasErrors('delivery_address');
        $response->assertSessionHasInput('delivery_sale_id', (string) $sale->id);
        $this->assertSame(0, DeliveryOrder::count());

        $sale->update(['status' => 'cancelled']);
        $response = $this->post(route('sales.deliveries.store'), [
            'sale_id' => $sale->id,
            'delivery_address' => 'Casablanca',
            'delivery_sale_id' => $sale->id,
        ]);

        $response->assertSessionHasErrors('sale_id');
        $this->assertSame(0, DeliveryOrder::count());
    }
}
