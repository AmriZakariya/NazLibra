@extends('castlit.layout')

@section('title', 'Abonnements — Administration CastLit')

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
    .pager { margin-top: 18px; }
    .pager a, .pager span { display: inline-block; }
</style>

<main class="admin">
    <div class="wrap">
        <div class="admin-head">
            <div>
                <h1>Demandes d'abonnement</h1>
                <p>Validez ou refusez les inscriptions. L'approbation lance la création automatique de l'espace client.</p>
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

        <div class="card">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>Commerce</th>
                        <th>Contact</th>
                        <th>Statut</th>
                        <th>Provisioning</th>
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
                            </td>
                            <td>
                                {{ $sub->contact_name }}<br>
                                <span class="muted">{{ $sub->email }}</span>
                            </td>
                            <td><span class="pill pill-{{ $sub->status }}">{{ $tabs[$sub->status] ?? $sub->status }}</span></td>
                            <td>
                                @if ($sub->install)
                                    <span class="pill pill-{{ $sub->install->status }}">{{ ucfirst($sub->install->status) }}</span>
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
                        <tr><td colspan="6" class="empty">Aucune demande dans cette catégorie.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="pager">{{ $subscriptions->links() }}</div>
    </div>
</main>
@endsection
