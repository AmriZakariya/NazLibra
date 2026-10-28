<?php

namespace Tests\Feature;

use App\Support\GeoCountry;
use Illuminate\Http\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The signup used to open on Maroc for everyone. It now opens on the
 * visitor's country: IP, then browser language, then (in the page) the
 * browser's timezone — and Maroc only when nothing says.
 */
class SignupCountryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        // Before parent::setUp(): the marketing routes are registered at boot.
        putenv('CASTLIT_MASTER=true');
        $_ENV['CASTLIT_MASTER'] = 'true';

        parent::setUp();
    }

    protected function tearDown(): void
    {
        GeoCountry::fake(null);
        GeoCountry::reset();
        putenv('CASTLIT_MASTER');
        unset($_ENV['CASTLIT_MASTER']);

        parent::tearDown();
    }

    private function request(string $ip, array $headers = []): Request
    {
        $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => $ip]);
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        return $request;
    }

    public function test_the_ip_decides_first(): void
    {
        GeoCountry::fake(fn (string $ip) => $ip === '90.1.2.3' ? 'FR' : null);

        $this->assertSame('FR', GeoCountry::guess($this->request('90.1.2.3', ['Accept-Language' => 'fr-MA'])));
    }

    public function test_behind_the_hosts_proxy_the_forwarded_address_is_the_visitors(): void
    {
        $request = $this->request('127.0.0.1', ['X-Forwarded-For' => '10.0.0.4, 41.250.1.1']);

        $this->assertSame('41.250.1.1', GeoCountry::clientIp($request));
        $this->assertSame('8.8.8.8', GeoCountry::clientIp($this->request('8.8.8.8', ['X-Forwarded-For' => '41.250.1.1'])),
            'a public address talking directly is not overridden by a header');
    }

    public function test_the_browser_language_region_comes_next(): void
    {
        GeoCountry::fake(fn () => null);

        $this->assertSame('SN', GeoCountry::guess($this->request('127.0.0.1', ['Accept-Language' => 'fr-SN,fr;q=0.9'])));
        $this->assertNull(GeoCountry::fromAcceptLanguage('fr,en;q=0.8'), 'a language alone names no country');
    }

    public function test_a_country_the_form_does_not_offer_or_a_private_address_says_nothing(): void
    {
        GeoCountry::fake(fn () => 'ZZ');
        $this->assertNull(GeoCountry::fromIp('90.1.2.3'));
        $this->assertNull(GeoCountry::fromIp('192.168.1.10'));

        GeoCountry::fake(fn () => 'EH');
        $this->assertSame('MA', GeoCountry::fromIp('90.1.2.3'), 'listed under Morocco, as everywhere else');
    }

    public function test_without_the_database_nothing_breaks(): void
    {
        config(['castlit.geoip.path' => '/nonexistent/country.mmdb']);
        GeoCountry::reset();

        $this->assertNull(GeoCountry::fromIp('8.8.8.8'));
    }

    public function test_the_signup_opens_on_the_detected_country_and_its_currency(): void
    {
        GeoCountry::fake(fn () => 'FR');

        $html = $this->withServerVariables(['REMOTE_ADDR' => '90.1.2.3'])
            ->get('/', ['Accept-Language' => 'fr'])->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<option value="FR" selected/', $html);
        $this->assertMatchesRegularExpression('/<option value="EUR" selected/', $html);
        $this->assertStringNotContainsString('data-timezone-countries', $html, 'already known: no need for the browser');
    }

    public function test_when_nothing_is_known_the_browser_timezone_gets_its_say(): void
    {
        GeoCountry::fake(fn () => null);

        // A language with no region, as most browsers send.
        $html = $this->withServerVariables(['REMOTE_ADDR' => '90.1.2.3'])
            ->get('/', ['Accept-Language' => 'fr'])->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<option value="MA" selected/', $html);
        $this->assertStringContainsString('data-timezone-countries', $html);
        $this->assertSame('MA', GeoCountry::timezoneMap()['Africa/Casablanca']);
        $this->assertSame('FR', GeoCountry::timezoneMap()['Europe/Paris']);
    }

    public function test_the_app_can_ask_for_the_country(): void
    {
        GeoCountry::fake(fn () => 'TN');

        $this->withServerVariables(['REMOTE_ADDR' => '197.0.0.9'])
            ->getJson('/api/v1/public/country', ['Accept-Language' => 'fr'])
            ->assertOk()->assertJsonPath('country', 'TN');
    }

    public function test_the_real_table_when_present(): void
    {
        $path = getenv('TMPDIR').'/geo/country.mmdb';
        if (! is_file($path)) {
            $this->markTestSkipped('DB-IP file not downloaded on this machine.');
        }
        config(['castlit.geoip.path' => $path]);
        GeoCountry::reset();

        $this->assertSame('MA', GeoCountry::fromIp('41.250.1.1'));
        $this->assertSame('US', GeoCountry::fromIp('8.8.8.8'));
    }
}
