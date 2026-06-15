<div class="max-w-[180px]">
    <p class="truncate text-[12px] font-semibold text-slate-700 dark:text-slate-300" title="{{ $log->deviceLabel() }}">{{ $log->deviceLabel() }}</p>
    <p class="mt-0.5 truncate text-[11px] text-slate-400" title="{{ $log->realDeviceLabel() }}">{{ $log->realDeviceLabel() ?: 'Appareil réel' }}</p>
    @if ($log->device_name_snapshot)
        <p class="mt-0.5 truncate text-[10px] font-semibold uppercase tracking-wide text-brand">{{ $log->device_code_snapshot ?: 'Virtuel' }}</p>
    @endif
</div>
