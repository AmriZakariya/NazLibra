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
    $productInitials = collect(preg_split('/\s+/', trim($productName)))
        ->filter()
        ->take(2)
        ->map(fn ($word) => mb_strtoupper(mb_substr($word, 0, 1)))
        ->join('') ?: 'NP';
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
            body {
                background:
                    radial-gradient(circle at top left, color-mix(in srgb, var(--brand-primary) 18%, transparent), transparent 34rem),
                    radial-gradient(circle at bottom right, color-mix(in srgb, var(--brand-accent) 18%, transparent), transparent 32rem),
                    linear-gradient(135deg, #f8fbff 0%, #eef4fb 48%, #f6faf8 100%);
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
            .login-primary-button {
                background: linear-gradient(135deg, var(--brand-primary), color-mix(in srgb, var(--brand-primary) 72%, var(--brand-accent)));
                box-shadow: 0 15px 30px color-mix(in srgb, var(--brand-primary) 28%, transparent);
            }
            .login-primary-button:hover {
                transform: translateY(-1px);
                box-shadow: 0 18px 38px color-mix(in srgb, var(--brand-primary) 34%, transparent);
            }
            .login-vertical-card {
                background:
                    linear-gradient(180deg, rgba(255,255,255,0.98), rgba(255,255,255,0.94)),
                    radial-gradient(circle at top, color-mix(in srgb, var(--brand-primary) 12%, transparent), transparent 20rem);
                box-shadow: 0 28px 90px rgba(15, 23, 42, 0.14);
            }
            .login-brand-mark {
                background: linear-gradient(135deg, var(--brand-primary), color-mix(in srgb, var(--brand-accent) 82%, var(--brand-primary)));
                box-shadow: 0 16px 36px color-mix(in srgb, var(--brand-primary) 26%, transparent);
            }
        </style>
    </head>
    <body class="min-h-screen bg-[var(--app-bg)] text-slate-950 antialiased">
        <main class="flex min-h-screen items-center justify-center px-4 py-8 sm:px-6">
            <section class="w-full max-w-[480px]">
                <div class="mb-6 text-center">
                    <div class="mx-auto flex size-16 items-center justify-center rounded-[22px] login-brand-mark text-xl font-black tracking-[-0.08em] text-white">
                        {{ $productInitials }}
                    </div>
                    <p class="mt-4 text-sm font-black uppercase tracking-[0.22em] text-[var(--brand-primary)]">{{ $productName }}</p>
                    <h1 class="mt-3 text-3xl font-black tracking-[-0.045em] text-slate-950 sm:text-4xl">
                        {{ $tr('Connectez votre équipe') }}
                    </h1>
                    <p class="mx-auto mt-3 max-w-sm text-sm leading-6 text-slate-500">
                        {{ $tr('Accédez à votre caisse, votre stock, vos clients et vos rapports depuis un espace POS simple et sécurisé.') }}
                    </p>
                </div>

                <section class="login-vertical-card rounded-[30px] border border-white/80 p-5 backdrop-blur sm:p-6">
                    <div class="mb-5 flex items-center justify-between gap-3 rounded-2xl border border-slate-200/80 bg-white/80 px-4 py-3">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-black text-slate-950">{{ $tenantName }}</p>
                            <p class="mt-0.5 text-xs font-semibold text-slate-500">{{ $tr('Plateforme POS') }}</p>
                        </div>
                        <span class="shrink-0 rounded-full bg-slate-950 px-3 py-1.5 text-[10px] font-black uppercase tracking-wider text-white">MAD · DH</span>
                    </div>

                    {{-- heading --}}
                    <div class="mb-6 text-center">
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-[color-mix(in_srgb,var(--brand-primary)_10%,transparent)] px-3 py-1 text-[11px] font-bold uppercase tracking-wider text-[var(--brand-primary)]">
                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                                {{ $tr('Connexion sécurisée') }}
                            </span>
                        <h2 class="mt-3 text-2xl font-black tracking-[-0.04em] text-slate-950">{{ $tr('Connexion') }}</h2>
                        <p class="mt-1 text-sm leading-6 text-slate-500">{{ $tr('Utilisez votre compte équipe pour continuer.') }}</p>
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
                    <form action="{{ route('login.store') }}" method="POST" class="space-y-4">
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
                                <a href="{{ route('password.request') }}" class="text-[12px] font-semibold text-[var(--brand-primary)] hover:brightness-110 transition">{{ $tr('Mot de passe oublié ?') }}</a>
                            </div>

                            <button class="login-primary-button flex h-12 w-full items-center justify-center gap-2 rounded-xl px-4 text-sm font-bold text-white transition active:scale-[0.98]">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
                                {{ $tr('Se connecter') }}
                            </button>
                        </form>

                    <div class="mt-5 grid grid-cols-3 gap-2">
                        @foreach ([$tr('Caisse'), $tr('Stock'), $tr('Rapports')] as $feature)
                            <div class="rounded-2xl border border-slate-200/80 bg-slate-50 px-2 py-3 text-center text-[11px] font-black uppercase tracking-wider text-slate-500">
                                {{ $feature }}
                            </div>
                        @endforeach
                    </div>
                </section>

                        {{-- demo account --}}
                        @if (app()->environment(['local', 'testing']) && filled($demoLoginEmail ?? null))
                    <div class="mt-4 rounded-2xl border border-slate-200 bg-white/80 p-4 text-sm text-slate-500 shadow-sm">
                                <p class="font-semibold text-slate-700">{{ $tr('Compte de démonstration') }}</p>
                                <p class="mt-1 font-mono text-xs">{{ $demoLoginEmail }} · <span class="text-slate-400">password</span></p>
                            </div>
                        @endif

                        {{-- footer --}}
                <div class="mt-5 flex flex-wrap items-center justify-center gap-x-3 gap-y-1 text-[11px] text-slate-400">
                    <span class="font-semibold text-slate-500">{{ $productName }} {{ $appVersion }}</span>
                            <span>{{ $releaseLabel }} · {{ now()->format('d/m/Y') }}</span>
                        </div>
            </section>
        </main>
    </body>
</html>
