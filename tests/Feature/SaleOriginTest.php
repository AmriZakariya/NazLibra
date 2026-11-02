<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Location;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VirtualDevice;
use App\Models\VirtualDeviceSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Every ticket says where it was rung up: the phone app or the web till,
 * and on which terminal. The web's sales used to record no terminal at all.
 */
class SaleOriginTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Item $cut;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->tenant = Tenant::first();
        $this->user = User::first();
        $this->cut = Item::create([
            'tenant_id' => $this->tenant->id, 'title' => 'Coupe', 'type' => 'service',
            'status' => 'active', 'sale_price' => 60, 'purchase_price' => 0, 'stock_quantity' => 0,
        ]);
    }

    public function test_a_web_sale_records_the_web_terminal_it_was_rung_up_on(): void
    {
        $settings = $this->tenant->settings ?? [];
        data_set($settings, 'features.virtual_devices', true);
        $this->tenant->update(['settings' => $settings]);
        $terminal = VirtualDevice::create(['tenant_id' => $this->tenant->id, 'name' => 'Terminal Web 1',
            'code' => 'terminal-web-1', 'type' => 'computer', 'is_active' => true]);
        $session = VirtualDeviceSession::create([
            'tenant_id' => $this->tenant->id, 'virtual_device_id' => $terminal->id, 'user_id' => $this->user->id,
            'connection_token' => (string) Str::uuid(), 'connected_at' => now(), 'last_seen_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->withSession(['virtual_device_session_id' => $session->id])
            ->post(route('pos.store'), [
                '_idempotency_key' => (string) Str::uuid(),
                'cart' => json_encode([['id' => $this->cut->id, 'quantity' => 1, 'price' => 60]]),
                'cash_amount' => 60,
            ])->assertSessionHasNoErrors();

        $sale = Sale::latest('id')->first();
        $this->assertSame($terminal->id, $sale->virtual_device_id);
        $this->assertSame(['channel' => 'web', 'terminal' => 'Terminal Web 1'], $sale->origin());
        $this->assertSame('Caisse web · Terminal Web 1', $sale->originLabel());
    }

    public function test_an_app_sale_says_mobile_and_its_terminal_reaches_the_app(): void
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $this->user->email, 'password' => 'password', 'device_name' => 'till-'.Str::uuid(),
        ])->json('token');

        $sale = $this->withToken($token)->postJson('/api/v1/pos/sales', [
            'idempotency_key' => (string) Str::uuid(),
            'location_id' => Location::where('tenant_id', $this->tenant->id)->value('id'),
            'items' => [['item_id' => $this->cut->id, 'quantity' => 1]],
            'payments' => ['cash' => 60, 'card' => 0, 'transfer' => 0, 'advance' => 0],
        ])->assertCreated()->json('sale');

        $this->assertSame('mobile', $sale['origin']['channel']);
    }

    public function test_older_sales_are_told_apart_by_how_they_came_in(): void
    {
        $old = new Sale(['metadata' => ['source' => 'mobile_api'], 'terminal_name_snapshot' => 'Terminal Mobile 2']);
        $this->assertSame(['channel' => 'mobile', 'terminal' => 'Terminal Mobile 2'], $old->origin());

        $web = new Sale(['metadata' => ['document_flow' => 'sale_first']]);
        $this->assertSame('web', $web->origin()['channel']);
    }

    public function test_the_web_detail_shows_the_origin(): void
    {
        Sale::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id, 'number' => 'BL-03-000001', 'status' => 'paid',
            'payment_method' => 'cash', 'subtotal_amount' => 60, 'discount_amount' => 0, 'tax_amount' => 0, 'total_amount' => 60,
            'sold_at' => now(), 'terminal_name_snapshot' => 'Terminal Mobile 2',
            'metadata' => ['source' => 'mobile_api', 'channel' => 'mobile'],
        ]);

        $this->actingAs($this->user)->get(route('module', 'sales'))
            ->assertOk()
            ->assertSee('📱 Application mobile')
            ->assertSee('Terminal Mobile 2');
    }
}
