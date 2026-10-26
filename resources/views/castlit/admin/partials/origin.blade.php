{{-- Where a request came in. One partial so the list and the detail page
     cannot disagree about what a source is called or what it looks like. --}}
@php $origin = $sub->origin() ?? 'unknown'; @endphp
<span class="origin origin-{{ $origin }}" title="Provenance de la demande">
    @switch($origin)
        @case('website')
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg>
            @break
        @case('android')
            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.6 9.5 19.4 6.4a.4.4 0 0 0-.7-.4l-1.8 3.1A10.9 10.9 0 0 0 12 8a10.9 10.9 0 0 0-4.9 1.1L5.3 6a.4.4 0 0 0-.7.4l1.8 3.1A10.4 10.4 0 0 0 1 18h22a10.4 10.4 0 0 0-5.4-8.5ZM7 15.3a1 1 0 1 1 1-1 1 1 0 0 1-1 1Zm10 0a1 1 0 1 1 1-1 1 1 0 0 1-1 1Z"/></svg>
            @break
        @case('ios')
            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M16.4 12.6a4.6 4.6 0 0 1 2.2-3.9 4.7 4.7 0 0 0-3.7-2c-1.6-.2-3 .9-3.8.9s-2-.9-3.3-.9a4.9 4.9 0 0 0-4.1 2.5c-1.8 3-.5 7.5 1.2 10 .8 1.2 1.8 2.6 3.1 2.5s1.7-.8 3.3-.8 2 .8 3.3.8 2.2-1.2 3-2.4a10 10 0 0 0 1.3-2.8 4.4 4.4 0 0 1-2.5-3.9ZM14 5.3A4.4 4.4 0 0 0 15 2a4.6 4.6 0 0 0-3 1.5 4.2 4.2 0 0 0-1 3.2 3.8 3.8 0 0 0 3-1.4Z"/></svg>
            @break
        @case('desktop')
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/></svg>
            @break
        @case('mobile')
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="6" y="2" width="12" height="20" rx="2.5"/><path d="M11 18h2"/></svg>
            @break
        @case('admin')
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
            @break
        @default
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6M12 17h.01"/></svg>
    @endswitch
    {{ $sub->originLabel() }}
</span>
