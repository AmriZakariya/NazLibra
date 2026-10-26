@extends('castlit.layout')

@section('title', 'Journal — '.$install->domain.' — Administration Castl-it-POS')

@section('content')
<style>
    .admin { padding: 40px 0 0; }
    .admin-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 18px; }
    .admin-head h1 { font-size: 24px; font-weight: 800; letter-spacing: -.02em; }
    .admin-head p { color: var(--muted); font-size: 14px; margin-top: 4px; }
    .actions { display: flex; gap: 8px; flex-wrap: wrap; }
    .btn-sm { font-size: 12.5px; font-weight: 650; padding: 7px 12px; border-radius: 9px; border: 1px solid var(--sand); background: var(--surface); color: var(--ink); text-decoration: none; }
    .btn-sm:hover { border-color: var(--brand); color: var(--brand); }
    .btn-sm.is-on { background: var(--brand); border-color: var(--brand); color: #fff; }
    .card { background: var(--surface); border: 1px solid var(--sand); border-radius: var(--radius); box-shadow: var(--shadow); }
    .empty { padding: 36px 20px; text-align: center; color: var(--muted); }
    .entry { border-bottom: 1px solid color-mix(in srgb, var(--sand) 60%, transparent); padding: 14px 18px; }
    .entry:last-child { border-bottom: none; }
    .entry-head { display: flex; align-items: baseline; gap: 10px; flex-wrap: wrap; }
    .lvl { font-size: 11px; font-weight: 800; letter-spacing: .05em; padding: 2px 8px; border-radius: 999px; }
    .lvl.serious { background: var(--err-bg); color: var(--err); }
    .lvl.other { background: var(--warn-bg); color: var(--warn); }
    .when { font-size: 12.5px; color: var(--muted); font-variant-numeric: tabular-nums; }
    .msg { margin-top: 6px; font-size: 14px; font-weight: 600; word-break: break-word; }
    details summary { cursor: pointer; margin-top: 6px; font-size: 12.5px; color: var(--brand); }
    pre { margin-top: 8px; padding: 12px; border-radius: 10px; background: #0f1222; color: #e6e8f2; font-size: 12px; line-height: 1.5; overflow-x: auto; white-space: pre-wrap; word-break: break-word; }
    .note { margin-top: 14px; font-size: 12.5px; color: var(--muted); }
</style>

<section class="admin"><div class="wrap">
    <div class="admin-head">
        <div>
            <a href="{{ route('castlit.admin.clients') }}" class="btn-sm">← Clients</a>
            <h1 style="margin-top:12px">Journal d'erreurs — {{ $install->domain }}</h1>
            <p>
                @if ($file)
                    {{ $all ? 'Toutes les entrées récentes' : 'Erreurs du serveur et échecs de synchronisation de l\'app' }}
                    · fichier {{ basename($file) }} · {{ number_format($size / 1048576, 1, ',', ' ') }} Mo · modifié le {{ $updatedAt?->format('d/m/Y H:i') }}
                @else
                    Aucun journal trouvé pour cet espace.
                @endif
            </p>
        </div>
        @if ($file)
            <div class="actions">
                <a class="btn-sm {{ $all ? '' : 'is-on' }}" href="{{ route('castlit.admin.clients.journal', ['install' => $install->id]) }}">Erreurs</a>
                <a class="btn-sm {{ $all ? 'is-on' : '' }}" href="{{ route('castlit.admin.clients.journal', ['install' => $install->id, 'tout' => 1]) }}">Tout</a>
                <a class="btn-sm" href="{{ route('castlit.admin.clients.journal.download', ['install' => $install->id]) }}">Télécharger</a>
            </div>
        @endif
    </div>

    <div class="card" data-journal>
        @forelse ($entries as $entry)
            <article class="entry">
                <div class="entry-head">
                    <span class="lvl {{ $entry['serious'] ? 'serious' : 'other' }}">{{ $entry['level'] }}</span>
                    <span class="when">{{ $entry['at'] }}</span>
                </div>
                <p class="msg">{{ $entry['message'] }}</p>
                @if (trim($entry['details']) !== '')
                    <details><summary>Détails</summary><pre>{{ $entry['details'] }}</pre></details>
                @endif
            </article>
        @empty
            <p class="empty">{{ $file ? 'Aucune erreur récente. 🎉' : 'Le journal apparaîtra ici dès la première entrée.' }}</p>
        @endforelse
    </div>
    <p class="note">Les 150 entrées les plus récentes, de la plus récente à la plus ancienne. Le journal peut contenir des données de clients : ne le partagez qu'avec le support.</p>
</div></section>
@endsection
