@extends('castlit.layout')

@section('title', 'Visites — Administration Castl-it-POS')

@section('content')
@php
    $stageLabels = \App\Models\MarketingVisit::LABELS;
    $devices = ['phone' => 'Téléphone', 'tablet' => 'Tablette', 'computer' => 'Ordinateur'];
    $pct = fn (int $n) => $funnel['opened'] > 0 ? round($n * 100 / $funnel['opened']).' %' : '—';
@endphp
<style>
    .admin { padding: 40px 0 0; }
    .admin .wrap { max-width: 1440px; }
    .admin-head { display: flex; align-items: flex-end; gap: 16px; flex-wrap: wrap; margin-bottom: 22px; }
    .admin-head h1 { font-size: 26px; font-weight: 800; letter-spacing: -.02em; }
    .admin-head p { color: var(--muted); font-size: 14px; max-width: 70ch; }
    .card { background: var(--surface); border: 1px solid var(--sand); border-radius: var(--radius); box-shadow: var(--shadow); overflow: hidden; }
    .tbl-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .tbl { width: 100%; border-collapse: collapse; }
    .tbl th { text-align: left; font-size: 11.5px; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); padding: 12px 14px; border-bottom: 1px solid var(--sand); white-space: nowrap; }
    .tbl td { padding: 12px 14px; border-bottom: 1px solid color-mix(in srgb, var(--sand) 55%, transparent); font-size: 13.5px; vertical-align: middle; }
    .tbl tr:last-child td { border-bottom: none; }
    .tbl .num { text-align: end; font-variant-numeric: tabular-nums; }
    .muted { color: var(--muted); font-size: 12.5px; }
    .nowrap { white-space: nowrap; }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12.5px; }

    .filters { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; margin-bottom: 18px; }
    .filters label { display: flex; flex-direction: column; gap: 4px; font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); }
    .filters input, .filters select { font: inherit; font-size: 14px; text-transform: none; letter-spacing: 0; padding: 9px 11px; border-radius: 10px; border: 1.5px solid var(--sand); background: var(--paper); color: var(--ink); }

    .funnel { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 10px; margin-bottom: 18px; }
    .step { padding: 14px 16px; }
    .step b { display: block; font-size: 26px; font-weight: 800; font-variant-numeric: tabular-nums; letter-spacing: -.02em; }
    .step span { font-size: 12.5px; color: var(--muted); font-weight: 650; }
    .step small { display: block; margin-top: 2px; font-size: 12px; color: var(--muted); font-variant-numeric: tabular-nums; }
    .step.is-sent b { color: var(--ok); }
    .step.is-refused b { color: var(--err); }
    @media (max-width: 860px) { .funnel { grid-template-columns: repeat(2, minmax(0, 1fr)); } }

    .camp { display: inline-block; font-size: 11.5px; font-weight: 700; padding: 2px 8px; border-radius: 999px; background: color-mix(in srgb, var(--brand) 14%, transparent); color: var(--brand); white-space: nowrap; }
    .stage { display: inline-block; font-size: 12px; font-weight: 700; padding: 3px 9px; border-radius: 999px; white-space: nowrap; }
    .stage-opened { background: color-mix(in srgb, var(--sand) 60%, transparent); color: var(--muted); }
    .stage-started { background: color-mix(in srgb, var(--brand) 12%, transparent); color: var(--brand); }
    .stage-tried { background: var(--warn-bg); color: var(--warn); }
    .stage-refused { background: var(--err-bg); color: var(--err); }
    .stage-sent { background: var(--ok-bg); color: var(--ok); }
    .section-title { font-size: 15px; font-weight: 800; margin: 22px 0 10px; }
    .empty { padding: 40px; text-align: center; color: var(--muted); }
    .pager { margin: 16px 0 40px; }
</style>

<main class="admin">
    <div class="wrap">
        <div class="admin-head">
            <div>
                <h1>Visites du site</h1>
                <p>Les visites de la page castlitpos.com et l'étape atteinte vers une demande d'inscription. Robots, aperçus de liens et l'équipe connectée ne sont pas comptés. Conservées {{ \App\Support\MarketingVisits::KEEP_DAYS }} jours.</p>
            </div>
            <a href="{{ route('castlit.admin.index') }}" class="btn btn-ghost" style="margin-left:auto; padding:11px 18px">Demandes</a>
        </div>

        <form method="GET" action="{{ route('castlit.admin.visits') }}" class="filters">
            <label>Du <input type="date" name="from" value="{{ $filters['from'] }}"></label>
            <label>Au <input type="date" name="to" value="{{ $filters['to'] }}"></label>
            <label>Étape
                <select name="stage">
                    <option value="">Toutes</option>
                    @foreach ($stageLabels as $key => $label)
                        <option value="{{ $key }}" @selected(($filters['stage'] ?? '') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label>Appareil
                <select name="device">
                    <option value="">Tous</option>
                    @foreach ($devices as $key => $label)
                        <option value="{{ $key }}" @selected(($filters['device'] ?? '') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label>IP <input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="ex. 105.66" size="12"></label>
            @if (filled($filters['campaign_source'] ?? null))
                <input type="hidden" name="campaign_source" value="{{ $filters['campaign_source'] }}">
                <input type="hidden" name="campaign_name" value="{{ $filters['campaign_name'] ?? '' }}">
            @endif
            <button type="submit" class="btn btn-primary" style="padding:10px 18px">Filtrer</button>
            @if (filled($filters['campaign_source'] ?? null))
                <a href="{{ route('castlit.admin.visits', ['from' => $filters['from'], 'to' => $filters['to']]) }}" class="muted" style="align-self:center">Campagne : {{ $filters['campaign_source'] }}{{ filled($filters['campaign_name'] ?? null) ? ' · '.$filters['campaign_name'] : '' }} ✕</a>
            @endif
        </form>

        <div class="funnel" data-funnel>
            <div class="card step"><span>Page ouverte</span><b>{{ $funnel['opened'] }}</b><small>100 %</small></div>
            <div class="card step"><span>Formulaire commencé</span><b>{{ $funnel['started'] }}</b><small>{{ $pct($funnel['started']) }}</small></div>
            <div class="card step"><span>Envoi tenté</span><b>{{ $funnel['tried'] }}</b><small>{{ $pct($funnel['tried']) }}</small></div>
            <div class="card step is-refused"><span>Refusés (sans suite)</span><b>{{ $funnel['refused'] }}</b><small>{{ $pct($funnel['refused']) }}</small></div>
            <div class="card step is-sent"><span>Demande envoyée</span><b>{{ $funnel['sent'] }}</b><small>{{ $pct($funnel['sent']) }}</small></div>
        </div>

        @if ($campaigns->isNotEmpty())
            <h2 class="section-title">Par campagne</h2>
            <div class="card tbl-scroll" data-visit-campaigns>
                <table class="tbl">
                    <thead><tr><th>Source · campagne</th><th class="num">Visites</th><th class="num">Commencé</th><th class="num">Envoi tenté</th><th class="num">Envoyé</th></tr></thead>
                    <tbody>
                        @foreach ($campaigns as $c)
                            <tr>
                                <td>
                                    @if ($c['source'])
                                        <a href="{{ route('castlit.admin.visits', ['from' => $filters['from'], 'to' => $filters['to'], 'campaign_source' => $c['source'], 'campaign_name' => $c['name'] ?? '-']) }}"><span class="camp">{{ $c['label'] }}</span></a>
                                    @else
                                        <span class="muted">{{ $c['label'] }}</span>
                                    @endif
                                </td>
                                <td class="num">{{ $c['opened'] }}</td>
                                <td class="num">{{ $c['started'] }}</td>
                                <td class="num">{{ $c['tried'] }}</td>
                                <td class="num">{{ $c['sent'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <h2 class="section-title">Visites</h2>
        <div class="card tbl-scroll">
            <table class="tbl" style="min-width: 1100px">
                <thead>
                    <tr><th>Heure</th><th>IP</th><th>Pays</th><th>Appareil</th><th>Langue</th><th>Campagne</th><th>Venu de</th><th>Étape</th><th>Demande</th></tr>
                </thead>
                <tbody>
                    @forelse ($visits as $v)
                        <tr data-visit>
                            <td class="nowrap">{{ \App\Support\TenantClock::format($v->created_at, $zone, 'd/m/Y H:i') }}
                                @if ($v->last_seen_at && $v->last_seen_at->diffInMinutes($v->created_at, true) >= 1)
                                    <br><span class="muted">jusqu'à {{ \App\Support\TenantClock::format($v->last_seen_at, $zone, 'H:i') }}</span>
                                @endif
                            </td>
                            <td class="mono">{{ $v->ip ?? '—' }}</td>
                            <td class="nowrap">@if ($v->country){{ \App\Support\Countries::flag($v->country) }} {{ $v->country }}@else — @endif</td>
                            <td>{{ $devices[$v->device] ?? '—' }}</td>
                            <td>{{ strtoupper((string) $v->locale) ?: '—' }}</td>
                            <td>@if ($v->campaignLabel())<span class="camp">{{ $v->campaignLabel() }}</span>@else <span class="muted">—</span>@endif</td>
                            <td class="muted">{{ $v->referrer ?: '—' }}</td>
                            <td>
                                <span class="stage stage-{{ $v->stage }}">{{ $stageLabels[$v->stage] ?? $v->stage }}</span>
                                @if ($v->refused_fields)<br><span class="muted">sur : {{ str_replace(',', ', ', $v->refused_fields) }}</span>@endif
                            </td>
                            <td>
                                @if ($v->subscription)
                                    <a class="row-link" href="{{ route('castlit.admin.show', $v->subscription) }}">{{ $v->subscription->business_name }}</a>
                                @else
                                    <span class="muted">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="empty">Aucune visite sur cette période.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="pager">{{ $visits->links() }}</div>
    </div>
</main>
@endsection
