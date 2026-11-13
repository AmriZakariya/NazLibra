<?php

namespace Tests\Feature;

use App\Models\MarketingVisit;
use App\Support\GeoCountry;
use App\Support\MarketingLocale;
use App\Support\MarketingVisits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsMasterInstall;
use Tests\TestCase;

/**
 * castlitpos.com speaks the visitor's language: their choice, else their
 * browser's, else their country's, else English. Crawlers keep French at the
 * bare address so the index does not change language under it.
 */
class MarketingLocaleTest extends TestCase
{
    use ActsAsMasterInstall {
        setUp as actsAsMasterSetUp;
        tearDown as actsAsMasterTearDown;
    }
    use RefreshDatabase;

    private const PHONE = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/131.0 Mobile Safari/537.36';

    protected function setUp(): void
    {
        $this->actsAsMasterSetUp();
        GeoCountry::fake(fn (string $ip) => ['102.50.10.20' => 'MA', '102.50.10.21' => 'AE', '102.50.10.22' => 'NG', '102.50.10.23' => 'SN'][$ip] ?? null);
    }

    protected function tearDown(): void
    {
        GeoCountry::fake(null);
        $this->actsAsMasterTearDown();
    }

    private function lang(array $headers = [], string $url = '/', string $ip = '102.50.10.22'): string
    {
        $html = $this->withHeaders(['User-Agent' => self::PHONE] + $headers)
            ->withServerVariables(['REMOTE_ADDR' => $ip])
            ->get($url)->assertOk()->getContent();
        preg_match('/<html lang="(\w+)"/', $html, $m);

        return $m[1] ?? '?';
    }

    public function test_the_browser_language_decides_first(): void
    {
        $this->assertSame('fr', $this->lang(['Accept-Language' => 'fr-FR,fr;q=0.9,en;q=0.8'], ip: '102.50.10.21'));
    }

    public function test_arabic_browser(): void
    {
        $this->assertSame('ar', $this->lang(['Accept-Language' => 'ar-MA,ar;q=0.9,fr;q=0.8']));
    }

    public function test_the_first_language_we_speak_in_the_browsers_order(): void
    {
        // Spanish first, then English: English, not French.
        $this->assertSame('en', $this->lang(['Accept-Language' => 'es-ES,es;q=0.9,en;q=0.8,fr;q=0.5']));
        // q values decide, not the order written.
        $this->assertSame('fr', MarketingLocale::fromBrowser('en;q=0.3, fr;q=0.9, de'));
    }

    public function test_without_a_language_we_speak_the_country_decides(): void
    {
        $this->assertSame('ar', $this->lang(['Accept-Language' => 'de-DE'], ip: '102.50.10.21'));
        $this->assertSame('fr', $this->lang(['Accept-Language' => 'de-DE'], ip: '102.50.10.23'));
        $this->assertSame('fr', $this->lang(['Accept-Language' => 'es'], ip: '102.50.10.20'), 'Morocco: French unless the browser says Arabic or English');
    }

    public function test_english_when_nothing_decides(): void
    {
        $this->assertSame('en', $this->lang(['Accept-Language' => 'de-DE'], ip: '102.50.10.22'));
        $this->assertSame('en', $this->lang(['Accept-Language' => ''], ip: '203.0.113.9'));
    }

    public function test_the_visitors_choice_wins_and_is_remembered(): void
    {
        $this->assertSame('ar', $this->lang(['Accept-Language' => 'fr-FR'], '/?lang=ar'));
        $this->assertSame('ar', $this->lang(['Accept-Language' => 'fr-FR']), 'remembered on the next page');
    }

    public function test_crawlers_get_french_at_the_bare_address(): void
    {
        $html = $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1)', 'Accept-Language' => 'en-US'])
            ->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('<html lang="fr"', $html);

        // And the other languages stay where hreflang says they are.
        $en = $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1)'])->get('/?lang=en')->getContent();
        $this->assertStringContainsString('<html lang="en"', $en);
    }

    public function test_an_english_page_at_the_bare_address_names_its_own_canonical(): void
    {
        $html = $this->withHeaders(['User-Agent' => self::PHONE, 'Accept-Language' => 'en-US'])->get('/')->getContent();

        $this->assertMatchesRegularExpression('/<link rel="canonical" href="[^"]*\?lang=en">/', $html);
    }

    public function test_the_other_marketing_pages_follow_the_same_rule(): void
    {
        $this->assertSame('ar', $this->lang(['Accept-Language' => 'ar'], '/privacy'));
    }

    public function test_datacenter_visits_are_not_counted(): void
    {
        foreach (['2a03:2880:f814:32::', '57.141.20.68', '49.51.253.83', '170.106.180.139', '52.88.156.56'] as $ip) {
            $this->assertTrue(MarketingVisits::fromDatacenter($ip), $ip);
        }
        foreach (['154.144.241.138', '105.66.1.1', '41.140.2.3', '102.50.10.20'] as $ip) {
            $this->assertFalse(MarketingVisits::fromDatacenter($ip), $ip);
        }

        $this->withHeaders(['User-Agent' => self::PHONE])->withServerVariables(['REMOTE_ADDR' => '57.141.20.68'])->get('/')->assertOk();
        $this->assertSame(0, MarketingVisit::count());

        $this->withHeaders(['User-Agent' => self::PHONE])->withServerVariables(['REMOTE_ADDR' => '154.144.241.138'])->get('/')->assertOk();
        $this->assertSame(1, MarketingVisit::count());
    }
}
