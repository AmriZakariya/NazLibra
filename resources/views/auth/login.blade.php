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
            .login-grid-bg {
                background-image:
                    linear-gradient(rgba(255,255,255,0.04) 1px, transparent 1px),
                    linear-gradient(90deg, rgba(255,255,255,0.04) 1px, transparent 1px);
                background-size: 40px 40px;
            }
            .login-glow {
                background: radial-gradient(circle at 20% 20%, color-mix(in srgb, var(--brand-accent) 32%, transparent), transparent 55%),
                            radial-gradient(circle at 80% 80%, color-mix(in srgb, var(--brand-primary) 28%, transparent), transparent 50%),
                            linear-gradient(135deg, color-mix(in srgb, var(--brand-primary) 90%, #020617) 0%, #0f172a 55%, #020617 100%);
            }
            .naz-wordmark {
                font-size: 22px;
                font-weight: 800;
                letter-spacing: -0.03em;
                line-height: 1;
            }
            .naz-wordmark span {
                opacity: 0.55;
                font-weight: 600;
                font-size: 13px;
                letter-spacing: 0.01em;
            }
            .feature-card {
                background: rgba(255,255,255,0.07);
                border: 1px solid rgba(255,255,255,0.12);
                border-radius: 18px;
                padding: 16px 18px;
                backdrop-filter: blur(8px);
                transition: background 0.2s;
            }
            .feature-card:hover {
                background: rgba(255,255,255,0.11);
            }
            .feature-icon {
                width: 34px;
                height: 34px;
                border-radius: 10px;
                background: rgba(255,255,255,0.12);
                display: flex;
                align-items: center;
                justify-content: center;
                margin-bottom: 10px;
            }
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
                box-shadow: 0 0 0 3px color-mix(in srgb, var(--brand-primary) 14%, transparent);
                background: #fff;
            }
        </style>
    </head>
    <body class="min-h-screen bg-[var(--app-bg)] text-slate-950 antialiased">
        <main class="min-h-screen px-4 py-5 sm:px-6 lg:px-8 flex items-center justify-center">
            <section class="w-full max-w-6xl overflow-hidden rounded-[28px] border border-slate-200/80 bg-white shadow-[0_32px_100px_rgba(15,23,42,0.13)] lg:grid lg:grid-cols-[1.08fr_0.92fr]" style="min-height: min(760px, calc(100vh - 2.5rem))">

                {{-- ── Left panel ─────────────────────────────────────────────── --}}
                <div class="login-glow login-grid-bg relative flex min-h-[420px] flex-col justify-between overflow-hidden p-7 text-white sm:p-9 lg:p-11">

                    {{-- header --}}
                    <div class="relative flex items-center justify-between gap-4">
                        <div class="flex items-center gap-3.5">
                            {{-- Logo mark --}}
                            <div class="relative flex size-11 items-center justify-center rounded-[14px] bg-white shadow-md">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <rect width="24" height="24" rx="6" fill="url(#np-grad)"/>
                                    <path d="M5 17V7l7 10V7" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    <path d="M14 12h5" stroke="white" stroke-width="2" stroke-linecap="round"/>
                                    <circle cx="16.5" cy="8.5" r="1.5" fill="white"/>
                                    <circle cx="16.5" cy="15.5" r="1.5" fill="white"/>
                                    <defs>
                                        <linearGradient id="np-grad" x1="0" y1="0" x2="24" y2="24" gradientUnits="userSpaceOnUse">
                                            <stop stop-color="{{ $theme['primary'] }}"/>
                                            <stop offset="1" stop-color="{{ $theme['accent'] }}"/>
                                        </linearGradient>
                                    </defs>
                                </svg>
                            </div>
                            <div>
                                <p class="naz-wordmark text-white">{{ $productName }}</p>
                                <p class="mt-0.5 text-xs font-medium text-white/50 tracking-wide">{{ $tenantName }}</p>
                            </div>
                        </div>
                        <span class="login-version-badge shrink-0">{{ $tr('Version') }} {{ $appVersion }}</span>
                    </div>

                    {{-- hero --}}
                    <div class="relative mt-10 max-w-lg">
                        <p class="text-[11px] font-bold uppercase tracking-[0.2em] text-white/45">{{ $tr('Caisse · Stock · Clients · Rapports') }}</p>
                        <h1 class="mt-4 text-[2.4rem] font-bold leading-[1.12] tracking-[-0.02em] text-white sm:text-5xl">
                            {{ $tr('Une caisse moderne pour vendre plus vite.') }}
                        </h1>
                        <p class="mt-5 text-[15px] leading-7 text-white/60">
                            {{ $tr('Pilotez vos ventes, terminaux, stocks, clients et paiements depuis une plateforme POS claire, rapide et prête pour plusieurs activités.') }}
                        </p>
                    </div>

                    {{-- feature cards --}}
                    <div class="relative mt-10 grid gap-2.5 sm:grid-cols-3">
                        @foreach ([
                            ['icon' => 'M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm-8 2a2 2 0 1 0 0 4 2 2 0 0 0 0-4z', 'label' => $tr('Caisse')],
                            ['icon' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4', 'label' => $tr('Stock')],
                            ['icon' => 'M17 20h5v-2a3 3 0 0 0-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 0 1 5.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 0 1 9.288 0M15 7a3 3 0 1 1-6 0 3 3 0 0 1 6 0z', 'label' => $tr('Clients')],
                        ] as $card)
                            <div class="feature-card">
                                <div class="feature-icon">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="{{ $card['icon'] }}"/>
                                    </svg>
                                </div>
                                <p class="text-[10px] font-bold uppercase tracking-widest text-white/40">{{ $tr('Module') }}</p>
                                <strong class="mt-1 block text-[15px] font-semibold">{{ $card['label'] }}</strong>
                            </div>
                        @endforeach

                        <div class="feature-card sm:col-span-3">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <p class="text-[10px] font-bold uppercase tracking-widest text-white/40">{{ $tr('Accès rapide') }}</p>
                                    <strong class="mt-1 block text-[15px] font-semibold">{{ $tr('Caisse, catalogue et rapports') }}</strong>
                                </div>
                                <span class="shrink-0 rounded-full bg-white/15 px-3 py-1.5 text-[11px] font-black tracking-wide text-white ring-1 ring-white/20">MAD · DH</span>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- ── Right panel (login form) ──────────────────────────────── --}}
                <div class="flex items-center justify-center bg-[var(--surface)] p-6 sm:p-8 lg:p-10">
                    <section class="w-full max-w-[400px]">

                        {{-- heading --}}
                        <div class="mb-7">
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-[color-mix(in_srgb,var(--brand-primary)_10%,transparent)] px-3 py-1 text-[11px] font-bold uppercase tracking-wider text-[var(--brand-primary)]">
                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                                {{ $tr('Connexion sécurisée') }}
                            </span>
                            <h2 class="mt-3 text-[1.9rem] font-bold tracking-[-0.02em] text-slate-950">{{ $tr('Bienvenue') }}</h2>
                            <p class="mt-1.5 text-sm leading-6 text-slate-500">{{ $tr('Utilisez votre compte équipe pour accéder à l'espace de gestion.') }}</p>
                        </div>

                        {{-- alerts --}}
                        @if (session('status'))
                            <div class="mb-5 flex items-center gap-2.5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="shrink-0"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                                {{ session('status') }}
                            </div>
                        @endif
                        @if ($errors->any())
                            <div class="mb-5 flex items-center gap-2.5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-700">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="shrink-0"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                                {{ $errors->first() }}
                            </div>
                        @endif

                        {{-- form --}}
                        <form action="{{ route('login.store') }}" method="POST" class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-[0_2px_16px_rgba(15,23,42,0.06)]">
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
                                        <svg class="size-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                        <svg class="size-4.5 hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                    </button>
                                </div>
                            </label>

                            <div class="flex items-center justify-between gap-3">
                                <label class="flex cursor-pointer items-center gap-2 text-sm font-medium text-slate-600">
                                    <input name="remember" value="1" type="checkbox" class="size-4 rounded accent-[var(--brand-primary)]">
                                    {{ $tr('Se souvenir de moi') }}
                                </label>
                                <a href="{{ route('password.request') }}" class="text-[12px] font-semibold text-[var(--brand-primary)] hover:brightness-110 transition">{{ $tr('Mot de passe oublié ?') }}</a>
                            </div>

                            <button class="flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-[var(--brand-primary)] px-4 text-sm font-semibold text-white shadow-[0_4px_14px_color-mix(in_srgb,var(--brand-primary)_30%,transparent)] transition hover:brightness-110 active:scale-[0.98]">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
                                {{ $tr('Se connecter') }}
                            </button>
                        </form>

                        {{-- demo account --}}
                        @if (app()->environment(['local', 'testing']) && filled($demoLoginEmail ?? null))
                            <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-500">
                                <p class="font-semibold text-slate-700">{{ $tr('Compte de démonstration') }}</p>
                                <p class="mt-1 font-mono text-xs">{{ $demoLoginEmail }} · <span class="text-slate-400">password</span></p>
                            </div>
                        @endif

                        {{-- footer --}}
                        <div class="mt-5 flex flex-wrap items-center justify-between gap-2 text-[11px] text-slate-400">
                            <span class="font-semibold text-slate-500">{{ $productName }} {{ $appVersion }}</span>
                            <span>{{ $releaseLabel }} · {{ now()->format('d/m/Y') }}</span>
                        </div>
                    </section>
                </div>
            </section>
        </main>
    </body>
</html>
