{{-- Has this client reached their space? From the access journal: the last
     time the right code was entered, from what, and how often. --}}
@php
    /** @var array{count:int,devices:int,last:?\App\Models\ClientAccessEvent,failed:int}|null $summary */
    $last = $summary['last'] ?? null;
    $phone = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><rect x="6" y="2" width="12" height="20" rx="2.5"/><path d="M11 18h2"/></svg>';
    $globe = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 0 1 0 20 15 15 0 0 1 0-20"/></svg>';
@endphp
<div class="acc" data-access="{{ $last ? 'connected' : 'never' }}">
    @if ($last)
        <div class="acc-when" title="{{ $last->created_at->format('d/m/Y H:i') }}">
            <span class="acc-ch acc-{{ $last->channel }}">{!! $last->channel === 'web' ? $globe : $phone !!}{{ $last->channel === 'web' ? 'Web' : 'App' }}</span>
            {{ $last->created_at->locale('fr')->diffForHumans() }}
        </div>
        <div class="acc-dev">{{ $last->device_label ?? '—' }}</div>
        <div class="acc-n">
            {{ $summary['count'] }} accès · {{ $summary['devices'] }} {{ $summary['devices'] > 1 ? 'appareils' : 'appareil' }}
        </div>
    @else
        <span class="acc-never">Jamais connecté</span>
    @endif
    @if (($summary['failed'] ?? 0) > 0)
        <div class="acc-fail">{{ $summary['failed'] }} code{{ $summary['failed'] > 1 ? 's' : '' }} erroné{{ $summary['failed'] > 1 ? 's' : '' }} (30 j)</div>
    @endif
</div>
