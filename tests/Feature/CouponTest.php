<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Coupon;
use App\Models\Item;
use App\Models\Sale;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CouponTest extends TestCase
{
    use RefreshDatabase;

    public function test_finance_can_create_and_list_coupons(): void
    {
        $this->seed();

        $this->get(route('module', ['module' => 'finance', 'section' => 'coupon-add']))
            ->assertOk()
            ->assertSee('Créer un coupon')
            ->assertSee('Code coupon');

        $this->post(route('coupons.store'), [
            'code' => 'demo15',
            'name' => 'Demo coupon',
            'type' => 'percent',
            'value' => 15,
            'minimum_amount' => 100,
            'max_uses' => 20,
            'expires_at' => now()->addMonth()->toDateString(),
            'is_active' => 1,
        ])->assertRedirect(route('module', ['module' => 'finance', 'section' => 'coupons']));

        $this->assertDatabaseHas('coupons', [
            'code' => 'DEMO15',
            'type' => 'percent',
            'value' => 15,
        ]);

        $this->get(route('module', ['module' => 'finance', 'section' => 'coupons', 'q' => 'DEMO15']))
            ->assertOk()
            ->assertSee('DEMO15')
            ->assertSee('Demo coupon');
    }

    public function test_pos_previews_and_applies_coupon_to_sale(): void
    {
        $this->seed();

        $tenant = Tenant::firstOrFail();
        $item = Item::where('type', '!=', 'service')->where('stock_quantity', '>', 1)->firstOrFail();
        $subtotal = (float) $item->sale_price * 2;
        $coupon = Coupon::create([
            'tenant_id' => $tenant->id,
            'code' => 'POS20',
            'name' => 'POS 20%',
            'type' => 'percent',
            'value' => 20,
            'minimum_amount' => 0,
            'is_active' => true,
        ]);
        $expectedCouponAmount = round($subtotal * 0.20, 2);
        $expectedTotal = round($subtotal - $expectedCouponAmount, 2);

        $this->getJson(route('pos.coupons.preview', [
            'code' => 'POS20',
            'subtotal' => $subtotal,
        ]))
            ->assertOk()
            ->assertJsonPath('coupon.amount', $expectedCouponAmount);

        $this->post(route('pos.store'), [
            'cart' => json_encode([
                ['id' => $item->id, 'quantity' => 2],
            ]),
            'coupon_code' => 'POS20',
            'cash_amount' => $expectedTotal,
        ])->assertRedirect();

        $sale = Sale::orderByDesc('id')->firstOrFail();
        $this->assertSame($expectedCouponAmount, (float) $sale->discount_amount);
        $this->assertSame($expectedTotal, (float) $sale->total_amount);
        $this->assertSame('POS20', $sale->metadata['discount']['coupon']['code']);
        $this->assertSame(1, $coupon->fresh()->uses_count);
        $this->assertSame($expectedCouponAmount, (float) $coupon->fresh()->used_amount);
    }

    public function test_pos_caps_coupon_and_manual_discount_to_cart_total(): void
    {
        $this->seed();

        $tenant = Tenant::firstOrFail();
        $item = Item::where('type', '!=', 'service')->where('stock_quantity', '>', 0)->firstOrFail();
        $subtotal = (float) $item->sale_price;
        Coupon::create([
            'tenant_id' => $tenant->id,
            'code' => 'BIGFIXED',
            'type' => 'fixed',
            'value' => $subtotal * 3,
            'minimum_amount' => 0,
            'is_active' => true,
        ]);

        $this->post(route('pos.store'), [
            'cart' => json_encode([
                ['id' => $item->id, 'quantity' => 1],
            ]),
            'coupon_code' => 'BIGFIXED',
            'discount_type' => 'fixed',
            'discount_value' => 500,
            'cash_amount' => 0,
        ])->assertRedirect();

        $sale = Sale::orderByDesc('id')->firstOrFail();
        $this->assertSame($subtotal, (float) $sale->discount_amount);
        $this->assertSame(0.0, (float) $sale->total_amount);
        $this->assertTrue((bool) $sale->metadata['discount']['coupon']['capped']);
    }

    public function test_customer_coupon_requires_matching_client(): void
    {
        $this->seed();

        $tenant = Tenant::firstOrFail();
        $client = Contact::where('tenant_id', $tenant->id)->where('kind', 'client')->firstOrFail();
        $otherClient = Contact::where('tenant_id', $tenant->id)->where('kind', 'client')->whereKeyNot($client->id)->firstOrFail();
        Coupon::create([
            'tenant_id' => $tenant->id,
            'contact_id' => $client->id,
            'code' => 'CLIENTONLY',
            'type' => 'fixed',
            'value' => 20,
            'minimum_amount' => 0,
            'is_active' => true,
        ]);

        $this->getJson(route('pos.coupons.preview', [
            'code' => 'CLIENTONLY',
            'subtotal' => 100,
            'contact_id' => $otherClient->id,
        ]))->assertStatus(422);

        $this->getJson(route('pos.coupons.preview', [
            'code' => 'CLIENTONLY',
            'subtotal' => 100,
            'contact_id' => $client->id,
        ]))->assertOk();
    }
}
