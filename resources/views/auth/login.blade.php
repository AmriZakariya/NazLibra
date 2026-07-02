@php
    $theme = array_merge([
        'primary' => '#3157D5',
        'accent' => '#0F9F8A',
        'success' => '#16A34A',
        'warning' => '#D97706',
        'danger' => '#E11D48',
        'info' => '#0284C7',
        'background' => '#F4F7FB',
        'surface_color' => '#FFFFFF',
        'surface_muted' => '#EEF3F8',
        'text' => '#101828',
        'muted' => '#64748B',
        'border' => '#D7DEE9',
        'font_scale' => '1',
        'radius' => '12',
    ], $tenant?->settings['theme'] ?? []);
    $locale = \App\Support\Locale::current($tenant);
    $direction = \App\Support\Locale::dir($locale);
    $tr = fn (string $text): string => \App\Support\Locale::t($text, $locale);
    $productName = config('app.name', 'NAZ POS');
    $tenantName = $tenant?->name ?? $tr('Votre commerce');
    $appVersion = config('app.version', '1.0.0-beta.5');
    $releaseLabel = app()->environment('production') ? $tr('Production') : \Illuminate\Support\Str::headline(app()->environment());
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $direction }}" data-locale="{{ $locale }}" style="--brand-primary: {{ $theme['primary'] }}; --brand-accent: {{ $theme['accent'] }}; --brand-success: {{ $theme['success'] }}; --app-bg: {{ $theme['background'] }}; --surface: {{ $theme['surface_color'] }}; --surface-muted: {{ $theme['surface_muted'] }}; --text-main: {{ $theme['text'] }}; --text-muted: {{ $theme['muted'] }}; --border-soft: {{ $theme['border'] }}; --font-scale: {{ $theme['font_scale'] }}; --brand-radius: {{ $theme['radius'] }}px;">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $tr('Connexion') }} · {{ $productName }}</title>
        <link rel="icon" type="image/png" sizes="32x32" href="{{ route('app.icon', 32) }}">
        <link rel="shortcut icon" href="{{ route('app.icon', 32) }}" type="image/x-icon">
        <script>
            window.libraireProLocale = @json($locale);
            window.libraireProTranslations = @json($locale === 'ar' ? \App\Support\Locale::arabic() : []);
        </script>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <style>
            /* ── Page background ──────────────────────────────────────── */
            body {
                background:
                    radial-gradient(ellipse at top left, color-mix(in srgb, var(--brand-primary) 14%, transparent), transparent 38rem),
                    radial-gradient(ellipse at bottom right, color-mix(in srgb, var(--brand-accent) 12%, transparent), transparent 32rem),
                    linear-gradient(155deg, #f0f5ff 0%, #eaf4f0 100%);
            }

            /* ── Two-column card ──────────────────────────────────────── */
            .login-card {
                display: grid;
                grid-template-columns: 1.1fr 0.9fr;
                min-height: min(720px, calc(100vh - 3rem));
                border-radius: 28px;
                overflow: hidden;
                border: 1px solid rgba(255,255,255,0.7);
                box-shadow: 0 40px 110px rgba(15,23,42,0.16), 0 0 0 1px rgba(255,255,255,0.55) inset;
                background: #fff;
            }
            @media (max-width: 860px) {
                .login-card { grid-template-columns: 1fr; }
                .login-left  { display: none; }
            }

            /* ── Left panel ───────────────────────────────────────────── */
            .login-left {
                position: relative;
                overflow: hidden;
                display: flex;
                flex-direction: column;
                justify-content: space-between;
                padding: 36px 40px;
                background:
                    radial-gradient(circle at 18% 18%, color-mix(in srgb, var(--brand-accent) 30%, transparent), transparent 48%),
                    radial-gradient(circle at 82% 82%, color-mix(in srgb, var(--brand-primary) 22%, transparent), transparent 44%),
                    linear-gradient(145deg, color-mix(in srgb, var(--brand-primary) 92%, #020617) 0%, #0d1526 52%, #040b18 100%);
                color: #fff;
            }
            /* subtle grid texture */
            .login-left::before {
                content: '';
                position: absolute;
                inset: 0;
                background-image:
                    linear-gradient(rgba(255,255,255,0.035) 1px, transparent 1px),
                    linear-gradient(90deg, rgba(255,255,255,0.035) 1px, transparent 1px);
                background-size: 44px 44px;
                pointer-events: none;
            }
            /* glowing orb accent */
            .login-left::after {
                content: '';
                position: absolute;
                bottom: -80px;
                right: -80px;
                width: 320px;
                height: 320px;
                border-radius: 50%;
                background: radial-gradient(circle, color-mix(in srgb, var(--brand-accent) 25%, transparent), transparent 70%);
                pointer-events: none;
            }

            /* ── Brand header ─────────────────────────────────────────── */
            .login-brand {
                position: relative;
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
            }
            .login-logomark {
                width: 46px;
                height: 46px;
                border-radius: 14px;
                background: #fff;
                display: flex;
                align-items: center;
                justify-content: center;
                box-shadow: 0 8px 24px rgba(0,0,0,0.20);
                flex-shrink: 0;
            }
            .login-wordmark {
                font-size: 21px;
                font-weight: 800;
                letter-spacing: -0.03em;
                line-height: 1;
            }
            .login-tenant {
                font-size: 12px;
                font-weight: 500;
                color: rgba(255,255,255,0.5);
                margin-top: 3px;
            }
            .login-version-chip {
                flex-shrink: 0;
                border-radius: 999px;
                border: 1px solid rgba(255,255,255,0.14);
                background: rgba(255,255,255,0.07);
                padding: 7px 12px;
                font-size: 11px;
                font-weight: 700;
                color: rgba(255,255,255,0.65);
            }

            /* ── Hero text ────────────────────────────────────────────── */
            .login-hero {
                position: relative;
                max-width: 380px;
            }
            .login-eyebrow {
                font-size: 10.5px;
                font-weight: 800;
                letter-spacing: 0.18em;
                text-transform: uppercase;
                color: rgba(255,255,255,0.42);
                margin-bottom: 14px;
            }
            .login-headline {
                font-size: clamp(1.9rem, 3vw, 2.55rem);
                font-weight: 800;
                line-height: 1.1;
                letter-spacing: -0.025em;
                color: #fff;
                margin: 0 0 18px;
            }
            .login-subline {
                font-size: 14px;
                line-height: 1.7;
                color: rgba(255,255,255,0.58);
                margin: 0;
            }

            /* ── Feature cards ────────────────────────────────────────── */
            .login-features {
                position: relative;
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 10px;
            }
            .login-feature-card {
                border: 1px solid rgba(255,255,255,0.11);
                border-radius: 18px;
                background: rgba(255,255,255,0.065);
                backdrop-filter: blur(10px);
                padding: 14px 16px;
                transition: background 0.2s;
            }
            .login-feature-card:hover { background: rgba(255,255,255,0.10); }
            .login-feature-wide {
                grid-column: 1 / -1;
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
                padding: 14px 18px;
            }
            .login-feature-icon {
                width: 32px;
                height: 32px;
                border-radius: 9px;
                background: rgba(255,255,255,0.10);
                display: flex;
                align-items: center;
                justify-content: center;
                margin-bottom: 10px;
            }
            .login-feature-eyebrow {
                font-size: 9.5px;
                font-weight: 700;
                letter-spacing: 0.14em;
                text-transform: uppercase;
                color: rgba(255,255,255,0.35);
            }
            .login-feature-label {
                display: block;
                font-size: 14px;
                font-weight: 700;
                color: #fff;
                margin-top: 4px;
            }
            .login-currency-pill {
                flex-shrink: 0;
                border-radius: 999px;
                background: rgba(255,255,255,0.12);
                border: 1px solid rgba(255,255,255,0.18);
                padding: 6px 14px;
                font-size: 11px;
                font-weight: 800;
                letter-spacing: 0.04em;
            }

            /* ── Right panel (form) ───────────────────────────────────── */
            .login-right {
                display: flex;
                align-items: center;
                justify-content: center;
                background: #fff;
                padding: 36px 40px;
            }
            .login-right-inner { width: 100%; max-width: 360px; }

            /* ── Form elements ────────────────────────────────────────── */
            .login-form-input {
                height: 48px;
                width: 100%;
                border-radius: 12px;
                border: 1.5px solid var(--border-soft);
                background: #f8fafc;
                padding: 0 14px;
                font-size: 14px;
                color: #101828;
                outline: none;
                transition: border-color 0.18s, box-shadow 0.18s, background 0.18s;
            }
            .login-form-input:focus {
                border-color: var(--brand-primary);
                box-shadow: 0 0 0 3px color-mix(in srgb, var(--brand-primary) 13%, transparent);
                background: #fff;
            }
            .login-primary-button {
                background: linear-gradient(135deg, var(--brand-primary), color-mix(in srgb, var(--brand-primary) 74%, var(--brand-accent)));
                box-shadow: 0 12px 28px color-mix(in srgb, var(--brand-primary) 28%, transparent);
                transition: transform 0.18s, box-shadow 0.18s;
            }
            .login-primary-button:hover {
                transform: translateY(-1px);
                box-shadow: 0 18px 36px color-mix(in srgb, var(--brand-primary) 34%, transparent);
            }
            .login-primary-button:active { transform: scale(0.98); }
        </style>
    </head>
    <body class="min-h-screen bg-[var(--app-bg)] text-slate-950 antialiased">
        <main class="flex min-h-screen items-center justify-center px-4 py-6 sm:px-6 lg:px-8">
            <div class="login-card w-full max-w-5xl">

                {{-- ══════════════════════════════════════════════════════
                     LEFT — Branding & info
                ══════════════════════════════════════════════════════ --}}
                <div class="login-left">
                    {{-- Brand header --}}
                    <div class="login-brand">
                        <div class="flex items-center gap-3">
                            <div class="login-logomark">
                                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <defs>
                                        <linearGradient id="lg" x1="0" y1="0" x2="24" y2="24" gradientUnits="userSpaceOnUse">
                                            <stop stop-color="{{ $theme['primary'] }}"/>
                                            <stop offset="1" stop-color="{{ $theme['accent'] }}"/>
                                        </linearGradient>
                                    </defs>
                                    <rect width="24" height="24" rx="6" fill="url(#lg)"/>
                                    <path d="M5 17V7l6.5 9V7" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    <path d="M14.5 12h5" stroke="white" stroke-width="2" stroke-linecap="round"/>
                                    <circle cx="17" cy="8.5" r="1.5" fill="white"/>
                                    <circle cx="17" cy="15.5" r="1.5" fill="white"/>
                                </svg>
                            </div>
                            <div>
                                <p class="login-wordmark">{{ $productName }}</p>
                                <p class="login-tenant">{{ $tenantName }}</p>
                            </div>
                        </div>
                        <span class="login-version-chip">v{{ $appVersion }}</span>
                    </div>

                    {{-- Hero --}}
                    <div class="login-hero">
                        <p class="login-eyebrow">{{ $tr('Caisse · Stock · Clients · Rapports') }}</p>
                        <h1 class="login-headline">{{ $tr('Une caisse moderne pour vendre plus vite.') }}</h1>
                        <p class="login-subline">{{ $tr('Pilotez vos ventes, stocks et clients depuis une plateforme POS claire, rapide et multi-activités.') }}</p>
                    </div>

                    {{-- Feature cards --}}
                    <div class="login-features">
                        @foreach ([
                            ['icon' => 'M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.3 2.3c-.6.6-.2 1.7.7 1.7H17m0 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm-8 2a2 2 0 1 0 0 4 2 2 0 0 0 0-4z', 'label' => $tr('Caisse')],
                            ['icon' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4', 'label' => $tr('Stock')],
                            ['icon' => 'M17 20h5v-2a3 3 0 0 0-5.36-1.86M17 20H7m10 0v-2c0-.66-.13-1.28-.36-1.86M7 20H2v-2a3 3 0 0 1 5.36-1.86M7 20v-2c0-.66.13-1.28.36-1.86m0 0a5 5 0 0 1 9.28 0M15 7a3 3 0 1 1-6 0 3 3 0 0 1 6 0z', 'label' => $tr('Clients')],
                        ] as $f)
                            <div class="login-feature-card">
                                <div class="login-feature-icon">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $f['icon'] }}"/></svg>
                                </div>
                                <span class="login-feature-eyebrow">{{ $tr('Module') }}</span>
                                <strong class="login-feature-label">{{ $f['label'] }}</strong>
                            </div>
                        @endforeach

                        <div class="login-feature-card login-feature-wide">
                            <div>
                                <span class="login-feature-eyebrow">{{ $tr('Accès rapide') }}</span>
                                <strong class="login-feature-label">{{ $tr('Caisse, catalogue et rapports') }}</strong>
                            </div>
                            <span class="login-currency-pill">MAD · DH</span>
                        </div>
                    </div>
                </div>

                {{-- ══════════════════════════════════════════════════════
                     RIGHT — Login form
                ══════════════════════════════════════════════════════ --}}
                <div class="login-right">
                    <div class="login-right-inner">

                        {{-- Heading --}}
                        <div class="mb-7">
                            <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-[11px] font-bold uppercase tracking-wider" style="background: color-mix(in srgb, var(--brand-primary) 10%, transparent); color: var(--brand-primary)">
                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                                {{ $tr('Connexion sécurisée') }}
                            </span>
                            <h2 class="mt-3 text-[1.85rem] font-bold tracking-[-0.025em] text-slate-950">{{ $tr('Bienvenue') }}</h2>
                            <p class="mt-1.5 text-sm leading-6 text-slate-500">{{ $tr('Utilisez votre compte équipe pour accéder à l\'espace de gestion.') }}</p>
                        </div>

                        {{-- Alerts --}}
                        @if (session('status'))
                            <div class="mb-5 flex items-center gap-2.5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                                {{ session('status') }}
                            </div>
                        @endif
                        @if ($errors->any())
                            <div class="mb-5 flex items-center gap-2.5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-700">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="shrink-0"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                                {{ $errors->first() }}
                            </div>
                        @endif

                        {{-- Form --}}
                        <form action="{{ route('login.store') }}" method="POST" class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                            @csrf

                            <label class="block space-y-2">
                                <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Email</span>
                                <input
                                    name="email"
                                    value="{{ old('email') }}"
                                    type="email"
                                    required
                                    autofocus
                                    autocomplete="email"
                                    autocapitalize="none"
                                    spellcheck="false"
                                    class="login-form-input"
                                    placeholder="vous@entreprise.ma"
                                >
                            </label>

                            <label class="block space-y-2">
                                <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400">{{ $tr('Mot de passe') }}</span>
                                <div class="relative">
                                    <input
                                        name="password"
                                        type="password"
                                        required
                                        autocomplete="current-password"
                                        class="login-form-input pr-11"
                                        placeholder="{{ $tr('Minimum 8 caractères') }}"
                                        id="login-password"
                                    >
                                    <button
                                        type="button"
                                        class="absolute right-3 top-1/2 -translate-y-1/2 rounded-lg p-1 text-slate-400 hover:text-slate-600 transition"
                                        onclick="const p=document.getElementById('login-password');p.type=p.type==='password'?'text':'password';this.querySelector('svg:first-child').classList.toggle('hidden');this.querySelector('svg:last-child').classList.toggle('hidden')"
                                        tabindex="-1"
                                        aria-label="{{ $tr('Afficher/masquer le mot de passe') }}"
                                    >
                                        <svg class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                        <svg class="size-[18px] hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                    </button>
                                </div>
                            </label>

                            <div class="flex items-center justify-between gap-3">
                                <label class="flex cursor-pointer items-center gap-2 text-sm font-medium text-slate-600">
                                    <input name="remember" value="1" type="checkbox" class="size-4 rounded accent-[var(--brand-primary)]">
                                    {{ $tr('Se souvenir de moi') }}
                                </label>
                                <a href="{{ route('password.request') }}" class="text-[12px] font-semibold transition hover:brightness-110" style="color: var(--brand-primary)">{{ $tr('Mot de passe oublié ?') }}</a>
                            </div>

                            <button class="login-primary-button flex h-12 w-full items-center justify-center gap-2 rounded-xl px-4 text-sm font-bold text-white">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
                                {{ $tr('Se connecter') }}
                            </button>
                        </form>

                        {{-- Demo account --}}
                        @if (app()->environment(['local', 'testing']) && filled($demoLoginEmail ?? null))
                            <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm">
                                <p class="font-semibold text-slate-700">{{ $tr('Compte de démonstration') }}</p>
                                <p class="mt-1 font-mono text-xs text-slate-500">{{ $demoLoginEmail }} · <span class="text-slate-400">password</span></p>
                            </div>
                        @endif

                        {{-- Footer --}}
                        <div class="mt-5 flex flex-wrap items-center justify-between gap-2 text-[11px] text-slate-400">
                            <span class="font-semibold text-slate-500">{{ $productName }} {{ $appVersion }}</span>
                            <span>{{ $releaseLabel }} · {{ \App\Support\TenantClock::format(now(), $tenant, 'd/m/Y') }}</span>
                        </div>
                    </div>
                </div>

            </div>
        </main>
    </body>
</html>
