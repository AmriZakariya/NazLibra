<div class="max-w-[260px]">
    @if ($friendlyLabel)
        <p class="truncate text-[13px] font-semibold text-slate-900 dark:text-white" title="{{ $friendlyLabel }}">{{ $friendlyLabel }}</p>
        <p class="mt-0.5 truncate text-[11px] text-slate-400">{{ $log->action }}</p>
    @else
        <p class="truncate text-[13px] font-semibold text-slate-900 dark:text-white" title="{{ $log->action }}">{{ $log->action }}</p>
        <p class="mt-0.5 truncate text-[11px] text-slate-400">{{ class_basename((string) $log->subject_type) ?: 'App' }}{{ $log->subject_id ? ' #'.$log->subject_id : '' }}</p>
    @endif
</div>
