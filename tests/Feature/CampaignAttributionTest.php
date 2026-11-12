<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\User;
use App\Support\CampaignAttribution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\ActsAsMasterInstall;
use Tests\TestCase;

/**
 * Which ad brought which shop: utm tags (or the referring site) seen on the
 * landing page, kept in the session, saved with the sign-up request, counted
 * in castlit-admin and passed on to Google Play.
 */
class CampaignAttributionTest extends TestCase
{
    use ActsAsMasterInstall {
        setUp as actsAsMasterSetUp;
    }
    use RefreshDatabase;

    private const ANDROID = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/131.0 Mobile Safari/537.36';

    protected function setUp(): void
    {
        $this->actsAsMasterSetUp();
        Queue::fake();
        Bus::fake();
        config([
            'castlit.main_domain' => 'castlitpos.com',
            'castlit.brand.play_store' => 'https://play.google.com/store/apps/details?id=com.castlitpos.app',
        ]);
    }

    private function signUp(): Subscription
    {
        $this->post('/inscription', [
            'business_name' => 'Boutique Test',
            'contact_name' => 'Contact Test',
            'email' => 'contact@example.com',
            'desired_subdomain' => 'shop'.Str::lower(Str::random(6)),
            'currency' => 'MAD',
            'country' => 'MA',
        ])->assertRedirect(route('castlit.subscribe.success'));

        return Subscription::query()->latest('id')->firstOrFail();
    }

    public function test_an_ad_click_is_saved_with_the_signup(): void
    {
        $this->get('/?utm_source=Facebook&utm_medium=cpc&utm_campaign=promo-nov&utm_content=video-1&fbclid=abc')->assertOk();

        $sub = $this->signUp();

        $this->assertSame('facebook', $sub->campaign_source, 'sources are lowercased so they count as one');
        $this->assertSame('cpc', $sub->campaign_medium);
        $this->assertSame('promo-nov', $sub->campaign_name);
        $this->assertSame('video-1', $sub->meta['campaign']['content']);
        $this->assertSame('fbclid', $sub->meta['campaign']['click_id']);
        $this->assertSame('facebook · promo-nov', $sub->campaignLabel());
    }

    public function test_the_first_ad_keeps_the_credit(): void
    {
        $this->get('/?utm_source=facebook&utm_campaign=promo-nov')->assertOk();
        $this->get('/?utm_source=google&utm_campaign=search-brand')->assertOk();

        $this->assertSame('facebook', $this->signUp()->campaign_source);
    }

    public function test_an_ad_after_a_direct_visit_takes_the_credit(): void
    {
        $this->get('/')->assertOk();
        $this->get('/?utm_source=instagram&utm_campaign=reel-1')->assertOk();

        $sub = $this->signUp();
        $this->assertSame('instagram', $sub->campaign_source);
        $this->assertSame('reel-1', $sub->campaign_name);
    }

    public function test_without_tags_the_referring_site_stands_in(): void
    {
        $this->withHeader('Referer', 'https://www.google.co.ma/')->get('/')->assertOk();

        $sub = $this->signUp();
        $this->assertSame('google', $sub->campaign_source);
        $this->assertSame('organic', $sub->campaign_medium);
        $this->assertSame('google.co.ma', $sub->meta['campaign']['referrer']);
    }

    public function test_an_unknown_site_is_recorded_as_a_referral(): void
    {
        $this->withHeader('Referer', 'https://blog.example.org/article')->get('/')->assertOk();

        $sub = $this->signUp();
        $this->assertSame('blog.example.org', $sub->campaign_source);
        $this->assertSame('referral', $sub->campaign_medium);
    }

    public function test_a_typed_address_is_direct(): void
    {
        $this->get('/')->assertOk();

        $sub = $this->signUp();
        $this->assertSame(CampaignAttribution::DIRECT, $sub->campaign_source);
        $this->assertSame('Direct', $sub->campaignLabel());
    }

    public function test_our_own_pages_do_not_count_as_a_referral(): void
    {
        $this->get('/?utm_source=tiktok&utm_campaign=promo')->assertOk();
        $this->withHeader('Referer', 'https://castlitpos.com/guide')->get('/')->assertOk();

        $this->assertSame('tiktok', $this->signUp()->campaign_source);
    }

    public function test_odd_and_overlong_tags_are_cleaned(): void
    {
        $this->get('/?utm_source='.urlencode('<script>alert(1)</script>Face"book').'&utm_campaign='.str_repeat('a', 300))->assertOk();

        $sub = $this->signUp();
        $this->assertSame('scriptalert1/scriptfacebook', $sub->campaign_source);
        $this->assertSame(120, strlen((string) $sub->campaign_name));
    }

    public function test_a_google_ads_click_without_tags_is_google_cpc(): void
    {
        $this->get('/?gclid=xyz')->assertOk();

        $sub = $this->signUp();
        $this->assertSame('google', $sub->campaign_source);
        $this->assertSame('cpc', $sub->campaign_medium);
    }

    public function test_the_download_link_passes_the_campaign_to_google_play(): void
    {
        $response = $this->withHeader('User-Agent', self::ANDROID)
            ->get('/telecharger?utm_source=facebook&utm_medium=cpc&utm_campaign=promo-nov');

        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith('https://play.google.com/store/apps/details?id=com.castlitpos.app&referrer=', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('utm_source=facebook&utm_medium=cpc&utm_campaign=promo-nov', $query['referrer']);
    }

    public function test_the_download_link_uses_the_visits_campaign_when_the_click_has_none(): void
    {
        $this->get('/?utm_source=instagram&utm_campaign=reel-1')->assertOk();

        $location = (string) $this->withHeader('User-Agent', self::ANDROID)->get('/telecharger')->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('utm_source=instagram&utm_campaign=reel-1', $query['referrer']);
    }

    public function test_a_direct_download_goes_to_play_untouched(): void
    {
        $this->withHeader('User-Agent', self::ANDROID)->get('/telecharger')
            ->assertRedirect('https://play.google.com/store/apps/details?id=com.castlitpos.app');
    }

    public function test_the_admin_counts_requests_per_campaign_and_filters_by_one(): void
    {
        $make = fn (string $source, ?string $name, string $status) => Subscription::create([
            'business_name' => 'Shop '.Str::random(4), 'contact_name' => 'C', 'email' => 'c@example.com',
            'desired_subdomain' => 's'.Str::lower(Str::random(8)), 'currency' => 'MAD', 'status' => $status,
            'source' => Subscription::SOURCE_WEBSITE, 'campaign_source' => $source, 'campaign_name' => $name,
        ]);
        $make('facebook', 'promo-nov', Subscription::STATUS_APPROVED);
        $make('facebook', 'promo-nov', Subscription::STATUS_PENDING);
        $make('google', null, Subscription::STATUS_PENDING);
        $old = $make('tiktok', 'old', Subscription::STATUS_APPROVED);
        $old->forceFill(['created_at' => now()->subDays(45)])->save();

        $admin = User::factory()->create(['is_platform_admin' => true]);

        $html = $this->actingAs($admin)->get(route('castlit.admin.index', ['status' => 'all']))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/facebook · promo-nov<\/span><\/a><\/td>\s*<td class="num">2<\/td>\s*<td class="num">1<\/td>/', $html);
        $this->assertStringNotContainsString('tiktok · old</span></a>', $html, 'older than 30 days');

        $filtered = $this->actingAs($admin)
            ->get(route('castlit.admin.index', ['status' => 'all', 'campaign_source' => 'google', 'campaign_name' => '-']))
            ->assertOk();
        $this->assertSame(1, $filtered->viewData('subscriptions')->total());
        $filtered->assertSee('Filtre : google · sans nom', false);
    }

    public function test_the_detail_page_shows_the_campaign(): void
    {
        $this->get('/?utm_source=facebook&utm_medium=cpc&utm_campaign=promo-nov&utm_term=caisse')->assertOk();
        $sub = $this->signUp();

        $admin = User::factory()->create(['is_platform_admin' => true]);
        $this->actingAs($admin)->get(route('castlit.admin.show', $sub))
            ->assertOk()
            ->assertSee('facebook · promo-nov', false)
            ->assertSee('mot-clé : caisse', false);
    }
}
