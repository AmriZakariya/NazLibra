{{-- One terminal in the picker. Expects $device, $selectable and the parent's $tr, $tzDate, $ago, $icon, $currentDeviceId, $preferredDeviceId. --}}
@php
    $isCurrent = $currentDeviceId !== null && (int) $device->id === $currentDeviceId;
    $isBusy = $device->is_in_use && ! $isCurrent;
    $isDisabled = ! $selectable || $isBusy;
    $isRemembered = ! $isCurrent && $preferredDeviceId === (int) $device->id;
    $isSelected = $isCurrent || ($isRemembered && ! $isDisabled);
    $session = $device->active_session ?? null;
    $sessionUser = $session?->user;
    $realDevice = trim(collect([$session?->platform, $session?->browser])->filter()->join(' · '));
    $type = in_array($device->type, ['computer', 'tablet', 'mobile'], true) ? $device->type : 'other';
@endphp
<label class="device-item {{ $isDisabled ? 'is-disabled' : '' }} {{ $isBusy ? 'is-busy' : '' }} {{ $isSelected ? 'is-selected' : '' }}"
       data-device-id="{{ $device->id }}"
       data-name="{{ $device->name }}"
       data-search="{{ mb_strtolower($device->name.' '.$device->description) }}"
       @if ($isCurrent) data-current @endif
       @if ($isRemembered) data-remembered @endif
       @if ($isBusy) title="{{ $tr('Occupé') }}" @endif>
    @if ($selectable)
        <input type="radio" name="virtual_device_id" value="{{ $device->id }}" {{ $isDisabled ? 'disabled' : '' }} {{ $isSelected ? 'checked' : '' }}>
    @endif
    <span class="device-icon">{!! $icon($type) !!}<span class="dot" aria-hidden="true"></span></span>
    <span class="body">
        <span class="device-name">{{ $device->name }}</span>
        @if ($session && ! $isCurrent)
            <span class="device-lock">
                {{ $sessionUser?->name ?? $tr('Utilisateur inconnu') }} · {{ $ago($session->last_seen_at ?? $session->connected_at) }}
            </span>
            <span class="device-detail">
                {{ $tr('Utilisé par') }} {{ $sessionUser?->name ?? $tr('Utilisateur inconnu') }}
                @if ($realDevice !== '') · {{ $realDevice }} @endif
                @if ($session->ip_address) · IP {{ $session->ip_address }} @endif
                <br>
                {{ $tr('Connecté le') }} {{ $tzDate($session->connected_at) }}
                · {{ $tr('Dernière activité') }} {{ $tzDate($session->last_seen_at) }}
            </span>
        @elseif ($device->description)
            <span class="device-meta">{{ $device->description }}</span>
        @endif
    </span>
    @if ($isCurrent)
        <span class="device-badge badge-current">{{ $tr('Connecté') }}</span>
    @elseif ($isBusy)
        <span class="device-badge badge-in-use">{{ $tr('Occupé') }}</span>
    @elseif ($isRemembered && $selectable)
        <span class="device-badge badge-remembered">{{ $tr('Dernier appareil') }}</span>
    @endif
</label>
