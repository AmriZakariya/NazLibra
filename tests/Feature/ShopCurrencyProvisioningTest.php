<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A shop outside Morocco used to be created on Casablanca time, labelled
 * "Maroc", with two decimals whatever its currency.
 */
class ShopCurrencyProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private function install(array $payload): Tenant
    {
        $this->artisan('castlit:install-tenant', [
            '--payload' => base64_encode(json_encode($payload + [
                'business_name' => 'Salon Test',
                'contact_name' => 'Awa',
                'email' => 'awa@example.test',
            ])),
            '--subdomain' => 'salontest',
        ])->assertSuccessful();

        return Tenant::firstOrFail();
    }

    public function test_a_shop_in_dakar_runs_on_dakar_time_in_francs_cfa_without_centimes(): void
    {
        $tenant = $this->install(['currency' => 'XOF', 'country' => 'SN', 'city' => 'Dakar']);

        $this->assertSame('Africa/Dakar', $tenant->timezone);
        $this->assertSame('SN', data_get($tenant->settings, 'company_profile.country_code'));
        $this->assertSame('Sénégal', data_get($tenant->settings, 'company_profile.country'));
        $this->assertSame('Dakar', data_get($tenant->settings, 'company_profile.city'));
        $this->assertSame(0, Money::for($tenant)->decimals);
        $this->assertSame('SN', \App\Support\Phone::defaultCountry($tenant));
    }

    public function test_a_shop_in_montreal_keeps_the_zone_its_browser_gave(): void
    {
        $tenant = $this->install(['currency' => 'CAD', 'country' => 'CA', 'timezone' => 'America/Vancouver']);

        $this->assertSame('America/Vancouver', $tenant->timezone);
    }

    public function test_tunisian_prices_carry_millimes(): void
    {
        $tenant = $this->install(['currency' => 'TND', 'country' => 'TN']);

        $this->assertSame(3, Money::for($tenant)->decimals);
        $this->assertSame('Africa/Tunis', $tenant->timezone);
    }

    public function test_an_older_payload_without_a_country_is_still_a_moroccan_shop(): void
    {
        $tenant = $this->install(['currency' => 'MAD']);

        $this->assertSame('Africa/Casablanca', $tenant->timezone);
        $this->assertSame(2, Money::for($tenant)->decimals);
    }

    public function test_the_signup_offers_every_currency_and_accepts_one_outside_the_old_six(): void
    {
        putenv('CASTLIT_MASTER=true');
        $_ENV['CASTLIT_MASTER'] = 'true';
        $this->refreshApplication();
        $this->artisan('migrate');

        try {
            $html = $this->get('/', ['Accept-Language' => 'fr'])->assertOk()->getContent();
            $this->assertStringContainsString('<option value="SAR"', $html);
            $this->assertStringContainsString('SAR — Riyal saoudien', $html);

            $this->post(route('castlit.subscribe'), [
                'business_name' => 'Barbier Riyad', 'contact_name' => 'Omar', 'email' => 'omar@example.test',
                'desired_subdomain' => 'barbierriyad', 'currency' => 'SAR', 'country' => 'SA',
                'timezone' => 'Asia/Riyadh',
            ])->assertSessionHasNoErrors();

            $subscription = \App\Models\Subscription::latest('id')->first();
            $this->assertSame('SAR', $subscription->currency);
            $this->assertSame('Asia/Riyadh', data_get($subscription->meta, 'timezone'));
        } finally {
            putenv('CASTLIT_MASTER');
            unset($_ENV['CASTLIT_MASTER']);
        }
    }
}
