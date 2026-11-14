<?php

namespace App\Http\Controllers\Castlit;

use App\Http\Controllers\Controller;
use App\Models\MarketingVisit;
use App\Support\TenantClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** castlit-admin › Visites: who opened the landing page, and how far they got. */
class MarketingVisitAdminController extends Controller
{
    /** Columns the list can be sorted by (the header links). */
    public const SORTS = ['created_at', 'ip', 'country', 'device', 'locale', 'campaign', 'stage'];

    public const PER_PAGE = [25, 50, 100];

    /** Quick periods above the filters, in days counted back from today. */
    public const PERIODS = ['today' => 0, '7d' => 6, '30d' => 29];

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'period' => ['nullable', 'in:'.implode(',', array_keys(self::PERIODS))],
            'campaign_source' => ['nullable', 'string', 'max:60'],
            'campaign_name' => ['nullable', 'string', 'max:120'],
            // The campaign select: « source|name », « - » for no name.
            'campaign' => ['nullable', 'string', 'max:190'],
            'stage' => ['nullable', 'in:'.implode(',', array_keys(MarketingVisit::STAGES))],
            'device' => ['nullable', 'in:phone,tablet,computer'],
            'country' => ['nullable', 'string', 'size:2'],
            'locale' => ['nullable', 'string', 'max:5'],
            // IP, email or business name.
            'q' => ['nullable', 'string', 'max:120'],
            'robots' => ['nullable', 'boolean'],
            // Gave an email at the first step and sent nothing: to write to.
            'followup' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'in:'.implode(',', self::SORTS)],
            'dir' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'in:'.implode(',', self::PER_PAGE)],
        ]);
        $withRobots = (bool) ($filters['robots'] ?? false);

        if (filled($filters['campaign'] ?? null)) {
            [$source, $name] = array_pad(explode('|', $filters['campaign'], 2), 2, '');
            $filters['campaign_source'] = $source;
            $filters['campaign_name'] = $name === '' ? '-' : $name;
        }

        // Days on the team's clock, stored as UTC. A quick period wins over dates.
        $zone = TenantClock::DEFAULT_TIMEZONE;
        $today = now($zone)->toDateString();
        if (filled($filters['period'] ?? null)) {
            $from = now($zone)->subDays(self::PERIODS[$filters['period']])->toDateString();
            $to = $today;
        } else {
            $from = $filters['from'] ?? now($zone)->subDays(6)->toDateString();
            $to = $filters['to'] ?? $today;
        }

        $base = MarketingVisit::query()
            ->where('created_at', '>=', CarbonImmutable::parse($from, $zone)->startOfDay()->utc())
            ->where('created_at', '<=', CarbonImmutable::parse($to, $zone)->endOfDay()->utc());

        if (filled($filters['campaign_source'] ?? null)) {
            $base->where('campaign_source', $filters['campaign_source']);
            if (filled($filters['campaign_name'] ?? null)) {
                $filters['campaign_name'] === '-'
                    ? $base->whereNull('campaign_name')
                    : $base->where('campaign_name', $filters['campaign_name']);
            }
        }
        foreach (['device', 'country', 'locale'] as $column) {
            if (filled($filters[$column] ?? null)) {
                $base->where($column, $column === 'country' ? strtoupper($filters[$column]) : $filters[$column]);
            }
        }
        if (filled($filters['q'] ?? null)) {
            $q = '%'.trim($filters['q']).'%';
            $base->where(fn (Builder $w) => $w->where('ip', 'like', $q)
                ->orWhere('lead_email', 'like', $q)
                ->orWhere('lead_business', 'like', $q)
                ->orWhereHas('subscription', fn (Builder $s) => $s->where('business_name', 'like', $q)->orWhere('email', 'like', $q)));
        }
        $robots = (clone $base)->whereNotNull('robot_reason')->count();
        // Probable robots stay out of the counts and the list unless asked for.
        if (! $withRobots) {
            $base->whereNull('robot_reason');
        }

        // The funnel, the per-campaign table and the chart count the period;
        // the list below also narrows to a stage.
        $rows = (clone $base)->get(['id', 'stage', 'campaign_source', 'campaign_name', 'campaign_medium', 'lead_email', 'created_at']);
        $reached = fn ($set, string $stage) => $set->filter(fn ($v) => MarketingVisit::STAGES[$v->stage] >= MarketingVisit::STAGES[$stage])->count();
        $funnel = [
            'opened' => $rows->count(),
            'started' => $reached($rows, MarketingVisit::STARTED),
            'lead' => $rows->whereNotNull('lead_email')->count(),
            'tried' => $reached($rows, MarketingVisit::TRIED),
            'refused' => $rows->where('stage', MarketingVisit::REFUSED)->count(),
            'sent' => $rows->where('stage', MarketingVisit::SENT)->count(),
        ];
        $campaigns = $rows
            ->groupBy(fn ($v) => ($v->campaign_source ?? '')."\0".($v->campaign_name ?? ''))
            ->map(fn ($set) => [
                'source' => $set->first()->campaign_source,
                'name' => $set->first()->campaign_name,
                'label' => $set->first()->campaignLabel() ?? 'Non renseignée',
                'opened' => $set->count(),
                'started' => $reached($set, MarketingVisit::STARTED),
                'lead' => $set->whereNotNull('lead_email')->count(),
                'tried' => $reached($set, MarketingVisit::TRIED),
                'sent' => $set->where('stage', MarketingVisit::SENT)->count(),
            ])
            ->sortByDesc('opened')
            ->values();

        $sort = $filters['sort'] ?? 'created_at';
        $dir = $filters['dir'] ?? 'desc';
        $perPage = (int) ($filters['per_page'] ?? 50);

        $list = (clone $base)->with('subscription:id,business_name')
            ->when(filled($filters['stage'] ?? null), fn ($q) => $q->where('stage', $filters['stage']))
            ->when((bool) ($filters['followup'] ?? false), fn ($q) => $q->whereNotNull('lead_email')->where('stage', '!=', MarketingVisit::SENT));
        $this->sortBy($list, $sort, $dir);

        return view('castlit.admin.visits', [
            'visits' => $list->paginate($perPage)->withQueryString(),
            'funnel' => $funnel,
            'campaigns' => $campaigns,
            'daily' => $this->daily($rows, $from, $to, $zone),
            'live' => $this->live(),
            'options' => $this->options(),
            'filters' => array_merge($filters, ['from' => $from, 'to' => $to]),
            'sort' => $sort,
            'dir' => $dir,
            'perPage' => $perPage,
            'zone' => $zone,
            'robots' => $robots,
            'withRobots' => $withRobots,
            'followups' => (clone $base)->whereNotNull('lead_email')->where('stage', '!=', MarketingVisit::SENT)->count(),
        ]);
    }

    /** The list's order. Ties fall back on the newest, so pages never overlap. */
    private function sortBy(Builder $query, string $sort, string $dir): void
    {
        match ($sort) {
            // Along the way to a request, not alphabetically.
            'stage' => $query->orderByRaw(
                'CASE stage '.collect(MarketingVisit::STAGES)->map(fn ($rank, $stage) => "WHEN '{$stage}' THEN {$rank}")->implode(' ').' ELSE 0 END '.$dir
            ),
            'campaign' => $query->orderBy('campaign_source', $dir)->orderBy('campaign_name', $dir),
            default => $query->orderBy($sort, $dir),
        };
        $query->orderByDesc('id');
    }

    /**
     * Visits, emails left and requests sent per day of the period, for the
     * chart (at most the last 92 days of it).
     *
     * @return list<array{date: string, label: string, opened: int, lead: int, sent: int}>
     */
    private function daily($rows, string $from, string $to, string $zone): array
    {
        $start = CarbonImmutable::parse($from, $zone)->startOfDay();
        $end = CarbonImmutable::parse($to, $zone)->startOfDay();
        if ($start->diffInDays($end) > 91) {
            $start = $end->subDays(91);
        }
        $byDay = $rows->groupBy(fn ($v) => $v->created_at->copy()->setTimezone($zone)->toDateString());

        $days = [];
        for ($day = $start; $day <= $end; $day = $day->addDay()) {
            $set = $byDay[$day->toDateString()] ?? collect();
            $days[] = [
                'date' => $day->toDateString(),
                'label' => $day->format('d/m'),
                'opened' => $set->count(),
                'lead' => $set->whereNotNull('lead_email')->count(),
                'sent' => $set->where('stage', MarketingVisit::SENT)->count(),
            ];
        }

        return $days;
    }

    /** « En direct », whatever the filters: the last visit and the last hour. */
    private function live(): array
    {
        $people = MarketingVisit::query()->whereNull('robot_reason');

        return [
            'last' => (clone $people)->max('created_at'),
            'hour' => (clone $people)->where('created_at', '>=', now()->subHour())->count(),
            'today' => (clone $people)->where('created_at', '>=', now(TenantClock::DEFAULT_TIMEZONE)->startOfDay()->utc())->count(),
        ];
    }

    /** What the country, language and campaign lists offer: what was recorded. */
    private function options(): array
    {
        $visits = MarketingVisit::query();

        return [
            'countries' => (clone $visits)->whereNotNull('country')->distinct()->orderBy('country')->pluck('country')->all(),
            'locales' => (clone $visits)->whereNotNull('locale')->distinct()->orderBy('locale')->pluck('locale')->all(),
            'campaigns' => (clone $visits)->whereNotNull('campaign_source')
                ->select('campaign_source', 'campaign_name', 'campaign_medium')->distinct()->orderBy('campaign_source')->orderBy('campaign_name')
                ->get()->unique(fn ($v) => $v->campaign_source."\0".$v->campaign_name)
                ->map(fn ($v) => ['value' => $v->campaign_source.'|'.($v->campaign_name ?? ''), 'label' => $v->campaignLabel()])
                ->values()->all(),
        ];
    }
}
