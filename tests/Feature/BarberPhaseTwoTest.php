<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\SaleController;
use App\Models\Appointment;
use App\Models\CashRegisterMovement;
use App\Models\Contact;
use App\Models\Item;
use App\Models\Location;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\TipPayout;
use App\Models\User;
use App\Services\CashRegisterService;
use App\Support\AppModules;
use App\Support\StaffReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tips that are owed and paid out, appointments, and customer notes.
 */
class BarberPhaseTwoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Location $location;
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
        $this->tenant->update(['settings' => $settings]);
        $this->location = Location::where('tenant_id', $this->tenant->id)->first();
        $this->owner = User::first();
        $this->barber = User::create(['name' => 'Ali Barbier', 'email' => 'ali@example.test', 'password' => Hash::make('password')]);
        $this->tenant->users()->attach($this->barber->id, ['role' => 'cashier']);
        $this->cut = Item::create([
            'tenant_id' => $this->tenant->id, 'title' => 'Coupe homme', 'type' => 'service',
            'status' => 'active', 'sale_price' => 60, 'purchase_price' => 0, 'stock_quantity' => 0,
        ]);
    }

    private function token(): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $this->owner->email, 'password' => 'password', 'device_name' => 'till-'.Str::uuid(),
        ])->json('token');
    }

    private function sell(array $payments, float $tip, array $extra = []): array
    {
        return $this->withToken($this->token())->postJson('/api/v1/pos/sales', [
            'idempotency_key' => Str::uuid()->toString(),
            'location_id' => $this->location->id,
            'items' => [['item_id' => $this->cut->id, 'quantity' => 1, 'staff_user_id' => $this->barber->id]],
            'payments' => $payments + ['cash' => 0, 'card' => 0, 'transfer' => 0, 'advance' => 0],
            'tip' => $tip,
        ] + $extra)->assertCreated()->json('sale');
    }

    // ── Tips ──────────────────────────────────────────────────────────────────

    public function test_how_a_tip_was_paid_is_worked_out_from_the_payments(): void
    {
        $this->assertSame(['card', 0.0], SaleController::tipSplit(['cash' => 60, 'card' => 15], 60, 15));
        $this->assertSame(['cash', 15.0], SaleController::tipSplit(['cash' => 100, 'card' => 0], 60, 15));
        $this->assertSame(['card', 0.0], SaleController::tipSplit(['cash' => 0, 'card' => 75], 60, 15));
        // Card covers 10 beyond the total, cash the other 5.
        $this->assertSame(['mixed', 5.0], SaleController::tipSplit(['cash' => 50, 'card' => 20], 60, 15));
        $this->assertSame([null, 0.0], SaleController::tipSplit(['cash' => 60], 60, 0));
    }

    public function test_a_card_tip_is_recorded_as_such_and_not_as_drawer_cash(): void
    {
        $sale = $this->sell(['cash' => 60, 'card' => 15], 15);

        $this->assertSame('card', $sale['tip_method']);
        $this->assertSame(0.0, (float) data_get($sale, 'metadata.cash_register.tip_cash'));
        $this->assertSame(60.0, (float) data_get($sale, 'metadata.cash_register.cash_drawer_in'));
    }

    public function test_tips_stay_owed_until_paid_out_and_a_cash_payout_leaves_the_drawer(): void
    {
        $session = app(CashRegisterService::class)->openSession($this->tenant)
            ?? \App\Models\CashRegisterSession::create([
                'tenant_id' => $this->tenant->id,
                'store_key' => (string) (data_get($this->tenant->settings, 'current_store')
                    ?: Location::where('tenant_id', $this->tenant->id)->where('is_default', true)->value('id')),
                'number' => 'CR-T1', 'status' => 'open', 'opening_amount' => 100, 'expected_cash_amount' => 100, 'opened_at' => now(),
            ]);
        $this->sell(['cash' => 80], 20);
        $this->sell(['cash' => 70], 10);

        $this->assertSame(30.0, StaffReport::tipsOwed($this->tenant->id)[$this->barber->id]);

        $this->withToken($this->token())->postJson('/api/v1/tips/payouts', [
            'staff_user_id' => $this->barber->id, 'amount' => 25,
        ])->assertCreated()->assertJsonPath('still_owed', 5);

        $this->assertSame(5.0, StaffReport::tipsOwed($this->tenant->id)[$this->barber->id]);
        $movement = CashRegisterMovement::where('type', 'tip_payout')->first();
        $this->assertNotNull($movement, 'paid in cash, so out of the drawer');
        $this->assertSame('out', $movement->direction);
        $this->assertSame((float) $session->fresh()->expected_cash_amount, (float) $movement->balance_after);
    }

    public function test_more_than_is_owed_cannot_be_paid_out(): void
    {
        $this->sell(['cash' => 70], 10);

        $this->withToken($this->token())->postJson('/api/v1/tips/payouts', [
            'staff_user_id' => $this->barber->id, 'amount' => 50,
        ])->assertStatus(422);
        $this->assertSame(0, TipPayout::count());
    }

    public function test_a_refund_gives_back_the_service_but_the_barber_keeps_the_tip(): void
    {
        $this->sell(['cash' => 80], 20);
        $sale = Sale::latest('id')->first();
        $sale->update(['status' => 'refunded']);

        $row = StaffReport::rows(Sale::with('items.item')->get())->firstWhere('user_id', $this->barber->id);

        $this->assertSame(0.0, $row['revenue'], 'no commission on money given back');
        $this->assertSame(20.0, $row['tips']);
        $this->assertSame(20.0, StaffReport::tipsOwed($this->tenant->id)[$this->barber->id]);
    }

    public function test_tips_have_their_own_switch_reaching_the_till(): void
    {
        $this->assertTrue(StaffReport::tipsEnabled($this->tenant), 'on by default for a barber shop');

        $settings = $this->tenant->settings;
        $settings['pos']['tips_enabled'] = false;
        $this->tenant->update(['settings' => $settings]);

        $json = $this->withToken($this->token())->getJson('/api/v1/sync/settings')->json();
        $this->assertFalse($json['tips_enabled']);
        $this->assertTrue($json['ask_staff'], 'asking who did the cut is a separate switch');
    }

    public function test_the_web_till_takes_a_tip_and_a_barber_too(): void
    {
        $this->actingAs($this->owner)->post(route('pos.store'), [
            '_idempotency_key' => (string) Str::uuid(),
            'cart' => json_encode([['id' => $this->cut->id, 'quantity' => 1, 'price' => 60, 'staff_user_id' => $this->barber->id]]),
            'cash_amount' => 100,
            'tip' => 15,
        ])->assertSessionHasNoErrors();

        $sale = Sale::latest('id')->with('items')->first();
        $this->assertSame(60.0, (float) $sale->total_amount);
        $this->assertSame(15.0, (float) $sale->tip_amount);
        $this->assertSame($this->barber->id, $sale->tip_user_id);
        $this->assertSame($this->barber->id, $sale->items->first()->staff_user_id);
        $this->assertSame(25.0, (float) data_get($sale->metadata, 'change_amount'), '100 − 60 − 15');
    }

    // ── Appointments ──────────────────────────────────────────────────────────

    public function test_the_module_is_on_for_a_barber_and_off_for_a_bookshop(): void
    {
        $this->assertTrue(AppModules::enabled($this->tenant, 'appointments'));

        $settings = $this->tenant->settings;
        $settings['company_profile']['business_mode'] = 'library';
        $this->tenant->update(['settings' => $settings]);
        $this->assertFalse(AppModules::enabled($this->tenant->fresh(), 'appointments'));
    }

    public function test_booking_from_the_till_and_a_barber_cannot_be_double_booked(): void
    {
        $token = $this->token();
        $first = $this->withToken($token)->postJson('/api/v1/appointments', [
            'starts_at' => '2026-11-02 10:00', 'duration_minutes' => 30,
            'staff_user_id' => $this->barber->id, 'customer_name' => 'Karim', 'customer_phone' => '0612345678',
            'item_id' => $this->cut->id,
        ])->assertCreated()->json('appointment');

        $this->assertSame('Coupe homme', $first['service_name']);
        $this->assertStringStartsWith('https://wa.me/212612345678?text=', $first['reminder_url']);

        $this->withToken($token)->postJson('/api/v1/appointments', [
            'starts_at' => '2026-11-02 10:15', 'staff_user_id' => $this->barber->id, 'customer_name' => 'Youssef',
        ])->assertStatus(422)->assertJsonValidationErrors('starts_at');

        // Back to back is fine, and so is another barber at the same time.
        $this->withToken($token)->postJson('/api/v1/appointments', [
            'starts_at' => '2026-11-02 10:30', 'staff_user_id' => $this->barber->id, 'customer_name' => 'Youssef',
        ])->assertCreated();
        $this->withToken($token)->postJson('/api/v1/appointments', [
            'starts_at' => '2026-11-02 10:15', 'staff_user_id' => $this->owner->id, 'customer_name' => 'Nabil',
        ])->assertCreated();

        $day = $this->withToken($token)->getJson('/api/v1/appointments?date=2026-11-02')->assertOk()->json('appointments');
        $this->assertCount(3, $day);
        // 10:00 typed in the shop's timezone, handed back in UTC.
        $this->assertSame(
            \Carbon\CarbonImmutable::parse('2026-11-02 10:00', $this->tenant->timezone ?: 'Africa/Casablanca')->utc()->format('Y-m-d\TH:i:s\Z'),
            $day[0]['starts_at'],
        );
    }

    public function test_a_cancelled_booking_frees_the_chair(): void
    {
        $token = $this->token();
        $id = $this->withToken($token)->postJson('/api/v1/appointments', [
            'starts_at' => '2026-11-02 11:00', 'staff_user_id' => $this->barber->id, 'customer_name' => 'Karim',
        ])->json('appointment.id');

        $this->withToken($token)->patchJson("/api/v1/appointments/{$id}/status", ['status' => 'cancelled'])->assertOk();

        $this->withToken($token)->postJson('/api/v1/appointments', [
            'starts_at' => '2026-11-02 11:00', 'staff_user_id' => $this->barber->id, 'customer_name' => 'Youssef',
        ])->assertCreated();
    }

    public function test_paying_for_an_appointment_marks_it_done(): void
    {
        $appointment = Appointment::create([
            'tenant_id' => $this->tenant->id, 'customer_name' => 'Karim', 'staff_user_id' => $this->barber->id,
            'item_id' => $this->cut->id, 'starts_at' => now(), 'duration_minutes' => 30, 'status' => 'arrived',
        ]);

        $sale = $this->sell(['cash' => 60], 0, ['appointment_id' => $appointment->id]);

        $this->assertSame('done', $appointment->fresh()->status);
        $this->assertSame($sale['id'], $appointment->fresh()->sale_id);
    }

    public function test_the_module_switched_off_closes_the_agenda(): void
    {
        $settings = $this->tenant->settings;
        $settings['modules']['enabled']['appointments'] = false;
        $this->tenant->update(['settings' => $settings]);

        $this->withToken($this->token())->getJson('/api/v1/appointments')->assertStatus(403);
        $this->actingAs($this->owner)->get(route('appointments'))->assertNotFound();
    }

    public function test_the_agenda_page_lists_the_day_per_barber(): void
    {
        Appointment::create([
            'tenant_id' => $this->tenant->id, 'customer_name' => 'Karim', 'staff_user_id' => $this->barber->id,
            'service_name' => 'Coupe homme', 'starts_at' => now()->setTime(10, 0), 'duration_minutes' => 30, 'status' => 'booked',
        ]);

        $this->actingAs($this->owner)->get(route('appointments'))
            ->assertOk()
            ->assertSee('Ali Barbier')
            ->assertSee('Karim');
    }

    // ── Customer notes ────────────────────────────────────────────────────────

    public function test_customer_notes_are_kept_and_reach_the_till(): void
    {
        $contact = Contact::create(['tenant_id' => $this->tenant->id, 'kind' => 'client', 'name' => 'Karim', 'notes' => 'Dégradé 2 mm']);

        // A sync snapshot stops at the second before now.
        $contact->forceFill(['updated_at' => now()->subMinute()])->saveQuietly();
        $contacts = $this->withToken($this->token())->getJson('/api/v1/sync/contacts')->json('contacts');
        $this->assertSame('Dégradé 2 mm', collect($contacts)->firstWhere('id', $contact->id)['notes']);

        $this->withToken($this->token())->putJson("/api/v1/contacts/{$contact->id}", ['notes' => 'Allergique au parfum'])->assertOk();
        $this->assertSame('Allergique au parfum', $contact->fresh()->notes);
    }
}
