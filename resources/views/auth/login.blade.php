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
    $tenantName = $tenant?->name ?? 'LibrairePro';
    $businessMode = \App\Support\BusinessMode::current($tenant);
    $tenantInitials = collect(preg_split('/\s+/', trim($tenantName)))
        ->filter()
        ->take(2)
        ->map(fn ($word) => mb_strtoupper(mb_substr($word, 0, 1)))
        ->join('') ?: 'LP';
    $locale = \App\Support\Locale::current($tenant);
    $direction = \App\Support\Locale::dir($locale);
    $tr = fn (string $text): string => \App\Support\Locale::t($text, $locale);
    $appVersion = config('app.version', '1.0.0-beta.2');
    $releaseLabel = app()->environment('production') ? $tr('Production') : \Illuminate\Support\Str::headline(app()->environment());
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $direction }}" data-locale="{{ $locale }}" style="--brand-primary: {{ $theme['primary'] }}; --brand-accent: {{ $theme['accent'] }}; --brand-success: {{ $theme['success'] }}; --app-bg: {{ $theme['background'] }}; --surface: {{ $theme['surface_color'] }}; --surface-muted: {{ $theme['surface_muted'] }}; --text-main: {{ $theme['text'] }}; --text-muted: {{ $theme['muted'] }}; --border-soft: {{ $theme['border'] }}; --font-scale: {{ $theme['font_scale'] }}; --brand-radius: {{ $theme['radius'] }}px;">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $tr('Connexion') }} · LibrairePro</title>
        <link rel="icon" type="image/png" sizes="32x32" href="{{ route('app.icon', 32) }}">
        <link rel="shortcut icon" href="{{ route('app.icon', 32) }}" type="image/x-icon">
        <script>
            window.libraireProLocale = @json($locale);
            window.libraireProTranslations = @json($locale === 'ar' ? \App\Support\Locale::arabic() : []);
        </script>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-[var(--app-bg)] text-slate-950 antialiased dark:bg-slate-950">
        <main class="min-h-screen px-4 py-5 sm:px-6 lg:px-8">
            <section class="mx-auto grid min-h-[calc(100vh-2.5rem)] w-full max-w-6xl overflow-hidden rounded-[28px] border border-slate-200 bg-white shadow-[0_24px_80px_rgba(15,23,42,0.12)] dark:border-white/10 dark:bg-slate-950 lg:grid-cols-[1.04fr_0.96fr]">
                <div class="relative flex min-h-[420px] flex-col justify-between overflow-hidden bg-slate-950 p-6 text-white sm:p-8 lg:p-10">
                    <div class="absolute inset-0 opacity-95" style="background: radial-gradient(circle at 18% 16%, color-mix(in srgb, var(--brand-accent) 34%, transparent) 0, transparent 28%), linear-gradient(135deg, color-mix(in srgb, var(--brand-primary) 88%, #020617) 0%, #111827 50%, #020617 100%);"></div>
                    <div class="absolute inset-x-8 bottom-8 top-auto h-40 rounded-[32px] border border-white/10 bg-white/[0.06] backdrop-blur"></div>

                    <div class="relative">
                        <div class="flex items-center justify-between gap-3">
                            <div class="flex min-w-0 items-center gap-3">
                            <span class="grid size-12 place-items-center rounded-2xl bg-white text-sm font-black text-slate-950 shadow-sm">{{ $tenantInitials }}</span>
                            <div>
                            <p class="text-sm font-semibold text-white/80">LibrairePro · {{ $businessMode['short_label'] }}</p>
                                <p class="text-lg font-semibold">{{ $tenantName }}</p>
                            </div>
                            </div>
                            <span class="login-version-badge">{{ $tr('Version') }} {{ $appVersion }}</span>
                        </div>

                        <div class="mt-12 max-w-xl">
                            <p class="text-sm font-semibold uppercase tracking-[0.16em] text-white/55">{{ $businessMode['subtitle'] }}</p>
                            <h1 class="mt-3 text-4xl font-semibold tracking-normal text-white sm:text-5xl">{{ $businessMode['hero_title'] }}</h1>
                            <p class="mt-5 text-base leading-7 text-white/72">{{ $businessMode['hero_text'] }}</p>
                        </div>
                    </div>

                    <div class="relative mt-10 grid gap-3 sm:grid-cols-3">
                        @foreach (array_slice($businessMode['keywords'], 0, 3) as $keyword)
                            <div class="rounded-2xl border border-white/14 bg-white/10 p-4 backdrop-blur">
                                <span class="text-xs font-semibold uppercase text-white/55">{{ $tr('Module') }}</span>
                                <strong class="mt-2 block text-lg">{{ $keyword }}</strong>
                            </div>
                        @endforeach
                        <div class="sm:col-span-3 rounded-2xl border border-white/14 bg-white/10 p-4 backdrop-blur">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <span class="text-xs font-semibold uppercase text-white/55">{{ $tr('Accès rapide') }}</span>
                                    <strong class="mt-1 block text-lg">{{ $businessMode['catalog_label'] }}</strong>
                                </div>
                                <span class="rounded-full bg-white px-3 py-1 text-xs font-black text-slate-950">MAD · DH</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-center bg-[var(--surface)] p-5 sm:p-8 lg:p-10">
                    <section class="w-full max-w-md">
                        <div class="mb-8">
                            <p class="text-sm font-semibold text-brand">{{ $tr('Connexion sécurisée') }}</p>
                            <h2 class="mt-2 text-3xl font-semibold tracking-normal text-slate-950 dark:text-white">{{ $tr('Bienvenue') }}</h2>
                            <p class="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-300">{{ $tr('Utilisez votre compte équipe pour accéder à l’espace de gestion.') }}</p>
                        </div>

                        @if (session('status'))
                            <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-100">{{ session('status') }}</div>
                        @endif
                        @if ($errors->any())
                            <div class="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-black-100">{{ $errors->first() }}</div>
                        @endif

                        <form action="{{ route('login.store') }}" method="POST" class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.04]">
                            @csrf
                            <label class="block space-y-1.5">
                                <span class="text-xs font-semibold uppercase text-slate-500">Email</span>
                                <input name="email" value="{{ old('email') }}" type="email" required autofocus autocomplete="email" class="h-12 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-950 outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900 dark:text-white" placeholder="vous@entreprise.ma">
                            </label>
                            <label class="block space-y-1.5">
                                <span class="text-xs font-semibold uppercase text-slate-500">{{ $tr('Mot de passe') }}</span>
                                <div class="relative">
                                    <input name="password" type="password" required autocomplete="current-password" class="h-12 w-full rounded-xl border border-slate-200 bg-white px-3 pr-10 text-sm text-slate-950 outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900 dark:text-white" placeholder="{{ $tr('Minimum 8 caractères') }}" id="login-password">
                                    <button type="button" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200" onclick="const p=document.getElementById('login-password');p.type=p.type==='password'?'text':'password';this.querySelector('svg:first-child').classList.toggle('hidden');this.querySelector('svg:last-child').classList.toggle('hidden')" tabindex="-1" aria-label="Afficher/masquer le mot de passe">
                                        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                        <svg class="size-5 hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                                    </button>
                                </div>
                            </label>
                            <div class="flex items-center justify-between gap-3">
                                <label class="flex items-center gap-2 text-sm font-medium text-slate-600 dark:text-slate-300">
                                    <input name="remember" value="1" type="checkbox" class="size-4 accent-[var(--brand-primary)]">
                                    {{ $tr('Se souvenir de moi') }}
                                </label>
                                <a href="{{ route('password.request') }}" class="text-xs font-semibold text-brand hover:text-brand-600 transition">{{ $tr('Mot de passe oublié ?') }}</a>
                            </div>
                            <button class="h-12 w-full rounded-xl bg-brand px-4 text-sm font-semibold text-white shadow-sm shadow-indigo-500/20 transition hover:brightness-110">{{ $tr('Se connecter') }}</button>
                        </form>

                        @if (app()->environment(['local', 'testing']) && filled($demoLoginEmail ?? null))
                            <div class="mt-5 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600 dark:border-white/10 dark:bg-white/5 dark:text-slate-300">
                                <p class="font-semibold text-slate-800 dark:text-white">{{ $tr('Compte de démonstration') }}</p>
                                <p class="mt-1">Email: <span class="font-mono text-xs">{{ $demoLoginEmail }}</span> · {{ $tr('mot de passe') }}: <span class="font-mono text-xs">password</span></p>
                            </div>
                        @endif

                        <div class="mt-5 flex flex-wrap items-center justify-between gap-3 text-xs text-slate-500 dark:text-slate-400">
                            <span class="font-semibold">{{ $tr('Version') }} {{ $appVersion }}</span>
                            <span>{{ $releaseLabel }} · {{ now()->format('d/m/Y') }}</span>
                        </div>
                    </section>
                </div>
            </section>
        </main>
    </body>
</html>
