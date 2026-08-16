@extends('castlit.layout')

@section('title', 'Clients — Administration Castl-it-POS')

@section('content')
<style>
    .admin { padding: 40px 0 0; }
    .admin-head { display: flex; align-items: flex-end; gap: 16px; flex-wrap: wrap; margin-bottom: 22px; }
    .admin-head h1 { font-size: 26px; font-weight: 800; letter-spacing: -.02em; }
    .admin-head p { color: var(--muted); font-size: 14px; }
    .card { background: var(--surface); border: 1px solid var(--sand); border-radius: var(--radius); box-shadow: var(--shadow); overflow: hidden; }
    .scroll { overflow-x: auto; }
    .tbl { width: 100%; border-collapse: collapse; min-width: 760px; }
    .tbl th { text-align: left; font-size: 11.5px; text-transform: uppercase; letter-spacing: .06em;
              color: var(--muted); padding: 13px 16px; border-bottom: 1px solid var(--sand); white-space: nowrap; }
    .tbl td { padding: 14px 16px; border-bottom: 1px solid color-mix(in srgb, var(--sand) 55%, transparent); font-size: 14px; vertical-align: middle; }
    .tbl tr:last-child td { border-bottom: none; }
    .tbl tr:hover td { background: color-mix(in srgb, var(--brand) 4%, transparent); }
    .biz { font-weight: 700; }
    .biz a.sd { display: block; color: var(--brand); font-weight: 600; font-size: 12.5px; font-variant-numeric: tabular-nums; text-decoration: none; margin-top: 2px; }
    .biz a.sd:hover { text-decoration: underline; }
    .tag-manual { display: inline-block; margin-left: 6px; vertical-align: middle; font-size: 10.5px; font-weight: 700;
                  text-transform: uppercase; letter-spacing: .04em; padding: 2px 7px; border-radius: 999px;
                  color: var(--accent); background: color-mix(in srgb, var(--accent) 14%, transparent); }
    .pill { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 700; padding: 4px 10px; border-radius: 999px; white-space: nowrap; }
    .pill-live { background: var(--ok-bg); color: var(--ok); }
    .pill-failed { background: var(--err-bg); color: var(--err); }
    .pill-suspended { background: var(--warn-bg); color: var(--warn); }
    .pill-running, .pill-queued { background: color-mix(in srgb, var(--brand) 12%, transparent); color: var(--brand); }
    .ver { font-variant-numeric: tabular-nums; font-size: 13px; font-weight: 650; }
    .ver .dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 6px; vertical-align: middle; }
    .ver.ok .dot { background: var(--ok); } .ver.stale .dot { background: var(--warn); } .ver.na .dot { background: var(--sand); }
    .ver small { display: block; color: var(--muted); font-weight: 500; font-size: 11.5px; margin-top: 2px; }
    .step { color: var(--muted); font-size: 12px; margin-top: 4px; }
    .actions { display: flex; gap: 7px; justify-content: flex-end; flex-wrap: wrap; }
    .actions form { margin: 0; }
    .btn-sm { font-size: 12.5px; font-weight: 650; padding: 7px 12px; border-radius: 9px; border: 1px solid var(--sand);
              background: var(--surface); color: var(--ink); cursor: pointer; white-space: nowrap; }
    .btn-sm:hover { border-color: var(--brand); color: var(--brand); }
    .btn-sm.warn:hover { border-color: var(--warn); color: var(--warn); }
    .btn-sm.ok:hover { border-color: var(--ok); color: var(--ok); }
    .btn-sm.primary { background: var(--brand); color: #fff; border-color: var(--brand); }
    .btn-sm.primary:hover { filter: brightness(1.06); color: #fff; }
    .empty { padding: 48px; text-align: center; color: var(--muted); }
    .muted { color: var(--muted); font-size: 12.5px; }
    .summary { display: flex; gap: 18px; flex-wrap: wrap; margin-bottom: 18px; font-size: 13px; color: var(--muted); }
    .summary b { color: var(--ink); font-variant-numeric: tabular-nums; }
    .pager { margin-top: 18px; }
</style>

<main class="admin">
    <div class="wrap">
        <div class="admin-head">
            <div>
                <h1>Clients</h1>
                <p>Gérez les espaces provisionnés : version déployée, mises à jour et suspension.</p>
            </div>
            <a href="{{ route('castlit.admin.index') }}" class="btn btn-ghost" style="margin-left:auto; padding:11px 18px; white-space:nowrap">← Abonnements</a>
        </div>

        @if (session('success'))<div class="flash flash-ok">{{ session('success') }}</div>@endif
        @if (session('error'))<div class="flash flash-err">{{ session('error') }}</div>@endif

        <div class="summary">
            <span>Version master :
                @if ($masterSha)<b>{{ $masterSha }}</b>@else<b class="muted">indisponible (git absent)</b>@endif
            </span>
            <span>Espaces à jour : <b>{{ $upToDate }}</b></span>
            <span>Total : <b>{{ $installs->total() }}</b></span>
        </div>

        <div class="card scroll">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>Commerce</th>
                        <th>Version déployée</th>
                        <th>État</th>
                        <th>Provisionné</th>
                        <th style="text-align:right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($installs as $install)
                        @php
                            $sub = $install->subscription;
                            $busy = in_array($install->status, [\App\Models\TenantInstall::STATUS_QUEUED, \App\Models\TenantInstall::STATUS_RUNNING], true);
                            $stale = $install->updateAvailable($masterSha);
                        @endphp
                        <tr>
                            <td>
                                <div class="biz">{{ $sub->business_name ?? $install->subdomain }}
                                    @if ($sub && data_get($sub->meta, 'source') === 'manual')
                                        <span class="tag-manual">Manuel</span>
                                    @endif
                                </div>
                                <a class="sd" href="{{ $install->url() }}" target="_blank" rel="noopener">{{ $install->domain }} ↗</a>
                            </td>
                            <td>
                                @if (! $install->isLive())
                                    <span class="ver na"><span class="dot"></span>—</span>
                                @elseif ($stale)
                                    <span class="ver stale"><span class="dot"></span>{{ $install->commit_sha ?? '?' }}
                                        <small>Mise à jour disponible</small></span>
                                @else
                                    <span class="ver ok"><span class="dot"></span>{{ $install->commit_sha ?? '—' }}
                                        <small>{{ $masterSha ? 'À jour' : 'Déployé' }}</small></span>
                                @endif
                                @if ($install->updated_version_at)
                                    <small class="muted">MàJ {{ $install->updated_version_at->format('d/m/Y') }}</small>
                                @endif
                            </td>
                            <td>
                                @if ($busy)
                                    <span class="pill pill-{{ $install->status }}">{{ ucfirst($install->status) }}</span>
                                @elseif ($install->isSuspended())
                                    <span class="pill pill-suspended">Suspendu</span>
                                @elseif ($install->isLive())
                                    <span class="pill pill-live">En ligne</span>
                                @else
                                    <span class="pill pill-failed">{{ ucfirst($install->status) }}</span>
                                @endif
                                @if ($install->current_step)
                                    <div class="step">{{ $install->current_step }}</div>
                                @endif
                            </td>
                            <td class="muted">{{ $install->provisioned_at?->format('d/m/Y') ?? '—' }}</td>
                            <td>
                                <div class="actions">
                                    @if ($install->isLive() && ! $busy)
                                        <form method="POST" action="{{ route('castlit.admin.clients.update', $install) }}"
                                              onsubmit="return confirm('Déployer la dernière version sur {{ $install->domain }} ? Les données du client sont conservées.');">
                                            @csrf
                                            <button class="btn-sm primary" type="submit">Mettre à jour</button>
                                        </form>
                                        @if ($install->is_enabled)
                                            <form method="POST" action="{{ route('castlit.admin.clients.disable', $install) }}"
                                                  onsubmit="return confirm('Suspendre {{ $install->domain }} ? Le client verra une page « compte suspendu ».');">
                                                @csrf
                                                <button class="btn-sm warn" type="submit">Suspendre</button>
                                            </form>
                                        @else
                                            <form method="POST" action="{{ route('castlit.admin.clients.enable', $install) }}"
                                                  onsubmit="return confirm('Réactiver {{ $install->domain }} ?');">
                                                @csrf
                                                <button class="btn-sm ok" type="submit">Réactiver</button>
                                            </form>
                                        @endif
                                    @endif
                                    @if ($sub)
                                        <a class="btn-sm" href="{{ route('castlit.admin.show', $sub) }}">Détails</a>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="empty">Aucun espace client provisionné pour le moment.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="pager">{{ $installs->links() }}</div>
    </div>
</main>
@endsection
