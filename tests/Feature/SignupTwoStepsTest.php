<?php

namespace Tests\Feature;

use App\Models\MarketingVisit;
use App\Models\User;
use App\Support\MarketingVisits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ActsAsMasterInstall;
use Tests\TestCase;

/**
 * A visitor from Google Ads pressed « Envoyer » on an empty nine-field form,
 * saw four red fields and left; nothing told us who it was. The form now
 * asks for the email and business name first and keeps them at once, offers
 * the demo or a word with us, and « Visites » shows who to write to.
 */
class SignupTwoStepsTest extends TestCase
{
    use ActsAsMasterInstall {
        setUp as actsAsMasterSetUp;
    }
    use RefreshDatabase;

    private const PHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Safari';

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

    public function test_the_first_step_asks_for_the_name_the_link_and_the_email_only(): void
    {
        $html = $this->get('/?lang=fr')->assertOk()->getContent();

        $continue = strpos($html, 'data-continue>');
        $step2 = strpos($html, 'data-step2>');
        foreach (['id="business_name"', 'data-link-fld', 'id="email"'] as $first) {
            $this->assertLessThan($continue, strpos($html, $first), $first.' is on the first step');
        }
        foreach (['id="contact_name"', 'id="phone"', 'id="activity"', 'id="country"', 'id="city"', 'id="address"', 'id="currency"', 'id="heard_about"'] as $second) {
            $this->assertGreaterThan($step2, strpos($html, $second), $second.' waits for the second step');
        }
        $this->assertStringContainsString('Continuer →', $html);
        $this->assertStringContainsString('Étape 2 sur 2', $html);
    }

    public function test_a_way_out_for_a_visitor_not_ready_to_fill_a_form(): void
    {
        config(['castlit.demo.url' => 'https://demo.castlitpos.com']);

        $this->get('/?lang=fr')->assertOk()
            ->assertSee('Pas encore prêt ?')
            ->assertSee('data-wayout-demo', false)
            ->assertSee('https://demo.castlitpos.com', false)
            ->assertSee('data-wayout-chat', false);
    }

    public function test_the_page_carries_its_visit_for_the_first_step(): void
    {
        $html = $this->get('/?lang=fr')->assertOk()->getContent();

        $this->assertStringContainsString('data-visit-token="'.MarketingVisits::token($this->visit()).'"', $html);
    }

    public function test_the_first_step_keeps_the_email_and_business_name(): void
    {
        $this->get('/?utm_source=google&utm_medium=cpc')->assertOk();

        $this->post('/inscription/brouillon', [
            'visit' => MarketingVisits::token($this->visit()),
            'email' => ' Owner@Example.COM ',
            'business_name' => 'Atlas Shop',
        ])->assertNoContent();

        $v = $this->visit();
        $this->assertSame('owner@example.com', $v->lead_email);
        $this->assertSame('Atlas Shop', $v->lead_business);
        $this->assertNotNull($v->lead_at);
        $this->assertSame(MarketingVisit::LEAD, $v->stage);
    }

    public function test_a_bad_email_or_a_forged_visit_keeps_nothing(): void
    {
        $this->get('/')->assertOk();
        $id = $this->visit()->id;

        $this->post('/inscription/brouillon', ['visit' => MarketingVisits::token($this->visit()), 'email' => 'not-an-email', 'business_name' => 'X'])->assertNoContent();
        $this->post('/inscription/brouillon', ['visit' => $id.'.forged', 'email' => 'a@b.co', 'business_name' => 'X'])->assertNoContent();

        $this->assertNull($this->visit()->lead_email);
    }

    public function test_the_first_step_opens_no_session(): void
    {
        $this->get('/')->assertOk();
        $response = $this->post('/inscription/brouillon', ['visit' => MarketingVisits::token($this->visit()), 'email' => 'a@b.co']);

        $this->assertSame([], $response->headers->getCookies());
    }

    public function test_a_refused_form_opens_on_the_second_step(): void
    {
        $this->post('/inscription', ['business_name' => 'Atlas Shop'])->assertRedirect();

        $this->assertMatchesRegularExpression('/data-signup-form[^>]*class="on-step2"/', $this->get('/?lang=fr')->getContent());
    }

    public function test_a_form_sent_empty_is_told_apart_from_a_typo(): void
    {
        $this->get('/')->assertOk();
        $this->post('/visite/etape', ['visit' => MarketingVisits::token($this->visit()), 'stage' => 'refused', 'fields' => 'empty'])->assertNoContent();

        $this->assertSame(MarketingVisits::EMPTY_FORM, $this->visit()->refused_fields);

        $admin = User::factory()->create(['is_platform_admin' => true]);
        $this->actingAs($admin)->get(route('castlit.admin.visits'))->assertOk()->assertSee('bouton pressé, formulaire vide');
    }

    public function test_the_team_sees_who_left_an_email_without_sending(): void
    {
        MarketingVisit::create(['stage' => 'lead', 'lead_email' => 'owner@example.com', 'lead_business' => 'Atlas Shop', 'ip' => '102.0.0.1']);
        MarketingVisit::create(['stage' => 'sent', 'lead_email' => 'done@example.com', 'lead_business' => 'Done', 'ip' => '102.0.0.2']);
        MarketingVisit::create(['stage' => 'opened', 'ip' => '102.0.0.3']);
        $admin = User::factory()->create(['is_platform_admin' => true]);

        $this->actingAs($admin)->get(route('castlit.admin.index'))->assertOk()
            ->assertSee('data-followups', false)
            ->assertSee('1 personne a laissé son email');

        $page = $this->actingAs($admin)->get(route('castlit.admin.visits'))->assertOk();
        $this->assertSame(2, $page->viewData('funnel')['lead']);
        $this->assertSame(1, $page->viewData('followups'));

        $followup = $this->actingAs($admin)->get(route('castlit.admin.visits', ['followup' => 1]))->assertOk();
        $this->assertSame(1, $followup->viewData('visits')->total());
        $followup->assertSee('mailto:owner@example.com', false)->assertSee('Atlas Shop')->assertDontSee('done@example.com');
    }
}
