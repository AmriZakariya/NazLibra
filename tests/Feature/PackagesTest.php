<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\CustomerPackage;
use App\Models\CustomerPackageMovement;
use App\Models\Item;
use App\Models\Location;
use App\Models\Sale;
use App\Models\ServicePackage;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Services\Packages\PackageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Prepaid packages: sold to a customer, used a session at a time, refunded back. */
class PackagesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private Location $location;

    private string $token;

    private Item $service;

    private Contact $customer;

    private ServicePackage $offer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->tenant = Tenant::first();
        $this->owner = User::first();
        $this->location = Location::where('tenant_id', $this->tenant->id)->first();
        $settings = $this->tenant->settings ?? [];
        $settings['modules']['enabled'] = ['gift_cards' => true, 'packages' => true];
        $this->tenant->update(['settings' => $settings]);
        $this->token = $this->postJson('/api/v1/auth/login', [
            'email' => $this->owner->email, 'password' => 'password', 'device_name' => 'till-'.Str::uuid(),
        ])->json('token');
        $this->service = Item::create([
            'tenant_id' => $this->tenant->id, 'title' => 'Brushing', 'type' => 'service', 'status' => 'active',
            'sale_price' => 100, 'purchase_price' => 0, 'stock_quantity' => 9999,
            'unit_id' => Unit::where('tenant_id', $this->tenant->id)->value('id'),
            'tax_id' => Tax::where('tenant_id', $this->tenant->id)->value('id'),
        ]);
        $this->customer = Contact::create(['tenant_id' => $this->tenant->id, 'kind' => 'client', 'name' => 'Cliente Test', 'phone' => '+212600000001']);
        $this->offer = ServicePackage::create([
            'tenant_id' => $this->tenant->id, 'name' => '5 brushings', 'item_id' => $this->service->id,
            'sessions' => 5, 'price' => 400, 'validity_days' => 90, 'is_active' => true,
        ]);
    }

    private function sell(array $items, array $payments, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->withToken($this->token)->withHeaders(['X-App-Version' => '1.0.17'])->postJson('/api/v1/pos/sales', $extra + [
            'idempotency_key' => Str::uuid()->toString(),
            'location_id' => $this->location->id,
            'contact_id' => $this->customer->id,
            'items' => $items,
            'payments' => $payments,
        ]);
    }

    private function buy(): CustomerPackage
    {
        $this->sell([['kind' => 'package', 'package_id' => $this->offer->id, 'quantity' => 1]], ['cash' => 400])->assertCreated();

        return CustomerPackage::latest('id')->firstOrFail();
    }

    private function use(CustomerPackage $package, int $quantity = 1, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->sell([['item_id' => $this->service->id, 'quantity' => $quantity, 'kind' => 'package_use', 'customer_package_id' => $package->id]], ['cash' => 0], $extra);
    }

    public function test_selling_a_package_creates_it_for_the_customer_once_and_is_not_revenue(): void
    {
        $key = Str::uuid()->toString();
        $items = [['kind' => 'package', 'package_id' => $this->offer->id, 'quantity' => 1]];
        $this->sell($items, ['cash' => 400], ['idempotency_key' => $key])->assertCreated();
        $this->sell($items, ['cash' => 400], ['idempotency_key' => $key])->assertOk();

        $this->assertSame(1, CustomerPackage::count(), 'a replayed sale never creates a second package');
        $owned = CustomerPackage::first();
        $this->assertSame($this->customer->id, $owned->contact_id);
        $this->assertSame(5, $owned->sessions_remaining);
        $this->assertSame(5, $owned->sessions_total);
        $this->assertSame('active', $owned->status);
        $this->assertEquals(400, $owned->price_paid);
        $this->assertSame(now()->addDays(90)->toDateString(), $owned->expires_at->toDateString());
        $sale = Sale::latest('id')->first();
        $this->assertEquals(400, $sale->non_revenue_amount);
        $this->assertEquals(0, $sale->tax_amount);
        $this->assertSame('sale', CustomerPackageMovement::first()->type);
    }

    public function test_a_package_needs_a_customer_and_an_active_offer(): void
    {
        $items = [['kind' => 'package', 'package_id' => $this->offer->id, 'quantity' => 1]];
        $this->sell($items, ['cash' => 400], ['contact_id' => null])->assertStatus(422);
        $this->offer->update(['is_active' => false]);
        $this->sell($items, ['cash' => 400])->assertStatus(422);
        $this->assertSame(0, CustomerPackage::count());
    }

    public function test_using_a_session_prices_the_line_at_zero_and_consumes_one(): void
    {
        $owned = $this->buy();
        $this->use($owned)->assertCreated();

        $owned->refresh();
        $this->assertSame(4, $owned->sessions_remaining);
        $sale = Sale::latest('id')->first();
        $this->assertEquals(0, $sale->total_amount);
        $line = $sale->items()->first();
        $this->assertSame('package_use', $line->kind);
        $this->assertEquals(0, $line->unit_price);
        $this->assertSame($owned->id, (int) $line->customer_package_id);
        $this->assertSame('use', CustomerPackageMovement::latest('id')->first()->type);
    }

    public function test_a_session_is_consumed_once_even_if_the_sale_is_sent_twice(): void
    {
        $owned = $this->buy();
        $key = Str::uuid()->toString();
        $this->use($owned, 1, ['idempotency_key' => $key])->assertCreated();
        $this->use($owned, 1, ['idempotency_key' => $key])->assertOk();
        $this->assertSame(4, $owned->fresh()->sessions_remaining);
        $this->assertSame(1, CustomerPackageMovement::where('type', 'use')->count());
    }

    public function test_the_last_session_closes_the_package_and_an_empty_one_is_refused(): void
    {
        $owned = $this->buy();
        $this->use($owned, 5)->assertCreated();
        $owned->refresh();
        $this->assertSame(0, $owned->sessions_remaining);
        $this->assertSame('used', $owned->status);
        $this->use($owned)->assertStatus(422);
        $this->assertSame(0, $owned->fresh()->sessions_remaining, 'never negative');
    }

    public function test_it_cannot_overdraw_when_two_tills_use_the_last_session(): void
    {
        $owned = $this->buy();
        $owned->update(['sessions_remaining' => 1]);
        $service = app(PackageService::class);
        $stale = CustomerPackage::find($owned->id);
        $sale = Sale::latest('id')->first();
        $line = $sale->items()->first();
        $line->forceFill(['item_id' => $this->service->id])->save();

        $service->consume($this->tenant, $owned->id, 1, $sale, $line);
        try {
            $service->consume($this->tenant, $stale->id, 1, $sale, $line);
            $this->fail('the second till should have been refused');
        } catch (\App\Exceptions\PackageException $e) {
            $this->assertContains($e->reason, ['used', 'insufficient_sessions']);
        }
        $this->assertSame(0, $owned->fresh()->sessions_remaining);
        $this->assertSame(0, CustomerPackage::whereKey($owned->id)->where('sessions_remaining', '>=', 1)->update(['sessions_remaining' => \DB::raw('sessions_remaining - 1')]));
    }

    public function test_a_package_serves_only_its_own_customer_and_service_and_not_after_its_date(): void
    {
        $owned = $this->buy();
        $other = Contact::create(['tenant_id' => $this->tenant->id, 'kind' => 'client', 'name' => 'Autre Cliente']);
        $this->use($owned, 1, ['contact_id' => $other->id])->assertStatus(422);

        $razor = Item::create([
            'tenant_id' => $this->tenant->id, 'title' => 'Barbe', 'type' => 'service', 'status' => 'active',
            'sale_price' => 50, 'purchase_price' => 0, 'stock_quantity' => 9999,
            'unit_id' => Unit::where('tenant_id', $this->tenant->id)->value('id'),
            'tax_id' => Tax::where('tenant_id', $this->tenant->id)->value('id'),
        ]);
        $this->sell([['item_id' => $razor->id, 'quantity' => 1, 'kind' => 'package_use', 'customer_package_id' => $owned->id]], ['cash' => 0])->assertStatus(422);

        $owned->update(['expires_at' => now()->subDay()->toDateString()]);
        $this->use($owned)->assertStatus(422);
        $this->assertSame('expired', $owned->fresh()->status);
        $this->assertSame(5, $owned->fresh()->sessions_remaining);
    }

    public function test_refunding_a_package_use_line_gives_the_session_back(): void
    {
        $owned = $this->buy();
        $this->use($owned, 2)->assertCreated();
        $this->assertSame(3, $owned->fresh()->sessions_remaining);
        $sale = Sale::latest('id')->first();

        $this->withToken($this->token)->postJson("/api/v1/pos/sales/{$sale->id}/returns", [
            'idempotency_key' => Str::uuid()->toString(), 'refund_method' => 'cash',
            'return_lines' => [['sale_item_id' => $sale->items()->first()->id, 'quantity' => 1]],
        ])->assertCreated();
        $this->assertSame(4, $owned->fresh()->sessions_remaining);
        $this->assertSame('refund', CustomerPackageMovement::latest('id')->first()->type);
    }

    public function test_a_used_up_package_comes_back_to_life_on_refund(): void
    {
        $owned = $this->buy();
        $this->use($owned, 5)->assertCreated();
        $this->assertSame('used', $owned->fresh()->status);
        $sale = Sale::latest('id')->first();
        $this->withToken($this->token)->postJson("/api/v1/pos/sales/{$sale->id}/returns", [
            'idempotency_key' => Str::uuid()->toString(), 'refund_method' => 'cash',
        ])->assertCreated();
        $owned->refresh();
        $this->assertSame(5, $owned->sessions_remaining);
        $this->assertSame('active', $owned->status);
    }

    public function test_a_package_sale_is_refundable_only_while_untouched(): void
    {
        $owned = $this->buy();
        $buySale = Sale::latest('id')->first();
        $this->use($owned)->assertCreated();
        $this->withToken($this->token)->postJson("/api/v1/pos/sales/{$buySale->id}/returns", [
            'idempotency_key' => Str::uuid()->toString(), 'refund_method' => 'cash',
        ])->assertStatus(422);
        $this->assertSame('active', $owned->fresh()->status);

        $untouched = $this->buy();
        $sale = Sale::latest('id')->first();
        $this->withToken($this->token)->postJson("/api/v1/pos/sales/{$sale->id}/returns", [
            'idempotency_key' => Str::uuid()->toString(), 'refund_method' => 'cash',
        ])->assertCreated();
        $this->assertSame('cancelled', $untouched->fresh()->status);
    }

    public function test_the_till_syncs_the_offers_and_the_packages_customers_can_use(): void
    {
        $owned = $this->buy();
        $cancelled = $this->buy();
        app(PackageService::class)->cancel($this->tenant, $cancelled, 'test');

        $json = $this->withToken($this->token)->getJson('/api/v1/sync/packages')->assertOk()->json();
        $this->assertTrue($json['enabled']);
        $this->assertSame(['5 brushings'], collect($json['packages'])->pluck('name')->all());
        $this->assertSame([$owned->id], collect($json['customer_packages'])->pluck('id')->all());
        $this->assertSame(5, $json['customer_packages'][0]['sessions_remaining']);
        $this->assertSame($this->service->id, $json['customer_packages'][0]['item_id']);
    }

    public function test_the_module_switch_turns_packages_off(): void
    {
        $settings = $this->tenant->refresh()->settings;
        $settings['modules']['enabled']['packages'] = false;
        $this->tenant->update(['settings' => $settings]);
        app('auth')->forgetGuards();
        $this->sell([['kind' => 'package', 'package_id' => $this->offer->id, 'quantity' => 1]], ['cash' => 400])->assertStatus(422);
        $this->assertFalse($this->withToken($this->token)->getJson('/api/v1/sync/packages')->json('enabled'));
    }

    public function test_the_web_till_sells_and_uses_packages_and_lists_a_customers_packages(): void
    {
        $this->actingAs($this->owner);
        $this->post(route('pos.store'), [
            'contact_id' => $this->customer->id,
            'cart' => json_encode([['kind' => 'package', 'package_id' => $this->offer->id, 'quantity' => 1]]),
            'cash_amount' => 400,
        ])->assertSessionHasNoErrors();
        $owned = CustomerPackage::firstOrFail();

        $this->getJson(route('packages.contact', $this->customer))->assertOk()
            ->assertJsonPath('packages.0.sessions_remaining', 5);

        $this->post(route('pos.store'), [
            'contact_id' => $this->customer->id,
            'cart' => json_encode([['id' => $this->service->id, 'quantity' => 1, 'kind' => 'package_use', 'customer_package_id' => $owned->id]]),
        ])->assertSessionHasNoErrors();
        $this->assertSame(4, $owned->fresh()->sessions_remaining);

        $sale = Sale::latest('id')->first();
        $this->post(route('sales.refund', $sale), ['refund_method' => 'cash', 'refund_reason' => 'erreur'])->assertSessionHasNoErrors();
        $this->assertSame(5, $owned->fresh()->sessions_remaining);
    }

    public function test_back_office_defines_offers_lists_customer_packages_cancels_and_adjusts(): void
    {
        $owned = $this->buy();
        $this->actingAs($this->owner);

        $this->get(route('packages'))->assertOk()->assertSee('5 brushings')->assertSee('Cliente Test');
        $this->post(route('packages.store'), ['name' => '10 coupes', 'item_id' => $this->service->id, 'sessions' => 10, 'price' => 800])->assertSessionHasNoErrors();
        $this->assertTrue(ServicePackage::where('name', '10 coupes')->exists());
        $this->put(route('packages.update', $this->offer), ['name' => '5 brushings', 'item_id' => $this->service->id, 'sessions' => 6, 'price' => 450, 'is_active' => 1])->assertSessionHasNoErrors();
        $this->assertSame(5, $owned->fresh()->sessions_total, 'what a customer bought is frozen');

        $this->post(route('customer-packages.adjust', $owned), ['sessions' => 2, 'reason' => ''])->assertSessionHasErrors('reason');
        $this->post(route('customer-packages.adjust', $owned), ['sessions' => 2, 'reason' => 'geste commercial'])->assertSessionHasNoErrors();
        $this->assertSame(7, $owned->fresh()->sessions_remaining);
        $this->post(route('customer-packages.adjust', $owned), ['sessions' => -20, 'reason' => 'trop'])->assertSessionHasErrors('sessions');

        $this->post(route('customer-packages.cancel', $owned), ['reason' => 'erreur'])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $owned->fresh()->status);
        $this->assertSame(0, $owned->fresh()->sessions_remaining);

        $this->delete(route('packages.destroy', $this->offer))->assertSessionHasNoErrors();
        $this->assertSoftDeleted($this->offer);
    }

    public function test_the_customer_record_lists_active_packages(): void
    {
        $this->buy();
        $this->actingAs($this->owner);
        $response = $this->get(route('module', ['module' => 'contacts', 'section' => 'customer-add', 'edit' => $this->customer->id]));
        $response->assertOk();
        $this->assertStringContainsString('data-contact-packages', $response->getContent());
        $this->assertStringContainsString('5 brushings', $response->getContent());
        $this->assertStringContainsString('5/5 séances', $response->getContent());
    }
}
