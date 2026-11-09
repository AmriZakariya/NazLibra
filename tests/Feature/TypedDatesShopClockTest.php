<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Item;
use App\Models\Quotation;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A date typed in a form (a quote, a delivery, a payment…) is the shop's
 * time. It was stored as if it were UTC, and a list showing it on the shop's
 * clock would then have put it an hour off. Typed on the shop's clock,
 * stored in UTC, shown back as typed.
 */
class TypedDatesShopClockTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->tenant = Tenant::first();
        $this->tenant->update(['timezone' => 'Europe/Paris']);
        $this->actingAs(User::first());
    }

    public function test_reading_a_typed_value(): void
    {
        $this->assertSame('2026-11-08T11:19:00+00:00', TenantClock::fromInput('2026-11-08T12:19', 'Europe/Paris')->toIso8601String());
        // A date alone is the start of the shop's day.
        $this->assertSame('2026-11-07T23:00:00+00:00', TenantClock::fromInput('2026-11-08', 'Europe/Paris')->toIso8601String());
        // What the app sends says its offset: taken as it is.
        $this->assertSame('2026-11-08T11:19:00+00:00', TenantClock::fromInput('2026-11-08T11:19:00Z', 'Europe/Paris')->toIso8601String());
        $this->assertNull(TenantClock::fromInput('', 'Europe/Paris'));

        $this->travelTo(Carbon::parse('2026-11-08 23:30:00', 'UTC'));
        // Past midnight in Paris already: the form must offer the 9th.
        $this->assertSame('2026-11-09T00:30', TenantClock::inputValue(null, 'Europe/Paris'));
    }

    public function test_a_typed_quote_date_is_stored_in_utc_and_listed_as_typed(): void
    {
        $item = Item::where('status', 'active')->firstOrFail();
        $client = Contact::where('kind', 'client')->firstOrFail();

        $this->post(route('quotations.store'), [
            'contact_id' => $client->id,
            'quoted_at' => '2026-11-08T12:19',
            'expires_at' => '2026-11-20',
            'status' => 'sent',
            'reference' => 'HORLOGE',
            'items' => [['item_id' => $item->id, 'quantity' => 1, 'unit_price' => 20]],
        ])->assertSessionHasNoErrors();

        $quote = Quotation::firstOrFail();
        $this->assertSame('2026-11-08 11:19:00', $quote->quoted_at->utc()->format('Y-m-d H:i:s'));

        $this->get(route('module', ['module' => 'sales', 'section' => 'quotes', 'q' => 'HORLOGE']))
            ->assertOk()
            ->assertSee('08/11/2026 12:19');
    }

    public function test_the_forms_offer_the_shops_time_not_the_servers(): void
    {
        $this->travelTo(Carbon::parse('2026-11-08 11:19:00', 'UTC'));

        $this->get(route('module', ['module' => 'sales', 'section' => 'quote-add']))
            ->assertOk()
            ->assertSee('value="2026-11-08T12:19"', false)
            ->assertDontSee('value="2026-11-08T11:19"', false);
    }
}
