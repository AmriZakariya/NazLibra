<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Item;
use App\Models\Tenant;
use App\Models\User;
use App\Support\OnlineBooking;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The public booking page: a client asks for a free slot inside opening
 * hours, the request waits "à confirmer", and the shop answers it.
 */
class OnlineBookingTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Africa/Casablanca';

    private Tenant $tenant;
    private User $owner;
    private User $barber;
    private Item $cut;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->tenant = Tenant::first();
        $settings = $this->tenant->settings ?? [];
        $settings['company_profile']['business_mode'] = 'barber';
        $this->tenant->update(['settings' => $settings, 'timezone' => self::TZ]);
        $this->owner = User::first();
        $this->barber = User::create(['name' => 'Ali Barbier', 'email' => 'ali@example.test', 'password' => Hash::make('password')]);
        $this->tenant->users()->attach($this->barber->id, ['role' => 'cashier']);
        $this->cut = Item::create([
            'tenant_id' => $this->tenant->id, 'title' => 'Coupe homme', 'type' => 'service',
            'status' => 'active', 'sale_price' => 60, 'purchase_price' => 0, 'stock_quantity' => 0,
        ]);

        // Monday 2 November 2026, 08:00 in the shop.
        $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00', self::TZ));
        RateLimiter::clear('booking:'.$this->tenant->id.':127.0.0.1');
    }

    private function open(array $overrides = []): void
    {
        OnlineBooking::save($this->tenant->refresh(), $overrides + [
            'enabled' => true,
            'duration' => 30,
            'hours' => [
                1 => ['open' => '09:00', 'close' => '11:00'],
                2 => ['open' => '09:00', 'close' => '11:00'],
                3 => ['closed' => true],
                4 => ['open' => '09:00', 'close' => '11:00'],
                5 => ['open' => '09:00', 'close' => '11:00'],
                6 => ['open' => '09:00', 'close' => '11:00'],
                7 => ['closed' => true],
            ],
            'staff_ids' => [$this->barber->id],
        ]);
        $this->tenant->refresh();
    }

    private function ask(array $data = []): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('booking.store'), $data + [
            'item_id' => $this->cut->id,
            'staff_user_id' => '',
            'date' => '2026-11-02',
            'time' => '09:30',
            'customer_name' => 'Youssef',
            'customer_phone' => '0612345678',
        ]);
    }

    public function test_the_page_is_closed_until_the_shop_switches_it_on(): void
    {
        $this->get(route('booking.show'))->assertStatus(503)->assertSee(__('booking.closed_title'));
        $this->ask()->assertStatus(503);
        $this->assertSame(0, Appointment::count());

        $this->open();
        $this->get(route('booking.show'))->assertOk()->assertSee('Coupe homme');
    }

    public function test_slots_follow_opening_hours_and_skip_closed_days(): void
    {
        $this->open();

        $this->assertSame(['09:00', '09:30', '10:00', '10:30'], OnlineBooking::slots($this->tenant, '2026-11-02', null));
        // Wednesday is closed, and is not even offered as a day.
        $this->assertSame([], OnlineBooking::slots($this->tenant, '2026-11-04', null));
        $this->assertNotContains('2026-11-04', OnlineBooking::openDays($this->tenant));
        $this->assertContains('2026-11-05', OnlineBooking::openDays($this->tenant));
    }

    public function test_a_slot_already_gone_by_is_not_offered(): void
    {
        $this->open();
        $this->travelTo(CarbonImmutable::parse('2026-11-02 09:40', self::TZ));

        $this->assertSame(['10:00', '10:30'], OnlineBooking::slots($this->tenant, '2026-11-02', null));
    }

    public function test_a_booked_chair_or_a_pending_request_hides_the_slot(): void
    {
        $this->open();
        Appointment::create([
            'tenant_id' => $this->tenant->id, 'staff_user_id' => $this->barber->id, 'customer_name' => 'Déjà là',
            'starts_at' => CarbonImmutable::parse('2026-11-02 09:00', self::TZ)->utc(), 'duration_minutes' => 45, 'status' => 'booked',
        ]);
        $this->ask(['time' => '10:30'])->assertRedirect();

        // 09:00–09:45 overlaps 09:00 and 09:30; the request holds 10:30.
        $this->assertSame(['10:00'], OnlineBooking::slots($this->tenant, '2026-11-02', $this->barber->id));
        $this->getJson(route('booking.slots', ['date' => '2026-11-02', 'staff' => $this->barber->id]))
            ->assertOk()->assertExactJson(['slots' => ['10:00']]);
    }

    public function test_a_request_arrives_to_be_confirmed_with_the_first_free_barber(): void
    {
        $this->open();

        $this->ask()->assertRedirect(route('booking.show'))->assertSessionHas('booking_sent');
        $this->get(route('booking.show'))->assertSee(__('booking.sent_title'));

        $request = Appointment::sole();
        $this->assertSame('requested', $request->status);
        $this->assertSame($this->barber->id, $request->staff_user_id, '"peu importe" picks whoever is free');
        $this->assertSame('Coupe homme', $request->service_name);
        $this->assertSame('+212612345678', $request->getRawOriginal('customer_phone'));
        $this->assertSame('2026-11-02 09:30', $request->starts_at->setTimezone(self::TZ)->format('Y-m-d H:i'));
    }

    public function test_a_taken_slot_a_closed_day_or_an_unknown_service_is_refused(): void
    {
        $this->open();
        $this->ask()->assertRedirect();

        $this->ask()->assertSessionHasErrors('time');
        $this->ask(['date' => '2026-11-04'])->assertSessionHasErrors('time');
        $this->ask(['time' => '09:00', 'item_id' => 999999])->assertSessionHasErrors('item_id');
        $this->ask(['time' => '09:00', 'staff_user_id' => $this->owner->id])->assertSessionHasErrors('time');
        $this->assertSame(1, Appointment::count());
    }

    public function test_a_robot_filling_the_hidden_field_books_nothing(): void
    {
        $this->open();

        $this->ask(['website' => 'http://spam.example'])->assertRedirect()->assertSessionHas('booking_sent');
        $this->assertSame(0, Appointment::count());
    }

    public function test_one_device_cannot_flood_the_agenda(): void
    {
        $this->open();
        foreach (['09:00', '09:30', '10:00', '10:30'] as $time) {
            $this->ask(['time' => $time])->assertSessionHasNoErrors();
        }
        $this->ask(['date' => '2026-11-03', 'time' => '09:00'])->assertSessionHasNoErrors();

        $this->ask(['date' => '2026-11-03', 'time' => '09:30'])->assertSessionHasErrors('customer_name');
        $this->assertSame(5, Appointment::count());
    }

    public function test_the_shop_confirms_or_declines_and_the_app_sees_the_requests(): void
    {
        $this->open();
        $this->ask()->assertRedirect();
        $this->ask(['time' => '10:00'])->assertRedirect();
        [$first, $second] = Appointment::orderBy('starts_at')->get()->all();

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $this->owner->email, 'password' => 'password', 'device_name' => 'till-'.Str::uuid(),
        ])->json('token');
        $this->withToken($token)->getJson('/api/v1/appointments/requests')
            ->assertOk()
            ->assertJsonCount(2, 'appointments')
            ->assertJsonPath('appointments.0.status', 'requested')
            ->assertJsonPath('booking_url', route('booking.show'));

        $confirmed = $this->withToken($token)
            ->patchJson("/api/v1/appointments/{$first->id}/status", ['status' => 'booked'])
            ->assertOk()->json('appointment');
        $this->assertStringContainsString('est%20confirm', $confirmed['confirmation_url']);
        $this->withToken($token)->patchJson("/api/v1/appointments/{$second->id}/status", ['status' => 'cancelled'])->assertOk();

        $this->withToken($token)->getJson('/api/v1/appointments/requests')->assertJsonCount(0, 'appointments');
        // Declined, the slot is free again for the next client.
        $this->assertContains('10:00', OnlineBooking::slots($this->tenant, '2026-11-02', null));
    }

    public function test_the_web_agenda_shows_requests_and_saves_the_booking_settings(): void
    {
        $this->open();
        $this->ask()->assertRedirect();
        $request = Appointment::sole();

        $this->actingAs($this->owner)->get(route('appointments', ['date' => '2026-11-02']))
            ->assertOk()->assertSee('1 demande à confirmer')->assertSee('Youssef');

        $this->actingAs($this->owner)
            ->patch(route('appointments.status', $request), ['status' => 'booked'])
            ->assertSessionHas('confirmation_url');
        $this->assertSame('booked', $request->fresh()->status);

        $this->actingAs($this->owner)->post(route('appointments.booking'), [
            'enabled' => '0', 'duration' => 45, 'staff_ids_sent' => '1',
            'hours' => [1 => ['open' => '10:00', 'close' => '18:00'], 7 => ['closed' => '1']],
        ])->assertRedirect();
        $saved = OnlineBooking::settings($this->tenant->refresh());
        $this->assertFalse($saved['enabled']);
        $this->assertSame(45, $saved['duration']);
        $this->assertSame(['10:00', '18:00'], $saved['hours'][1]);
        $this->assertNull($saved['hours'][7]);
        $this->assertSame([], $saved['staff_ids'], 'no box ticked is nobody');
    }
}
