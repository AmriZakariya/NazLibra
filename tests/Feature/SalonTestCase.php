<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Contact;
use App\Models\Item;
use App\Models\ItemVariant;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use App\Support\OnlineBooking;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** Shared fixtures of the salon agenda tests: a beauty shop, two staff, a massage with a one-hour variant. */
abstract class SalonTestCase extends TestCase
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
}
