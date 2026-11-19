<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Item;
use App\Models\ItemVariant;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * The salon agenda: « Encaisser » keeps what was booked, staff-less and
 * pending bookings hold the chairs, online requests find their customer,
 * schedules, services per staff and the walk-in queue.
 */
class SalonAgendaTest extends TestCase
{
    use RefreshDatabase;

    protected const TZ = 'Africa/Casablanca';

    protected Tenant $tenant;

    protected User $owner;

    protected User $ali;

    protected User $sara;

    protected Item $massage;

    protected ItemVariant $long;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->tenant = Tenant::first();
        $settings = $this->tenant->settings ?? [];
        $settings['company_profile']['business_mode'] = 'beauty';
        $this->tenant->update(['settings' => $settings, 'timezone' => self::TZ]);
        $this->owner = User::first();
        $this->ali = $this->staff('Ali Barbier', 'ali@example.test');
        $this->sara = $this->staff('Sara Soins', 'sara@example.test');

        $this->massage = Item::create([
            'tenant_id' => $this->tenant->id, 'title' => 'Massage', 'type' => 'service', 'status' => 'active',
            'sale_price' => 100, 'purchase_price' => 0, 'stock_quantity' => 0, 'duration_minutes' => 30,
        ]);
        $this->long = ItemVariant::create([
            'tenant_id' => $this->tenant->id, 'item_id' => $this->massage->id, 'name' => '1 h', 'attributes' => [],
            'sale_price' => 400, 'sale_price_override' => 400, 'purchase_price' => 0, 'is_active' => true,
            'duration_minutes' => 60, 'sort_order' => 0,
        ]);
        $this->massage->update(['variant_count' => 1]);

        // Monday 2 November 2026, 08:00 in the shop.
        $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00', self::TZ));
        RateLimiter::clear('booking:'.$this->tenant->id.':127.0.0.1');
    }

    protected function staff(string $name, string $email): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => Hash::make('password')]);
        $this->tenant->users()->attach($user->id, ['role' => 'cashier']);

        return $user;
    }

    protected function book(array $attributes): Appointment
    {
        return Appointment::create($attributes + [
            'tenant_id' => $this->tenant->id, 'customer_name' => 'Client', 'duration_minutes' => 30, 'status' => 'booked',
        ]);
    }

    protected function at(string $time, string $date = '2026-11-03'): CarbonImmutable
    {
        return CarbonImmutable::parse($date.' '.$time, self::TZ)->utc();
    }

    // ---- B1 « Encaisser » keeps the booked variant ----------------------

    public function test_cashing_a_booking_keeps_its_variant_price_and_staff(): void
    {
        $booking = $this->book([
            'item_id' => $this->massage->id, 'variant_id' => $this->long->id, 'staff_user_id' => $this->sara->id,
            'starts_at' => $this->at('10:00'), 'duration_minutes' => 60, 'service_name' => 'Massage — 1 h',
        ]);

        $line = $this->actingAs($this->owner)->get(route('pos', ['appointment' => $booking->id]))
            ->assertOk()->viewData('sourceCart')->first();

        $this->assertSame($this->long->id, $line['variant_id']);
        $this->assertSame(400.0, (float) $line['price'], 'the variant price, not the service base price');
        $this->assertSame($this->sara->id, $line['staff_user_id']);

        // The till posts it back: the sale carries the variant and closes the booking.
        $this->post(route('pos.store'), [
            'cart' => json_encode([[
                'id' => $this->massage->id, 'variant_id' => $line['variant_id'], 'quantity' => 1,
                'price' => 400, 'staff_user_id' => $this->sara->id,
            ]]),
            'cash_amount' => 400, 'appointment_id' => $booking->id,
        ])->assertSessionHasNoErrors();

        $sale = Sale::latest('id')->firstOrFail();
        $this->assertSame($this->long->id, $sale->items()->sole()->variant_id);
        $booking->refresh();
        $this->assertSame('done', $booking->status);
        $this->assertSame($sale->id, $booking->sale_id);
    }
}
