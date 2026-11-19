<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Contact;
use App\Models\QueueEntry;
use App\Support\Appointments;
use App\Support\OnlineBooking;
use App\Support\ServiceStaff;
use App\Support\WalkInQueue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** The walk-in queue: ordered, estimated, called per barber, seated into a booking, shown on a public screen. */
class SalonQueueTest extends SalonTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $settings = $this->tenant->settings ?? [];
        $settings['modules']['enabled'] = ['appointments' => true, 'queue' => true];
        $this->tenant->update(['settings' => $settings]);
        $this->tenant->refresh();
        // The tests run on Monday 2 Nov 08:00; the shop opens at 09:00 and the barbers work from 08:00.
        $this->openBooking([$this->ali, $this->sara]);
        \App\Support\Schedules::saveWeek($this->tenant, $this->ali->id, array_fill(1, 7, [['open' => '08:00', 'close' => '19:00']]));
        \App\Support\Schedules::saveWeek($this->tenant, $this->sara->id, array_fill(1, 7, [['open' => '08:00', 'close' => '19:00']]));
    }

    public function test_the_module_is_on_for_barbers_and_off_for_beauty_by_default(): void
    {
        $fresh = fn (string $mode) => tap(\App\Models\Tenant::first(), function ($t) use ($mode) {
            $s = $t->settings ?? [];
            unset($s['modules']);
            $s['company_profile']['business_mode'] = $mode;
            $t->update(['settings' => $s]);
        })->refresh();

        $this->assertTrue(\App\Support\AppModules::enabled($fresh('barber'), 'queue'));
        $this->assertFalse(\App\Support\AppModules::enabled($fresh('beauty'), 'queue'));
    }

    public function test_walk_ins_are_numbered_in_order_and_matched_to_a_customer_by_phone(): void
    {
        $known = Contact::create(['tenant_id' => $this->tenant->id, 'kind' => 'client', 'name' => 'Karim B.', 'phone' => '+212612345678']);
        $a = WalkInQueue::add($this->tenant, ['customer_name' => 'Karim', 'customer_phone' => '0612345678']);
        $b = WalkInQueue::add($this->tenant, ['customer_name' => 'Nadia']);

        $this->assertSame([1, 2], [$a->ticket_number, $b->ticket_number]);
        $this->assertSame($known->id, $a->contact_id, 'by phone');
        $this->assertNull($b->contact_id, 'no phone, no file');
        $this->assertSame([$a->id, $b->id], WalkInQueue::board($this->tenant)->pluck('entry.id')->all());
    }

    public function test_the_estimated_wait_counts_durations_ahead_and_busy_barbers(): void
    {
        // Only Ali works today.
        \App\Support\Schedules::saveWeek($this->tenant, $this->sara->id, array_fill(1, 7, []));
        $this->book(['starts_at' => $this->at('07:45', '2026-11-02'), 'staff_user_id' => $this->ali->id, 'duration_minutes' => 30, 'status' => 'arrived']);

        $first = WalkInQueue::add($this->tenant, ['customer_name' => 'A']);
        $second = WalkInQueue::add($this->tenant, ['customer_name' => 'B', 'item_id' => $this->massage->id, 'variant_id' => $this->long->id]);
        $third = WalkInQueue::add($this->tenant, ['customer_name' => 'C']);

        $board = WalkInQueue::board($this->tenant)->keyBy('entry.id');
        $this->assertSame(15, $board[$first->id]['wait_minutes'], 'Ali is busy until 08:15');
        $this->assertSame(45, $board[$second->id]['wait_minutes'], '+ the first one\'s 30 minutes');
        $this->assertSame(105, $board[$third->id]['wait_minutes'], '+ the one-hour massage');
    }

    public function test_calling_the_next_respects_preference_and_the_service_a_barber_does(): void
    {
        ServiceStaff::set($this->tenant, $this->massage, [$this->sara->id]);
        $wantsAli = WalkInQueue::add($this->tenant, ['customer_name' => 'A', 'staff_user_id' => $this->ali->id]);
        $massage = WalkInQueue::add($this->tenant, ['customer_name' => 'B', 'item_id' => $this->massage->id]);
        $anyone = WalkInQueue::add($this->tenant, ['customer_name' => 'C']);

        $forSara = WalkInQueue::callNext($this->tenant, $this->sara->id);
        $this->assertSame($massage->id, $forSara->id, 'A asked for Ali; the massage is Sara\'s');
        $forAli = WalkInQueue::callNext($this->tenant, $this->ali->id);
        $this->assertSame($wantsAli->id, $forAli->id);
        WalkInQueue::leave($this->tenant, $forAli->refresh());
        $this->assertSame($anyone->id, WalkInQueue::callNext($this->tenant, $this->ali->id)?->id, 'then the one without a preference');
    }

    public function test_a_barber_cannot_call_two_at_once_and_nobody_waiting_is_not_an_error(): void
    {
        $this->assertNull(WalkInQueue::callNext($this->tenant, $this->ali->id));
        WalkInQueue::add($this->tenant, ['customer_name' => 'A']);
        WalkInQueue::add($this->tenant, ['customer_name' => 'B']);
        WalkInQueue::callNext($this->tenant, $this->ali->id);

        $this->expectException(ValidationException::class);
        WalkInQueue::callNext($this->tenant, $this->ali->id);
    }

    public function test_a_called_walk_in_holds_the_chair_online_and_in_the_agenda(): void
    {
        $entry = WalkInQueue::add($this->tenant, ['customer_name' => 'A']);
        // Called at 08:00 for 30 minutes: Ali is held until 08:30.
        WalkInQueue::callNext($this->tenant, $this->ali->id);
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-02 08:05', self::TZ));

        try {
            Appointments::save($this->tenant, ['starts_at' => '2026-11-02 08:10', 'duration_minutes' => 30, 'staff_user_id' => $this->ali->id, 'customer_name' => 'C'], $this->owner);
            $this->fail('the walk-in holds Ali');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('file d’attente', $e->errors()['starts_at'][0]);
        }
        // Sara is free; and online, 08:00 Ali would be refused the same way.
        Appointments::save($this->tenant, ['starts_at' => '2026-11-02 08:10', 'duration_minutes' => 30, 'staff_user_id' => $this->sara->id, 'customer_name' => 'C'], $this->owner);

        $this->openBooking([$this->ali]);
        $this->assertNotContains('08:00', OnlineBooking::slots($this->tenant->refresh(), '2026-11-02', $this->ali->id, \Carbon\CarbonImmutable::parse('2026-11-02 07:00', self::TZ), 30));

        // Once he leaves, the chair is free again.
        WalkInQueue::leave($this->tenant, $entry->refresh());
        $this->assertSame(0, WalkInQueue::holds($this->tenant, \Carbon\CarbonImmutable::parse('2026-11-02', self::TZ))->count());
    }

    public function test_seating_creates_the_booking_to_cash_out_and_paying_it_finishes_the_entry(): void
    {
        $entry = WalkInQueue::add($this->tenant, ['customer_name' => 'A', 'item_id' => $this->massage->id, 'variant_id' => $this->long->id]);
        WalkInQueue::callNext($this->tenant, $this->sara->id);
        $entry = WalkInQueue::seat($this->tenant, $entry->refresh(), $this->owner);

        $this->assertSame('seated', $entry->status);
        $appointment = Appointment::findOrFail($entry->appointment_id);
        $this->assertSame(['arrived', $this->sara->id, $this->long->id, 60], [$appointment->status, $appointment->staff_user_id, $appointment->variant_id, $appointment->duration_minutes]);

        // « Encaisser » is the booking's own path (variant, price, staff).
        $line = $this->actingAs($this->owner)->get(route('pos', ['appointment' => $appointment->id]))->assertOk()->viewData('sourceCart')->first();
        $this->assertSame($this->long->id, $line['variant_id']);

        $appointment->update(['status' => 'done']);
        $this->assertSame([], WalkInQueue::board($this->tenant)->all());
        $this->assertSame('done', $entry->refresh()->status);
    }

    public function test_the_public_screen_shows_numbers_first_names_and_waits_only(): void
    {
        WalkInQueue::add($this->tenant, ['customer_name' => 'Karim Benali', 'customer_phone' => '0612345678', 'item_id' => $this->massage->id]);
        $token = WalkInQueue::tvToken($this->tenant);

        $json = $this->getJson(route('queue.tv.data', ['token' => $token]))->assertOk();
        $row = $json->json('entries.0');
        $this->assertSame(['ticket', 'first_name', 'status', 'wait_minutes'], array_keys($row));
        $this->assertSame('Karim', $row['first_name']);
        $this->assertStringNotContainsString('Benali', $json->getContent());
        $this->assertStringNotContainsString('0612345678', $json->getContent());
        $this->assertStringNotContainsString('Massage', $json->getContent());

        $this->get(route('queue.tv', ['token' => $token]))->assertOk();
        $this->get(route('queue.tv', ['token' => 'wrong']))->assertNotFound();

        // A new address kills the old one.
        $new = WalkInQueue::tvToken($this->tenant->refresh(), regenerate: true);
        $this->assertNotSame($token, $new);
        $this->get(route('queue.tv', ['token' => $token]))->assertNotFound();
    }

    public function test_the_back_office_page_and_the_api_run_the_queue(): void
    {
        $this->actingAs($this->owner);
        $this->post(route('queue.store'), ['customer_name' => 'Karim'])->assertSessionHasNoErrors();
        $this->get(route('queue'))->assertOk()->assertSee('data-queue-entry', false)->assertSee('Appeler le suivant');
        $this->post(route('queue.call'), ['staff_user_id' => $this->ali->id])->assertSessionHasNoErrors();
        $this->assertSame('called', QueueEntry::firstOrFail()->status);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $this->owner->email, 'password' => 'password', 'device_name' => 'till-'.Str::uuid(),
        ])->json('token');
        $this->withToken($token)->postJson('/api/v1/queue', ['customer_name' => 'Nadia'])->assertCreated()->assertJsonPath('entry.ticket', 2);
        $id = QueueEntry::firstOrFail()->id;
        $seat = $this->withToken($token)->postJson("/api/v1/queue/{$id}/seat")->assertOk();
        $this->assertSame('arrived', $seat->json('appointment.status'));
        $board = $this->withToken($token)->getJson('/api/v1/queue')->assertOk();
        $this->assertSame(2, count($board->json('entries')));
        $this->withToken($token)->postJson('/api/v1/queue/next', ['staff_user_id' => $this->sara->id])->assertOk()->assertJsonPath('entry.customer_name', 'Nadia');
    }

    public function test_the_module_switch_closes_everything(): void
    {
        $settings = $this->tenant->settings;
        $settings['modules']['enabled']['queue'] = false;
        $this->tenant->update(['settings' => $settings]);
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $this->owner->email, 'password' => 'password', 'device_name' => 'till-'.Str::uuid(),
        ])->json('token');

        $this->actingAs($this->owner)->get(route('queue'))->assertNotFound();
        $this->withToken($token)->getJson('/api/v1/queue')->assertStatus(403);
    }
}
