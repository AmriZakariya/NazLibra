<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ActivityRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The journal's cards and its table disagreed: « Aujourd'hui 4 » above an
 * empty table. The cards counted page views, which the table's default
 * « Type » hides, and over a UTC day rather than the shop's.
 */
class ActivityJournalCountsTest extends TestCase
{
    use RefreshDatabase;

    private const ZONE = 'Africa/Casablanca';

    private User $owner;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->owner = User::where('email', 'amina@librairie-atlas.ma')->firstOrFail();
        $this->tenant = $this->owner->currentTenant;
        $this->tenant->forceFill(['timezone' => self::ZONE])->save();
        AuditLog::query()->where('tenant_id', $this->tenant->id)->delete();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function log(string $kind, \DateTimeInterface $at): AuditLog
    {
        return AuditLog::forceCreate([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->owner->id,
            'kind' => $kind,
            'action' => $kind === ActivityRecorder::VIEW ? 'dashboard' : 'pos.store',
            'properties' => ['method' => $kind === ActivityRecorder::VIEW ? 'GET' : 'POST', 'path' => 'caisse'],
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    /** The table's own count for the same filters. */
    private function tableCount(array $filters = []): int
    {
        return (int) $this->actingAs($this->owner)
            ->getJson(route('profile.activity.data', $filters))
            ->assertOk()
            ->json('recordsTotal');
    }

    public function test_page_views_no_longer_inflate_the_cards_above_an_empty_table(): void
    {
        $this->log(ActivityRecorder::VIEW, now());
        $this->log(ActivityRecorder::VIEW, now());

        $today = now(self::ZONE)->toDateString();
        $page = $this->actingAs($this->owner)->get(route('profile.activity', ['from' => $today, 'to' => $today]))->assertOk();

        $this->assertSame(0, $page->viewData('totals')['today']);
        $this->assertSame(0, $page->viewData('totals')['all']);
        $this->assertSame(0, $this->tableCount(['from' => $today, 'to' => $today]));
    }

    public function test_the_cards_count_what_the_table_shows(): void
    {
        $this->log(ActivityRecorder::ACTION, now());
        $this->log(ActivityRecorder::AUTH, now());
        $this->log(ActivityRecorder::ACTION, now()->subDays(3));
        $this->log(ActivityRecorder::VIEW, now());

        $page = $this->actingAs($this->owner)->get(route('profile.activity'))->assertOk();
        $this->assertSame(3, $page->viewData('totals')['all']);
        $this->assertSame(2, $page->viewData('totals')['today']);
        $this->assertSame(3, $this->tableCount());

        // A narrower « Type » narrows the cards the same way.
        $actions = $this->actingAs($this->owner)->get(route('profile.activity', ['kind' => 'action']))->assertOk();
        $this->assertSame(2, $actions->viewData('totals')['all']);
        $this->assertSame(1, $actions->viewData('totals')['today']);
        $this->assertSame(2, $this->tableCount(['kind' => 'action']));
    }

    public function test_hidden_page_views_are_announced_with_a_link_to_show_them(): void
    {
        $this->log(ActivityRecorder::VIEW, now());
        $this->log(ActivityRecorder::VIEW, now());
        $today = now(self::ZONE)->toDateString();

        $page = $this->actingAs($this->owner)->get(route('profile.activity', ['from' => $today, 'to' => $today]))->assertOk();

        $this->assertGreaterThanOrEqual(2, $page->viewData('hiddenViews'));
        $page->assertSee('data-hidden-views', false)
            ->assertSee(e(route('profile.activity', ['from' => $today, 'to' => $today, 'kind' => 'all'])), false);

        // With « Tout », nothing is hidden and nothing is announced.
        $all = $this->actingAs($this->owner)->get(route('profile.activity', ['kind' => 'all']))->assertOk();
        $this->assertSame(0, $all->viewData('hiddenViews'));
        $all->assertDontSee('data-hidden-views', false);
    }

    public function test_the_day_is_the_shops_day_not_a_utc_day(): void
    {
        // 00:30 on 12 November in Casablanca (UTC+1) is still 11 November in UTC.
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-12 10:00', self::ZONE)->utc());
        CarbonImmutable::setTestNow(Carbon::now());
        $this->log(ActivityRecorder::ACTION, CarbonImmutable::parse('2026-11-12 00:30', self::ZONE)->utc());
        $this->log(ActivityRecorder::ACTION, CarbonImmutable::parse('2026-11-11 23:30', self::ZONE)->utc());

        $page = $this->actingAs($this->owner)->get(route('profile.activity'))->assertOk();
        $this->assertSame(1, $page->viewData('totals')['today']);

        $this->assertSame(1, $this->tableCount(['from' => '2026-11-12', 'to' => '2026-11-12']));
        $this->assertSame(1, $this->tableCount(['from' => '2026-11-11', 'to' => '2026-11-11']));
    }
}
