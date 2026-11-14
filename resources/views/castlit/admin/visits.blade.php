@extends('castlit.layout')

@section('title', 'Visites — Administration Castl-it-POS')

@section('content')
@php
    $stageLabels = \App\Models\MarketingVisit::LABELS;
    $devices = ['phone' => 'Téléphone', 'tablet' => 'Tablette', 'computer' => 'Ordinateur'];
    $deviceIcons = ['phone' => '📱', 'tablet' => '📟', 'computer' => '💻'];
    $pct = fn (int $n) => $funnel['opened'] > 0 ? round($n * 100 / $funnel['opened']).' %' : '—';
    // Every link keeps the current filters; only what it changes differs.
    $query = array_filter(request()->query(), fn ($v) => $v !== null && $v !== '');
    $with = fn (array $changes) => route('castlit.admin.visits', array_filter(array_merge($query, $changes), fn ($v) => $v !== null && $v !== ''));
    $sortLink = function (string $column) use ($with, $sort, $dir) {
        $next = $sort === $column && $dir === 'desc' ? 'asc' : 'desc';

        return $with(['sort' => $column, 'dir' => $next, 'page' => null]);
    };
    $sortMark = fn (string $column) => $sort === $column ? ($dir === 'asc' ? '↑' : '↓') : '';
    $ago = fn ($date) => $date ? \Illuminate\Support\Carbon::parse($date)->locale('fr')->diffForHumans() : '—';
    $maxDay = max(1, collect($daily)->max('opened'));
    $hasFilters = collect(['campaign_source', 'stage', 'device', 'country', 'locale', 'q', 'followup'])->contains(fn ($k) => filled($filters[$k] ?? null));
@endphp
<style>
    .admin { padding: 32px 0 0; }
    .admin .wrap { max-width: 1440px; }
    .admin-head { display: flex; align-items: flex-end; gap: 16px; flex-wrap: wrap; margin-bottom: 18px; }
    .admin-head h1 { font-size: 26px; font-weight: 800; letter-spacing: -.02em; }
    .admin-head p { color: var(--muted); font-size: 13.5px; max-width: 72ch; margin-top: 4px; }
    .card { background: var(--surface); border: 1px solid var(--sand); border-radius: var(--radius); box-shadow: var(--shadow); overflow: hidden; }
    .muted { color: var(--muted); font-size: 12.5px; }
    .nowrap { white-space: nowrap; }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12.5px; }

    /* Live strip */
    .live { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 18px; padding: 12px 16px; margin-bottom: 14px; font-size: 13.5px; }
    .live-dot { width: 9px; height: 9px; border-radius: 50%; background: var(--ok); box-shadow: 0 0 0 0 color-mix(in srgb, var(--ok) 50%, transparent); animation: pulse 1.8s ease infinite; }
    @keyframes pulse { 0% { box-shadow: 0 0 0 0 color-mix(in srgb, var(--ok) 45%, transparent); } 70% { box-shadow: 0 0 0 8px transparent; } 100% { box-shadow: 0 0 0 0 transparent; } }
    @media (prefers-reduced-motion: reduce) { .live-dot { animation: none; } }
    .live strong { font-variant-numeric: tabular-nums; }
    .live .auto { margin-left: auto; display: flex; align-items: center; gap: 6px; color: var(--muted); font-size: 13px; cursor: pointer; }

    /* Periods + filters */
    .periods { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 10px; }
    .chip { display: inline-flex; align-items: center; padding: 7px 13px; border-radius: 999px; border: 1px solid var(--sand); background: var(--surface);
            font-size: 13px; font-weight: 650; color: var(--muted); }
    .chip.on { background: var(--brand); border-color: var(--brand); color: #fff; }
    .filters { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px; align-items: end; padding: 14px; margin-bottom: 14px; }
    .filters label { display: flex; flex-direction: column; gap: 4px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); }
    .filters input, .filters select { font: inherit; font-size: 13.5px; text-transform: none; letter-spacing: 0; padding: 8px 10px; border-radius: 9px;
                                      border: 1.5px solid var(--sand); background: var(--paper); color: var(--ink); width: 100%; min-width: 0; }
    .filters .wide { grid-column: span 2; }
    .filters .actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
    .filters .check { flex-direction: row; align-items: center; gap: 6px; text-transform: none; letter-spacing: 0; font-size: 13px; font-weight: 600; color: var(--ink); }
    .filters .check input { width: auto; }
    .reset { font-size: 13px; font-weight: 650; color: var(--brand); }

    /* Funnel + chart */
    .top { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.15fr); gap: 14px; margin-bottom: 14px; }
    @media (max-width: 1100px) { .top { grid-template-columns: 1fr; } }
    .funnel { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; }
    .step { padding: 12px 14px; }
    .step b { display: block; font-size: 24px; font-weight: 800; font-variant-numeric: tabular-nums; letter-spacing: -.02em; }
    .step span { font-size: 12px; color: var(--muted); font-weight: 650; }
    .step small { display: block; font-size: 11.5px; color: var(--muted); font-variant-numeric: tabular-nums; }
    .step.is-sent b { color: var(--ok); }
    .step.is-lead b { color: var(--brand); }
    .step.is-refused b { color: var(--err); }
    .chart { padding: 14px 16px 10px; }
    .chart h3 { font-size: 13px; font-weight: 750; margin-bottom: 8px; display: flex; gap: 14px; flex-wrap: wrap; align-items: center; }
    .chart h3 i { display: inline-block; width: 10px; height: 10px; border-radius: 3px; margin-right: 5px; vertical-align: -1px; }
    .chart h3 span { font-weight: 600; color: var(--muted); font-size: 12px; }
    .bars { display: flex; align-items: flex-end; gap: 3px; height: 120px; }
    .bar { flex: 1; position: relative; display: flex; align-items: flex-end; justify-content: center; height: 100%; }
    .bar .v { width: 100%; max-width: 26px; border-radius: 4px 4px 0 0; background: color-mix(in srgb, var(--brand) 22%, transparent); }
    .bar .l { position: absolute; bottom: 0; width: 46%; max-width: 12px; border-radius: 3px 3px 0 0; background: var(--brand); }
    .bar .s { position: absolute; bottom: 0; width: 46%; max-width: 12px; border-radius: 3px 3px 0 0; background: var(--ok); transform: translateX(105%); }
    .bar:hover .v { background: color-mix(in srgb, var(--brand) 36%, transparent); }
    .days { display: flex; gap: 3px; margin-top: 4px; }
    .days span { flex: 1; text-align: center; font-size: 10.5px; color: var(--muted); font-variant-numeric: tabular-nums; overflow: hidden; white-space: nowrap; }

    .followup { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-bottom: 14px; padding: 12px 14px; border-radius: 12px;
                background: color-mix(in srgb, var(--brand) 8%, transparent); border: 1px solid color-mix(in srgb, var(--brand) 25%, transparent); font-size: 14px; }
    .followup a { font-weight: 750; color: var(--brand); }

    /* Tables */
    .section-title { font-size: 15px; font-weight: 800; margin: 18px 0 10px; display: flex; align-items: baseline; gap: 10px; flex-wrap: wrap; }
    .section-title small { font-size: 12.5px; font-weight: 600; color: var(--muted); }
    .tbl-scroll { overflow: auto; -webkit-overflow-scrolling: touch; }
    .tbl-scroll.tall { max-height: 72vh; }
    .tbl { width: 100%; border-collapse: separate; border-spacing: 0; }
    .tbl th { position: sticky; top: 0; z-index: 1; background: var(--surface); text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .06em;
              color: var(--muted); padding: 11px 12px; border-bottom: 1px solid var(--sand); white-space: nowrap; }
    .tbl th a { color: inherit; display: inline-flex; gap: 4px; align-items: center; }
    .tbl th a:hover, .tbl th a.on { color: var(--brand); }
    .tbl td { padding: 10px 12px; border-bottom: 1px solid color-mix(in srgb, var(--sand) 55%, transparent); font-size: 13.5px; vertical-align: top; }
    .tbl tbody tr:hover td { background: color-mix(in srgb, var(--brand) 4%, transparent); }
    .tbl .num { text-align: end; font-variant-numeric: tabular-nums; }
    tr.is-lead td { background: color-mix(in srgb, var(--brand) 6%, transparent); }
    tr.is-sent td { background: color-mix(in srgb, var(--ok) 8%, transparent); }
    tr.is-lead td:first-child { box-shadow: inset 3px 0 0 var(--brand); }
    tr.is-sent td:first-child { box-shadow: inset 3px 0 0 var(--ok); }
    tr.is-robot td { opacity: .55; }

    .camp { display: inline-block; font-size: 11.5px; font-weight: 700; padding: 2px 8px; border-radius: 999px; background: color-mix(in srgb, var(--brand) 14%, transparent); color: var(--brand); white-space: nowrap; }
    .stage { display: inline-block; font-size: 12px; font-weight: 700; padding: 3px 9px; border-radius: 999px; white-space: nowrap; }
    .stage-opened { background: color-mix(in srgb, var(--sand) 60%, transparent); color: var(--muted); }
    .stage-started { background: color-mix(in srgb, var(--brand) 12%, transparent); color: var(--brand); }
    .stage-lead { background: var(--brand); color: #fff; }
    .stage-tried { background: var(--warn-bg); color: var(--warn); }
    .stage-refused { background: var(--err-bg); color: var(--err); }
    .stage-sent { background: var(--ok-bg); color: var(--ok); }
    .robot { display: inline-block; margin-top: 4px; font-size: 11.5px; font-weight: 700; padding: 2px 8px; border-radius: 999px; background: color-mix(in srgb, var(--muted) 18%, transparent); color: var(--muted); }
    .ua { display: block; max-width: 200px; margin-top: 2px; font-size: 11px; color: var(--muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .when b { display: block; font-weight: 700; }
    .lead { display: block; font-weight: 700; }
    .lead a, a.lead { color: var(--brand); }
    .empty { padding: 40px; text-align: center; color: var(--muted); }

    /* Pager: Laravel's default needs Tailwind, which this layout does not load. */
    .pager { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; margin: 12px 0 40px; font-size: 13.5px; }
    .pager nav { display: flex; align-items: center; gap: 8px; }
    .pager a, .pager span.off { display: inline-flex; align-items: center; padding: 7px 13px; border-radius: 9px; border: 1px solid var(--sand); background: var(--surface); font-weight: 650; }
    .pager a { color: var(--brand); }
    .pager span.off { color: var(--muted); opacity: .55; }
    .pager .per { display: flex; align-items: center; gap: 6px; color: var(--muted); }
    .pager .per a, .pager .per span { padding: 4px 9px; border-radius: 7px; }
    .pager .per span { background: var(--brand); color: #fff; border: 1px solid var(--brand); font-weight: 700; }
</style>

<main class="admin">
    <div class="wrap">
        <div class="admin-head">
            <div>
                <h1>Visites du site</h1>
                <p>Les visites de castlitpos.com (et les inscriptions commencées dans l'application) et l'étape atteinte vers une demande. Robots connus, serveurs et l'équipe connectée ne sont pas comptés ; les robots probables sont mis à part. Conservées {{ \App\Support\MarketingVisits::KEEP_DAYS }} jours.</p>
            </div>
            <a href="{{ route('castlit.admin.index') }}" class="btn btn-ghost" style="margin-left:auto; padding:11px 18px">Demandes</a>
        </div>

        {{-- En direct, whatever the filters. --}}
        <div class="card live" data-live>
            <span class="live-dot" aria-hidden="true"></span>
            <span>Dernière visite : <strong>{{ $ago($live['last']) }}</strong></span>
            <span>Dernière heure : <strong>{{ $live['hour'] }}</strong></span>
            <span>Aujourd'hui : <strong>{{ $live['today'] }}</strong></span>
            <label class="auto"><input type="checkbox" data-autorefresh> Actualiser chaque minute</label>
        </div>

        <div class="periods" data-periods>
            @foreach (['today' => "Aujourd'hui", '7d' => '7 jours', '30d' => '30 jours'] as $key => $label)
                <a class="chip {{ ($filters['period'] ?? '') === $key ? 'on' : '' }}" href="{{ $with(['period' => $key, 'from' => null, 'to' => null, 'page' => null]) }}">{{ $label }}</a>
            @endforeach
        </div>

        <form method="GET" action="{{ route('castlit.admin.visits') }}" class="card filters" data-filters>
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
            <label>Campagne
                <select name="campaign">
                    <option value="">Toutes</option>
                    @php $currentCampaign = filled($filters['campaign_source'] ?? null) ? $filters['campaign_source'].'|'.(($filters['campaign_name'] ?? '-') === '-' ? '' : $filters['campaign_name']) : ''; @endphp
                    @foreach ($options['campaigns'] as $c)
                        <option value="{{ $c['value'] }}" @selected($currentCampaign === $c['value'])>{{ $c['label'] }}</option>
                    @endforeach
                </select>
            </label>
            <label>Pays
                <select name="country">
                    <option value="">Tous</option>
                    @foreach ($options['countries'] as $code)
                        <option value="{{ $code }}" @selected(strtoupper($filters['country'] ?? '') === $code)>{{ \App\Support\Countries::flag($code) }} {{ $code }}</option>
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
            <label>Langue
                <select name="locale">
                    <option value="">Toutes</option>
                    @foreach ($options['locales'] as $loc)
                        <option value="{{ $loc }}" @selected(($filters['locale'] ?? '') === $loc)>{{ strtoupper($loc) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="wide">Rechercher <input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="IP, email ou commerce"></label>
            <div class="actions">
                <label class="check"><input type="checkbox" name="robots" value="1" @checked($withRobots)> Robots ({{ $robots }})</label>
            </div>
            <div class="actions">
                @if ($sort !== 'created_at' || $dir !== 'desc')<input type="hidden" name="sort" value="{{ $sort }}"><input type="hidden" name="dir" value="{{ $dir }}">@endif
                @if ($perPage !== 50)<input type="hidden" name="per_page" value="{{ $perPage }}">@endif
                <button type="submit" class="btn btn-primary" style="padding:9px 18px">Filtrer</button>
                @if ($hasFilters || $withRobots || filled($filters['period'] ?? null))
                    <a class="reset" href="{{ route('castlit.admin.visits') }}">Effacer</a>
                @endif
            </div>
        </form>

        {{-- People who left an email and sent nothing: the ones to write to. --}}
        @if ($followups > 0 || ($filters['followup'] ?? false))
            <div class="followup" data-followup>
                <strong>✉ {{ $followups }} {{ $followups > 1 ? 'personnes ont laissé leur email' : 'personne a laissé son email' }} sans envoyer la demande.</strong>
                @if ($filters['followup'] ?? false)
                    <a href="{{ $with(['followup' => null, 'page' => null]) }}">Voir toutes les visites</a>
                @else
                    <a href="{{ $with(['followup' => 1, 'page' => null]) }}">Voir qui relancer →</a>
                @endif
            </div>
        @endif

        <div class="top">
            <div class="funnel" data-funnel>
                <div class="card step"><span>Page ouverte</span><b>{{ $funnel['opened'] }}</b><small>100 %</small></div>
                <div class="card step"><span>Formulaire commencé</span><b>{{ $funnel['started'] }}</b><small>{{ $pct($funnel['started']) }}</small></div>
                <div class="card step is-lead"><span>Email laissé</span><b>{{ $funnel['lead'] }}</b><small>{{ $pct($funnel['lead']) }}</small></div>
                <div class="card step"><span>Envoi tenté</span><b>{{ $funnel['tried'] }}</b><small>{{ $pct($funnel['tried']) }}</small></div>
                <div class="card step is-refused"><span>Refusés (sans suite)</span><b>{{ $funnel['refused'] }}</b><small>{{ $pct($funnel['refused']) }}</small></div>
                <div class="card step is-sent"><span>Demande envoyée</span><b>{{ $funnel['sent'] }}</b><small>{{ $pct($funnel['sent']) }}</small></div>
            </div>
            <div class="card chart" data-chart>
                <h3>Par jour
                    <span><i style="background:color-mix(in srgb, var(--brand) 22%, transparent)"></i>Visites</span>
                    <span><i style="background:var(--brand)"></i>Emails laissés</span>
                    <span><i style="background:var(--ok)"></i>Demandes</span>
                </h3>
                <div class="bars" role="img" aria-label="Visites, emails laissés et demandes par jour">
                    @foreach ($daily as $d)
                        <div class="bar" title="{{ $d['label'] }} : {{ $d['opened'] }} visite(s), {{ $d['lead'] }} email(s), {{ $d['sent'] }} demande(s)">
                            <div class="v" style="height: {{ round($d['opened'] * 100 / $maxDay) }}%"></div>
                            @if ($d['lead'])<div class="l" style="height: {{ max(4, round($d['lead'] * 100 / $maxDay)) }}%"></div>@endif
                            @if ($d['sent'])<div class="s" style="height: {{ max(4, round($d['sent'] * 100 / $maxDay)) }}%"></div>@endif
                        </div>
                    @endforeach
                </div>
                @php $every = max(1, (int) ceil(count($daily) / 10)); @endphp
                <div class="days" aria-hidden="true">
                    @foreach ($daily as $i => $d)
                        <span>{{ $i % $every === 0 ? $d['label'] : '' }}</span>
                    @endforeach
                </div>
            </div>
        </div>

        @if ($campaigns->isNotEmpty())
            <h2 class="section-title">Par campagne</h2>
            <div class="card tbl-scroll" data-visit-campaigns>
                <table class="tbl">
                    <thead><tr><th>Source · campagne</th><th class="num">Visites</th><th class="num">Commencé</th><th class="num">Email laissé</th><th class="num">Envoi tenté</th><th class="num">Envoyé</th></tr></thead>
                    <tbody>
                        @foreach ($campaigns as $c)
                            <tr>
                                <td>
                                    @if ($c['source'])
                                        <a href="{{ $with(['campaign' => $c['source'].'|'.($c['name'] ?? ''), 'campaign_source' => null, 'campaign_name' => null, 'page' => null]) }}"><span class="camp">{{ $c['label'] }}</span></a>
                                    @else
                                        <span class="muted">{{ $c['label'] }}</span>
                                    @endif
                                </td>
                                <td class="num">{{ $c['opened'] }}</td>
                                <td class="num">{{ $c['started'] }}</td>
                                <td class="num">{{ $c['lead'] }}</td>
                                <td class="num">{{ $c['tried'] }}</td>
                                <td class="num">{{ $c['sent'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <h2 class="section-title">Visites <small>{{ $visits->total() }} au total</small></h2>
        <div class="card tbl-scroll tall">
            <table class="tbl" style="min-width: 1180px" data-visits-table>
                <thead>
                    <tr>
                        @foreach (['created_at' => 'Heure', 'ip' => 'IP', 'country' => 'Pays', 'device' => 'Appareil', 'locale' => 'Langue', 'campaign' => 'Campagne'] as $col => $label)
                            <th><a href="{{ $sortLink($col) }}" class="{{ $sort === $col ? 'on' : '' }}" data-sort="{{ $col }}">{{ $label }} {{ $sortMark($col) }}</a></th>
                        @endforeach
                        <th>Venu de</th>
                        <th><a href="{{ $sortLink('stage') }}" class="{{ $sort === 'stage' ? 'on' : '' }}" data-sort="stage">Étape {{ $sortMark('stage') }}</a></th>
                        <th>Demande ou email</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($visits as $v)
                        @php $rowClass = $v->robot_reason ? 'is-robot' : ($v->stage === 'sent' ? 'is-sent' : ($v->lead_email ? 'is-lead' : '')); @endphp
                        <tr data-visit class="{{ $rowClass }}">
                            <td class="nowrap when" title="{{ \App\Support\TenantClock::format($v->created_at, $zone, 'd/m/Y H:i:s') }}">
                                <b>{{ $ago($v->created_at) }}</b>
                                <span class="muted">{{ \App\Support\TenantClock::format($v->created_at, $zone, 'd/m H:i') }}@if ($v->last_seen_at && $v->last_seen_at->diffInMinutes($v->created_at, true) >= 1) → {{ \App\Support\TenantClock::format($v->last_seen_at, $zone, 'H:i') }}@endif</span>
                            </td>
                            <td class="mono"><a href="{{ $with(['q' => $v->ip, 'page' => null]) }}" title="Toutes les visites de cette adresse">{{ $v->ip ?? '—' }}</a></td>
                            <td class="nowrap">@if ($v->country){{ \App\Support\Countries::flag($v->country) }} {{ $v->country }}@else — @endif</td>
                            <td>{{ $deviceIcons[$v->device] ?? '' }} {{ $devices[$v->device] ?? '—' }}
                                @if ($v->user_agent)<span class="ua" title="{{ $v->user_agent }}">{{ $v->user_agent }}</span>@endif
                            </td>
                            <td>{{ strtoupper((string) $v->locale) ?: '—' }}</td>
                            <td>@if ($v->campaignLabel())<span class="camp">{{ $v->campaignLabel() }}</span>@else <span class="muted">—</span>@endif</td>
                            <td class="muted">{{ $v->referrer ?: '—' }}</td>
                            <td>
                                <span class="stage stage-{{ $v->stage }}">{{ $stageLabels[$v->stage] ?? $v->stage }}</span>
                                @if ($v->refused_fields === \App\Support\MarketingVisits::EMPTY_FORM)<br><span class="muted">bouton pressé, formulaire vide</span>
                                @elseif ($v->refused_fields)<br><span class="muted">sur : {{ str_replace(',', ', ', $v->refused_fields) }}</span>@endif
                                @if ($v->robot_reason)<br><span class="robot" title="{{ \App\Models\MarketingVisit::ROBOT_REASONS[$v->robot_reason] ?? '' }}">Robot probable · {{ \App\Models\MarketingVisit::ROBOT_REASONS[$v->robot_reason] ?? $v->robot_reason }}</span>@endif
                            </td>
                            <td>
                                @if ($v->subscription)
                                    <a class="row-link" href="{{ route('castlit.admin.show', $v->subscription) }}">{{ $v->subscription->business_name }}</a>
                                @elseif ($v->lead_email)
                                    <span class="lead" data-lead>{{ $v->lead_business ?: '—' }}</span>
                                    <a class="lead" href="mailto:{{ $v->lead_email }}">{{ $v->lead_email }}</a>
                                @else
                                    <span class="muted">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="empty">Aucune visite pour ces filtres.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="pager" data-pager>
            <span class="muted">
                @if ($visits->total() > 0){{ $visits->firstItem() }}–{{ $visits->lastItem() }} sur {{ $visits->total() }}@endif
            </span>
            <nav aria-label="Pages">
                @if ($visits->onFirstPage())<span class="off">← Précédent</span>@else<a href="{{ $visits->previousPageUrl() }}" rel="prev">← Précédent</a>@endif
                <span class="muted">Page {{ $visits->currentPage() }} sur {{ max(1, $visits->lastPage()) }}</span>
                @if ($visits->hasMorePages())<a href="{{ $visits->nextPageUrl() }}" rel="next">Suivant →</a>@else<span class="off">Suivant →</span>@endif
            </nav>
            <span class="per">Par page
                @foreach (\App\Http\Controllers\Castlit\MarketingVisitAdminController::PER_PAGE as $n)
                    @if ($n === $perPage)<span>{{ $n }}</span>@else<a href="{{ $with(['per_page' => $n, 'page' => null]) }}">{{ $n }}</a>@endif
                @endforeach
            </span>
        </div>
    </div>
</main>

<script>
    // « Actualiser chaque minute », remembered in this browser only.
    (function () {
        var box = document.querySelector('[data-autorefresh]');
        if (!box) return;
        var KEY = 'castlit_visits_autorefresh';
        try { box.checked = localStorage.getItem(KEY) === '1'; } catch (e) {}
        var timer = null;
        function arm() {
            clearTimeout(timer);
            if (box.checked) timer = setTimeout(function () { location.reload(); }, 60000);
        }
        box.addEventListener('change', function () {
            try { localStorage.setItem(KEY, box.checked ? '1' : '0'); } catch (e) {}
            arm();
        });
        arm();
    })();
</script>
@endsection
