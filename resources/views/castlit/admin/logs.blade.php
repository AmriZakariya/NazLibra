@extends('castlit.layout')

@section('title', 'Journaux — Administration Castl-it-POS')

@section('content')
<style>
    .admin { padding: 32px 0 40px; }
    .admin .wrap { max-width: 1240px; }
    .admin-head { display: flex; align-items: flex-end; gap: 16px; flex-wrap: wrap; margin-bottom: 18px; }
    .admin-head h1 { font-size: 26px; font-weight: 800; letter-spacing: -.02em; }
    .admin-head p { color: var(--muted); font-size: 14px; }
    .card { background: var(--surface); border: 1px solid var(--sand); border-radius: var(--radius); box-shadow: var(--shadow); overflow: hidden; }
    .tools { padding: 12px; border-bottom: 1px solid var(--sand); }
    .tools input { width: 100%; max-width: 420px; padding: 9px 12px; border: 1px solid var(--sand); border-radius: 10px; background: var(--surface); color: var(--ink); font: inherit; font-size: 14px; }
    .scroll { overflow-x: auto; }
    .tbl { width: 100%; border-collapse: collapse; min-width: 760px; }
    .tbl th { text-align: left; font-size: 11.5px; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); padding: 12px 16px; border-bottom: 1px solid var(--sand); }
    .tbl td { padding: 12px 16px; border-bottom: 1px solid color-mix(in srgb, var(--sand) 55%, transparent); font-size: 14px; vertical-align: top; }
    .tbl tr:hover td { background: color-mix(in srgb, var(--brand) 4%, transparent); }
    .ref { font-family: ui-monospace, Menlo, monospace; font-weight: 800; color: var(--brand); text-decoration: none; }
    .muted { color: var(--muted); font-size: 12.5px; }
    .note { max-width: 360px; white-space: pre-wrap; }
    .empty { padding: 40px; text-align: center; color: var(--muted); }
</style>
<main class="admin">
    <div class="wrap">
        <div class="admin-head">
            <div>
                <h1>Journaux des appareils</h1>
                <p>Envoyés depuis l'application (« Envoyer les journaux au support »). Gardés {{ \App\Support\DeviceReports::KEEP_DAYS }} jours.</p>
            </div>
            <div style="margin-left:auto; display:flex; gap:10px; flex-wrap:wrap">
                <a href="{{ route('castlit.admin.support') }}" class="btn btn-ghost" style="padding:11px 18px">Support</a>
                <a href="{{ route('castlit.admin.clients') }}" class="btn btn-ghost" style="padding:11px 18px">Clients</a>
            </div>
        </div>
        <section class="card">
            <form class="tools" method="GET">
                <input type="search" name="q" value="{{ $search }}" placeholder="Référence, espace, appareil, note…">
            </form>
            <div class="scroll">
                <table class="tbl">
                    <thead><tr><th>Référence</th><th>Espace</th><th>Appareil</th><th>Note</th><th>Reçu</th></tr></thead>
                    <tbody>
                        @forelse ($reports as $r)
                            <tr data-report="{{ $r->reference }}">
                                <td><a class="ref" href="{{ route('castlit.admin.logs.show', $r) }}">{{ $r->reference }}</a>
                                    <div class="muted">{{ number_format($r->size / 1024, 0, ',', ' ') }} Ko</div></td>
                                <td>{{ $r->install?->subdomain ?? 'Avant connexion' }}@if ($r->user_name)<div class="muted">{{ $r->user_name }}</div>@endif</td>
                                <td>{{ $r->device ?? '—' }}<div class="muted">{{ $r->platform }}@if ($r->app_version) · v{{ $r->app_version }}@endif</div></td>
                                <td class="note">{{ $r->note }}</td>
                                <td class="muted">{{ $r->created_at->setTimezone('Africa/Casablanca')->format('d/m/Y H:i') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="empty">Aucun journal reçu.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</main>
@endsection
