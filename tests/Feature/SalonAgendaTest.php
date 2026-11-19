<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Item;
use App\Models\ItemVariant;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Appointments;
use App\Support\OnlineBooking;
use Illuminate\Validation\ValidationException;
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

    // ---- helpers for the public page ------------------------------------

    /** The public page open Mon-Sat 09:00-11:00, 30-minute slots, for [$staff]. */
    protected function openBooking(array $staff, array $extra = []): void
    {
        $hours = [];
        foreach (range(1, 6) as $d) {
            $hours[$d] = ['open' => '09:00', 'close' => '11:00'];
        }
        $hours[7] = ['closed' => true];
        OnlineBooking::save($this->tenant->refresh(), $extra + [
            'enabled' => true, 'duration' => 30, 'hours' => $hours,
            'staff_ids' => array_map(fn (User $u) => $u->id, $staff),
        ]);
        $this->tenant->refresh();
    }

    /** @return list<string> */
    protected function slots(?User $staff = null, int $duration = 30): array
    {
        return OnlineBooking::slots($this->tenant->refresh(), '2026-11-03', $staff?->id, null, $duration);
    }

    // ---- B3 staff-less bookings hold a chair -----------------------------

    public function test_a_staffless_booking_takes_the_only_barber_of_a_one_barber_shop(): void
    {
        $this->openBooking([$this->ali]);
        $this->book(['starts_at' => $this->at('09:00'), 'staff_user_id' => null]);

        $this->assertNotContains('09:00', $this->slots(), 'peu importe: nobody is left');
        $this->assertNotContains('09:00', $this->slots($this->ali), 'the one barber would get that booking');
        $this->assertContains('09:30', $this->slots());
        $this->assertNull(Appointment::first()->staff_user_id, 'it stays unassigned');
    }

    public function test_a_staffless_booking_takes_one_of_two_barbers_not_both(): void
    {
        $this->openBooking([$this->ali, $this->sara]);
        $this->book(['starts_at' => $this->at('09:00'), 'staff_user_id' => null]);

        $this->assertContains('09:00', $this->slots(), 'one barber is still free');
        $this->assertContains('09:00', $this->slots($this->ali), 'the staff-less booking can go to the other one');
        $this->assertContains('09:00', $this->slots($this->sara));

        // A second staff-less booking: now both are spoken for.
        $this->book(['starts_at' => $this->at('09:00'), 'staff_user_id' => null]);
        $this->assertNotContains('09:00', $this->slots());
        $this->assertNotContains('09:00', $this->slots($this->ali));
    }

    public function test_a_staffless_booking_forces_the_choice_when_one_barber_is_named_elsewhere(): void
    {
        $this->openBooking([$this->ali, $this->sara]);
        $this->book(['starts_at' => $this->at('09:00'), 'staff_user_id' => $this->ali->id]);
        $this->book(['starts_at' => $this->at('09:00'), 'staff_user_id' => null]);

        // Ali is named, the staff-less booking can only be Sara's.
        $this->assertNotContains('09:00', $this->slots());
        $this->assertNotContains('09:00', $this->slots($this->sara));
        $this->assertNotContains('09:00', $this->slots($this->ali));
    }

    // ---- B4 a pending request holds the chair ----------------------------

    protected function manual(array $data): Appointment
    {
        return Appointments::save($this->tenant->refresh(), $data + ['customer_name' => 'Client', 'duration_minutes' => 30], $this->owner);
    }

    public function test_a_manual_booking_over_a_pending_request_is_refused_with_a_clear_message(): void
    {
        $this->openBooking([$this->ali, $this->sara]);
        $this->book(['starts_at' => $this->at('10:00'), 'staff_user_id' => $this->ali->id, 'status' => 'requested']);

        try {
            $this->manual(['starts_at' => '2026-11-03 10:00', 'staff_user_id' => $this->ali->id]);
            $this->fail('the request holds the chair');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('demande en ligne', $e->errors()['starts_at'][0]);
        }
        // Another barber is fine.
        $this->manual(['starts_at' => '2026-11-03 10:00', 'staff_user_id' => $this->sara->id]);
        $this->assertSame(2, Appointment::count());
    }

    public function test_confirming_a_request_ignores_itself(): void
    {
        $this->openBooking([$this->ali, $this->sara]);
        $request = $this->book(['starts_at' => $this->at('10:00'), 'staff_user_id' => $this->ali->id, 'status' => 'requested']);

        $confirmed = Appointments::save($this->tenant->refresh(), ['status' => 'booked'], $this->owner, $request);

        $this->assertSame('booked', $confirmed->status);
    }

    public function test_a_manual_booking_cannot_steal_the_chair_a_staffless_booking_needs(): void
    {
        $this->openBooking([$this->ali]);
        $this->book(['starts_at' => $this->at('09:00'), 'staff_user_id' => null]);

        $this->expectException(ValidationException::class);
        $this->manual(['starts_at' => '2026-11-03 09:00', 'staff_user_id' => $this->ali->id]);
    }

    public function test_a_staffless_booking_leaves_room_when_another_barber_can_take_it(): void
    {
        $this->openBooking([$this->ali, $this->sara]);
        $this->book(['starts_at' => $this->at('09:00'), 'staff_user_id' => null]);

        $this->manual(['starts_at' => '2026-11-03 09:00', 'staff_user_id' => $this->ali->id]);
        $this->assertSame(2, Appointment::count());
        // Now the staff-less one can only be Sara's: Sara is taken for it.
        $this->expectException(ValidationException::class);
        $this->manual(['starts_at' => '2026-11-03 09:00', 'staff_user_id' => $this->sara->id]);
    }
}
