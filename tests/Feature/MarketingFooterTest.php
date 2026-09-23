<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The site footer.
 *
 * It is the last thing on every marketing page and the only place a visitor
 * who scrolled past the form can find an address to write to — so the contact
 * email, the feature list and the legal links all have to survive a template
 * change, in all three languages.
 */
class MarketingFooterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        // Before parent::setUp(): the marketing routes are registered at BOOT
        // behind this flag.
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

    /**
     * Just the footer's own markup.
     *
     * Asserting against the whole page proves nothing here: the features are
     * in the body and the JSON-LD too, and the email is in the bottom bar —
     * so a broken contact block or an empty feature column still "passed".
     */
    private function footer(string $url = '/'): string
    {
        $html = $this->get($url)->assertOk()->getContent();
        $start = strpos($html, '<footer>');
        $end = strpos($html, '</footer>');
        $this->assertNotFalse($start, 'the page has no footer');
        $this->assertNotFalse($end, 'the footer is not closed');

        return substr($html, $start, $end - $start);
    }

    /**
     * The footer's contact link, split into its opening tag and its text.
     *
     * @return array{open: string, text: string}
     */
    private function contactBlock(string $url = '/'): array
    {
        $footer = $this->footer($url);
        $matched = preg_match(
            '/(<a\\b[^>]*class="footer-mail"[^>]*>)(.*?)<\\/a>/s',
            $footer,
            $m,
        );
        $this->assertSame(1, $matched, 'the footer has no contact link');

        return ['open' => $m[1], 'text' => $m[2]];
    }

    public function test_the_footer_shows_an_address_a_visitor_can_write_to(): void
    {
        // The contact block itself, not merely somewhere in the footer: the
        // bottom bar also carries a mailto, so an unbounded search passes even
        // when this block is broken.
        $block = $this->contactBlock();

        $this->assertStringContainsString('mailto:contact@castlitpos.com', $block['open']);
        // Written out, not hidden behind the word "Contact": someone copying
        // the address into their own mail client never clicks the link.
        $this->assertStringContainsString('contact@castlitpos.com', $block['text']);
    }

    public function test_the_contact_address_follows_the_configured_one(): void
    {
        // A client install pointing support elsewhere must not still publish
        // the platform address.
        config(['castlit.brand.email' => 'support@example.test']);

        $footer = $this->footer();

        $this->assertStringContainsString('support@example.test', $footer);
        $this->assertStringNotContainsString('contact@castlitpos.com', $footer);
    }

    public function test_the_footer_lists_what_the_app_actually_does(): void
    {
        // Taken from the same localized list the page body and the JSON-LD
        // use, so the footer cannot advertise a feature the product dropped.
        // Explicitly French: the page renders in French, while the test
        // process itself sits on the app's default locale, so the untargeted
        // lookup compared English strings against a French page.
        $features = collect(__('castlit.features', [], 'fr'))->pluck('t')->filter();
        $this->assertNotEmpty($features, 'the feature list should not be empty');

        $footer = $this->footer();
        foreach ($features->take(6) as $feature) {
            // Escaped: "Factures & devis" reaches the page as
            // "Factures &amp; devis", and comparing the raw string fails on
            // exactly the features whose names contain punctuation.
            $this->assertStringContainsString(e($feature), $footer);
        }
    }

    public function test_the_footer_carries_the_legal_links(): void
    {
        $footer = $this->footer();

        $this->assertStringContainsString(route('castlit.privacy'), $footer);
        $this->assertStringContainsString(route('castlit.terms'), $footer);
    }

    public function test_the_footer_links_to_the_app_when_it_is_published(): void
    {
        $this->assertStringContainsString(
            'play.google.com/store/apps/details?id=com.castlitpos.app',
            $this->footer(),
        );
    }

    public function test_the_footer_does_not_advertise_a_store_link_it_has_not_got(): void
    {
        // An empty URL must drop the button, not render a link to nowhere.
        config(['castlit.brand.play_store' => '']);

        // The attribute, not the bare class name: the stylesheet defines
        // `.footer-store` whether or not anything uses it, so the loose
        // string matches even when the button is correctly absent.
        $footer = $this->footer();

        $this->assertStringNotContainsString('class="footer-store"', $footer);
        $this->assertStringNotContainsString('play.google.com', $footer);
    }

    public function test_an_empty_social_list_renders_no_social_row(): void
    {
        // Every account is unset by default; a row of dead icons reads worse
        // than no row at all.
        config(['castlit.brand.social' => []]);

        $this->assertStringNotContainsString('class="footer-social"', $this->footer());
    }

    public function test_real_social_accounts_are_shown(): void
    {
        config(['castlit.brand.social' => ['https://www.linkedin.com/company/castlitpos']]);

        $footer = $this->footer();

        $this->assertStringContainsString('class="footer-social"', $footer);
        $this->assertStringContainsString('https://www.linkedin.com/company/castlitpos', $footer);
    }

    /**
     * The footer is translated everywhere the site is.
     */
    public function test_the_footer_speaks_every_language_the_site_does(): void
    {
        foreach (['fr' => 'Nous contacter', 'en' => 'Get in touch', 'ar' => 'تواصل معنا'] as $lang => $heading) {
            $this->assertStringContainsString(
                $heading,
                $this->footer($lang === 'fr' ? '/' : '/?lang='.$lang),
                "the $lang footer should be translated",
            );
        }
    }

    public function test_the_arabic_footer_is_laid_out_right_to_left(): void
    {
        // The columns are a grid; without dir=rtl on the document the Arabic
        // footer reads left to right.
        $this->get('/?lang=ar')->assertOk()->assertSee('dir="rtl"', false);
    }
}
