<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Item;
use App\Models\ItemVariant;
use App\Models\Tenant;
use App\Models\User;
use App\Support\OnlineBooking;
use App\Support\ServiceStaff;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** The public /reserver page: what it shows (photos, search, chips, prices, staff) — the booking rules live in OnlineBookingTest. */
class BookingPageTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $sara;
    private User $nadia;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->tenant = Tenant::first();
        $settings = $this->tenant->settings ?? [];
        $settings['company_profile']['business_mode'] = 'barber';
        $this->tenant->update(['settings' => $settings, 'timezone' => 'Africa/Casablanca', 'phone' => '0612345678', 'address' => '12 rue des Orangers, Casablanca']);
        $this->sara = $this->member('Sara');
        $this->nadia = $this->member('Nadia');
        $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00', 'Africa/Casablanca'));
        OnlineBooking::save($this->tenant->refresh(), [
            'enabled' => true, 'duration' => 30,
            'hours' => array_fill(1, 6, ['open' => '09:00', 'close' => '19:00']) + [7 => ['closed' => true]],
            'staff_ids' => [$this->sara->id, $this->nadia->id],
        ]);
    }

    private function member(string $name): User
    {
        $user = User::create(['name' => $name, 'email' => strtolower($name).'@example.test', 'password' => Hash::make('password')]);
        $this->tenant->users()->attach($user->id, ['role' => 'cashier']);

        return $user;
    }

    private function service(string $title, float $price, array $extra = []): Item
    {
        return Item::create($extra + [
            'tenant_id' => $this->tenant->id, 'title' => $title, 'type' => 'service', 'status' => 'active',
            'sale_price' => $price, 'purchase_price' => 0, 'stock_quantity' => 0, 'bookable_online' => true,
        ]);
    }

    public function test_the_page_shows_photos_search_and_category_chips(): void
    {
        $hair = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Coiffure', 'slug' => 'coiffure']);
        $this->service('Brushing', 80, ['category_id' => $hair->id, 'images' => ['items/brushing.jpg']]);
        $this->service('Épilation sourcils', 40);

        $this->get(route('booking.show'))->assertOk()
            ->assertSee('data-search-input', false)
            ->assertSee('data-chip="'.$hair->id.'"', false)
            ->assertSee('storage/items/brushing.jpg', false)
            ->assertSee('loading="lazy"', false)
            // No photo: initials stand in.
            ->assertSee('12 rue des Orangers')
            ->assertSee('https://wa.me/212612345678', false)
            ->assertSee('tel:+212612345678', false);
        $this->assertMatchesRegularExpression('/<span class="thumb">\s*ÉS\s*<\/span>/u', $this->get(route('booking.show'))->getContent());
    }

    public function test_a_service_not_bookable_online_stays_hidden(): void
    {
        $this->service('Brushing', 80);
        $this->service('Soin secret', 99, ['bookable_online' => false]);

        $this->get(route('booking.show'))->assertSee('Brushing')->assertDontSee('Soin secret');
    }

    public function test_prices_say_from_for_a_floor_or_for_options_that_differ(): void
    {
        $floor = $this->service('Coloration', 300, ['price_is_from' => true]);
        $this->service('Manucure', 120);
        $cut = $this->service('Coupe', 100);
        foreach ([['Court', 100, 20], ['Long', 160, 45]] as [$name, $price, $minutes]) {
            ItemVariant::create([
                'tenant_id' => $this->tenant->id, 'item_id' => $cut->id, 'name' => $name, 'attributes' => [],
                'sale_price' => $price, 'sale_price_override' => $price, 'purchase_price' => 0, 'is_active' => true, 'duration_minutes' => $minutes,
            ]);
        }

        $html = $this->get(route('booking.show'))->assertOk()
            ->assertSee(__('booking.from').' ', false)
            ->assertSee('data-variants-for="'.$cut->id.'"', false)
            ->assertSee('20–45 min')
            ->getContent();
        $this->assertSame(2, substr_count($html, '>'.__('booking.from').' '));
        $this->assertStringNotContainsString('data-variants-for="'.$floor->id.'"', $html);
    }

    public function test_staff_are_still_filtered_per_service(): void
    {
        $massage = $this->service('Massage', 200);
        ServiceStaff::set($this->tenant, $massage, [$this->sara->id]);

        $html = $this->get(route('booking.show'))->assertOk()->getContent();
        $this->assertStringContainsString('data-staff-choice="'.$this->nadia->id.'"', $html);
        $this->assertMatchesRegularExpression('/"'.$massage->id.'":\[\s*'.$this->sara->id.'\s*\]/', $html);
        $this->assertSame([], OnlineBooking::slots($this->tenant, '2026-11-03', $this->nadia->id, null, 30, $massage->fresh()));
        $this->assertNotEmpty(OnlineBooking::slots($this->tenant, '2026-11-03', $this->sara->id, null, 30, $massage->fresh()));
    }

    public function test_the_closed_days_are_marked_in_the_strip_and_arabic_flips_the_page(): void
    {
        $this->service('Brushing', 80);
        $this->get(route('booking.show'))->assertOk()
            ->assertSee('dir="ltr"', false)
            // Sunday 8 November is closed: shown, but not pickable.
            ->assertSee('value="2026-11-08"', false);
        $this->assertMatchesRegularExpression('/value="2026-11-08"[^>]*\sdisabled/', $this->get(route('booking.show'))->getContent());
        $this->assertDoesNotMatchRegularExpression('/value="2026-11-03"[^>]*\sdisabled/', $this->get(route('booking.show'))->getContent());

        $this->tenant->update(['locale' => 'ar']);
        $this->get(route('booking.show'))->assertOk()->assertSee('lang="ar" dir="rtl"', false)->assertSee(__('booking.search_placeholder', [], 'ar'))
            // The week starts on Monday whatever the locale: Sunday (closed) is labelled Sunday.
            ->assertSeeInOrder(['الأحد</span><span>'.__('booking.closed', [], 'ar')], false);
    }
}
