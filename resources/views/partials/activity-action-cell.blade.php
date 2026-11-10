@php
    $failed = ($log->outcome ?? 'ok') === \App\Support\ActivityRecorder::FAILED;
    $kindLabel = [
        'view' => 'Navigation',
        'download' => 'Téléchargement',
        'auth' => 'Connexion',
    ][$log->kind ?? 'action'] ?? null;
@endphp
<div class="max-w-[300px]" data-kind="{{ $log->kind ?? 'action' }}" data-outcome="{{ $log->outcome ?? 'ok' }}">
    <p class="flex min-w-0 items-center gap-1.5">
        @if ($failed)
            <span class="shrink-0 rounded-md bg-rose-100 px-1.5 py-px text-[10px] font-bold uppercase tracking-wide text-rose-700 dark:bg-rose-500/20 dark:text-rose-300">Échec</span>
        @endif
        @if ($kindLabel)
            <span class="shrink-0 rounded-md bg-slate-100 px-1.5 py-px text-[10px] font-bold uppercase tracking-wide text-slate-500 dark:bg-white/10 dark:text-slate-300">{{ $kindLabel }}</span>
        @endif
        <span class="truncate text-[13px] font-semibold text-slate-900 dark:text-white" title="{{ $friendlyLabel ?: $log->action }}">{{ $friendlyLabel ?: $log->action }}</span>
    </p>
    @if ($log->outcome_message)
        <p class="mt-0.5 truncate text-[11px] {{ $failed ? 'font-semibold text-rose-600 dark:text-rose-300' : 'text-emerald-700 dark:text-emerald-300' }}" title="{{ $log->outcome_message }}">{{ $log->outcome_message }}</p>
    @elseif ($friendlyLabel)
        <p class="mt-0.5 truncate text-[11px] text-slate-400">{{ $log->action }}</p>
    @else
        <p class="mt-0.5 truncate text-[11px] text-slate-400">{{ class_basename((string) $log->subject_type) ?: 'App' }}{{ $log->subject_id ? ' #'.$log->subject_id : '' }}</p>
    @endif
</div>
