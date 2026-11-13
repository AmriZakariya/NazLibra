<?php

namespace App\Http\Controllers\Castlit;

use App\Http\Controllers\Controller;
use App\Models\MarketingVisit;
use App\Support\TenantClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** castlit-admin › Visites: who opened the landing page, and how far they got. */
class MarketingVisitAdminController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'campaign_source' => ['nullable', 'string', 'max:60'],
            'campaign_name' => ['nullable', 'string', 'max:120'],
            'stage' => ['nullable', 'in:'.implode(',', array_keys(MarketingVisit::STAGES))],
            'device' => ['nullable', 'in:phone,tablet,computer'],
            'q' => ['nullable', 'string', 'max:60'],
        ]);

        // Days on the team's clock, stored as UTC.
        $zone = TenantClock::DEFAULT_TIMEZONE;
        $today = now($zone)->toDateString();
        $from = $filters['from'] ?? now($zone)->subDays(6)->toDateString();
        $to = $filters['to'] ?? $today;

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
        if (filled($filters['device'] ?? null)) {
            $base->where('device', $filters['device']);
        }
        if (filled($filters['q'] ?? null)) {
            $base->where('ip', 'like', '%'.trim($filters['q']).'%');
        }

        // The funnel and the per-campaign table count the period; the list
        // below also narrows to a stage.
        $rows = (clone $base)->get(['id', 'stage', 'campaign_source', 'campaign_name', 'campaign_medium']);
        $reached = fn ($set, string $stage) => $set->filter(fn ($v) => MarketingVisit::STAGES[$v->stage] >= MarketingVisit::STAGES[$stage])->count();
        $funnel = [
            'opened' => $rows->count(),
            'started' => $reached($rows, MarketingVisit::STARTED),
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
                'tried' => $reached($set, MarketingVisit::TRIED),
                'sent' => $set->where('stage', MarketingVisit::SENT)->count(),
            ])
            ->sortByDesc('opened')
            ->values();

        $list = (clone $base)->with('subscription:id,business_name')
            ->when(filled($filters['stage'] ?? null), fn ($q) => $q->where('stage', $filters['stage']))
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return view('castlit.admin.visits', [
            'visits' => $list,
            'funnel' => $funnel,
            'campaigns' => $campaigns,
            'filters' => array_merge($filters, ['from' => $from, 'to' => $to]),
            'zone' => $zone,
        ]);
    }
}
