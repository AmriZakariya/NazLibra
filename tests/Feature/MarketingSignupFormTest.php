<?php

namespace Tests\Feature;

use App\Support\Countries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ViewErrorBag;
use Tests\Concerns\ActsAsMasterInstall;
use Tests\TestCase;

/**
 * The sign-up form on castlitpos.com, as a visitor from an ad meets it on a
 * phone: hints that follow the chosen country, the currency as a line that
 * follows it too, and a compact header so the first field is above the fold.
 */
class MarketingSignupFormTest extends TestCase
{
    use ActsAsMasterInstall {
        setUp as actsAsMasterSetUp;
    }
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->actsAsMasterSetUp();
        Queue::fake();
        Bus::fake();
    }

    public function test_example_cities_come_from_each_countrys_main_zone(): void
    {
        $cities = Countries::exampleCities('en');

        $this->assertSame('Lagos', $cities['NG']);
        $this->assertSame('Dubai', $cities['AE']);
        $this->assertSame('New York', $cities['US']);
        $this->assertSame('Casablanca', $cities['MA']);
        $this->assertSame('Buenos Aires', $cities['AR'], 'underscores become spaces, the last segment is the city');
    }

    public function test_example_cities_speak_the_visitors_language_where_it_matters(): void
    {
        $this->assertSame('الدار البيضاء', Countries::exampleCities('ar')['MA']);
        $this->assertSame('Montréal', Countries::exampleCities('fr')['CA']);
        $this->assertSame('Toronto', Countries::exampleCities('en')['CA']);
    }

    public function test_the_landing_form_carries_the_city_hints(): void
    {
        $html = $this->get('/?lang=en')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-city-hints=\'\{[^\']*"NG":"Lagos"/', $html);
    }

    public function test_the_admin_form_does_not_carry_them(): void
    {
        // The partial is shared with « Nouveau client », where the admin types
        // a known city: the hints are for the public form only.
        $html = view('castlit.partials.place-fields', ['errors' => new ViewErrorBag])->render();

        $this->assertStringNotContainsString('data-city-hints', $html);
    }

    public function test_the_currency_is_a_line_that_opens_on_change(): void
    {
        $html = $this->get('/?lang=en')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<div class="fld currency-fld\s*" data-currency-fld>/', $html);
        $this->assertStringContainsString('data-currency-change', $html);
        // The select is still there and still required: without the script
        // it is simply shown.
        $this->assertMatchesRegularExpression('/<select id="currency" name="currency" required>/', $html);
    }

    public function test_a_refused_currency_opens_the_list(): void
    {
        $this->post('/inscription', ['currency' => 'XXX'])->assertRedirect();

        $html = $this->get('/?lang=en')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<div class="fld currency-fld is-open" data-currency-fld>/', $html);
    }

    public function test_the_country_comes_before_the_currency(): void
    {
        // The currency follows the country, so it is asked after it.
        $html = $this->get('/?lang=en')->assertOk()->getContent();

        $this->assertLessThan(strpos($html, 'id="currency"'), strpos($html, 'id="country"'));
    }

    public function test_phones_get_the_short_steps(): void
    {
        $this->get('/?lang=en')->assertOk()
            ->assertSee('class="su-steps-short"', false)
            ->assertSee(__('castlit.su_step3_short', [], 'en'), false);
    }

    public function test_the_email_hint_speaks_the_page_language(): void
    {
        $this->get('/?lang=en')->assertOk()->assertSee('placeholder="you@example.com"', false);
        $this->get('/?lang=fr')->assertOk()->assertSee('placeholder="vous@exemple.com"', false);
    }
}
