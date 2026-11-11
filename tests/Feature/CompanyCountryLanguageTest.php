<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Locale;
use App\Support\Phone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Paramètres › Société: the country is the sign-up's list, not free text, so
 * phone numbers and the currency can read it; the language offers every
 * language the app speaks, while the web itself shows French or Arabic.
 */
class CompanyCountryLanguageTest extends TestCase
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

    private function save(array $fields): TestResponse
    {
        // Every field, as the browser submits the form.
        $blank = array_fill_keys(['store_code', 'mobile', 'email', 'phone', 'cnss', 'rc', 'gst_no', 'vat_no', 'pan_no',
            'store_website', 'signature', 'bank_details', 'state', 'postcode', 'address', 'store_logo', 'default_account_id',
            'sales_discount', 'sales_invoice_format_id', 'pos_invoice_format_id', 'sales_invoice_footer_text', 'invoice_terms'], '');

        return $this->actingAs($this->user)->post(route('settings.company.update'), array_merge($blank, [
            'store_name' => $this->tenant->name,
            'country' => 'MA',
            'city' => 'Casablanca',
            'timezone' => 'Africa/Casablanca',
            'date_format' => 'dd/mm/yyyy',
            'time_format' => '24',
            'currency' => 'MAD',
            'currency_placement' => 'Right',
            'decimals' => '2',
            'qty_decimals' => '2',
            'language_id' => 'fr',
            'number_to_words' => 'Default',
        ], $fields));
    }

    private function profile(): array
    {
        return (array) data_get($this->tenant->fresh()->settings, 'company_profile');
    }

    private function companyPage(): string
    {
        return $this->actingAs($this->user)
            ->get(route('module', ['module' => 'settings', 'section' => 'company']))
            ->assertOk()->getContent();
    }

    public function test_the_country_is_picked_from_the_signup_list_and_saves_code_and_name(): void
    {
        $this->assertStringContainsString('data-company-country', $this->companyPage());

        $this->save(['country' => 'BE'])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('BE', $this->profile()['country_code']);
        $this->assertSame('Belgique', $this->profile()['country']);
        $this->assertSame('BE', Phone::defaultCountry($this->tenant->fresh()), 'phone numbers follow it');
    }

    public function test_free_text_is_refused(): void
    {
        $this->save(['country' => 'Pays imaginaire'])->assertSessionHasErrors('country');
    }

    public function test_an_older_typed_country_is_preselected(): void
    {
        $settings = $this->tenant->settings;
        data_set($settings, 'company_profile.country', 'France');
        data_set($settings, 'company_profile.country_code', null);
        $this->tenant->update(['settings' => $settings]);

        $this->assertMatchesRegularExpression('/<option value="FR"\s+selected/', $this->companyPage());
    }

    public function test_every_app_language_can_be_chosen_and_reaches_the_app(): void
    {
        $page = $this->companyPage();
        foreach (Locale::SHOP_LANGUAGES as $code => $label) {
            $this->assertStringContainsString('<option value="'.$code.'"', $page);
            $this->assertStringContainsString($label, $page);
        }

        foreach (Locale::SHOP_LOCALES as $code => $locale) {
            $this->save(['language_id' => $code])->assertSessionHasNoErrors();
            $this->assertSame($locale, $this->tenant->fresh()->locale);
            $this->assertSame($code, $this->profile()['language_id']);
        }

        $this->save(['language_id' => 'es']);
        $token = $this->user->createToken('test')->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/sync/settings')
            ->assertOk()->assertJsonPath('locale', 'es_ES');
    }

    public function test_a_language_the_web_does_not_speak_shows_it_in_french(): void
    {
        $settings = $this->tenant->settings;
        data_set($settings, 'language_id', 'de');
        $this->tenant->update(['settings' => $settings, 'locale' => 'de_DE']);

        $this->assertSame('fr', Locale::current($this->tenant->fresh()));
    }
}
