<?php

namespace Tests\Feature;

use App\Models\MarketingVisit;
use App\Models\Subscription;
use App\Models\User;
use App\Support\MarketingVisits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Concerns\ActsAsMasterInstall;
use Tests\TestCase;

/**
 * Visits to the castlitpos.com landing page and how far each got: 77 visits
 * from an ad and no request said nothing about where people stopped.
 */
class MarketingVisitsTest extends TestCase
{
    use ActsAsMasterInstall {
        setUp as actsAsMasterSetUp;
    }
    use RefreshDatabase;

    private const PHONE = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/131.0 Mobile Safari/537.36';

    protected function setUp(): void
    {
        $this->actsAsMasterSetUp();
        Queue::fake();
        Bus::fake();
        $this->withHeader('User-Agent', self::PHONE);
    }

    private function visit(): MarketingVisit
    {
        return MarketingVisit::query()->latest('id')->firstOrFail();
    }

    private function token(): string
    {
        return MarketingVisits::token($this->visit());
    }

    private function signUp(array $overrides = [])
    {
        return $this->post('/inscription', array_merge([
            'business_name' => 'Boutique Test',
            'contact_name' => 'Contact Test',
            'email' => 'contact@example.com',
            'desired_subdomain' => 'shop'.Str::lower(Str::random(6)),
            'currency' => 'MAD',
            'country' => 'MA',
        ], $overrides));
    }

    public function test_opening_the_landing_page_records_a_visit_with_its_campaign(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '102.50.10.20'])
            ->get('/?lang=en&utm_source=youtube&utm_medium=shorts&utm_campaign=video-en-nov')
            ->assertOk();

        $v = $this->visit();
        $this->assertSame('102.50.10.20', $v->ip);
        $this->assertSame('en', $v->locale);
        $this->assertSame('phone', $v->device);
        $this->assertSame('youtube', $v->campaign_source);
        $this->assertSame('shorts', $v->campaign_medium);
        $this->assertSame('video-en-nov', $v->campaign_name);
        $this->assertSame(MarketingVisit::OPENED, $v->stage);
    }

    public function test_a_reload_is_the_same_visit(): void
    {
        $this->get('/')->assertOk();
        $this->get('/')->assertOk();

        $this->assertSame(1, MarketingVisit::count());
    }

    public function test_bots_link_previews_prefetches_and_the_team_are_not_counted(): void
    {
        $this->withHeader('User-Agent', 'Mozilla/5.0 (compatible; Googlebot/2.1)')->get('/')->assertOk();
        $this->withHeader('User-Agent', 'facebookexternalhit/1.1')->get('/')->assertOk();
        $this->withHeader('User-Agent', self::PHONE)->withHeader('Sec-Purpose', 'prefetch')->get('/')->assertOk();
        $this->actingAs(User::factory()->create(['is_platform_admin' => true]))
            ->withHeader('User-Agent', self::PHONE)->withHeader('Sec-Purpose', '')->get('/')->assertOk();

        $this->assertSame(0, MarketingVisit::count());
    }

    public function test_the_page_steps_move_the_visit_forward_never_back(): void
    {
        $this->get('/')->assertOk();
        $token = $this->token();

        $this->post('/visite/etape', ['visit' => $token, 'stage' => 'started'])->assertNoContent();
        $this->assertSame(MarketingVisit::STARTED, $this->visit()->stage);

        $this->post('/visite/etape', ['visit' => $token, 'stage' => 'refused', 'fields' => 'email,desired_subdomain,evil'])->assertNoContent();
        $this->assertSame(MarketingVisit::REFUSED, $this->visit()->stage);
        $this->assertSame('desired_subdomain,email', $this->visit()->refused_fields, 'only known fields, in form order');

        // A late « started » does not move it back.
        $this->post('/visite/etape', ['visit' => $token, 'stage' => 'started'])->assertNoContent();
        $this->assertSame(MarketingVisit::REFUSED, $this->visit()->stage);
    }

    public function test_the_page_cannot_claim_a_sent_request_or_touch_another_visit(): void
    {
        $this->get('/')->assertOk();
        $id = $this->visit()->id;

        $this->post('/visite/etape', ['visit' => $this->token(), 'stage' => 'sent'])->assertNoContent();
        $this->post('/visite/etape', ['visit' => $id.'.forged', 'stage' => 'tried'])->assertNoContent();

        $this->assertSame(MarketingVisit::OPENED, $this->visit()->stage);
    }

    public function test_the_step_route_opens_no_session(): void
    {
        // It is sent while the form posts: writing the session there could
        // drop what the « merci » page reads.
        $this->get('/')->assertOk();
        $response = $this->post('/visite/etape', ['visit' => $this->token(), 'stage' => 'tried']);

        $response->assertNoContent();
        $this->assertSame([], $response->headers->getCookies());
    }

    public function test_a_server_refusal_names_the_fields(): void
    {
        $this->get('/')->assertOk();
        $this->signUp(['email' => 'not-an-email'])->assertRedirect();

        $v = $this->visit();
        $this->assertSame(MarketingVisit::REFUSED, $v->stage);
        $this->assertSame('email', $v->refused_fields);
    }

    public function test_a_sent_request_closes_the_visit_and_links_it(): void
    {
        $this->get('/?utm_source=youtube&utm_campaign=video-en-nov')->assertOk();
        $this->signUp(['email' => 'bad'])->assertRedirect();
        $this->signUp()->assertRedirect(route('castlit.subscribe.success'));

        $v = $this->visit();
        $sub = Subscription::query()->latest('id')->firstOrFail();
        $this->assertSame(MarketingVisit::SENT, $v->stage, 'a refused visit can still be sent');
        $this->assertSame($sub->id, $v->subscription_id);
    }

    public function test_visits_are_kept_ninety_days(): void
    {
        $this->get('/')->assertOk();
        $old = $this->visit();
        $old->forceFill(['created_at' => now()->subDays(91)])->save();
        MarketingVisit::create(['stage' => MarketingVisit::OPENED]);

        $this->assertSame(1, MarketingVisits::prune());
        $this->assertSame(1, MarketingVisit::count());
    }

    public function test_the_admin_sees_the_funnel_the_campaigns_and_each_visit(): void
    {
        $make = fn (string $stage, ?string $source, ?string $name, string $ip) => MarketingVisit::create([
            'stage' => $stage, 'campaign_source' => $source, 'campaign_name' => $name, 'ip' => $ip, 'device' => 'phone', 'country' => 'NG',
        ]);
        $make('opened', 'youtube', 'video-en-nov', '102.0.0.1');
        $make('started', 'youtube', 'video-en-nov', '102.0.0.2');
        $make('refused', 'youtube', 'video-en-nov', '102.0.0.3');
        $make('sent', 'facebook', 'promo', '102.0.0.4');

        $admin = User::factory()->create(['is_platform_admin' => true]);
        $page = $this->actingAs($admin)->get(route('castlit.admin.visits'))->assertOk();

        $this->assertSame(['opened' => 4, 'started' => 3, 'tried' => 2, 'refused' => 1, 'sent' => 1], $page->viewData('funnel'));
        $youtube = $page->viewData('campaigns')->firstWhere('source', 'youtube');
        $this->assertSame([3, 2, 1, 0], [$youtube['opened'], $youtube['started'], $youtube['tried'], $youtube['sent']]);
        $page->assertSee('102.0.0.3')->assertSee('youtube · video-en-nov', false);

        $filtered = $this->actingAs($admin)->get(route('castlit.admin.visits', ['campaign_source' => 'facebook', 'campaign_name' => 'promo']))->assertOk();
        $this->assertSame(1, $filtered->viewData('funnel')['opened']);
    }

    public function test_the_campaign_summary_counts_visits_beside_requests(): void
    {
        MarketingVisit::create(['stage' => 'opened', 'campaign_source' => 'youtube', 'campaign_name' => 'video-en-nov']);
        MarketingVisit::create(['stage' => 'opened', 'campaign_source' => 'youtube', 'campaign_name' => 'video-en-nov']);

        $admin = User::factory()->create(['is_platform_admin' => true]);
        $campaigns = $this->actingAs($admin)->get(route('castlit.admin.index', ['status' => 'all']))->assertOk()->viewData('campaigns');

        $row = $campaigns->firstWhere('source', 'youtube');
        $this->assertSame(2, $row['visits']);
        $this->assertSame(0, $row['total'], 'a campaign with visits and no request still shows');
    }

    public function test_only_the_master_has_the_visit_routes(): void
    {
        // A client space has no marketing page: nothing to count there.
        $this->assertTrue(Route::has('castlit.visit.stage'));
        $this->assertTrue(Route::has('castlit.admin.visits'));
    }

    public function test_a_value_no_list_offers_marks_a_probable_robot(): void
    {
        $this->get('/')->assertOk();
        $this->post('/visite/etape', ['visit' => $this->token(), 'stage' => 'started'])->assertNoContent();

        $this->signUp(['activity' => 'free text from a script', 'desired_subdomain' => 'Bad Address!'])->assertRedirect();

        $v = $this->visit();
        $this->assertSame(MarketingVisit::REFUSED, $v->stage);
        $this->assertSame('impossible', $v->robot_reason);
    }

    public function test_a_form_sent_without_touching_a_field_is_a_probable_robot(): void
    {
        $this->get('/')->assertOk();
        $this->signUp(['email' => 'not-an-email'])->assertRedirect();

        $this->assertSame('untouched', $this->visit()->robot_reason);
    }

    public function test_the_hidden_anti_spam_field_marks_a_probable_robot(): void
    {
        $this->get('/')->assertOk();
        $this->post('/visite/etape', ['visit' => $this->token(), 'stage' => 'started'])->assertNoContent();
        $this->signUp(['website' => 'https://spam.example'])->assertRedirect();

        $this->assertSame('honeypot', $this->visit()->robot_reason);
    }

    public function test_a_person_who_typed_and_made_a_mistake_is_not_a_robot(): void
    {
        $this->get('/')->assertOk();
        $this->post('/visite/etape', ['visit' => $this->token(), 'stage' => 'started'])->assertNoContent();
        $this->signUp(['email' => 'not-an-email'])->assertRedirect();
        $this->signUp()->assertRedirect(route('castlit.subscribe.success'));

        $v = $this->visit();
        $this->assertSame(MarketingVisit::SENT, $v->stage);
        $this->assertNull($v->robot_reason);
    }

    public function test_probable_robots_leave_the_counts_unless_asked_for(): void
    {
        MarketingVisit::create(['stage' => 'opened', 'campaign_source' => 'youtube', 'campaign_name' => 'v', 'ip' => '102.0.0.1']);
        MarketingVisit::create(['stage' => 'refused', 'campaign_source' => 'youtube', 'campaign_name' => 'v', 'ip' => '31.76.93.71',
            'robot_reason' => 'impossible', 'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) ScriptedBrowser/1.0']);

        $admin = User::factory()->create(['is_platform_admin' => true]);
        $page = $this->actingAs($admin)->get(route('castlit.admin.visits'))->assertOk();
        $this->assertSame(1, $page->viewData('funnel')['opened']);
        $this->assertSame(1, $page->viewData('robots'));
        $page->assertDontSee('31.76.93.71');

        $all = $this->actingAs($admin)->get(route('castlit.admin.visits', ['robots' => 1]))->assertOk();
        $this->assertSame(2, $all->viewData('funnel')['opened']);
        $all->assertSee('31.76.93.71')->assertSee('Robot probable', false)->assertSee('ScriptedBrowser/1.0', false);

        $campaigns = $this->actingAs($admin)->get(route('castlit.admin.index', ['status' => 'all']))->viewData('campaigns');
        $this->assertSame(1, $campaigns->firstWhere('source', 'youtube')['visits']);
    }
}
