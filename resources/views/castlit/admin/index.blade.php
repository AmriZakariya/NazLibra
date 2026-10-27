@extends('castlit.layout')

@section('title', 'Abonnements — Administration Castl-it-POS')

@section('content')
<style>
    .admin { padding: 40px 0 0; }
    .admin-head { display: flex; align-items: flex-end; gap: 16px; flex-wrap: wrap; margin-bottom: 22px; }
    .admin-head h1 { font-size: 26px; font-weight: 800; letter-spacing: -.02em; }
    .admin-head p { color: var(--muted); font-size: 14px; }
    .tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 18px; }
    .tab { display: inline-flex; align-items: center; gap: 8px; padding: 8px 14px; border-radius: 999px;
           border: 1px solid var(--sand); font-size: 13.5px; font-weight: 600; color: var(--muted); background: var(--surface); }
    .tab.active { background: var(--brand); color: #fff; border-color: var(--brand); }
    .tab .n { font-variant-numeric: tabular-nums; background: color-mix(in srgb, currentColor 16%, transparent);
              border-radius: 999px; padding: 0 7px; font-size: 12px; }
    .card { background: var(--surface); border: 1px solid var(--sand); border-radius: var(--radius); box-shadow: var(--shadow); overflow: hidden; }
    .tbl { width: 100%; border-collapse: collapse; }
    .tbl th { text-align: left; font-size: 11.5px; text-transform: uppercase; letter-spacing: .06em;
              color: var(--muted); padding: 13px 16px; border-bottom: 1px solid var(--sand); }
    .tbl td { padding: 14px 16px; border-bottom: 1px solid color-mix(in srgb, var(--sand) 55%, transparent); font-size: 14px; vertical-align: middle; }
    .tbl tr:last-child td { border-bottom: none; }
    .tbl tr:hover td { background: color-mix(in srgb, var(--brand) 4%, transparent); }
    .biz { font-weight: 700; }
    .biz .sd { color: var(--muted); font-weight: 500; font-size: 12.5px; font-variant-numeric: tabular-nums; }
    .tag-manual { display: inline-block; margin-left: 6px; vertical-align: middle; font-size: 10.5px; font-weight: 700;
                  text-transform: uppercase; letter-spacing: .04em; padding: 2px 7px; border-radius: 999px;
                  color: var(--accent); background: color-mix(in srgb, var(--accent) 14%, transparent); }
    .pill { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 700;
            padding: 4px 10px; border-radius: 999px; }
    .pill-pending { background: var(--warn-bg); color: var(--warn); }
    .pill-approved { background: var(--ok-bg); color: var(--ok); }
    .pill-rejected { background: var(--err-bg); color: var(--err); }
    .pill-live { background: var(--ok-bg); color: var(--ok); }
    .pill-failed { background: var(--err-bg); color: var(--err); }
    .pill-running, .pill-queued { background: color-mix(in srgb, var(--brand) 12%, transparent); color: var(--brand); }
    .row-link { color: var(--brand); font-weight: 650; font-size: 13.5px; }
    .empty { padding: 48px; text-align: center; color: var(--muted); }
    .muted { color: var(--muted); font-size: 12.5px; }
    .origin { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 700; padding: 4px 10px;
              border-radius: 999px; border: 1px solid var(--sand); background: color-mix(in srgb, var(--surface) 70%, transparent); white-space: nowrap; }
    .origin svg { width: 14px; height: 14px; flex: none; }
    .origin-website { color: #7FA3FF; }
    .origin-android { color: #3DDC84; }
    .origin-ios { color: #E6E8EF; }
    .origin-mobile { color: #B9A7FF; }
    .origin-desktop { color: #5EC8F0; }
    .origin-admin, .origin-unknown { color: var(--muted); }
    .place { margin-top: 6px; font-size: 12.5px; color: var(--ink); white-space: nowrap; }
    .place .none { color: var(--muted); }
    .origins { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 14px; margin: -6px 0 16px; font-size: 13px; color: var(--muted); }
    /* Same look as on the Clients page. */
    .accode { display: flex; align-items: center; gap: 6px; margin-top: 6px; }
    .accode-label { color: var(--muted); font-size: 11px; text-transform: uppercase; letter-spacing: .4px; }
    .accode code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 13px; font-weight: 700;
                   letter-spacing: 1px; background: var(--surface); border: 1px solid var(--sand); border-radius: 7px; padding: 2px 8px; }
    .accode-btn { border: 1px solid var(--sand); background: var(--surface); color: var(--muted); border-radius: 7px;
                  width: 26px; height: 26px; cursor: pointer; line-height: 1; }
    .accode-btn:hover { border-color: var(--brand); color: var(--brand); }
    .accode form { margin: 0; }
    .accode-btn.copied { border-color: var(--ok); color: var(--ok); }
    .origins strong { color: var(--ink); font-variant-numeric: tabular-nums; }
    .pager { margin-top: 18px; }
    /* Connexions: has the client reached their space (access journal). */
    .acc { font-size: 12.5px; line-height: 1.45; min-width: 150px; }
    .acc-when { display: flex; align-items: center; gap: 7px; font-weight: 700; color: var(--ink); white-space: nowrap; }
    .acc-ch { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 800; padding: 2px 7px; border-radius: 999px; }
    .acc-ch svg { width: 12px; height: 12px; }
    .acc-app { background: color-mix(in srgb, var(--brand) 12%, transparent); color: var(--brand); }
    .acc-web { background: var(--ok-bg); color: var(--ok); }
    .acc-dev { color: var(--ink); margin-top: 3px; }
    .acc-n { color: var(--muted); }
    .acc-never { display: inline-block; font-size: 12px; font-weight: 700; padding: 4px 10px; border-radius: 999px; background: var(--warn-bg); color: var(--warn); }
    .acc-fail { margin-top: 4px; color: var(--err); font-weight: 650; }
    .pager a, .pager span { display: inline-block; }
</style>

<main class="admin">
    <div class="wrap">
        <div class="admin-head">
            <div>
                <h1>Demandes d'abonnement</h1>
                <p>Validez ou refusez les inscriptions. L'approbation lance la création automatique de l'espace client.</p>
            </div>
            <div style="margin-left:auto; display:flex; gap:10px; flex-wrap:wrap">
                <a href="{{ route('castlit.admin.clients') }}" class="btn btn-ghost" style="padding:11px 18px; white-space:nowrap">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:6px"><rect x="3" y="4" width="18" height="6" rx="1.5"/><rect x="3" y="14" width="18" height="6" rx="1.5"/></svg>
                    Clients
                </a>
                <a href="{{ route('castlit.admin.create') }}" class="btn btn-primary" style="padding:11px 18px; white-space:nowrap">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" style="margin-right:6px"><path d="M12 5v14M5 12h14"/></svg>
                    Nouveau client
                </a>
            </div>
        </div>

        @if (session('success'))<div class="flash flash-ok">{{ session('success') }}</div>@endif
        @if (session('error'))<div class="flash flash-err">{{ session('error') }}</div>@endif

        <div class="tabs">
            @php $tabs = ['pending' => 'En attente', 'approved' => 'Approuvées', 'rejected' => 'Rejetées', 'all' => 'Toutes']; @endphp
            @foreach ($tabs as $key => $label)
                <a href="{{ route('castlit.admin.index', $key === 'all' ? [] : ['status' => $key]) }}"
                   class="tab {{ $status === $key ? 'active' : '' }}">
                    {{ $label }}
                    @if (isset($counts[$key]))<span class="n">{{ $counts[$key] }}</span>@endif
                </a>
            @endforeach
        </div>

        {{-- D'où viennent les demandes, toutes périodes confondues : c'est ce
             qui dit si l'argent mis dans une boutique d'applications ou dans
             le site rapporte des commerces. --}}
        @if (isset($origins) && $origins->isNotEmpty())
            <div class="origins" data-origins>
                <span>Provenance :</span>
                @foreach ($origins as $label => $n)
                    <span>{{ $label }} <strong>{{ $n }}</strong></span>
                @endforeach
            </div>
        @endif

        <div class="card">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>Commerce</th>
                        <th>Contact</th>
                        <th>Provenance</th>
                        <th>Statut</th>
                        <th>Provisioning</th>
                        <th>Connexions</th>
                        <th>Reçue</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($subscriptions as $sub)
                        <tr>
                            <td>
                                <div class="biz">{{ $sub->business_name }}</div>
                                <div class="sd">{{ $sub->desired_subdomain }}.{{ config('castlit.main_domain') }}</div>
                                {{-- Le code que le client saisit dans l'application pour
                                     trouver son espace. Affiché ici pour pouvoir le lui
                                     donner au téléphone sans changer de page ; la
                                     régénération reste sur la page Clients, puisqu'elle
                                     invalide l'ancien code. --}}
                                @if ($sub->install?->access_code)
                                    <div class="accode" data-access-code="{{ $sub->install->access_code }}">
                                        <span class="accode-label">Code d'accès</span>
                                        <code>{{ $sub->install->access_code }}</code>
                                        <button type="button" class="accode-btn" title="Copier le code"
                                                onclick="navigator.clipboard?.writeText(this.closest('[data-access-code]').dataset.accessCode).then(() => { this.classList.add('copied'); this.textContent = '✓'; setTimeout(() => { this.classList.remove('copied'); this.textContent = '⧉'; }, 1500); })">⧉</button>
                                    </div>
                                @endif
                            </td>
                            <td>
                                {{ $sub->contact_name }}<br>
                                <span class="muted">{{ $sub->email }}</span>
                            </td>
                            <td data-origin="{{ $sub->origin() ?? 'unknown' }}">
                                @include('castlit.admin.partials.origin', ['sub' => $sub])
                                <div class="place">
                                    @if ($sub->place())
                                        {{ \App\Support\Countries::flag($sub->country) }} {{ $sub->place() }}
                                    @else
                                        <span class="none">Lieu non renseigné</span>
                                    @endif
                                </div>
                            </td>
                            <td><span class="pill pill-{{ $sub->status }}">{{ $tabs[$sub->status] ?? $sub->status }}</span></td>
                            <td>
                                @if ($sub->install)
                                    <span class="pill pill-{{ $sub->install->status }}">{{ ucfirst($sub->install->status) }}</span>
                                @else
                                    <span class="muted">—</span>
                                @endif
                            </td>
                            <td>
                                @if ($sub->install?->status === \App\Models\TenantInstall::STATUS_LIVE)
                                    @include('castlit.admin.partials.access', ['summary' => $access[$sub->install->id] ?? null])
                                @else
                                    <span class="muted">—</span>
                                @endif
                            </td>
                            <td class="muted">{{ $sub->created_at->format('d/m/Y H:i') }}</td>
                            <td style="text-align:right">
                                <a href="{{ route('castlit.admin.show', $sub) }}" class="row-link">Détails →</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="empty">Aucune demande dans cette catégorie.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="pager">{{ $subscriptions->links() }}</div>
    </div>
</main>
@endsection
