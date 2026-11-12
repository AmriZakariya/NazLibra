<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Getting the app from the marketing site: the printed /telecharger link
 * (flyers, posts, the QR code) and the install bar shown on phones.
 */
class MarketingAppDownloadTest extends TestCase
{
    use RefreshDatabase;

    private const ANDROID = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/131.0 Mobile Safari/537.36';

    private const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile/15E148 Safari/604.1';

    private const DESKTOP = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Version/18.0 Safari/605.1.15';

    protected function setUp(): void
    {
        // Before parent::setUp(): the marketing routes are registered at boot.
        putenv('CASTLIT_MASTER=true');
        $_ENV['CASTLIT_MASTER'] = 'true';

        parent::setUp();

        config([
            'castlit.brand.play_store' => 'https://play.google.com/store/apps/details?id=com.castlitpos.app',
            'castlit.brand.app_store' => '',
        ]);
    }

    protected function tearDown(): void
    {
        putenv('CASTLIT_MASTER');
        unset($_ENV['CASTLIT_MASTER']);

        parent::tearDown();
    }

    public function test_an_android_phone_goes_to_google_play(): void
    {
        $this->withHeader('User-Agent', self::ANDROID)->get('/telecharger')
            ->assertStatus(302)
            ->assertRedirect('https://play.google.com/store/apps/details?id=com.castlitpos.app');
    }

    public function test_an_iphone_goes_to_the_app_store_once_it_is_set(): void
    {
        config(['castlit.brand.app_store' => 'https://apps.apple.com/app/id1234567890']);

        $this->withHeader('User-Agent', self::IPHONE)->get('/telecharger')
            ->assertStatus(302)
            ->assertRedirect('https://apps.apple.com/app/id1234567890');
    }

    public function test_an_iphone_lands_on_the_app_section_before_the_app_store_listing(): void
    {
        // Never Google Play for an iPhone, and never a dead link.
        $this->withHeader('User-Agent', self::IPHONE)->get('/telecharger')
            ->assertStatus(302)
            ->assertRedirect(route('castlit.landing').'#application');
    }

    public function test_a_computer_lands_on_the_app_section(): void
    {
        $this->withHeader('User-Agent', self::DESKTOP)->get('/download')
            ->assertStatus(302)
            ->assertRedirect(route('castlit.landing').'#application');
    }

    public function test_the_redirect_is_never_permanent_or_cached(): void
    {
        // The App Store target arrives later; a 301 or a cached answer would
        // keep sending iPhones to the page after the listing exists.
        $response = $this->withHeader('User-Agent', self::ANDROID)->get('/telecharger');

        $response->assertStatus(302);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('User-Agent', (string) $response->headers->get('Vary'));
    }

    public function test_the_install_bar_carries_the_play_link_and_no_ios_link_yet(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<div class="dl-bar"[^>]*\shidden\s/', $html, 'the bar must start hidden; the script decides');
        $this->assertMatchesRegularExpression('/data-android-url="https:\/\/play\.google\.com[^"]*"/', $html);
        // An empty iOS link is what keeps the bar off iPhones until the listing exists.
        $this->assertStringContainsString('data-ios-url=""', $html);
    }

    public function test_the_install_bar_offers_the_app_store_once_it_is_set(): void
    {
        config(['castlit.brand.app_store' => 'https://apps.apple.com/app/id1234567890']);

        $this->get('/')->assertOk()
            ->assertSee('data-ios-url="https://apps.apple.com/app/id1234567890"', false);
    }

    public function test_no_install_bar_without_any_store(): void
    {
        config(['castlit.brand.play_store' => '']);

        $this->get('/')->assertOk()->assertDontSee('<div class="dl-bar"', false);
    }

    public function test_the_app_section_shows_the_qr_code_and_its_address(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('id="application"', $html);
        $this->assertMatchesRegularExpression('/class="app-qr"[^>]*>\s*<img[^>]*app-qr\.svg/s', $html);
        $this->assertStringContainsString(config('castlit.main_domain').'/telecharger', $html);
    }

    public function test_the_qr_code_encodes_the_download_address(): void
    {
        // The SVG is generated once; its <title> carries the encoded address
        // so a change of link without regenerating the image is caught here.
        $svg = (string) file_get_contents(public_path('img/app-qr.svg'));

        $this->assertStringContainsString('<title>castlitpos.com/telecharger</title>', $svg);
    }
}
