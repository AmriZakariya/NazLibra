@extends('castlit.layout')

@section('title', $report->reference.' — Journaux')

@section('content')
<style>
    .admin { padding: 32px 0 40px; }
    .admin .wrap { max-width: 1440px; }
    .admin-head { display: flex; align-items: flex-end; gap: 16px; flex-wrap: wrap; margin-bottom: 14px; }
    .admin-head h1 { font-size: 24px; font-weight: 800; font-family: ui-monospace, Menlo, monospace; }
    .meta { color: var(--muted); font-size: 13.5px; display: flex; gap: 14px; flex-wrap: wrap; margin-top: 4px; }
    .meta a { color: var(--brand); font-weight: 650; text-decoration: none; }
    .note { background: var(--warn-bg); color: var(--warn); border-radius: 10px; padding: 10px 14px; margin-bottom: 14px; white-space: pre-wrap; font-size: 14px; }
    .bar { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 10px; }
    .bar a.lv { font-size: 12.5px; font-weight: 650; padding: 6px 11px; border-radius: 999px; border: 1px solid var(--sand); color: var(--muted); text-decoration: none; }
    .bar a.lv.on { background: var(--brand); border-color: var(--brand); color: #fff; }
    .bar input { flex: 1; min-width: 200px; max-width: 420px; padding: 8px 12px; border: 1px solid var(--sand); border-radius: 10px; background: var(--surface); color: var(--ink); font: inherit; font-size: 14px; }
    .btn-sm { font-size: 12.5px; font-weight: 650; padding: 7px 12px; border-radius: 9px; border: 1px solid var(--sand); background: var(--surface); color: var(--ink); text-decoration: none; }
    .count { color: var(--muted); font-size: 12.5px; }
    .log { background: #0b1020; color: #d7deef; border-radius: 12px; padding: 12px 0; max-height: 72vh; overflow: auto;
           font: 12.5px/1.55 ui-monospace, SFMono-Regular, Menlo, monospace; }
    .log div { padding: 0 14px; white-space: pre; }
    .log div:hover { background: rgba(255,255,255,.05); }
    .log .E { color: #ff8a8a; background: rgba(255,80,80,.08); }
    .log .W { color: #ffd27a; }
    .log .H { color: #8fb2ff; font-weight: 700; }
</style>
<main class="admin">
    <div class="wrap">
        <div class="admin-head">
            <div>
                <h1>{{ $report->reference }}</h1>
                <div class="meta">
                    <span>{{ $report->install?->subdomain ?? 'Avant connexion' }}@if ($report->user_name) · {{ $report->user_name }}@endif</span>
                    <span>{{ $report->device ?? '—' }} · {{ $report->platform }}@if ($report->app_version) · v{{ $report->app_version }}@endif</span>
                    <span>{{ $report->created_at->setTimezone('Africa/Casablanca')->format('d/m/Y H:i') }}</span>
                    @if ($report->conversation)<a href="{{ route('castlit.admin.support', ['c' => $report->conversation->id]) }}">Conversation support →</a>@endif
                </div>
            </div>
            <div style="margin-left:auto; display:flex; gap:8px">
                <a class="btn-sm" href="{{ route('castlit.admin.logs.download', $report) }}">Télécharger (.gz)</a>
                <a class="btn-sm" href="{{ route('castlit.admin.logs') }}">← Journaux</a>
            </div>
        </div>
        @if ($report->note)<div class="note">{{ $report->note }}</div>@endif
        <form class="bar" method="GET">
            @foreach (\App\Http\Controllers\Castlit\DeviceReportAdminController::LEVELS as $key => $label)
                <a class="lv {{ $level === $key ? 'on' : '' }}" href="{{ route('castlit.admin.logs.show', [$report, 'niveau' => $key, 'q' => $search ?: null]) }}">{{ $label }}</a>
            @endforeach
            <input type="hidden" name="niveau" value="{{ $level }}">
            <input type="search" name="q" value="{{ $search }}" placeholder="Rechercher dans le journal…">
            <span class="count">{{ number_format(count($lines), 0, ',', ' ') }} lignes</span>
        </form>
        <div class="log" id="log">
            @foreach ($lines as $line)
                @php $lv = \App\Support\DeviceReports::levelOf($line); @endphp
                <div class="{{ str_starts_with($line, '=====') ? 'H' : ($lv === 'E' || $lv === 'W' ? $lv : '') }}">{{ $line }}</div>
            @endforeach
        </div>
    </div>
</main>
<script>
    // The newest lines are at the bottom: open there.
    const log = document.getElementById('log');
    log.scrollTop = log.scrollHeight;
</script>
@endsection
