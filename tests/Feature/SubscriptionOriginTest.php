<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\ActsAsMasterInstall;
use Tests\TestCase;

/**
 * Where a subscription request came from, and where the shop is.
 *
 * Requests arrive through three doors — the website form, the app's public
 * API, and the platform admin's "Nouveau client" — and nothing recorded
 * which. The app tagged itself `mobile` without saying Android or iOS, and
 * no form asked for a country, so a shop in Paris and one in Casablanca
 * looked identical and were both provisioned in dirhams.
 */
class SubscriptionOriginTest extends TestCase
{
    use ActsAsMasterInstall {
        setUp as actsAsMasterSetUp;
    }
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->actsAsMasterSetUp();
        Queue::fake();
        Bus::fake();
    }

    /** @return array<string, mixed> */
    private function request(array $overrides = []): array
    {
        return array_merge([
            'business_name' => 'Librairie Test',
            'contact_name' => 'Contact Test',
            'email' => 'contact@example.com',
            'desired_subdomain' => 'shop'.Str::lower(Str::random(6)),
            'currency' => 'MAD',
        ], $overrides);
    }

    private function latest(): Subscription
    {
        return Subscription::query()->latest('id')->firstOrFail();
    }

    // ── The website ───────────────────────────────────────────────────────────

    public function test_a_website_signup_says_it_came_from_the_website(): void
    {
        $this->post('/inscription', $this->request(['country' => 'MA', 'city' => 'Casablanca']))
            ->assertRedirect();

        $sub = $this->latest();
        $this->assertSame(Subscription::SOURCE_WEBSITE, $sub->source);
        $this->assertSame('MA', $sub->country);
        $this->assertSame('Casablanca', $sub->city);
    }

    public function test_the_website_defaults_to_morocco(): void
    {
        // A form submitted without the field — an old cached page, a script —
        // still lands somewhere sensible rather than nowhere.
        $this->post('/inscription', $this->request())->assertRedirect();

        $this->assertSame('MA', $this->latest()->country);
    }

    public function test_a_country_outside_the_list_is_refused(): void
    {
        $this->post('/inscription', $this->request(['country' => 'ZZ']))
            ->assertSessionHasErrors('country');

        $this->assertSame(0, Subscription::count());
    }

    public function test_a_blank_city_is_stored_as_nothing(): void
    {
        $this->post('/inscription', $this->request(['city' => '   ']))->assertRedirect();

        $this->assertNull($this->latest()->city);
    }

    public function test_the_form_offers_the_country_with_morocco_selected(): void
    {
        $page = $this->get('/')->assertOk()->getContent();
        $select = Str::before(Str::after($page, 'name="country"'), '</select>');

        $this->assertMatchesRegularExpression('/value="MA"\s+selected/', $select);
        $this->assertStringContainsString('name="city"', $page);
    }

    public function test_the_arabic_page_names_the_countries_in_arabic(): void
    {
        $page = $this->get('/?lang=ar')->assertOk()->getContent();
        $select = Str::before(Str::after($page, 'name="country"'), '</select>');

        $this->assertStringContainsString('المغرب', $select);
    }

    // ── The app ───────────────────────────────────────────────────────────────

    private function fromApp(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/public/subscribe', $this->request($overrides));
    }

    public function test_the_app_says_which_store_it_came_from(): void
    {
        $this->fromApp(['platform' => 'android'])->assertOk();
        $this->assertSame(Subscription::SOURCE_ANDROID, $this->latest()->source);

        $this->fromApp(['platform' => 'ios'])->assertOk();
        $this->assertSame(Subscription::SOURCE_IOS, $this->latest()->source);
    }

    public function test_the_windows_till_is_its_own_source(): void
    {
        $this->fromApp(['platform' => 'windows'])->assertOk();

        $this->assertSame(Subscription::SOURCE_DESKTOP, $this->latest()->source);
    }

    public function test_an_older_app_build_that_says_nothing_still_signs_up(): void
    {
        // Builds already in the stores do not send `platform`. They must keep
        // working, and land as "App mobile" rather than as nothing.
        $this->fromApp()->assertOk();

        $this->assertSame(Subscription::SOURCE_MOBILE, $this->latest()->source);
        $this->assertSame('MA', $this->latest()->country);
    }

    public function test_the_app_sends_the_place(): void
    {
        $this->fromApp(['platform' => 'ios', 'country' => 'FR', 'city' => 'Paris'])->assertOk();

        $this->assertSame('FR', $this->latest()->country);
        $this->assertSame('Paris', $this->latest()->city);
    }

    public function test_an_unknown_platform_is_refused(): void
    {
        $this->fromApp(['platform' => 'toaster'])->assertStatus(422);
    }

    // ── The admin ─────────────────────────────────────────────────────────────

    private function admin(): User
    {
        return User::factory()->create(['is_platform_admin' => true]);
    }

    public function test_a_client_created_by_hand_says_so(): void
    {
        $this->actingAs($this->admin())
            ->post('/castlit-admin/nouveau', $this->request(['country' => 'TN', 'city' => 'Tunis']))
            ->assertRedirect();

        $sub = $this->latest();
        $this->assertSame(Subscription::SOURCE_ADMIN, $sub->source);
        $this->assertSame('TN', $sub->country);
        $this->assertSame('Tunis', $sub->city);
    }

    public function test_the_list_shows_where_each_request_came_from_and_where_it_is(): void
    {
        Subscription::create($this->request([
            'status' => Subscription::STATUS_PENDING,
            'source' => Subscription::SOURCE_ANDROID,
            'country' => 'MA',
            'city' => 'Rabat',
        ]));

        $page = $this->actingAs($this->admin())->get('/castlit-admin')->assertOk()->getContent();
        $row = Str::before(Str::after($page, 'data-origin="android"'), '</td>');

        $this->assertStringContainsString('App Android', $row);
        $this->assertStringContainsString('Rabat, Maroc', $row);
    }

    public function test_the_list_counts_requests_by_origin(): void
    {
        foreach ([Subscription::SOURCE_WEBSITE, Subscription::SOURCE_WEBSITE, Subscription::SOURCE_IOS] as $source) {
            Subscription::create($this->request(['status' => Subscription::STATUS_PENDING, 'source' => $source]));
        }

        $page = $this->actingAs($this->admin())->get('/castlit-admin')->assertOk()->getContent();
        $strip = Str::before(Str::after($page, 'data-origins'), '</div>');

        $this->assertMatchesRegularExpression('/Site web\s*<strong>2<\/strong>/', $strip);
        $this->assertMatchesRegularExpression('/App iOS\s*<strong>1<\/strong>/', $strip);
    }

    public function test_the_detail_page_shows_the_place_and_the_origin(): void
    {
        $sub = Subscription::create($this->request([
            'status' => Subscription::STATUS_PENDING,
            'source' => Subscription::SOURCE_IOS,
            'country' => 'FR',
            'city' => 'Lyon',
        ]));

        $page = $this->actingAs($this->admin())->get('/castlit-admin/'.$sub->id)->assertOk()->getContent();

        $this->assertStringContainsString('Lyon', $page);
        $this->assertStringContainsString('France', $page);
        $this->assertStringContainsString('App iOS', $page);
    }

    // ── Requests from before the columns existed ──────────────────────────────

    public function test_older_requests_are_read_from_what_they_did_record(): void
    {
        $cases = [
            [['source' => 'mobile'], Subscription::SOURCE_MOBILE],
            [['source' => 'manual'], Subscription::SOURCE_ADMIN],
            [['user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0)'], Subscription::SOURCE_WEBSITE],
            [['user_agent' => 'Dart/3.5 (dart:io)'], Subscription::SOURCE_MOBILE],
            [[], null],
        ];

        foreach ($cases as [$meta, $expected]) {
            $sub = new Subscription(['meta' => $meta]);
            $this->assertSame($expected, $sub->origin(), json_encode($meta));
        }
    }

    public function test_a_phone_browser_on_the_website_is_still_the_website(): void
    {
        // An iPhone user agent does not make it an app signup: the form was
        // the website's, opened in Safari.
        $sub = new Subscription(['meta' => ['user_agent' => 'Mozilla/5.0 (Linux; Android 14) Chrome/120']]);

        $this->assertSame('Site web', $sub->originLabel());
    }

    public function test_the_column_wins_over_the_old_tags(): void
    {
        $sub = new Subscription(['source' => 'ios', 'meta' => ['source' => 'mobile']]);

        $this->assertSame(Subscription::SOURCE_IOS, $sub->origin());
    }

    public function test_requests_already_in_the_list_are_not_stamped_as_moroccan(): void
    {
        // A column default would have marked every existing request as
        // Moroccan, including the ones plainly from elsewhere.
        $sub = Subscription::create($this->request(['status' => Subscription::STATUS_PENDING]));

        $this->assertNull($sub->fresh()->country);
        $this->assertNull($sub->fresh()->place());
    }

    // ── The access code on the list ───────────────────────────────────────────

    public function test_the_list_shows_each_clients_access_code(): void
    {
        $sub = Subscription::create($this->request(['status' => Subscription::STATUS_APPROVED, 'desired_subdomain' => 'withcode']));
        \App\Models\TenantInstall::create([
            'subscription_id' => $sub->id, 'subdomain' => 'withcode', 'domain' => 'withcode.castlitpos.com',
            'status' => \App\Models\TenantInstall::STATUS_LIVE, 'access_code' => 'HK7M2Q',
        ]);

        $page = $this->actingAs($this->admin())->get('/castlit-admin?status=approved')->assertOk()->getContent();

        // On the row of THAT client, so the code given on the phone is the right one.
        $row = Str::before(Str::after($page, 'withcode.castlitpos.com'), '</tr>');
        $this->assertStringContainsString('data-access-code="HK7M2Q"', $row);
        $this->assertStringContainsString('<code>HK7M2Q</code>', $row);
    }

    public function test_a_request_with_no_space_yet_shows_no_code(): void
    {
        Subscription::create($this->request(['status' => Subscription::STATUS_PENDING, 'desired_subdomain' => 'waiting']));

        $page = $this->actingAs($this->admin())->get('/castlit-admin')->assertOk()->getContent();
        $row = Str::before(Str::after($page, 'waiting.castlitpos.com'), '</tr>');

        $this->assertStringNotContainsString('data-access-code', $row);
    }

    public function test_the_list_does_not_offer_to_regenerate_the_code(): void
    {
        // Regenerating invalidates the code a client is already using; that
        // stays on the Clients page, behind its confirmation.
        $sub = Subscription::create($this->request(['status' => Subscription::STATUS_APPROVED, 'desired_subdomain' => 'regen']));
        \App\Models\TenantInstall::create([
            'subscription_id' => $sub->id, 'subdomain' => 'regen', 'domain' => 'regen.castlitpos.com',
            'status' => \App\Models\TenantInstall::STATUS_LIVE, 'access_code' => 'ABCD23',
        ]);

        $page = $this->actingAs($this->admin())->get('/castlit-admin?status=approved')->assertOk()->getContent();

        $this->assertStringNotContainsString('regenerate-code', $page);
    }

    public function test_a_spa_can_sign_up_as_a_spa(): void
    {
        $this->post('/inscription', $this->request(['activity' => 'beauty']))
            ->assertRedirect(route('castlit.subscribe.success'));

        $this->assertSame('beauty', $this->latest()->activity);
    }

    public function test_the_form_offers_every_activity(): void
    {
        $page = $this->get('/')->assertOk()->getContent();
        $select = Str::before(Str::after($page, 'name="activity"'), '</select>');

        foreach (array_keys(\App\Support\BusinessMode::all()) as $key) {
            $this->assertStringContainsString('value="'.$key.'"', $select, $key);
        }
    }
}
