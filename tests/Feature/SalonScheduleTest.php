<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentBlock;
use App\Models\ScheduleException;
use App\Models\Sale;
use App\Models\ShopClosure;
use App\Models\StaffSchedule;
use App\Support\Appointments;
use App\Support\Schedules;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Working hours: a person's week, a day off, a holiday, and the agenda's
 * blocks. The public page never offers a slot outside them; a booking by
 * hand outside them needs an explicit « hors horaires ».
 */
class SalonScheduleTest extends SalonTestCase
{
    // Tuesday 3 November 2026 (the day the helpers book on).

    public function test_without_a_schedule_a_person_follows_the_shop_hours(): void
    {
        $this->openBooking([$this->ali]);

        $this->assertSame([['09:00', '11:00']], Schedules::intervals($this->tenant, $this->ali->id, '2026-11-03'));
        $this->assertSame([], Schedules::intervals($this->tenant, $this->ali->id, '2026-11-08'), 'Sunday: the shop is closed');
    }

    public function test_a_weekly_schedule_with_a_lunch_break_shapes_the_public_slots(): void
    {
        $this->openBooking([$this->ali]);
        Schedules::saveWeek($this->tenant, $this->ali->id, [
            2 => [['open' => '09:00', 'close' => '09:30'], ['open' => '10:00', 'close' => '11:00']],
        ]);

        $this->assertSame([['09:00', '09:30'], ['10:00', '11:00']], Schedules::intervals($this->tenant, $this->ali->id, '2026-11-03'));
        $this->assertSame([], Schedules::intervals($this->tenant, $this->ali->id, '2026-11-02'), 'a customised week has days off');
        $this->assertSame(['09:00', '10:00', '10:30'], $this->slots($this->ali));
        $this->assertNotContains('09:30', $this->slots($this->ali), 'the break');
    }

    public function test_a_day_off_and_a_holiday_close_the_day(): void
    {
        $this->openBooking([$this->ali, $this->sara]);
        ScheduleException::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->ali->id, 'date' => '2026-11-03', 'kind' => 'off']);

        $this->assertSame([], $this->slots($this->ali));
        $this->assertNotSame([], $this->slots($this->sara));
        $this->assertNotSame([], $this->slots(), 'peu importe: Sara works');

        ShopClosure::create(['tenant_id' => $this->tenant->id, 'starts_on' => '2026-11-03', 'ends_on' => '2026-11-04', 'label' => 'Fête']);
        $this->assertSame([], $this->slots());
        $this->assertNotContains('2026-11-03', \App\Support\OnlineBooking::openDays($this->tenant));
        $this->assertContains('2026-11-05', \App\Support\OnlineBooking::openDays($this->tenant));
    }

    public function test_custom_hours_on_one_date_replace_the_usual_ones(): void
    {
        $this->openBooking([$this->ali]);
        ScheduleException::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->ali->id, 'date' => '2026-11-03', 'kind' => 'custom', 'starts_at' => '10:00', 'ends_at' => '11:00']);

        $this->assertSame(['10:00', '10:30'], $this->slots($this->ali));
    }

    public function test_peu_importe_is_offered_only_where_someone_works(): void
    {
        $this->openBooking([$this->ali, $this->sara]);
        Schedules::saveWeek($this->tenant, $this->sara->id, [2 => [['open' => '10:00', 'close' => '11:00']]]);

        $this->assertContains('09:00', $this->slots(), 'Ali works from 9');
        $this->assertNotContains('09:00', $this->slots($this->sara));
        // Ali busy at 09:00: nobody else works then.
        $this->book(['starts_at' => $this->at('09:00'), 'staff_user_id' => $this->ali->id]);
        $this->assertNotContains('09:00', $this->slots());
    }

    public function test_a_block_removes_the_slot_and_refuses_a_manual_booking(): void
    {
        $this->openBooking([$this->ali]);
        Schedules::addBlock($this->tenant, ['user_id' => $this->ali->id, 'starts_at' => '2026-11-03 09:30', 'duration_minutes' => 30, 'kind' => 'pause'], $this->owner->id);

        $this->assertNotContains('09:30', $this->slots($this->ali));
        $this->assertContains('10:00', $this->slots($this->ali));
        try {
            Appointments::save($this->tenant, ['starts_at' => '2026-11-03 09:30', 'duration_minutes' => 30, 'staff_user_id' => $this->ali->id, 'customer_name' => 'C'], $this->owner);
            $this->fail('the pause cannot be booked');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Pause', $e->errors()['starts_at'][0]);
        }
    }

    public function test_a_block_is_not_a_booking_and_never_a_sale(): void
    {
        $sales = Sale::count();
        Schedules::addBlock($this->tenant, ['user_id' => $this->ali->id, 'starts_at' => '2026-11-03 12:00', 'duration_minutes' => 60, 'kind' => 'training'], null);

        $this->assertSame(0, Appointment::count());
        $this->assertSame($sales, Sale::count());
        $this->assertSame(1, AppointmentBlock::count());

        // Over an existing booking: refused, move it first.
        $this->book(['starts_at' => $this->at('09:00'), 'staff_user_id' => $this->ali->id]);
        $this->expectException(ValidationException::class);
        Schedules::addBlock($this->tenant, ['user_id' => $this->ali->id, 'starts_at' => '2026-11-03 09:00', 'duration_minutes' => 30, 'kind' => 'absent'], null);
    }

    public function test_a_manual_booking_outside_the_hours_needs_the_explicit_confirmation(): void
    {
        $this->openBooking([$this->ali]);
        $data = ['starts_at' => '2026-11-03 14:00', 'duration_minutes' => 30, 'staff_user_id' => $this->ali->id, 'customer_name' => 'C'];

        try {
            Appointments::save($this->tenant, $data, $this->owner);
            $this->fail('outside the hours');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('outside_hours', $e->errors());
            $this->assertStringContainsString('Hors horaires', $e->errors()['outside_hours'][0]);
        }
        $this->assertSame(0, Appointment::count());

        Appointments::save($this->tenant, $data + ['outside_hours' => true], $this->owner);
        $this->assertSame(1, Appointment::count());
    }

    public function test_a_shop_that_never_set_hours_is_not_bothered(): void
    {
        // No booking settings, no schedule: manual bookings behave as before.
        Appointments::save($this->tenant->refresh(), ['starts_at' => '2026-11-08 22:00', 'duration_minutes' => 30, 'staff_user_id' => $this->ali->id, 'customer_name' => 'C'], $this->owner);
        $this->assertSame(1, Appointment::count());
    }

    public function test_the_api_carries_the_hours_and_blocks_and_creates_a_block(): void
    {
        $this->openBooking([$this->ali]);
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $this->owner->email, 'password' => 'password', 'device_name' => 'till-'.Str::uuid(),
        ])->json('token');

        $this->withToken($token)->postJson('/api/v1/appointments/blocks', [
            'staff_user_id' => $this->ali->id, 'starts_at' => '2026-11-03 09:00', 'duration_minutes' => 30, 'kind' => 'pause',
        ])->assertCreated()->assertJsonPath('block.kind', 'pause');

        $day = $this->withToken($token)->getJson('/api/v1/appointments?date=2026-11-03')->assertOk();
        $day->assertJsonPath('blocks.0.staff_user_id', $this->ali->id)->assertJsonPath('closed', false);
        $mine = collect($day->json('schedule'))->firstWhere('staff_user_id', $this->ali->id);
        $this->assertSame([['09:00', '11:00']], $mine['intervals']);

        // Outside hours over the API: the app is told which field, and resends with outside_hours.
        $body = ['starts_at' => '2026-11-03 15:00', 'customer_name' => 'C', 'staff_user_id' => $this->ali->id];
        $this->withToken($token)->postJson('/api/v1/appointments', $body)->assertStatus(422)->assertJsonValidationErrors('outside_hours');
        $this->withToken($token)->postJson('/api/v1/appointments', $body + ['outside_hours' => true])->assertCreated();
    }

    public function test_the_back_office_saves_a_week_and_shows_the_agenda_marks(): void
    {
        $this->openBooking([$this->ali]);
        $this->actingAs($this->owner);

        $week = [];
        foreach (range(1, 7) as $d) {
            $week[$d] = $d === 2 ? [['open' => '10:00', 'close' => '12:00']] : ['off' => '1'];
        }
        $this->post(route('appointments.schedules.save', $this->ali->id), ['week' => $week])->assertSessionHasNoErrors();
        $this->assertSame([['10:00', '12:00']], Schedules::intervals($this->tenant, $this->ali->id, '2026-11-03'));
        $this->assertSame([], Schedules::intervals($this->tenant, $this->ali->id, '2026-11-04'));

        $this->post(route('appointments.schedules.save', $this->ali->id), ['follow_shop' => 1]);
        $this->assertSame(0, StaffSchedule::where('user_id', $this->ali->id)->count());

        $this->get(route('appointments.schedules'))->assertOk()->assertSee('data-schedule-form', false);

        ScheduleException::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->ali->id, 'date' => '2026-11-03', 'kind' => 'off']);
        $this->get(route('appointments', ['date' => '2026-11-03']))->assertOk()->assertSee('data-staff-off', false)->assertSee('Ne travaille pas ce jour');

        $this->post(route('appointments.blocks.store'), ['user_id' => $this->sara->id, 'date' => '2026-11-03', 'time' => '12:00', 'duration_minutes' => 60, 'kind' => 'pause'])
            ->assertSessionHasNoErrors();
        $this->get(route('appointments', ['date' => '2026-11-03']))->assertSee('data-block=', false);
    }
}
