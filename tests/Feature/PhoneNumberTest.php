<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Tenant;
use App\Support\Phone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phone fields send "+212612345678"; the server keeps one shape for it and
 * tells the forms which country a local number belongs to. An invalid number
 * only warns in the form, so the server must keep it, not drop it.
 */
class PhoneNumberTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_international_number_loses_its_spacing(): void
    {
        $this->assertSame('+212612345678', Phone::normalize('+212 6 12 34 56 78'));
        $this->assertSame('+33612345678', Phone::normalize('0033 (6) 12-34-56-78'));
        $this->assertSame('+212612345678', Phone::normalize("  +212\t612345678 "));
    }

    public function test_anything_else_is_kept_as_typed(): void
    {
        $this->assertSame('06 12 34 56 78', Phone::normalize(' 06  12 34 56 78 '));
        $this->assertSame('+212 61', Phone::normalize('+212 61'), 'too short to be a number: kept for the cashier to fix');
        $this->assertSame('poste 24', Phone::normalize('poste 24'));
        $this->assertNull(Phone::normalize('   '));
        $this->assertNull(Phone::normalize(null));
    }

    public function test_a_saved_contact_stores_the_one_shape(): void
    {
        $this->seed();
        $tenant = Tenant::firstOrFail();

        $contact = Contact::create(['tenant_id' => $tenant->id, 'kind' => 'customer', 'name' => 'Test', 'phone' => '+212 6 61 00 00 00']);

        $this->assertSame('+212661000000', $contact->fresh()->getRawOriginal('phone'), 'one shape in the database, for lookups');
        $this->assertSame('+212 6 61 00 00 00', $contact->fresh()->phone, 'read the way the country writes it');
    }

    public function test_a_number_is_shown_the_way_its_country_writes_it(): void
    {
        $this->assertSame('+212 6 12 34 56 78', Phone::display('+212612345678'));
        $this->assertSame('+33 6 12 34 56 78', Phone::display('+33612345678'));
        $this->assertSame('+1 514 555 1234', Phone::display('+15145551234'));
        $this->assertSame('+216 20 123 456', Phone::display('+21620123456'));
        $this->assertSame('06 12 34 56 78', Phone::display('06 12 34 56 78'), 'an old local number is left alone');
        $this->assertNull(Phone::display(null));
    }

    public function test_a_local_number_is_read_in_the_shops_country(): void
    {
        $this->assertSame('+212600000003', Phone::internationalize('06 00 00 00 03', 'MA'));
        $this->assertSame('+33612345678', Phone::internationalize('06 12 34 56 78', 'FR'), 'a Paris shop: not +212');
        $this->assertSame('+2250707123456', Phone::internationalize('07 07 12 34 56', 'CI'), 'no national prefix: the 0 is part of the number');
        $this->assertSame('+212612345678', Phone::internationalize('212612345678', 'MA'), 'international, plus forgotten');
        $this->assertSame('+33612345678', Phone::internationalize('+33 6 12 34 56 78', 'MA'), 'already international: the shop does not matter');
        $this->assertSame('poste 24', Phone::internationalize('poste 24', 'MA'));
    }

    public function test_the_online_shop_reads_a_bare_number_in_its_own_country(): void
    {
        $this->seed();
        $tenant = Tenant::firstOrFail();
        $tenant->update(['timezone' => 'Europe/Paris', 'currency' => 'EUR']);
        $item = \App\Models\Item::where('type', '!=', 'service')->where('stock_quantity', '>', 0)->firstOrFail();
        $tenant->update(['settings' => array_replace_recursive((array) $tenant->settings, ['company_profile' => ['country' => null]])]);

        $this->post(route('storefront.orders.store'), [
            'customer_name' => 'Client parisien',
            'customer_phone' => '06 12 34 56 78',
            'delivery_address' => 'Paris',
            'items' => [['item_id' => $item->id, 'quantity' => 1]],
        ])->assertSessionHasNoErrors();

        $this->assertSame('+33612345678', \App\Models\OnlineOrder::latest('id')->firstOrFail()->getRawOriginal('customer_phone'));
    }

    public function test_the_default_country_is_the_shops(): void
    {
        $shop = fn (array $attributes, array $profile = []) => new Tenant($attributes + ['settings' => ['company_profile' => $profile]]);

        $this->assertSame('MA', Phone::defaultCountry(null));
        $this->assertSame('MA', Phone::defaultCountry($shop(['timezone' => 'Africa/Casablanca'])));
        $this->assertSame('FR', Phone::defaultCountry($shop(['timezone' => 'Europe/Paris', 'currency' => 'EUR'])));
        $this->assertSame('BE', Phone::defaultCountry($shop(['timezone' => 'Europe/Paris'], ['country' => 'Belgique'])), 'the address country wins over the timezone');
        $this->assertSame('MA', Phone::defaultCountry($shop(['timezone' => 'Africa/El_Aaiun'])), 'Laâyoune is Maroc');
        $this->assertSame('TN', Phone::defaultCountry($shop(['timezone' => 'UTC', 'currency' => 'TND'])));
    }

    public function test_the_app_is_told_the_shops_country(): void
    {
        $this->seed();
        $tenant = Tenant::firstOrFail();
        $user = \App\Models\User::where('email', 'amina@librairie-atlas.ma')->firstOrFail();

        $settings = $this->withToken($user->createToken('t')->plainTextToken)
            ->withHeader('X-Tenant-Slug', $tenant->slug)
            ->getJson('/api/v1/sync/settings')
            ->assertOk()
            ->json();

        $this->assertSame(Phone::defaultCountry($tenant), $settings['phone_country'] ?? data_get($settings, 'data.phone_country'));
    }

    public function test_every_phone_field_on_the_pages_is_a_phone_input(): void
    {
        $this->seed();

        $html = $this->get(route('module', ['module' => 'contacts', 'section' => 'customer-add']))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<input name="phone" type="tel"/', $html);
        $this->assertMatchesRegularExpression('/<input name="secondary_phone" type="tel"/', $html);
        $this->assertStringContainsString('data-phone-country="MA"', $html);
    }
}
