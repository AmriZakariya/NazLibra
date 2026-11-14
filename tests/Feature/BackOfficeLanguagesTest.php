<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\I18n\Extractor;
use App\Support\I18n\HtmlTranslator;
use App\Support\Locale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The shop back office in the 7 languages of the app: every French text it
 * can show has a translation, each person picks their language in a menu
 * that keeps it on their account, and the PDFs follow the shop's language.
 */
class BackOfficeLanguagesTest extends TestCase
{
    use RefreshDatabase;

    private const TRANSLATED = ['ar', 'en', 'es', 'pt', 'de', 'it'];

    private const PLACEHOLDER = '/\{\d+\}|:[a-z_]+\b|_[A-Z]+_|%[sd]/';

    private function placeholders(string $text): array
    {
        preg_match_all(self::PLACEHOLDER, $text, $m);
        sort($m[0]);

        return $m[0];
    }

    public function test_every_french_text_of_the_back_office_has_a_translation_in_every_language(): void
    {
        // Fails when a page, a script or a message gains a French text that
        // no language file has: run `php artisan castlit:i18n-extract --missing`.
        $keys = array_keys(app(Extractor::class)->strings());
        $this->assertGreaterThan(3000, count($keys));

        foreach (self::TRANSLATED as $locale) {
            $dictionary = Locale::dictionary($locale);
            $missing = array_values(array_filter($keys, fn ($k) => ! isset($dictionary[$k]) || trim($dictionary[$k]) === ''));
            $this->assertSame([], array_slice($missing, 0, 20), "{$locale}: ".count($missing).' text(s) without a translation');
        }
    }

    public function test_translations_keep_their_placeholders(): void
    {
        foreach (self::TRANSLATED as $locale) {
            $broken = [];
            foreach (Locale::dictionary($locale) as $french => $translation) {
                if ($this->placeholders($french) !== $this->placeholders($translation)) {
                    $broken[] = $french;
                }
            }
            $this->assertSame([], array_slice($broken, 0, 10), "{$locale}: placeholders lost");
        }
    }

    public function test_the_language_is_the_persons_then_the_shops(): void
    {
        $tenant = new Tenant(['settings' => ['language_id' => 'es']]);
        $user = User::factory()->create(['locale' => null]);

        $this->assertSame('es', Locale::current($tenant, $user), 'the shop’s language by default');

        $user->locale = 'de';
        $this->assertSame('de', Locale::current($tenant, $user), 'the person’s own choice first');

        session([Locale::SESSION_KEY => 'it']);
        $this->assertSame('it', Locale::current($tenant, $user), 'a choice made this session (the login page) first');

        session([Locale::SESSION_KEY => 'xx']);
        $user->locale = null;
        $this->assertSame('es', Locale::current($tenant, $user), 'an unknown value is ignored');

        $this->assertSame('fr', Locale::current(null, null));
    }

    public function test_the_menu_keeps_the_choice_on_the_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->from('/')->post(route('locale.switch', 'pt'))->assertRedirect('/');

        $this->assertSame('pt', $user->fresh()->locale);
        $this->assertSame('pt', session(Locale::SESSION_KEY));
    }

    public function test_the_menu_works_before_signing_in(): void
    {
        $this->from('/connexion')->post(route('locale.switch', 'en'))->assertRedirect('/connexion');

        $this->assertSame('en', session(Locale::SESSION_KEY));
    }

    public function test_an_unknown_language_is_refused(): void
    {
        $this->post(route('locale.switch', 'xx'))->assertNotFound();
    }

    public function test_each_language_has_its_dictionary_cached_for_good(): void
    {
        $response = $this->get(route('i18n.script', ['locale' => 'en', 'v' => Locale::version('en')]))->assertOk();

        $this->assertStringStartsWith('window.libraireProTranslations={', $response->getContent());
        $this->assertStringContainsString('immutable', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('javascript', (string) $response->headers->get('Content-Type'));
        $this->assertSame([], $response->headers->getCookies(), 'no session for a static file');

        $this->get(route('i18n.script', ['locale' => 'fr']))->assertNotFound();
        $this->get('/i18n/xx.js')->assertNotFound();
    }

    public function test_the_login_page_offers_every_language_and_loads_the_translator(): void
    {
        $html = $this->withSession([Locale::SESSION_KEY => 'ar'])->get('/connexion')->getContent();

        $this->assertStringContainsString('data-login-language', $html);
        foreach (Locale::SHOP_LANGUAGES as $code => $name) {
            $this->assertStringContainsString(route('locale.switch', $code), $html);
            $this->assertStringContainsString($name, $html);
        }
        $this->assertStringContainsString('<html lang="ar" dir="rtl"', $html);
        $this->assertStringContainsString('/i18n/ar.js?v=', $html);
        $this->assertStringContainsString('i18n-pending', $html, 'hidden until translated, so French never flashes');
    }

    public function test_french_needs_no_dictionary(): void
    {
        $html = $this->withSession([Locale::SESSION_KEY => 'fr'])->get('/connexion')->getContent();

        $this->assertStringNotContainsString('/i18n/fr.js', $html);
        $this->assertStringNotContainsString('i18n-pending', $html);
    }

    public function test_server_texts_and_validation_follow_the_language(): void
    {
        app()->setLocale('en');
        $this->assertSame('Settings', Locale::t('Paramètres'));
        $this->assertSame('Paramètres', Locale::t('Paramètres', 'fr'));
        $this->assertNotSame(__('validation.required', ['attribute' => 'x']), (function () {
            app()->setLocale('fr');

            return __('validation.required', ['attribute' => 'x']);
        })(), 'validation messages exist in English too');

        foreach (['es', 'pt', 'de', 'it'] as $locale) {
            $this->assertNotSame('validation.required', trans('validation.required', [], $locale), "{$locale} validation file");
        }
    }

    public function test_pdfs_follow_the_shops_language(): void
    {
        $html = '<html><body><h1>Facture</h1><td title="Total">Total</td><p>Ce texte n’existe pas</p></body></html>';
        $translated = HtmlTranslator::translate($html, 'en');

        $this->assertStringContainsString('<h1>'.Locale::t('Facture', 'en').'</h1>', $translated);
        $this->assertStringContainsString('Ce texte n’existe pas', $translated, 'unknown text stays');

        $this->assertSame('ar', HtmlTranslator::pdfLocale(new Tenant(['settings' => ['language_id' => 'ar']])), 'Arabic is shaped for dompdf (ArabicShaper)');
        $this->assertSame('de', HtmlTranslator::pdfLocale(new Tenant(['settings' => ['language_id' => 'de']])));
    }

    public function test_a_pattern_carries_its_values_across(): void
    {
        $patterned = collect(Locale::dictionary('en'))->keys()->first(fn ($k) => $k === '{0} ligne(s) chargée(s)');
        $this->assertNotNull($patterned, 'a known pattern is in the dictionary');

        $this->assertSame(str_replace('{0}', '12', Locale::dictionary('en')['{0} ligne(s) chargée(s)']), HtmlTranslator::text('12 ligne(s) chargée(s)', 'en'));
    }
}
