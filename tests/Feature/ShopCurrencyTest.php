<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\LoyaltyService;
use App\Support\AmountInWords;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A shop's amounts are written in its own currency.
 *
 * Every formatter used to end in a hard-coded " DH", so a shop in Paris that
 * signed up in euros saw dirhams on every page, ticket and PDF; and the
 * placement and decimals saved in Paramètres were never read.
 */
class ShopCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->tenant = Tenant::firstOrFail();
        $this->user = User::where('email', 'amina@librairie-atlas.ma')->firstOrFail();
    }

    private function inEuros(array $numberFormat = []): void
    {
        $settings = $this->tenant->settings ?? [];
        $settings['number_format'] = array_merge($settings['number_format'] ?? [], $numberFormat);
        $this->tenant->update(['currency' => 'EUR', 'settings' => $settings]);
        Money::forget();
    }

    /** Amounts written in dirhams: "12,00 DH", or a bare DH unit in a label. */
    private function assertNoDirhams(string $html, string $page): void
    {
        // The currency list in Paramètres names the dirham on purpose.
        $html = (string) preg_replace('/<select name="currency".*?<\/select>/s', '', $html);

        $this->assertDoesNotMatchRegularExpression('/\d DH\b/u', $html, "{$page} still writes an amount in DH");
        $this->assertDoesNotMatchRegularExpression('/[>(]\s*DH\s*[<)]/u', $html, "{$page} still labels a field in DH");
        $this->assertStringNotContainsString('MAD · DH', $html, "{$page} still shows the MAD pill");
    }

    // ── The formatter ─────────────────────────────────────────────────────────

    public function test_amounts_follow_currency_placement_and_decimals(): void
    {
        $this->assertSame('1 250,00 €', (new Money('EUR'))->format(1250));
        $this->assertSame('€ 1 250,00', (new Money('EUR', 'Left'))->format(1250));
        $this->assertSame('12,500 DT', (new Money('TND', 'Right', 3))->format(12.5));
        $this->assertSame('12 DH', (new Money('MAD', 'Right', 0))->format(12.4));
        $this->assertSame('12,00 FCFA', (new Money('XOF'))->format(12));
        $this->assertSame('12,00 SEK', (new Money('SEK'))->format(12), 'an unlisted currency shows its code');
    }

    public function test_the_shop_settings_drive_the_formatter(): void
    {
        $this->inEuros(['currency_placement' => 'Left', 'decimals' => 3]);

        $money = Money::for($this->tenant->fresh());

        $this->assertSame('EUR', $money->code);
        $this->assertSame('€ 9,500', $money->format(9.5));
    }

    public function test_a_moroccan_shop_still_reads_dirhams(): void
    {
        $this->assertSame('12,00 DH', Money::for($this->tenant)->format(12));
        $this->assertSame('12,00 DH', Money::for(null)->format(12));
    }

    public function test_a_document_keeps_the_currency_it_was_issued_in(): void
    {
        $this->inEuros();

        $this->assertSame('5,00 DH', Money::for($this->tenant->fresh())->withCode('MAD')->format(5));
        $this->assertSame('5,00 €', Money::for($this->tenant->fresh())->withCode(null)->format(5));
    }

    // ── The web app ───────────────────────────────────────────────────────────

    public function test_no_page_writes_dirhams_for_a_shop_in_euros(): void
    {
        $this->inEuros();
        $this->actingAs($this->user);

        $pages = [
            'dashboard' => route('dashboard'),
            'pos' => route('pos'),
            'catalogue' => route('catalog'),
            'services' => route('catalog', ['panel' => 'services']),
            'manual sale' => route('module', ['module' => 'sales', 'section' => 'manual-sale']),
            'sales' => route('module', ['module' => 'sales', 'section' => 'sales']),
            'quotes' => route('module', ['module' => 'sales', 'section' => 'quotations']),
            'purchases' => route('module', ['module' => 'purchases', 'section' => 'purchases']),
            'reports' => route('module', ['module' => 'reports', 'section' => 'sales']),
            'settings' => route('module', ['module' => 'settings', 'section' => 'company']),
        ];

        $checked = 0;
        foreach ($pages as $name => $url) {
            $response = $this->get($url);
            if ($response->status() !== 200) {
                continue; // a section this seed does not enable
            }
            $html = $response->getContent();
            $this->assertNoDirhams($html, $name);
            $checked++;
        }

        $this->assertGreaterThanOrEqual(6, $checked, 'too few pages rendered to prove anything');
    }

    public function test_the_page_hands_the_currency_to_its_scripts(): void
    {
        $this->inEuros();

        $html = $this->actingAs($this->user)->get(route('pos'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/window\.appCurrency = \{"code":"EUR","symbol":"\\\\u20ac"/', $html);
    }

    public function test_the_profile_menu_shows_the_currency(): void
    {
        $this->inEuros();

        $html = $this->actingAs($this->user)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-account-currency>.*?Euro.*?1 250,00 €.*?EUR · €/s', $html);
    }

    public function test_settings_offer_currencies_by_name_and_keep_an_unlisted_one(): void
    {
        $this->tenant->update(['currency' => 'SEK']);

        $this->actingAs($this->user)
            ->get(route('module', ['module' => 'settings', 'section' => 'company']))
            ->assertOk()
            ->assertSee('EUR · € — Euro')
            ->assertSee('<option value="SEK" selected>', false)
            ->assertSee('ne convertit pas les prix');
    }

    public function test_the_built_scripts_no_longer_hard_code_dirhams(): void
    {
        $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
        $js = file_get_contents(public_path('build/'.$manifest['resources/js/app.js']['file']));

        $this->assertStringContainsString('appCurrency', $js);
        $this->assertStringNotContainsString(" DH'", $js);
        $this->assertStringNotContainsString(' DH`', $js);
        $this->assertStringNotContainsString("currency:\"MAD\"", $js);
    }

    // ── Messages and documents ────────────────────────────────────────────────

    public function test_loyalty_messages_name_the_shops_currency(): void
    {
        $this->inEuros();
        $loyalty = app(LoyaltyService::class);
        $cfg = array_merge($loyalty->config($this->tenant->fresh()), ['min_redeem_points' => 1, 'max_redeem_pct' => 10, 'redeem_rate' => 1]);

        try {
            $loyalty->validateRedemption(500, 1000, 100, $cfg);
            $this->fail('the redemption should have been capped');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('10,00 €', $e->getMessage());
            $this->assertStringNotContainsString('MAD', $e->getMessage());
        }
    }

    public function test_amounts_in_words_cover_the_signup_currencies(): void
    {
        $this->assertSame('Douze euros', AmountInWords::money(12, 'EUR'));
        $this->assertSame('Douze francs CFA', AmountInWords::money(12, 'XOF'));
        $this->assertSame('Douze dinars', AmountInWords::money(12, 'DZD'));
    }
}
