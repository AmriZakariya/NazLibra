<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Item;
use App\Support\OnlineBooking;
use App\Support\ServiceStaff;
use Illuminate\Support\Str;

/** Who does which service, and which services the public page offers. */
class SalonServicesTest extends SalonTestCase
{
    public function test_a_service_nobody_is_assigned_to_is_done_by_everyone(): void
    {
        $this->openBooking([$this->ali, $this->sara]);

        $this->assertNull(ServiceStaff::idsFor($this->massage));
        $this->assertContains('09:00', $this->slots($this->ali));
        $this->assertContains('09:00', $this->slots($this->sara));
    }

    public function test_only_the_assigned_staff_are_offered_and_peu_importe_picks_among_them(): void
    {
        $this->openBooking([$this->ali, $this->sara]);
        ServiceStaff::set($this->tenant, $this->massage, [$this->sara->id]);

        $this->assertSame([], OnlineBooking::slots($this->tenant->refresh(), '2026-11-03', $this->ali->id, null, 30, $this->massage));
        $this->assertNotSame([], OnlineBooking::slots($this->tenant, '2026-11-03', $this->sara->id, null, 30, $this->massage));

        // Sara busy at 09:00: nobody else does it, so « peu importe » is not offered then either.
        $this->book(['starts_at' => $this->at('09:00'), 'staff_user_id' => $this->sara->id]);
        $this->assertNotContains('09:00', OnlineBooking::slots($this->tenant, '2026-11-03', null, null, 30, $this->massage));
        $this->assertContains('09:00', OnlineBooking::slots($this->tenant, '2026-11-03', null, null, 30), 'another service: Ali is free');

        // A request for « peu importe » lands on Sara.
        $appt = OnlineBooking::request($this->tenant, [
            'item_id' => $this->massage->id, 'variant_id' => $this->long->id, 'staff_user_id' => null,
            'date' => '2026-11-03', 'time' => '10:00', 'customer_name' => 'Karim', 'customer_phone' => '+212612345678', 'note' => null,
        ]);
        $this->assertSame($this->sara->id, $appt->staff_user_id);
    }

    public function test_the_public_page_hides_non_bookable_services_and_groups_by_category(): void
    {
        $this->openBooking([$this->ali]);
        $hair = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Cheveux', 'slug' => 'cheveux']);
        $this->massage->update(['category_id' => $hair->id]);
        $hidden = Item::create(['tenant_id' => $this->tenant->id, 'title' => 'Service interne', 'type' => 'service', 'status' => 'active',
            'sale_price' => 10, 'purchase_price' => 0, 'stock_quantity' => 0, 'bookable_online' => false]);

        $this->get(route('booking.show'))->assertOk()->assertSee('Massage')->assertSee('Cheveux')->assertDontSee('Service interne');
        $this->assertFalse(OnlineBooking::services($this->tenant)->contains('id', $hidden->id));
    }

    public function test_the_default_depends_on_the_activity(): void
    {
        $service = fn () => Item::create(['tenant_id' => $this->tenant->id, 'title' => 'S'.Str::random(4), 'type' => 'service', 'status' => 'active',
            'sale_price' => 10, 'purchase_price' => 0, 'stock_quantity' => 0]);
        $this->assertTrue($service()->bookable_online, 'beauty shop');

        $product = Item::create(['tenant_id' => $this->tenant->id, 'title' => 'Gel', 'type' => 'supply', 'status' => 'active',
            'sale_price' => 10, 'purchase_price' => 0, 'stock_quantity' => 1]);
        $this->assertFalse($product->bookable_online);
    }

    public function test_the_service_form_and_the_api_set_both(): void
    {
        $this->actingAs($this->owner);
        $this->get(route('catalog'))->assertOk();

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $this->owner->email, 'password' => 'password', 'device_name' => 'till-'.Str::uuid(),
        ])->json('token');
        $this->withToken($token)->putJson("/api/v1/items/{$this->massage->id}", [
            'bookable_online' => false, 'staff_user_ids' => [$this->ali->id],
        ])->assertOk();

        $this->assertFalse($this->massage->refresh()->bookable_online);
        $this->assertSame([$this->ali->id], ServiceStaff::idsFor($this->massage));

        $this->travel(5)->seconds();
        $sync = $this->withToken($token)->withHeaders(['X-Tenant-Slug' => $this->tenant->slug, 'X-Location-Id' => (string) \App\Models\Location::where('tenant_id', $this->tenant->id)->value('id')])->getJson('/api/v1/sync/items?per_page=500')->assertOk();
        $row = collect($sync->json('items'))->firstWhere('id', $this->massage->id);
        $this->assertSame([$this->ali->id], $row['staff_user_ids']);
        $this->assertFalse((bool) $row['bookable_online']);
    }
}
