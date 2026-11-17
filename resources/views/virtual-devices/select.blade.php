@php
    $locale = \App\Support\Locale::current($tenant);
    $tr = fn (string $text): string => \App\Support\Locale::t($text, $locale);
    $hasCurrent = $currentSession && $currentSession->virtualDevice?->is_active;
    $currentDeviceId = $hasCurrent ? (int) $currentSession->virtual_device_id : null;
    $preferredDeviceId = isset($preferredDeviceId) ? (int) $preferredDeviceId : null;
    $devicePersistenceKey = 'librairepro.virtual_device.' . $tenant->id . '.' . (auth()->id() ?? 'guest');
    $tzDate = fn ($date, string $format = 'd/m/Y H:i'): string => \App\Support\TenantClock::format($date, $tenant, $format);
    $ago = fn ($date): string => $date ? $date->copy()->locale($locale)->diffForHumans() : '';

    $groups = \App\Support\TerminalPicker::groups($devices, $currentDeviceId);
    $webGroups = array_values(array_filter($groups, fn ($g) => $g['selectable']));
    $mobileGroup = collect($groups)->firstWhere('selectable', false);
    $webCount = array_sum(array_column($webGroups, 'total'));
    $availableCount = array_sum(array_column($webGroups, 'free'));
    $showSearch = $webCount > 8;

    $icons = [
        'computer' => '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>',
        'tablet'   => '<rect x="5" y="2.5" width="14" height="19" rx="2.5"/><path d="M11 18.5h2"/>',
        'mobile'   => '<rect x="7" y="2.5" width="10" height="19" rx="2.2"/><path d="M11 18.5h2"/>',
        'other'    => '<rect x="3" y="7" width="18" height="10" rx="2"/><path d="M7 11h4M15 12h2"/>',
    ];
    $icon = fn (string $type): string => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.($icons[$type] ?? $icons['other']).'</svg>';
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ \App\Support\Locale::dir($locale) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="device-heartbeat" content="{{ route('device.heartbeat') }}">
    <title>{{ $tr('Sélectionner un appareil') }} · {{ $tenant->name }}</title>
    <style>
        :root {
            --ground: #0e1420;
            --ground-2: #161e2e;
            --panel: rgba(255,255,255,0.035);
            --line: rgba(255,255,255,0.09);
            --line-strong: rgba(255,255,255,0.18);
            --ink: #eef2f7;
            --ink-2: #a3aec2;
            --ink-3: #6b778c;
            --accent: #7c83f6;
            --accent-soft: rgba(124,131,246,0.13);
            --free: #34d399;
            --busy: #fbbf24;
            --busy-soft: rgba(251,191,36,0.12);
            --danger: #f87171;
            --danger-soft: rgba(239,68,68,0.12);
            --radius: 14px;
        }
        *, ::before, ::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Noto Sans Arabic', sans-serif;
            background: radial-gradient(1200px 600px at 50% -10%, #1d2740 0%, var(--ground) 60%) fixed, var(--ground);
            min-height: 100vh;
            color: var(--ink);
            padding: clamp(1rem, 4vw, 3rem) 1rem;
            display: flex;
            justify-content: center;
            align-items: flex-start;
        }
        .shell { width: 100%; max-width: 760px; }
        .card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 22px;
            padding: clamp(1.25rem, 4vw, 2.25rem);
            box-shadow: 0 30px 70px rgba(0,0,0,0.35);
        }
        header.top { display: flex; gap: 1rem; align-items: center; margin-bottom: 1.5rem; }
        .shop-mark {
            display: grid; place-items: center; flex-shrink: 0;
            width: 48px; height: 48px; border-radius: 14px;
            background: var(--accent-soft); color: var(--accent);
        }
        .title { font-size: 1.3rem; font-weight: 700; text-wrap: balance; }
        .subtitle { font-size: 0.88rem; color: var(--ink-2); margin-top: 0.2rem; line-height: 1.5; }
        .summary { margin-inline-start: auto; text-align: end; font-size: 0.8rem; color: var(--ink-3); white-space: nowrap; }
        .summary strong { display: block; font-size: 1.15rem; color: var(--free); font-variant-numeric: tabular-nums; }

        .error { background: var(--danger-soft); color: var(--danger); padding: 0.75rem 1rem; border-radius: 12px; font-size: 0.86rem; font-weight: 500; margin-bottom: 1rem; }

        .resume {
            display: none; align-items: center; gap: 0.9rem;
            padding: 0.85rem 1rem; margin-bottom: 1.25rem;
            border-radius: var(--radius);
            background: linear-gradient(90deg, var(--accent-soft), transparent);
            border: 1px solid rgba(124,131,246,0.35);
        }
        .resume.is-visible { display: flex; }
        .resume-text { flex: 1; min-width: 0; font-size: 0.85rem; color: var(--ink-2); }
        .resume-text strong { display: block; color: var(--ink); font-size: 0.98rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

        .search { position: relative; margin-bottom: 1.25rem; }
        .search input {
            width: 100%; padding: 0.75rem 1rem 0.75rem 2.6rem;
            border-radius: 12px; border: 1px solid var(--line);
            background: rgba(0,0,0,0.2); color: var(--ink); font-size: 0.92rem;
        }
        [dir="rtl"] .search input { padding: 0.75rem 2.6rem 0.75rem 1rem; }
        .search input:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-soft); }
        .search svg { position: absolute; inset-inline-start: 0.9rem; top: 50%; transform: translateY(-50%); color: var(--ink-3); }

        .group + .group { margin-top: 1.5rem; }
        .group-head { display: flex; align-items: baseline; gap: 0.6rem; margin-bottom: 0.7rem; }
        .group-title { font-size: 0.75rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; color: var(--ink-2); }
        .group-count { font-size: 0.75rem; color: var(--ink-3); font-variant-numeric: tabular-nums; }
        .group-count b { color: var(--free); font-weight: 600; }
        .group-rule { flex: 1; height: 1px; background: var(--line); align-self: center; }

        .tiles { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 300px), 1fr)); gap: 0.6rem; }
        .device-item {
            position: relative;
            display: flex; align-items: center; gap: 0.85rem;
            padding: 0.9rem 1rem;
            border-radius: var(--radius);
            border: 1.5px solid var(--line);
            background: rgba(255,255,255,0.015);
            cursor: pointer;
            transition: border-color .15s, background .15s, transform .15s;
            color: inherit; min-width: 0;
        }
        .device-item input { position: absolute; opacity: 0; width: 1px; height: 1px; pointer-events: none; }
        .device-item:hover:not(.is-disabled) { border-color: var(--line-strong); background: rgba(255,255,255,0.04); }
        .device-item:has(input:focus-visible) { outline: 2px solid var(--accent); outline-offset: 2px; }
        .device-item.is-selected { border-color: var(--accent); background: var(--accent-soft); }
        .device-item.is-disabled { cursor: default; }
        .device-item.is-disabled .device-icon, .device-item.is-disabled .device-name { opacity: 0.55; }
        .device-item[hidden] { display: none; }

        .device-icon {
            position: relative; flex-shrink: 0;
            display: grid; place-items: center;
            width: 42px; height: 42px; border-radius: 12px;
            background: rgba(255,255,255,0.06); color: var(--ink-2);
        }
        .is-selected .device-icon { background: var(--accent); color: #fff; }
        .dot {
            position: absolute; inset-inline-end: -3px; bottom: -3px;
            width: 12px; height: 12px; border-radius: 50%;
            border: 2.5px solid var(--ground-2);
            background: var(--free);
        }
        .is-busy .dot { background: var(--busy); }
        .body { flex: 1; min-width: 0; }
        .device-name { display: block; font-weight: 600; font-size: 0.97rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .device-meta { display: block; font-size: 0.78rem; color: var(--ink-3); margin-top: 0.15rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .device-lock { display: block; font-size: 0.78rem; color: var(--busy); margin-top: 0.2rem; }
        .device-detail {
            display: none; font-size: 0.74rem; color: var(--ink-2); line-height: 1.5;
            margin-top: 0.45rem; padding-top: 0.45rem; border-top: 1px dashed var(--line);
        }
        .device-item.is-busy:hover .device-detail,
        .device-item.is-busy.show-detail .device-detail { display: block; }

        .device-badge {
            flex-shrink: 0; align-self: center;
            font-size: 0.68rem; font-weight: 700; letter-spacing: 0.03em; text-transform: uppercase;
            padding: 0.25rem 0.55rem; border-radius: 999px;
        }
        .badge-in-use { background: var(--busy-soft); color: var(--busy); }
        .badge-current { background: rgba(52,211,153,0.14); color: var(--free); }
        .badge-remembered { background: var(--accent-soft); color: #b7bcfb; }
        .badge-disabled { background: rgba(255,255,255,0.06); color: var(--ink-3); }

        .no-match { display: none; text-align: center; color: var(--ink-3); font-size: 0.88rem; padding: 1.5rem 0; }
        .no-match.is-visible { display: block; }

        details.mobile-group { margin-top: 1.5rem; border-top: 1px solid var(--line); padding-top: 1rem; }
        details.mobile-group summary {
            list-style: none; cursor: pointer; display: flex; align-items: center; gap: 0.6rem;
            font-size: 0.82rem; color: var(--ink-2);
        }
        details.mobile-group summary::-webkit-details-marker { display: none; }
        details.mobile-group summary:focus-visible { outline: 2px solid var(--accent); outline-offset: 4px; border-radius: 6px; }
        .chev { transition: transform .15s; color: var(--ink-3); }
        details[open] .chev { transform: rotate(90deg); }
        [dir="rtl"] .chev { transform: scaleX(-1); }
        [dir="rtl"] details[open] .chev { transform: rotate(90deg); }
        details.mobile-group .tiles { margin-top: 0.8rem; }
        .mobile-hint { color: var(--ink-3); }

        .actions { position: sticky; bottom: 0; margin-top: 1.5rem; padding-top: 1rem; background: linear-gradient(transparent, var(--ground-2) 35%); }
        .btn {
            display: flex; align-items: center; justify-content: center; gap: 0.5rem;
            width: 100%; padding: 0.9rem 1.5rem;
            border-radius: 14px; border: none;
            font-size: 0.95rem; font-weight: 600; cursor: pointer;
            transition: background .15s, opacity .15s;
        }
        .btn:focus-visible { outline: 2px solid #fff; outline-offset: 2px; }
        .btn-primary { background: var(--accent); color: #fff; }
        .btn-primary:hover { background: #666ef0; }
        .btn-primary:disabled { opacity: 0.4; cursor: not-allowed; }
        .btn-small { width: auto; padding: 0.55rem 1rem; font-size: 0.85rem; flex-shrink: 0; }
        .btn-outline { background: transparent; border: 1.5px solid var(--line-strong); color: var(--ink-2); margin-top: 0.6rem; }
        .btn-outline:hover { border-color: var(--danger); color: var(--danger); }

        .empty { text-align: center; padding: 3rem 1rem; color: var(--ink-3); }
        .footer { text-align: center; margin-top: 1.25rem; }
        .footer a { color: var(--ink-3); font-size: 0.82rem; text-decoration: none; }
        .footer a:hover, .footer a:focus-visible { color: var(--danger); }

        @media (max-width: 520px) {
            header.top { flex-wrap: wrap; }
            .summary { margin-inline-start: 0; text-align: start; width: 100%; display: flex; gap: 0.4rem; align-items: baseline; }
            .summary strong { display: inline; }
            .badge-remembered { display: none; }
        }
        @media (prefers-reduced-motion: reduce) { * { transition: none !important; } }
    </style>
    @include('partials.i18n-head', ['locale' => $locale])
</head>
<body>
<div class="shell">
    <div class="card" style="background-color: var(--ground-2);">
        <header class="top">
            <span class="shop-mark">{!! $icon('computer') !!}</span>
            <div>
                <h1 class="title">{{ $tr('Sélectionner un appareil') }}</h1>
                <p class="subtitle">{{ $tr('Chaque terminal est utilisé par une seule personne à la fois.') }}</p>
            </div>
            @if ($webCount > 0)
                <div class="summary"><strong>{{ $availableCount }} / {{ $webCount }}</strong>{{ $tr('terminaux libres') }}</div>
            @endif
        </header>

        @error('virtual_device_id')
            <div class="error" role="alert">{{ $message }}</div>
        @enderror

        @if ($currentSession && ! $currentSession->virtualDevice?->is_active)
            <div class="error" role="alert">{{ $tr('Votre appareil actuel a été désactivé. Veuillez en choisir un autre.') }}</div>
        @endif

        <form method="POST" action="{{ route('device.connect') }}" id="device-form" data-device-persistence-key="{{ $devicePersistenceKey }}">
            @csrf

            <div class="resume" id="resume" aria-live="polite">
                <span class="shop-mark" style="width:38px;height:38px;border-radius:11px;" id="resume-icon"></span>
                <span class="resume-text">{{ $tr('Reprendre sur') }}<strong id="resume-name"></strong></span>
                <button type="button" class="btn btn-primary btn-small" id="resume-btn">{{ $tr('Reprendre') }}</button>
            </div>

            @if ($showSearch)
                <label class="search">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                    <input type="search" id="device-search" placeholder="{{ $tr('Rechercher un terminal') }}" aria-label="{{ $tr('Rechercher un terminal') }}" autocomplete="off">
                </label>
            @endif

            @forelse ($webGroups as $group)
                <section class="group" data-group="{{ $group['type'] }}" aria-labelledby="group-{{ $group['type'] }}">
                    <div class="group-head">
                        <h2 class="group-title" id="group-{{ $group['type'] }}">{{ $tr($group['label']) }}</h2>
                        <span class="group-count"><b>{{ $group['free'] }}</b> / {{ $group['total'] }} {{ $tr('terminaux libres') }}</span>
                        <span class="group-rule"></span>
                    </div>
                    <div class="tiles" role="radiogroup" aria-labelledby="group-{{ $group['type'] }}">
                        @foreach ($group['devices'] as $device)
                            @include('virtual-devices.partials.terminal-tile', ['device' => $device, 'selectable' => true])
                        @endforeach
                    </div>
                </section>
            @empty
                @unless ($mobileGroup)
                    <div class="empty">
                        <p style="font-weight:600;margin-bottom:0.25rem;color:var(--ink-2);">{{ $tr('Aucun appareil disponible') }}</p>
                        <p style="font-size:0.85rem;">{{ $tr('Contactez le propriétaire pour configurer les appareils virtuels.') }}</p>
                    </div>
                @endunless
            @endforelse

            <p class="no-match" id="no-match">{{ $tr('Aucun terminal ne correspond à la recherche.') }}</p>

            @if ($mobileGroup)
                <details class="mobile-group">
                    <summary>
                        <svg class="chev" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
                        <span>{{ $tr($mobileGroup['label']) }} ({{ $mobileGroup['total'] }})</span>
                        <span class="mobile-hint">· {{ $tr('utilisés depuis l\'application') }}</span>
                    </summary>
                    <div class="tiles">
                        @foreach ($mobileGroup['devices'] as $device)
                            @include('virtual-devices.partials.terminal-tile', ['device' => $device, 'selectable' => false])
                        @endforeach
                    </div>
                </details>
            @endif

            @if ($webCount > 0)
                <div class="actions">
                    <button type="submit" class="btn btn-primary" id="submit-btn" {{ $availableCount === 0 && ! $hasCurrent ? 'disabled' : '' }}>
                        {{ $hasCurrent ? $tr('Continuer avec cet appareil') : $tr('Se connecter avec cet appareil') }}
                    </button>
                </div>
            @endif
        </form>

        @if ($currentSession)
            <form method="POST" action="{{ route('device.disconnect') }}">
                @csrf
                <button type="submit" class="btn btn-outline">{{ $tr('Déconnecter') }}</button>
            </form>
        @endif

        <div class="footer">
            <a href="{{ route('logout') }}" onclick="event.preventDefault(); document.getElementById('logout-form').submit();">{{ $tr('Se déconnecter') }}</a>
            <form id="logout-form" action="{{ route('logout') }}" method="POST" style="display:none;">@csrf</form>
        </div>
    </div>
</div>

<script>
    (function () {
        const form = document.getElementById('device-form');
        if (!form) return;
        const persistenceKey = form.dataset.devicePersistenceKey;
        const submit = document.getElementById('submit-btn');
        const selectable = () => [...form.querySelectorAll('.device-item:not(.is-disabled)')];

        const rememberDevice = function (deviceId) {
            if (!persistenceKey || !deviceId) return;
            try { localStorage.setItem(persistenceKey, String(deviceId)); } catch (e) {}
        };

        const selectDevice = function (el) {
            if (!el || el.classList.contains('is-disabled')) return;
            form.querySelectorAll('.device-item').forEach((i) => i.classList.remove('is-selected'));
            el.classList.add('is-selected');
            const input = el.querySelector('input');
            if (input) input.checked = true;
            if (submit) submit.disabled = false;
        };

        // A busy tile cannot be chosen; a tap shows who holds it (no hover on touch).
        form.querySelectorAll('.device-item.is-busy').forEach(function (el) {
            el.addEventListener('click', () => el.classList.toggle('show-detail'));
        });

        selectable().forEach(function (el) {
            const input = el.querySelector('input');
            el.addEventListener('click', () => selectDevice(el));
            el.addEventListener('dblclick', function () { selectDevice(el); form.requestSubmit(); });
            input?.addEventListener('change', () => selectDevice(el));
        });

        // The terminal this browser used last: pre-selected, and offered as a
        // one-click "resume" when it is free.
        let remembered = form.querySelector('.device-item[data-remembered]:not(.is-disabled)');
        if (!remembered && persistenceKey) {
            try {
                const id = localStorage.getItem(persistenceKey);
                remembered = id ? selectable().find((i) => i.dataset.deviceId === id) : null;
            } catch (e) {}
        }
        const alreadyChecked = form.querySelector('.device-item input:checked');
        if (remembered && !form.querySelector('.device-item[data-current]')) {
            if (!alreadyChecked) selectDevice(remembered);
            const resume = document.getElementById('resume');
            document.getElementById('resume-name').textContent = remembered.dataset.name;
            document.getElementById('resume-icon').innerHTML = remembered.querySelector('.device-icon svg')?.outerHTML || '';
            resume.classList.add('is-visible');
            document.getElementById('resume-btn').addEventListener('click', function () {
                selectDevice(remembered);
                form.requestSubmit();
            });
        }

        form.addEventListener('submit', function () {
            const selected = form.querySelector('input[name="virtual_device_id"]:checked');
            if (selected) rememberDevice(selected.value);
        });

        const search = document.getElementById('device-search');
        search?.addEventListener('input', function () {
            const q = search.value.trim().toLowerCase();
            let shown = 0;
            form.querySelectorAll('section.group').forEach(function (group) {
                let inGroup = 0;
                group.querySelectorAll('.device-item').forEach(function (tile) {
                    const match = q === '' || tile.dataset.search.includes(q);
                    tile.hidden = !match;
                    if (match) inGroup++;
                });
                group.hidden = inGroup === 0;
                shown += inGroup;
            });
            document.getElementById('no-match').classList.toggle('is-visible', shown === 0);
        });
    })();
</script>
</body>
</html>
