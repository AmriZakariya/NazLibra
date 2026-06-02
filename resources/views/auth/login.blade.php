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
@endphp
<!DOCTYPE html>
<html lang="fr" style="--brand-primary: {{ $theme['primary'] }}; --brand-accent: {{ $theme['accent'] }}; --brand-success: {{ $theme['success'] }}; --app-bg: {{ $theme['background'] }}; --surface: {{ $theme['surface_color'] }}; --surface-muted: {{ $theme['surface_muted'] }}; --text-main: {{ $theme['text'] }}; --text-muted: {{ $theme['muted'] }}; --border-soft: {{ $theme['border'] }}; --font-scale: {{ $theme['font_scale'] }}; --brand-radius: {{ $theme['radius'] }}px;">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Connexion · LibrairePro</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-slate-50 text-slate-950 antialiased">
        <main class="min-h-screen px-4 py-6 sm:px-6 lg:px-8">
            <section class="mx-auto grid min-h-[calc(100vh-3rem)] w-full max-w-6xl overflow-hidden rounded-[28px] border border-slate-200 bg-white shadow-[0_24px_80px_rgba(15,23,42,0.12)] dark:border-white/10 dark:bg-slate-950 lg:grid-cols-[1.05fr_0.95fr]">
                <div class="relative flex min-h-[360px] flex-col justify-between overflow-hidden bg-slate-950 p-6 text-white sm:p-8 lg:p-10">
                    <div class="absolute inset-0 opacity-90" style="background: linear-gradient(135deg, color-mix(in srgb, var(--brand-primary) 82%, #020617) 0%, #020617 52%, color-mix(in srgb, var(--brand-accent) 42%, #020617) 100%);"></div>
                    <div class="absolute inset-x-0 bottom-0 h-1/2 bg-[linear-gradient(180deg,transparent,rgba(255,255,255,0.10))]"></div>

                    <div class="relative">
                        <div class="flex items-center gap-3">
                            <span class="grid size-12 place-items-center rounded-2xl bg-white text-sm font-black text-slate-950 shadow-sm">LP</span>
                            <div>
                                <p class="text-sm font-semibold text-white/80">LibrairePro SaaS</p>
                                <p class="text-lg font-semibold">{{ $tenantName }}</p>
                            </div>
                        </div>

                        <div class="mt-12 max-w-xl">
                            <p class="text-sm font-semibold uppercase text-white/60">Caisse, stock, ventes et achats</p>
                            <h1 class="mt-3 text-4xl font-semibold tracking-normal text-white sm:text-5xl">Votre librairie prête pour la rentrée.</h1>
                            <p class="mt-5 text-base leading-7 text-white/72">Connectez-vous pour piloter le catalogue, la caisse, les clients et les rapports avec une interface rapide, claire et pensée pour les journées chargées.</p>
                        </div>
                    </div>

                    <div class="relative mt-10 grid gap-3 sm:grid-cols-3">
                        <div class="rounded-2xl border border-white/14 bg-white/10 p-4 backdrop-blur">
                            <span class="text-xs font-semibold uppercase text-white/55">Mode</span>
                            <strong class="mt-2 block text-lg">Multi-magasin</strong>
                        </div>
                        <div class="rounded-2xl border border-white/14 bg-white/10 p-4 backdrop-blur">
                            <span class="text-xs font-semibold uppercase text-white/55">Caisse</span>
                            <strong class="mt-2 block text-lg">Barcode ready</strong>
                        </div>
                        <div class="rounded-2xl border border-white/14 bg-white/10 p-4 backdrop-blur">
                            <span class="text-xs font-semibold uppercase text-white/55">Devise</span>
                            <strong class="mt-2 block text-lg">MAD · DH</strong>
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-center bg-[var(--surface)] p-5 sm:p-8 lg:p-10">
                    <section class="w-full max-w-md">
                        <div class="mb-8">
                            <p class="text-sm font-semibold text-brand">Connexion sécurisée</p>
                            <h2 class="mt-2 text-3xl font-semibold tracking-normal text-slate-950 dark:text-white">Bienvenue</h2>
                            <p class="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-300">Utilisez votre compte équipe pour accéder à l’espace de gestion.</p>
                        </div>

                        @if (session('status'))
                            <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-100">{{ session('status') }}</div>
                        @endif
                        @if ($errors->any())
                            <div class="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-100">{{ $errors->first() }}</div>
                        @endif

                        <form action="{{ route('login.store') }}" method="POST" class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                            @csrf
                            <label class="block space-y-1.5">
                                <span class="text-xs font-semibold uppercase text-slate-500">Email</span>
                                <input name="email" value="{{ old('email') }}" type="email" required autofocus autocomplete="email" class="h-12 w-full rounded-xl border border-slate-200 px-3 text-sm outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900" placeholder="vous@librairie.ma">
                            </label>
                            <label class="block space-y-1.5">
                                <span class="text-xs font-semibold uppercase text-slate-500">Mot de passe</span>
                                <input name="password" type="password" required autocomplete="current-password" class="h-12 w-full rounded-xl border border-slate-200 px-3 text-sm outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900" placeholder="Minimum 8 caractères">
                            </label>
                            <div class="flex items-center justify-between gap-3">
                                <label class="flex items-center gap-2 text-sm font-medium text-slate-600 dark:text-slate-300">
                                    <input name="remember" value="1" type="checkbox" class="size-4 accent-[var(--brand-primary)]">
                                    Se souvenir de moi
                                </label>
                                <span class="text-xs font-semibold text-slate-400">Accès équipe</span>
                            </div>
                            <button class="h-12 w-full rounded-xl bg-brand px-4 text-sm font-semibold text-white shadow-sm shadow-indigo-500/20 transition hover:brightness-110">Se connecter</button>
                        </form>

                        @if (app()->environment(['local', 'testing']) && filled($demoLoginEmail ?? null))
                            <div class="mt-5 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600 dark:border-white/10 dark:bg-white/5 dark:text-slate-300">
                                <p class="font-semibold text-slate-800 dark:text-white">Compte de démonstration</p>
                                <p class="mt-1">Email: <span class="font-mono text-xs">{{ $demoLoginEmail }}</span> · mot de passe: <span class="font-mono text-xs">password</span></p>
                            </div>
                        @endif
                    </section>
                </div>
            </section>
        </main>
    </body>
</html>
