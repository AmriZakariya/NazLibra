<?php

namespace Tests\Feature;

use App\Models\MarketingVisit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\ActsAsMasterInstall;
use Tests\TestCase;

/**
 * castlit-admin › Visites as a working table: sort by any column (on the
 * whole list, not one page), filter by country, language, campaign or a
 * search, a pager that renders without Tailwind, and the monitoring strip.
 */
class VisitsAdminPageTest extends TestCase
{
    use ActsAsMasterInstall {
        setUp as actsAsMasterSetUp;
    }
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        $this->actsAsMasterSetUp();
        $this->admin = User::factory()->create(['is_platform_admin' => true]);
    }

    private function visit(array $attributes, ?string $at = null): MarketingVisit
    {
        $v = MarketingVisit::create($attributes + ['stage' => 'opened']);
        if ($at) {
            $v->forceFill(['created_at' => $at])->save();
        }

        return $v;
    }

    private function page(array $query = [])
    {
        return $this->actingAs($this->admin)->get(route('castlit.admin.visits', $query))->assertOk();
    }

    private function ips($page): array
    {
        return collect($page->viewData('visits')->items())->pluck('ip')->all();
    }

    public function test_any_column_sorts_the_whole_list_both_ways(): void
    {
        $this->visit(['ip' => '10.0.0.2', 'country' => 'MA']);
        $this->visit(['ip' => '10.0.0.1', 'country' => 'FR']);
        $this->visit(['ip' => '10.0.0.3', 'country' => 'BE']);

        $this->assertSame(['10.0.0.1', '10.0.0.2', '10.0.0.3'], $this->ips($this->page(['sort' => 'ip', 'dir' => 'asc'])));
        $this->assertSame(['10.0.0.3', '10.0.0.2', '10.0.0.1'], $this->ips($this->page(['sort' => 'ip', 'dir' => 'desc'])));
        $this->assertSame(['10.0.0.3', '10.0.0.1', '10.0.0.2'], $this->ips($this->page(['sort' => 'country', 'dir' => 'asc'])));
    }

    public function test_the_stage_sorts_along_the_way_to_a_request_not_alphabetically(): void
    {
        $this->visit(['ip' => 'sent', 'stage' => 'sent']);
        $this->visit(['ip' => 'opened', 'stage' => 'opened']);
        $this->visit(['ip' => 'lead', 'stage' => 'lead']);

        $this->assertSame(['sent', 'lead', 'opened'], $this->ips($this->page(['sort' => 'stage', 'dir' => 'desc'])));
    }

    public function test_the_header_links_flip_the_order_and_keep_the_filters(): void
    {
        $this->visit(['ip' => '10.0.0.1', 'country' => 'MA']);

        $html = $this->page(['country' => 'MA', 'sort' => 'ip', 'dir' => 'desc'])->getContent();

        $this->assertMatchesRegularExpression('/href="[^"]*country=MA[^"]*sort=ip[^"]*dir=asc[^"]*"[^>]*data-sort="ip"|href="[^"]*dir=asc[^"]*sort=ip[^"]*"[^>]*data-sort="ip"/', html_entity_decode($html));
    }

    public function test_filters_by_country_language_campaign_and_a_search(): void
    {
        $this->visit(['ip' => '10.0.0.1', 'country' => 'MA', 'locale' => 'fr', 'campaign_source' => 'youtube', 'campaign_name' => 'video-en-nov']);
        $this->visit(['ip' => '10.0.0.2', 'country' => 'BE', 'locale' => 'en', 'campaign_source' => 'google', 'lead_email' => 'owner@atlas.test', 'lead_business' => 'Atlas Shop']);

        $this->assertSame(['10.0.0.1'], $this->ips($this->page(['country' => 'ma'])));
        $this->assertSame(['10.0.0.2'], $this->ips($this->page(['locale' => 'en'])));
        $this->assertSame(['10.0.0.1'], $this->ips($this->page(['campaign' => 'youtube|video-en-nov'])));
        $this->assertSame(['10.0.0.2'], $this->ips($this->page(['campaign' => 'google|'])), 'a campaign without a name');
        $this->assertSame(['10.0.0.2'], $this->ips($this->page(['q' => 'atlas'])), 'by business name');
        $this->assertSame(['10.0.0.2'], $this->ips($this->page(['q' => 'owner@'])), 'by email');
        $this->assertSame(['10.0.0.1'], $this->ips($this->page(['q' => '10.0.0.1'])), 'by IP');
    }

    public function test_the_lists_offer_what_was_recorded(): void
    {
        $this->visit(['country' => 'NG', 'locale' => 'en', 'campaign_source' => 'facebook', 'campaign_name' => 'promo']);

        $options = $this->page()->viewData('options');
        $this->assertSame(['NG'], $options['countries']);
        $this->assertSame(['en'], $options['locales']);
        $this->assertSame([['value' => 'facebook|promo', 'label' => 'facebook · promo']], $options['campaigns']);
    }

    public function test_the_pager_renders_on_its_own_and_pages_do_not_overlap(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->visit(['ip' => sprintf('10.0.0.%02d', $i)]);
        }

        $first = $this->page(['per_page' => 25]);
        $second = $this->page(['per_page' => 25, 'page' => 2]);

        $this->assertCount(25, $first->viewData('visits')->items());
        $this->assertCount(5, $second->viewData('visits')->items());
        $this->assertSame([], array_intersect($this->ips($first), $this->ips($second)));

        $html = $first->getContent();
        $this->assertStringContainsString('data-pager', $html);
        $this->assertStringContainsString('Page 1 sur 2', $html);
        $this->assertStringContainsString('1–25 sur 30', $html);
        // Not Laravel's default view, which needs Tailwind this layout lacks.
        $this->assertStringNotContainsString('relative inline-flex', $html);
    }

    public function test_quick_periods_set_the_dates(): void
    {
        $this->visit(['ip' => 'old'], now()->subDays(20)->toDateTimeString());
        $this->visit(['ip' => 'new']);

        $this->assertSame(['new'], $this->ips($this->page(['period' => 'today'])));
        $this->assertEqualsCanonicalizing(['new', 'old'], $this->ips($this->page(['period' => '30d'])));
    }

    public function test_the_chart_counts_each_day_and_the_live_strip_ignores_filters(): void
    {
        $this->visit(['ip' => 'a', 'lead_email' => 'x@y.co', 'stage' => 'lead']);
        $this->visit(['ip' => 'b', 'stage' => 'sent', 'country' => 'MA']);
        $this->visit(['ip' => 'c', 'robot_reason' => 'impossible']);

        $page = $this->page(['country' => 'FR']);
        $today = collect($page->viewData('daily'))->last();
        $this->assertSame(0, $today['opened'], 'the chart follows the filters');

        $all = collect($this->page()->viewData('daily'))->last();
        $this->assertSame(['opened' => 2, 'lead' => 1, 'sent' => 1], array_intersect_key($all, array_flip(['opened', 'lead', 'sent'])));

        $live = $page->viewData('live');
        $this->assertSame(2, $live['hour'], 'robots apart, filters ignored');
        $this->assertSame(2, $live['today']);
        $page->assertSee('data-live', false)->assertSee('Actualiser chaque minute');
    }

    public function test_the_last_visit_is_the_most_recent_one_not_the_last_row(): void
    {
        $this->visit(['ip' => 'recent'], now()->subMinutes(3)->toDateTimeString());
        $this->visit(['ip' => 'older-but-added-last'], now()->subDays(2)->toDateTimeString());

        $last = Carbon::parse($this->page()->viewData('live')['last']);
        $this->assertTrue($last->greaterThan(now()->subMinutes(5)));
    }

    public function test_rows_say_at_a_glance_who_left_an_email_and_who_sent(): void
    {
        $this->visit(['ip' => '10.0.0.1', 'lead_email' => 'x@y.co', 'stage' => 'lead']);
        $this->visit(['ip' => '10.0.0.2', 'stage' => 'sent']);

        $html = $this->page()->getContent();
        $this->assertStringContainsString('<tr data-visit class="is-lead">', $html);
        $this->assertStringContainsString('<tr data-visit class="is-sent">', $html);
        $this->assertStringContainsString('il y a', $html, 'times read as « il y a … »');
    }
}
