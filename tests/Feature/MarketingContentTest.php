<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the castlitpos.com home page says. It used to cover a third of the
 * product (six features, four trades); it now walks through every area of the
 * shop, in three languages that must not drift apart.
 */
class MarketingContentTest extends TestCase
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
        putenv('CASTLIT_MASTER');
        unset($_ENV['CASTLIT_MASTER']);

        parent::tearDown();
    }

    public function test_every_area_of_the_product_is_on_the_page_with_its_screenshot(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        foreach ((array) __('castlit.areas', [], 'fr') as $area) {
            $this->assertStringContainsString('id="panel-'.$area['key'].'"', $html);
            $this->assertStringContainsString(e($area['title']), $html);
            $this->assertStringContainsString('img/marketing/'.$area['shot'], $html);
            $this->assertFileExists(public_path('img/marketing/'.$area['shot']), 'a screenshot the page shows must ship');
        }

        foreach (['restauration', 'hors-ligne', 'materiel', 'secteurs', 'essai', 'faq', 'inscription'] as $section) {
            $this->assertStringContainsString('id="'.$section.'"', $html);
        }
        $this->assertCount(12, (array) __('castlit.sectors', [], 'fr'), 'one card per business mode');
    }

    public function test_the_trial_length_comes_from_the_config(): void
    {
        config(['castlit.trial_months' => 2]);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('2 mois pour l’essayer en vrai', $html);
        $this->assertStringContainsString('2 mois offerts', $html);
        $this->assertStringNotContainsString(':months', $html, 'a placeholder left in the FAQ or its JSON-LD');
    }

    public function test_no_language_shows_a_raw_translation_key(): void
    {
        foreach (['/', '/?lang=en', '/?lang=ar'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertDoesNotMatchRegularExpression('/castlit\.[a-z_]+/', strip_tags($html), "$url shows a key instead of text");
        }
    }

    public function test_the_three_languages_say_the_same_things(): void
    {
        $shape = function (array $tree) use (&$shape): array {
            $out = [];
            foreach ($tree as $key => $value) {
                $out[$key] = is_array($value) ? $shape($value) : gettype($value);
            }

            return $out;
        };
        $fr = $shape(require lang_path('fr/castlit.php'));

        foreach (['en', 'ar'] as $lang) {
            $this->assertSame($fr, $shape(require lang_path("$lang/castlit.php")), "$lang/castlit.php has drifted from the French copy");
        }
    }
}
