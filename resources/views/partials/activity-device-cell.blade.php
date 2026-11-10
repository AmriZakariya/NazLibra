<div class="max-w-[180px]">
    <p class="truncate text-[12px] font-semibold text-slate-700 dark:text-slate-300" title="{{ $log->deviceLabel() }}">{{ $log->deviceLabel() }}</p>
    <p class="mt-0.5 truncate text-[11px] text-slate-400" title="{{ $log->realDeviceLabel() }}">{{ $log->realDeviceLabel() ?: 'Appareil réel' }}</p>
    @if ($log->device_name_snapshot || $log->app_version)
        <p class="mt-0.5 flex items-center gap-1.5 truncate text-[10px] font-semibold uppercase tracking-wide text-brand">
            @if ($log->device_name_snapshot)<span>{{ $log->device_code_snapshot ?: 'Virtuel' }}</span>@endif
            @if ($log->app_version)<span class="rounded bg-brand/10 px-1.5 py-px normal-case tracking-normal" data-app-version>v{{ $log->app_version }}</span>@endif
        </p>
    @endif
</div>
