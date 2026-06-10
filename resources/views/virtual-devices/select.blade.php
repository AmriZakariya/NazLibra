@php
    $locale = \App\Support\Locale::current($tenant);
    $tr = fn (string $text): string => \App\Support\Locale::t($text, $locale);
    $availableCount = $devices->where('is_in_use', false)->count();
    $hasCurrent = $currentSession && $currentSession->virtualDevice?->is_active;
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ \App\Support\Locale::dir($locale) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="device-heartbeat" content="{{ route('device.heartbeat') }}">
    <title>{{ $tr('Sélectionner un appareil') }} · LibrairePro</title>
    <style>
        *, ::before, ::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #f1f5f9;
            padding: 1.5rem;
        }
        .card {
            width: 100%;
            max-width: 540px;
            background: rgba(255,255,255,0.04);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 1.5rem;
            padding: 2.5rem;
            box-shadow: 0 25px 60px rgba(0,0,0,0.3);
        }
        .icon-wrap {
            display: grid;
            place-items: center;
            width: 56px;
            height: 56px;
            border-radius: 1rem;
            background: rgba(99,102,241,0.15);
            color: #818cf8;
            margin: 0 auto 1.25rem;
        }
        .title { text-align: center; font-size: 1.35rem; font-weight: 700; margin-bottom: 0.35rem; }
        .subtitle { text-align: center; font-size: 0.9rem; color: #94a3b8; margin-bottom: 2rem; line-height: 1.5; }
        .device-list { display: flex; flex-direction: column; gap: 0.625rem; margin-bottom: 1.25rem; max-height: 340px; overflow-y: auto; }
        .device-item {
            display: flex; align-items: center; gap: 1rem;
            padding: 1rem 1.25rem;
            border-radius: 1rem;
            border: 1.5px solid rgba(255,255,255,0.08);
            cursor: pointer;
            transition: all 0.15s;
            background: transparent;
            color: inherit;
            width: 100%;
            text-align: left;
            font-size: 0.95rem;
        }
        .device-item:hover:not(.is-disabled) { border-color: #818cf8; background: rgba(99,102,241,0.06); }
        .device-item.is-disabled { opacity: 0.45; cursor: not-allowed; }
        .device-item input { display: none; }
        .device-item.is-selected { border-color: #818cf8; background: rgba(99,102,241,0.1); }
        .device-icon {
            display: grid; place-items: center;
            width: 42px; height: 42px; border-radius: 0.75rem;
            background: rgba(255,255,255,0.06);
            font-size: 1.2rem; flex-shrink: 0;
        }
        .device-type { font-size: 0.75rem; color: #818cf8; text-transform: uppercase; font-weight: 600; letter-spacing: 0.05em; }
        .device-name { font-weight: 600; }
        .device-badge {
            margin-left: auto; flex-shrink: 0;
            font-size: 0.7rem; font-weight: 700; text-transform: uppercase;
            padding: 0.25rem 0.6rem; border-radius: 9999px;
        }
        .badge-in-use { background: rgba(251,191,36,0.15); color: #fbbf24; }
        .badge-disabled { background: rgba(239,68,68,0.15); color: #f87171; }
        .badge-current { background: rgba(16,185,129,0.15); color: #34d399; }
        .btn {
            display: flex; align-items: center; justify-content: center; gap: 0.5rem;
            width: 100%; padding: 0.875rem 1.5rem;
            border-radius: 1rem; border: none;
            font-size: 0.95rem; font-weight: 600; cursor: pointer;
            transition: all 0.15s;
        }
        .btn-primary { background: #6366f1; color: white; }
        .btn-primary:hover { background: #4f46e5; }
        .btn-primary:disabled { opacity: 0.4; cursor: not-allowed; }
        .btn-outline { background: transparent; border: 1.5px solid rgba(255,255,255,0.15); color: #94a3b8; margin-top: 0.75rem; }
        .btn-outline:hover { border-color: #ef4444; color: #f87171; }
        .error { background: rgba(239,68,68,0.12); color: #f87171; padding: 0.75rem 1rem; border-radius: 0.75rem; font-size: 0.85rem; font-weight: 500; margin-bottom: 1rem; text-align: center; }
        .empty { text-align: center; padding: 3rem 1rem; color: #64748b; }
        .empty-icon { font-size: 2.5rem; margin-bottom: 1rem; opacity: 0.4; }
        .footer { text-align: center; margin-top: 1rem; }
        .footer a { color: #64748b; font-size: 0.8rem; text-decoration: none; }
        .footer a:hover { color: #f87171; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon-wrap">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>
            </svg>
        </div>
        <h1 class="title">{{ $tr('Sélectionner un appareil') }}</h1>
        <p class="subtitle">{{ $tr('Choisissez l\'appareil que vous utilisez actuellement. Chaque appareil peut être utilisé par une seule personne à la fois.') }}</p>

        @error('virtual_device_id')
            <div class="error">{{ $message }}</div>
        @enderror

        @if ($currentSession && ! $currentSession->virtualDevice?->is_active)
            <div class="error">{{ $tr('Votre appareil actuel a été désactivé. Veuillez en choisir un autre.') }}</div>
        @endif

        <form method="POST" action="{{ route('device.connect') }}" id="device-form">
            @csrf
            <div class="device-list">
                @forelse ($devices as $device)
                    @php
                        $isCurrent = $currentSession && $currentSession->virtual_device_id === $device->id;
                        $isDisabled = $device->is_in_use && ! $isCurrent;
                    @endphp
                    <label class="device-item {{ $isDisabled ? 'is-disabled' : '' }} {{ $isCurrent ? 'is-selected' : '' }}">
                        <input type="radio" name="virtual_device_id" value="{{ $device->id }}" {{ $isDisabled ? 'disabled' : '' }} {{ $isCurrent ? 'checked' : '' }}>
                        <span class="device-icon">
                            @if ($device->type === 'mobile') 📱
                            @elseif ($device->type === 'tablet') 📋
                            @else 💻
                            @endif
                        </span>
                        <span style="flex:1;min-width:0;">
                            <span class="device-type">{{ $tr(ucfirst($device->type)) }}</span>
                            <span class="device-name" style="display:block;">{{ $device->name }}</span>
                            @if ($device->description)
                                <span style="display:block;font-size:0.75rem;color:#64748b;margin-top:0.15rem;">{{ \Illuminate\Support\Str::limit($device->description, 60) }}</span>
                            @endif
                        </span>
                        @if ($isCurrent)
                            <span class="device-badge badge-current">{{ $tr('Connecté') }}</span>
                        @elseif ($device->is_in_use)
                            <span class="device-badge badge-in-use">{{ $tr('Occupé') }}</span>
                        @endif
                    </label>
                @empty
                    <div class="empty">
                        <div class="empty-icon">🖥</div>
                        <p style="font-weight:600;margin-bottom:0.25rem;">{{ $tr('Aucun appareil disponible') }}</p>
                        <p style="font-size:0.85rem;">{{ $tr('Contactez le propriétaire pour configurer les appareils virtuels.') }}</p>
                    </div>
                @endforelse
            </div>

            @if ($devices->isNotEmpty())
                <button type="submit" class="btn btn-primary" id="submit-btn" {{ $availableCount === 0 && ! $hasCurrent ? 'disabled' : '' }}>
                    {{ $hasCurrent ? $tr('Continuer avec cet appareil') : $tr('Se connecter avec cet appareil') }}
                </button>
            @endif
        </form>

        @if ($currentSession)
            <form method="POST" action="{{ route('device.disconnect') }}" style="margin-top:0;">
                @csrf
                <button type="submit" class="btn btn-outline">{{ $tr('Déconnecter') }}</button>
            </form>
        @endif

        <div class="footer">
            <a href="{{ route('logout') }}" onclick="event.preventDefault(); document.getElementById('logout-form').submit();">{{ $tr('Se déconnecter') }}</a>
            <form id="logout-form" action="{{ route('logout') }}" method="POST" style="display:none;">@csrf</form>
        </div>
    </div>

    <script>
        document.querySelectorAll('.device-item:not(.is-disabled)').forEach(function(el) {
            el.addEventListener('click', function() {
                document.querySelectorAll('.device-item').forEach(function(i) { i.classList.remove('is-selected'); });
                el.classList.add('is-selected');
                el.querySelector('input').checked = true;
                document.getElementById('submit-btn').disabled = false;
            });
        });
    </script>
</body>
</html>
