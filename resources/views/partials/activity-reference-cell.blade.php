<div class="max-w-[140px]">
    <span class="inline-flex items-center rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-mono font-semibold text-slate-700 dark:bg-white/10 dark:text-slate-300">{{ $reference }}</span>
    @if ($name)
        <p class="mt-0.5 truncate text-[11px] text-slate-400" title="{{ $name }}">{{ $name }}</p>
    @endif
</div>
