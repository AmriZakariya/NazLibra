<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public marketing landing page.
 *
 * Had no test at all, and it carries the one thing a visitor is meant to act
 * on: the link to the app. That link is config-driven and falls back to a
 * "bientôt disponible" ribbon when the URL is empty — so an install that
 * forgot the setting would advertise an app that is already published as
 * coming soon, silently and indefinitely.
 */
class MarketingLandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        // Before parent::setUp(): the marketing routes are registered at BOOT
        // behind this flag, and rebooting afterwards would tear down the
        // in-memory database RefreshDatabase has just built.
        putenv('CASTLIT_MASTER=true');
        $_ENV['CASTLIT_MASTER'] = 'true';

        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv('CASTLIT_MASTER');
        unset($_ENV['CASTLIT_MASTER']);

        parent::tearDown();
    }

    public function test_the_landing_page_renders(): void
    {
        $this->get('/')->assertOk()->assertSee('Castl-it-POS', false);
    }

    public function test_it_links_to_the_published_app(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('play.google.com/store/apps/details?id=com.castlitpos.app', false);
    }

    public function test_the_store_link_opens_safely_in_a_new_tab(): void
    {
        // target=_blank without rel=noopener hands the opened page a handle
        // back to ours.
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<a href="https:\/\/play\.google\.com[^"]*"[^>]*rel="noopener"/',
            $html,
        );
    }

    public function test_it_does_not_say_coming_soon_for_a_published_app(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertDontSee(__('castlit.store_ribbon'), false);
    }

    public function test_it_still_says_coming_soon_when_no_url_is_set(): void
    {
        // The fallback has to keep working — a future product with no listing
        // yet must not render a dead link to the store.
        config(['castlit.brand.play_store' => '']);

        $this->get('/')
            ->assertOk()
            ->assertSee(__('castlit.store_ribbon'), false);
    }

    public function test_it_shows_the_real_app_icon_beside_the_heading(): void
    {
        // Asserting the filename alone was too loose: the icon also appears in
        // the phone mock, so the test passed with the main one removed.
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            // Bounded to the element itself: a lazy .*? runs on and matches
            // the icon in the phone mock further down, which is how the looser
            // version passed with the main icon deleted.
            '/class="app-id"[^>]*>\s*<img[^>]*app-icon\.png/s',
            $html,
            'the app icon is not shown next to the download heading',
        );
    }

    public function test_the_structured_data_points_at_the_listing(): void
    {
        // Without installUrl a rich result has nowhere to send someone who
        // wants to install the app.
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('"installUrl"', $html);
        $this->assertStringContainsString('"SoftwareApplication"', $html);
    }
}
