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
    .pill-blocked { background: var(--err-bg); color: var(--err); }
    .pill-trial { background: var(--warn-bg); color: var(--warn); }
    .pill-running, .pill-queued { background: color-mix(in srgb, var(--brand) 12%, transparent); color: var(--brand); }
    .ver { font-variant-numeric: tabular-nums; font-size: 13px; font-weight: 650; }
    .ver .dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 6px; vertical-align: middle; }
    .ver.ok .dot { background: var(--ok); } .ver.stale .dot { background: var(--warn); } .ver.na .dot { background: var(--sand); }
    .ver small { display: block; color: var(--muted); font-weight: 500; font-size: 11.5px; margin-top: 2px; }
    .step { color: var(--muted); font-size: 12px; margin-top: 4px; }
    .accode { display: flex; align-items: center; gap: 6px; margin-top: 6px; }
    .accode-label { color: var(--muted); font-size: 11px; text-transform: uppercase; letter-spacing: .4px; }
    .accode code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 13px; font-weight: 700;
                   letter-spacing: 1px; background: var(--surface); border: 1px solid var(--sand); border-radius: 7px; padding: 2px 8px; }
    .accode-btn { border: 1px solid var(--sand); background: var(--surface); color: var(--muted); border-radius: 7px;
                  width: 26px; height: 26px; cursor: pointer; line-height: 1; }
    .accode-btn:hover { border-color: var(--brand); color: var(--brand); }
    .accode form { margin: 0; }
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
    .deploy-alert { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;
                    margin-bottom: 18px; padding: 14px 18px; border-radius: 12px; font-size: 14px;
                    background: var(--warn-bg); color: var(--warn); border: 1px solid color-mix(in srgb, var(--warn) 30%, transparent); }
    .deploy-alert code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-weight: 700; }
    .deploy-ok { margin-bottom: 18px; font-size: 13px; font-weight: 600; color: var(--ok); }
    .op-log { margin-bottom: 18px; border: 1px solid var(--sand); border-radius: 12px; background: var(--surface); box-shadow: var(--shadow); overflow: hidden; }
    .op-log summary { cursor: pointer; padding: 12px 16px; font-weight: 700; font-size: 13.5px; }
    .op-log pre { margin: 0; padding: 0 16px 16px; max-height: 340px; overflow: auto; white-space: pre-wrap; word-break: break-word;
                  font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; line-height: 1.5; color: var(--muted); }
    .log-sha .pending { display: inline-block; margin-left: 6px; font-size: 10px; font-weight: 800; text-transform: uppercase;
                        letter-spacing: .04em; color: var(--warn); background: var(--warn-bg); padding: 1px 6px; border-radius: 999px; vertical-align: middle; }
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
            <div style="margin-left:auto; display:flex; gap:10px; flex-wrap:wrap">
                <form method="POST" action="{{ route('castlit.admin.clients.git-pull', [], false) }}"
                      onsubmit="return confirm('Récupérer la dernière version du code depuis Git sur le master ?');">
                    @csrf
                    <button type="submit" class="btn btn-primary" style="padding:11px 18px; white-space:nowrap">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:6px"><path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/></svg>
                        Actualiser depuis Git
                    </button>
                </form>
                <a href="{{ route('castlit.admin.index') }}" class="btn btn-ghost" style="padding:11px 18px; white-space:nowrap">← Abonnements</a>
            </div>
        </div>

        @if (session('success'))<div class="flash flash-ok">{{ session('success') }}</div>@endif
        @if (session('error'))<div class="flash flash-err">{{ session('error') }}</div>@endif
        @if (session('update_log'))
            <details class="op-log" open>
                <summary>Détail de l'opération</summary>
                <pre>{{ session('update_log') }}</pre>
            </details>
        @endif

        <div class="summary">
            <span>Version master :
                @if ($masterSha)<b>{{ $masterSha }}</b>@else<b class="muted">indisponible (git absent)</b>@endif
            </span>
            <span>Espaces à jour : <b>{{ $upToDate }}</b></span>
            <span>Payés : <b>{{ $stats['paid'] }}</b></span>
            <span>En essai : <b>{{ $stats['trial'] }}</b></span>
            <span>Bloqués : <b>{{ $stats['blocked'] }}</b></span>
            <span>Total : <b>{{ $installs->total() }}</b></span>
        </div>

        @if (($behind ?? 0) > 0)
            <div class="deploy-alert">
                <div>
                    <strong>{{ $behind }} commit{{ $behind > 1 ? 's' : '' }} non déployé{{ $behind > 1 ? 's' : '' }}</strong>
                    sur <code>origin/master</code> — le master tourne sur <code>{{ $masterSha }}</code>.
                </div>
                <form method="POST" action="{{ route('castlit.admin.clients.git-pull', [], false) }}"
                      onsubmit="return confirm('Déployer la dernière version depuis Git sur le master ?');">
                    @csrf
                    <button type="submit" class="btn-sm primary">Déployer maintenant</button>
                </form>
            </div>
        @elseif ($behind === 0)
            <div class="deploy-ok">✓ Master à jour avec origin/master ({{ $masterSha }}).</div>
        @endif

        <div class="card scroll">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>Commerce</th>
                        <th>Facturation</th>
                        <th>État</th>
                        <th>Version déployée</th>
                        <th>Créé le</th>
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
                                <div class="accode">
                                    <span class="accode-label">Code d'accès</span>
                                    <code>{{ $install->access_code ?? '—' }}</code>
                                    <form method="POST" action="{{ route('castlit.admin.clients.action', [], false) }}"
                                          onsubmit="return confirm('Régénérer le code d\'accès de {{ $install->domain }} ? L\'ancien code ne fonctionnera plus.');">
                                        @csrf
                                        <input type="hidden" name="install" value="{{ $install->id }}">
                                        <input type="hidden" name="do" value="regenerate-code">
                                        <button type="submit" class="accode-btn" title="Régénérer le code">↻</button>
                                    </form>
                                </div>
                            </td>
                            <td>
                                @if ($install->isPaid())
                                    <span class="pill pill-live">Payé</span>
                                    <small class="muted">le {{ $install->paid_at->format('d/m/Y') }}</small>
                                @elseif ($install->trialExpired())
                                    <span class="pill pill-failed">Essai expiré</span>
                                    <small class="muted">fin {{ $install->trial_ends_at->format('d/m/Y') }}</small>
                                @elseif ($install->onTrial())
                                    <span class="pill pill-trial">Essai · {{ $install->trialDaysLeft() }} j</span>
                                    <small class="muted">fin {{ $install->trial_ends_at->format('d/m/Y') }}</small>
                                @else
                                    <span class="muted">—</span>
                                @endif
                            </td>
                            <td>
                                @if ($busy)
                                    <span class="pill pill-{{ $install->status }}">{{ ucfirst($install->status) }}</span>
                                @elseif ($install->isSuspended())
                                    <span class="pill pill-blocked">Bloqué</span>
                                @elseif ($install->isLive())
                                    <span class="pill pill-live">En ligne</span>
                                @else
                                    <span class="pill pill-failed">{{ ucfirst($install->status) }}</span>
                                @endif
                                @if ($install->current_step)
                                    <div class="step">{{ $install->current_step }}</div>
                                @endif
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
                            </td>
                            <td class="muted">{{ $install->created_at?->format('d/m/Y') ?? '—' }}</td>
                            <td>
                                <div class="actions">
                                    @if ($install->isLive() && ! $busy)
                                        <form method="POST" action="{{ route('castlit.admin.clients.action', [], false) }}"
                                              onsubmit="return confirm('Déployer la dernière version sur {{ $install->domain }} ? Les données du client sont conservées.');">
                                            @csrf
                                            <input type="hidden" name="install" value="{{ $install->id }}">
                                            <input type="hidden" name="do" value="update">
                                            <button class="btn-sm primary" type="submit">Mettre à jour</button>
                                        </form>
                                        <form method="POST" action="{{ route('castlit.admin.clients.action', [], false) }}"
                                              onsubmit="return confirm('Vider le cache (vues, config, routes) de {{ $install->domain }} ?');">
                                            @csrf
                                            <input type="hidden" name="install" value="{{ $install->id }}">
                                            <input type="hidden" name="do" value="clear-cache">
                                            <button class="btn-sm" type="submit">Vider le cache</button>
                                        </form>
                                        @if ($install->isPaid())
                                            <form method="POST" action="{{ route('castlit.admin.clients.action', [], false) }}"
                                                  onsubmit="return confirm('Remettre {{ $install->domain }} en essai / impayé ?');">
                                                @csrf
                                                <input type="hidden" name="install" value="{{ $install->id }}">
                                                <input type="hidden" name="do" value="unpaid">
                                                <button class="btn-sm" type="submit">Marquer impayé</button>
                                            </form>
                                        @else
                                            <form method="POST" action="{{ route('castlit.admin.clients.action', [], false) }}"
                                                  onsubmit="return confirm('Marquer {{ $install->domain }} comme payé ?');">
                                                @csrf
                                                <input type="hidden" name="install" value="{{ $install->id }}">
                                                <input type="hidden" name="do" value="paid">
                                                <button class="btn-sm ok" type="submit">Marquer payé</button>
                                            </form>
                                        @endif
                                        @if ($install->is_enabled)
                                            <form method="POST" action="{{ route('castlit.admin.clients.action', [], false) }}"
                                                  onsubmit="return confirm('Bloquer {{ $install->domain }} ? Le client verra une page « compte suspendu ».');">
                                                @csrf
                                                <input type="hidden" name="install" value="{{ $install->id }}">
                                                <input type="hidden" name="do" value="disable">
                                                <button class="btn-sm warn" type="submit">Bloquer</button>
                                            </form>
                                        @else
                                            <form method="POST" action="{{ route('castlit.admin.clients.action', [], false) }}"
                                                  onsubmit="return confirm('Débloquer {{ $install->domain }} ?');">
                                                @csrf
                                                <input type="hidden" name="install" value="{{ $install->id }}">
                                                <input type="hidden" name="do" value="enable">
                                                <button class="btn-sm ok" type="submit">Débloquer</button>
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
                        <tr><td colspan="6" class="empty">Aucun espace client provisionné pour le moment.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="pager">{{ $installs->links() }}</div>

        {{-- ── Version history / changelog ─────────────────────────────────── --}}
        <style>
            .changelog { margin-top: 40px; }
            .changelog h2 { font-size: 18px; font-weight: 800; letter-spacing: -.01em; margin-bottom: 4px; }
            .changelog .sub { color: var(--muted); font-size: 13px; margin-bottom: 16px; }
            .log { position: relative; padding-left: 4px; }
            .log-item { display: grid; grid-template-columns: 92px 1fr auto; gap: 14px; align-items: baseline;
                        padding: 12px 4px; border-bottom: 1px solid color-mix(in srgb, var(--sand) 55%, transparent); }
            .log-item:last-child { border-bottom: none; }
            .log-sha { font-variant-numeric: tabular-nums; font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
                       font-size: 12.5px; font-weight: 700; color: var(--brand); }
            .log-sha .head { display: inline-block; margin-left: 6px; font-family: inherit; font-size: 10px; font-weight: 800;
                             text-transform: uppercase; letter-spacing: .04em; color: var(--ok); background: var(--ok-bg);
                             padding: 1px 6px; border-radius: 999px; vertical-align: middle; }
            .log-msg { font-size: 14px; line-height: 1.45; }
            .log-msg .kind { display: inline-block; margin-right: 8px; font-size: 10.5px; font-weight: 800; text-transform: uppercase;
                             letter-spacing: .04em; padding: 2px 8px; border-radius: 999px; vertical-align: middle; }
            .kind-feat { color: var(--ok); background: var(--ok-bg); }
            .kind-fix { color: var(--err); background: var(--err-bg); }
            .kind-refactor, .kind-perf { color: var(--brand); background: color-mix(in srgb, var(--brand) 12%, transparent); }
            .kind-chore, .kind-docs, .kind-style, .kind-test, .kind-other { color: var(--muted); background: color-mix(in srgb, var(--muted) 14%, transparent); }
            .log-meta { color: var(--muted); font-size: 12px; white-space: nowrap; text-align: right; }
            .log-meta .who { display: block; font-size: 11px; }
            @media (max-width: 640px) {
                .log-item { grid-template-columns: 1fr; gap: 4px; }
                .log-meta { text-align: left; }
            }
        </style>

        <section class="changelog">
            <h2>Journal des versions</h2>
            <p class="sub">
                Historique du code déployé (branche master).
                @if ($masterSha) Version actuelle : <b>{{ $masterSha }}</b>. @endif
                Chaque espace client tourne sur la version indiquée dans le tableau ci-dessus.
            </p>

            <div class="card">
                <div class="log" style="padding: 6px 16px;">
                    @php $kindLabels = ['feat' => 'Nouveau', 'fix' => 'Correctif', 'refactor' => 'Refonte', 'perf' => 'Perf', 'chore' => 'Maintenance', 'docs' => 'Docs', 'style' => 'Style', 'test' => 'Test', 'other' => '·']; @endphp
                    @forelse ($commits as $i => $c)
                        @php $pending = ($behind ?? 0) > 0 && $i < $behind; @endphp
                        <div class="log-item">
                            <div class="log-sha">
                                {{ $c['sha'] }}
                                @if ($masterSha && $c['sha'] === $masterSha)<span class="head">déployé</span>
                                @elseif ($pending)<span class="pending">à déployer</span>@endif
                            </div>
                            <div class="log-msg">
                                <span class="kind kind-{{ $c['type'] }}">{{ $kindLabels[$c['type']] ?? '·' }}</span>
                                {{ \Illuminate\Support\Str::of($c['subject'])->after(':')->trim()->whenEmpty(fn () => \Illuminate\Support\Str::of($c['subject'])) }}
                            </div>
                            <div class="log-meta">
                                @if ($c['date'])<time datetime="{{ $c['date'] }}">{{ \Illuminate\Support\Carbon::parse($c['date'])->translatedFormat('d/m/Y') }}</time>@endif
                                <span class="who">{{ $c['author'] }}</span>
                            </div>
                        </div>
                    @empty
                        <div class="empty" style="padding: 28px;">Historique git indisponible sur cet hôte.</div>
                    @endforelse
                </div>
            </div>
        </section>
    </div>
</main>
@endsection
