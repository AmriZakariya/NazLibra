<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Category;
use App\Models\Item;
use App\Models\ItemLocationStock;
use App\Models\Location;
use App\Models\OnlineOrder;
use App\Models\Sale;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\Unit;
use App\Services\Inventory\InventoryService;
use App\Support\AppModules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnlineOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_online_orders_module_renders_and_can_be_disabled(): void
    {
        $this->seed();

        $this->get(route('module', ['module' => 'online-orders', 'section' => 'list']))
            ->assertOk()
            ->assertSee('Liste des précommandes')
            ->assertSee('Nouvelle précommande');

        $tenant = Tenant::firstOrFail();
        $enabled = collect(AppModules::settings($tenant)['enabled'])
            ->filter(fn (bool $enabled, string $key) => $enabled && $key !== 'online_orders')
            ->keys()
            ->all();

        $this->post(route('settings.modules.update'), [
            'enabled' => $enabled,
            'order' => implode(',', AppModules::settings($tenant)['order']),
        ])->assertRedirect();

        $this->get(route('module', ['module' => 'online-orders', 'section' => 'list']))
            ->assertNotFound();
    }

    public function test_online_order_can_be_created_and_status_tracked(): void
    {
        $this->seed();

        $client = Contact::where('kind', 'client')->firstOrFail();
        $item = Item::where('type', '!=', 'service')->firstOrFail();
        $initialStock = $item->stock_quantity;

        $response = $this->post(route('online-orders.store'), [
            'contact_id' => $client->id,
            'channel' => 'whatsapp',
            'status' => 'pending',
            'ordered_at' => now()->format('Y-m-d H:i:s'),
            'expected_at' => now()->addDays(2)->toDateString(),
            'deposit_amount' => 20,
            'discount_amount' => 5,
            'internal_note' => 'À confirmer avec le client',
            'items' => [
                [
                    'item_id' => $item->id,
                    'quantity' => 2,
                    'unit_price' => 30,
                    'discount_amount' => 0,
                    'note' => 'Edition demandée',
                ],
            ],
        ]);

        $order = OnlineOrder::with('items')->latest('id')->firstOrFail();
        $response->assertRedirect(route('module', ['module' => 'online-orders', 'section' => 'list', 'order' => $order->id]));

        $this->assertStringStartsWith('PRE', $order->number);
        $this->assertSame('pending', $order->status);
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertSame($client->name, $order->customer_name);
        $this->assertEquals(55.0, (float) $order->total_amount);
        $this->assertEquals(20.0, (float) $order->deposit_amount);
        $this->assertCount(1, $order->items);
        $this->assertSame($initialStock, $item->fresh()->stock_quantity, 'Une précommande ne doit pas décrémenter le stock.');

        $this->patch(route('online-orders.status.update', $order), [
            'status' => 'confirmed',
            'internal_note' => 'Confirmée par WhatsApp',
        ])->assertRedirect();

        $order->refresh();
        $this->assertSame('confirmed', $order->status);
        $this->assertSame('Confirmée par WhatsApp', $order->internal_note);
        $this->assertSame('confirmed', data_get($order->metadata, 'status_history.0.to'));
    }

    public function test_public_store_displays_only_online_visible_items(): void
    {
        $this->seed();

        $visible = Item::where('tenant_id', Tenant::firstOrFail()->id)
            ->where('status', 'active')
            ->where('is_enabled', true)
            ->firstOrFail();
        $hidden = Item::where('tenant_id', $visible->tenant_id)
            ->whereKeyNot($visible->id)
            ->firstOrFail();
        $hidden->update(['online_store_visible' => false]);

        $this->get(route('storefront.index'))
            ->assertOk()
            ->assertSee('Boutique en ligne')
            ->assertSee($visible->title)
            ->assertDontSee($hidden->title);
    }

    public function test_public_store_filters_by_price_categories_tags_and_stock_visibility(): void
    {
        $this->seed();

        $tenant = Tenant::firstOrFail();
        $category = Category::firstOrCreate(
            ['tenant_id' => $tenant->id, 'slug' => 'test-web-filter'],
            ['name' => 'Test web filter', 'icon' => 'tag', 'color' => '#3157D5', 'loan_duration_days' => 14, 'daily_fine_amount' => 2],
        );
        $unit = Unit::where('tenant_id', $tenant->id)->firstOrFail();
        $tax = Tax::where('tenant_id', $tenant->id)->firstOrFail();
        $storeName = data_get($tenant->settings, 'stores.0.name');
        $locationId = app(InventoryService::class)->locationIdFromName($tenant->id, $storeName);

        $matching = Item::create([
            'tenant_id' => $tenant->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'tax_id' => $tax->id,
            'type' => 'supply',
            'status' => 'active',
            'is_enabled' => true,
            'checkout_visible' => true,
            'online_store_visible' => true,
            'item_code' => 'WEB-FILTER-OK',
            'title' => 'Article filtre boutique unique',
            'purchase_price' => 10,
            'sale_price' => 25,
            'stock_quantity' => 3,
            'min_stock_threshold' => 1,
            'tags' => ['rentrée', 'test-web'],
        ]);
        $expensive = Item::create([
            'tenant_id' => $tenant->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'tax_id' => $tax->id,
            'type' => 'supply',
            'status' => 'active',
            'is_enabled' => true,
            'checkout_visible' => true,
            'online_store_visible' => true,
            'item_code' => 'WEB-FILTER-NO',
            'title' => 'Article filtre boutique trop cher',
            'purchase_price' => 10,
            'sale_price' => 250,
            'stock_quantity' => 4,
            'min_stock_threshold' => 1,
            'tags' => ['rentrée', 'test-web'],
        ]);
        $outOfStock = Item::create([
            'tenant_id' => $tenant->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'tax_id' => $tax->id,
            'type' => 'supply',
            'status' => 'active',
            'is_enabled' => true,
            'checkout_visible' => true,
            'online_store_visible' => true,
            'item_code' => 'WEB-FILTER-STOCK',
            'title' => 'Article filtre boutique rupture',
            'purchase_price' => 10,
            'sale_price' => 20,
            'stock_quantity' => 0,
            'min_stock_threshold' => 1,
            'tags' => ['rentrée', 'test-web'],
        ]);

        ItemLocationStock::updateOrCreate(
            ['tenant_id' => $tenant->id, 'item_id' => $matching->id, 'location_id' => $locationId, 'variant_id' => null],
            ['quantity' => 3, 'reserved_quantity' => 0],
        );
        ItemLocationStock::updateOrCreate(
            ['tenant_id' => $tenant->id, 'item_id' => $expensive->id, 'location_id' => $locationId, 'variant_id' => null],
            ['quantity' => 4, 'reserved_quantity' => 0],
        );
        ItemLocationStock::updateOrCreate(
            ['tenant_id' => $tenant->id, 'item_id' => $outOfStock->id, 'location_id' => $locationId, 'variant_id' => null],
            ['quantity' => 0, 'reserved_quantity' => 0],
        );

        $this->get(route('storefront.index', [
            'categories' => [$category->slug],
            'tags' => ['rentrée'],
            'min_price' => 10,
            'max_price' => 50,
            'include_out_of_stock' => 0,
        ]))
            ->assertOk()
            ->assertSee($matching->title)
            ->assertDontSee($expensive->title)
            ->assertDontSee($outOfStock->title);
    }

    public function test_public_store_can_be_disabled_from_settings(): void
    {
        $this->seed();

        $tenant = Tenant::firstOrFail();
        $settings = $tenant->settings ?? [];
        $settings['online_store'] = array_merge($settings['online_store'] ?? [], ['enabled' => false]);
        $tenant->update(['settings' => $settings]);

        $this->get(route('storefront.index'))->assertNotFound();
    }

    public function test_public_store_creates_pending_online_order_without_decrementing_stock(): void
    {
        $this->seed();

        $item = Item::where('type', '!=', 'service')->where('stock_quantity', '>', 2)->firstOrFail();
        $initialStock = (int) $item->stock_quantity;

        $response = $this->post(route('storefront.orders.store'), [
            'customer_name' => 'Client boutique',
            'customer_phone' => '+212600000001',
            'customer_email' => 'client-boutique@example.test',
            'delivery_address' => 'Casablanca',
            'customer_note' => 'Livraison demain si possible',
            'items' => [
                ['item_id' => $item->id, 'quantity' => 2],
            ],
        ]);

        $order = OnlineOrder::with('items')->latest('id')->firstOrFail();
        $response->assertRedirect(route('storefront.index', ['commande' => $order->number]));

        $this->assertSame('online', $order->channel);
        $this->assertSame('pending', $order->status);
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertSame('Client boutique', $order->customer_name);
        $this->assertSame('online_store', data_get($order->metadata, 'source'));
        $this->assertCount(1, $order->items);
        $this->assertSame($initialStock, (int) $item->fresh()->stock_quantity);
    }

    public function test_public_store_normalizes_phone_and_reuses_existing_customer(): void
    {
        $this->seed();

        $tenant = Tenant::firstOrFail();
        $client = Contact::create([
            'tenant_id' => $tenant->id,
            'kind' => 'client',
            'name' => 'Client existant',
            'phone' => '+212600000003',
            'email' => 'old@example.test',
        ]);
        $item = Item::where('type', '!=', 'service')->where('stock_quantity', '>', 0)->firstOrFail();

        $this->post(route('storefront.orders.store'), [
            'customer_name' => 'Client boutique mis à jour',
            'customer_phone' => '06 00 00 00 03',
            'customer_email' => 'new@example.test',
            'delivery_address' => 'Rabat',
            'items' => [
                ['item_id' => $item->id, 'quantity' => 1],
            ],
        ])->assertRedirect();

        $order = OnlineOrder::latest('id')->firstOrFail();

        $this->assertSame($client->id, $order->contact_id);
        $this->assertSame('+212600000003', $order->customer_phone);
        $this->assertSame(1, Contact::where('tenant_id', $tenant->id)->where('phone', '+212600000003')->count());
        $this->assertSame('Client boutique mis à jour', $client->fresh()->name);
    }

    public function test_public_store_uses_selected_pickup_store_stock_and_saves_store_metadata(): void
    {
        $this->seed();

        $tenant = Tenant::firstOrFail();
        $settings = $tenant->settings ?? [];
        $settings['stores'] = [
            ['key' => 'casa', 'name' => 'Casa Store', 'type' => 'store', 'is_active' => true],
            ['key' => 'rabat', 'name' => 'Rabat Store', 'type' => 'store', 'is_active' => true],
        ];
        $settings['current_store'] = 'casa';
        $settings['online_store'] = ['enabled' => true, 'pickup_store' => 'casa'];
        $tenant->update(['settings' => $settings]);

        $casa = Location::create(['tenant_id' => $tenant->id, 'name' => 'Casa Store', 'type' => 'store', 'is_active' => true, 'is_default' => false]);
        $rabat = Location::create(['tenant_id' => $tenant->id, 'name' => 'Rabat Store', 'type' => 'store', 'is_active' => true, 'is_default' => false]);
        $item = Item::where('type', '!=', 'service')->firstOrFail();
        $item->update(['stock_quantity' => 0, 'online_store_visible' => true, 'is_enabled' => true, 'status' => 'active']);
        ItemLocationStock::updateOrCreate(
            ['tenant_id' => $tenant->id, 'item_id' => $item->id, 'location_id' => $casa->id, 'variant_id' => null],
            ['quantity' => 0, 'reserved_quantity' => 0]
        );
        ItemLocationStock::updateOrCreate(
            ['tenant_id' => $tenant->id, 'item_id' => $item->id, 'location_id' => $rabat->id, 'variant_id' => null],
            ['quantity' => 3, 'reserved_quantity' => 0]
        );

        $this->get(route('storefront.index', ['pickup_store' => 'casa']))
            ->assertOk()
            ->assertSee('Rupture');

        $this->post(route('storefront.orders.store'), [
            'customer_name' => 'Client Rabat',
            'customer_phone' => '+212600000004',
            'pickup_store' => 'rabat',
            'items' => [
                ['item_id' => $item->id, 'quantity' => 2],
            ],
        ])->assertRedirect();

        $order = OnlineOrder::latest('id')->firstOrFail();
        $this->assertSame('rabat', data_get($order->metadata, 'pickup_store'));
        $this->assertSame('Rabat Store', data_get($order->metadata, 'pickup_store_name'));
    }

    public function test_public_store_rejects_quantity_above_available_stock(): void
    {
        $this->seed();

        $item = Item::where('type', '!=', 'service')->firstOrFail();

        $this->from(route('storefront.index'))->post(route('storefront.orders.store'), [
            'customer_name' => 'Client stock',
            'customer_phone' => '+212600000002',
            'items' => [
                ['item_id' => $item->id, 'quantity' => (int) $item->stock_quantity + 100],
            ],
        ])->assertRedirect(route('storefront.index'))
            ->assertSessionHasErrors('items');
    }

    public function test_invalid_online_order_transition_is_blocked(): void
    {
        $this->seed();

        $order = OnlineOrder::create([
            'tenant_id' => Tenant::firstOrFail()->id,
            'user_id' => auth()->id(),
            'number' => 'PRE99999',
            'channel' => 'online',
            'status' => 'fulfilled',
            'customer_name' => 'Client final',
            'ordered_at' => now(),
            'total_amount' => 100,
        ]);

        $this->patch(route('online-orders.status.update', $order), [
            'status' => 'pending',
            'payment_status' => 'paid',
        ])->assertSessionHasErrors('status');

        $this->assertSame('fulfilled', $order->fresh()->status);
    }

    public function test_confirmed_online_order_can_create_only_one_linked_sale(): void
    {
        $this->seed();

        $tenant = Tenant::firstOrFail();
        $item = Item::where('tenant_id', $tenant->id)->where('type', '!=', 'service')->where('stock_quantity', '>', 2)->firstOrFail();
        $order = OnlineOrder::create([
            'tenant_id' => $tenant->id,
            'contact_id' => Contact::where('tenant_id', $tenant->id)->where('kind', 'client')->value('id'),
            'user_id' => auth()->id(),
            'number' => 'PRE-CONVERT-1',
            'channel' => 'online',
            'status' => 'confirmed',
            'payment_status' => 'unpaid',
            'customer_name' => 'Client conversion',
            'ordered_at' => now(),
            'subtotal_amount' => $item->sale_price,
            'total_amount' => $item->sale_price,
        ]);
        $order->items()->create([
            'item_id' => $item->id,
            'name' => $item->title,
            'code' => $item->item_code,
            'quantity' => 1,
            'unit_price' => $item->sale_price,
            'discount_amount' => 0,
            'total_amount' => $item->sale_price,
            'display_order' => 1,
        ]);

        $this->get(route('online-orders.sale.prepare', $order))
            ->assertRedirect(route('pos', ['source_online_order' => $order->id]));

        $this->get(route('pos', ['source_online_order' => $order->id]))
            ->assertOk()
            ->assertSee('name="source_online_order_id" type="hidden" value="'.$order->id.'"', false)
            ->assertSee($item->title);

        $payload = [
            '_idempotency_key' => 'online-order-sale-test-1',
            'source_online_order_id' => $order->id,
            'contact_id' => $order->contact_id,
            'cash_amount' => (float) $item->sale_price,
            'cart' => json_encode([[
                'id' => $item->id,
                'quantity' => 1,
                'price' => (float) $item->sale_price,
                'note' => 'Depuis '.$order->number,
            ]]),
        ];

        $this->post(route('pos.store'), $payload)->assertRedirect();

        $sale = Sale::where('tenant_id', $tenant->id)->where('source_online_order_id', $order->id)->firstOrFail();
        $order->refresh();
        $this->assertSame($sale->id, $order->converted_sale_id);
        $this->assertSame('fulfilled', $order->status);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('online_order_then_sale', data_get($sale->metadata, 'document_flow'));

        $this->post(route('pos.store'), array_replace($payload, ['_idempotency_key' => 'online-order-sale-test-2']))
            ->assertRedirect(route('pos', ['sale' => $sale->id]));

        $this->assertSame(1, Sale::where('tenant_id', $tenant->id)->where('source_online_order_id', $order->id)->count());
        $this->get(route('online-orders.sale.prepare', $order->fresh()))
            ->assertRedirect(route('module', ['module' => 'sales', 'section' => 'list', 'detail_sale' => $sale->id]));
    }

    public function test_pending_online_order_must_be_confirmed_before_sale_creation(): void
    {
        $this->seed();

        $tenant = Tenant::firstOrFail();
        $order = OnlineOrder::create([
            'tenant_id' => $tenant->id,
            'user_id' => auth()->id(),
            'number' => 'PRE-PENDING-BLOCK',
            'channel' => 'online',
            'status' => 'pending',
            'payment_status' => 'unpaid',
            'customer_name' => 'Client pending',
            'ordered_at' => now(),
            'total_amount' => 10,
        ]);

        $this->get(route('online-orders.sale.prepare', $order))
            ->assertRedirect(route('module', ['module' => 'online-orders', 'section' => 'list', 'order' => $order->id]))
            ->assertSessionHasErrors('sale');
    }

    public function test_online_order_checkout_does_not_convert_or_decrement_stock_when_location_stock_is_insufficient(): void
    {
        $this->seed();

        $tenant = Tenant::firstOrFail();
        $item = Item::where('tenant_id', $tenant->id)->where('type', '!=', 'service')->firstOrFail();
        $order = OnlineOrder::create([
            'tenant_id' => $tenant->id,
            'user_id' => auth()->id(),
            'number' => 'PRE-STOCK-EDGE',
            'channel' => 'online',
            'status' => 'confirmed',
            'payment_status' => 'unpaid',
            'customer_name' => 'Client stock',
            'ordered_at' => now(),
            'subtotal_amount' => $item->sale_price * 999,
            'total_amount' => $item->sale_price * 999,
        ]);
        $order->items()->create([
            'item_id' => $item->id,
            'name' => $item->title,
            'quantity' => 999,
            'unit_price' => $item->sale_price,
            'total_amount' => $item->sale_price * 999,
            'display_order' => 1,
        ]);

        $stockBefore = (int) $item->stock_quantity;
        $this->post(route('pos.store'), [
            '_idempotency_key' => 'online-order-insufficient-stock',
            'source_online_order_id' => $order->id,
            'cash_amount' => (float) $order->total_amount,
            'cart' => json_encode([['id' => $item->id, 'quantity' => 999, 'price' => (float) $item->sale_price]]),
        ])->assertSessionHasErrors('cart');

        $this->assertNull($order->fresh()->converted_sale_id);
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertSame('unpaid', $order->fresh()->payment_status);
        $this->assertSame($stockBefore, (int) $item->fresh()->stock_quantity);
        $this->assertSame(0, Sale::where('source_online_order_id', $order->id)->count());
    }

    public function test_ready_online_order_cannot_be_fulfilled_without_linked_sale(): void
    {
        $this->seed();

        $tenant = Tenant::firstOrFail();
        $order = OnlineOrder::create([
            'tenant_id' => $tenant->id,
            'user_id' => auth()->id(),
            'number' => 'PRE-READY-BLOCK',
            'channel' => 'online',
            'status' => 'ready',
            'payment_status' => 'unpaid',
            'customer_name' => 'Client prêt',
            'ordered_at' => now(),
            'total_amount' => 10,
        ]);

        $this->patch(route('online-orders.status.update', $order), [
            'status' => 'fulfilled',
            'payment_status' => 'unpaid',
        ])->assertSessionHasErrors('status');

        $this->assertSame('ready', $order->fresh()->status);
    }
}
