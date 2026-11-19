<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Contact;
use App\Models\Sale;
use App\Support\Appointments;
use Illuminate\Validation\ValidationException;

/**
 * The salon agenda: « Encaisser » keeps what was booked, staff-less and
 * pending bookings hold the chairs, online requests find their customer.
 */
class SalonAgendaTest extends SalonTestCase
{
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

    // ---- B5 an online request finds (or opens) the customer's file ------

    protected function ask(array $data = []): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('booking.store'), $data + [
            'item_id' => $this->massage->id, 'variant_id' => $this->long->id, 'staff_user_id' => '',
            'date' => '2026-11-03', 'time' => '09:00', 'customer_name' => 'Karim', 'customer_phone' => '0612345678',
        ]);
    }

    public function test_an_online_request_opens_a_customer_file_with_the_e164_phone(): void
    {
        $this->openBooking([$this->ali]);
        $this->ask()->assertSessionHasNoErrors();

        $contact = Contact::where('tenant_id', $this->tenant->id)->where('name', 'Karim')->sole();
        $this->assertSame('+212612345678', $contact->getRawOriginal('phone'));
        $this->assertSame($contact->id, Appointment::sole()->contact_id);
    }

    public function test_an_online_request_finds_the_customer_by_phone_not_by_name(): void
    {
        $this->openBooking([$this->ali]);
        $known = Contact::create(['tenant_id' => $this->tenant->id, 'kind' => 'client', 'name' => 'Karim B.', 'phone' => '+212 6 12 34 56 78']);
        $namesake = Contact::create(['tenant_id' => $this->tenant->id, 'kind' => 'client', 'name' => 'Karim', 'phone' => '+212600000009']);
        $other = \App\Models\Tenant::create(['name' => 'Autre', 'slug' => 'autre-'.uniqid()]);
        Contact::create(['tenant_id' => $other->id, 'kind' => 'client', 'name' => 'Karim', 'phone' => '+212611111111']);

        $before = Contact::count();
        $this->ask()->assertSessionHasNoErrors();
        $this->assertSame($known->id, Appointment::sole()->contact_id, 'same phone, written differently: the same customer');
        $this->assertSame($before, Contact::count(), 'no new file');

        // Same name, another number: a new customer, the namesake is untouched.
        $this->ask(['time' => '10:00', 'customer_phone' => '0611111111'])->assertSessionHasNoErrors();
        $second = Appointment::latest('id')->first();
        $this->assertNotSame($namesake->id, $second->contact_id);
        $this->assertNotSame($known->id, $second->contact_id);
        $this->assertSame('+212611111111', Contact::find($second->contact_id)->getRawOriginal('phone'));
        $this->assertSame($before + 1, Contact::count(), 'the other shop\'s customer with that number is not ours');
    }
}
