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
    $locale = \App\Support\Locale::current($tenant);
    $direction = \App\Support\Locale::dir($locale);
    $tr = fn (string $text): string => \App\Support\Locale::t($text, $locale);
    $appVersion = config('app.version', '1.0.0-beta.4');
    $releaseLabel = app()->environment('production') ? $tr('Production') : \Illuminate\Support\Str::headline(app()->environment());
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $direction }}" style="--brand-primary: {{ $theme['primary'] }}; --brand-accent: {{ $theme['accent'] }}; --brand-success: {{ $theme['success'] }}; --app-bg: {{ $theme['background'] }}; --surface: {{ $theme['surface_color'] }}; --surface-muted: {{ $theme['surface_muted'] }}; --text-main: {{ $theme['text'] }}; --text-muted: {{ $theme['muted'] }}; --border-soft: {{ $theme['border'] }}; --font-scale: {{ $theme['font_scale'] }}; --brand-radius: {{ $theme['radius'] }}px;">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $tr('Mot de passe oublié') }} · LibrairePro</title>
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
                            <p class="text-sm font-semibold uppercase text-white/60">{{ $tr('Réinitialisation') }}</p>
                            <h1 class="mt-3 text-4xl font-semibold tracking-normal text-white sm:text-5xl">{{ $tr('Mot de passe oublié ?') }}</h1>
                            <p class="mt-5 text-base leading-7 text-white/72">{{ $tr('Saisissez votre adresse email et nous vous enverrons un lien pour réinitialiser votre mot de passe.') }}</p>
                        </div>
                    </div>
                </div>
                <div class="flex items-center justify-center bg-[var(--surface)] p-5 sm:p-8 lg:p-10">
                    <section class="w-full max-w-md">
                        <div class="mb-8">
                            <p class="text-sm font-semibold text-brand">{{ $tr('Sécurité') }}</p>
                            <h2 class="mt-2 text-3xl font-semibold tracking-normal text-slate-950 dark:text-white">{{ $tr('Réinitialiser') }}</h2>
                            <p class="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-300">{{ $tr('Recevez un lien par email pour créer un nouveau mot de passe.') }}</p>
                        </div>
                        @if (session('status'))
                            <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-100">{{ session('status') }}</div>
                        @endif
                        @if ($errors->any())
                            <div class="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-100">{{ $errors->first() }}</div>
                        @endif
                        <form action="{{ route('password.email') }}" method="POST" class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                            @csrf
                            <label class="block space-y-1.5">
                                <span class="text-xs font-semibold uppercase text-slate-500">Email</span>
                                <input name="email" value="{{ old('email') }}" type="email" required autofocus autocomplete="email" class="h-12 w-full rounded-xl border border-slate-200 px-3 text-sm outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900" placeholder="vous@librairie.ma">
                            </label>
                            <button class="h-12 w-full rounded-xl bg-brand px-4 text-sm font-semibold text-white shadow-sm shadow-indigo-500/20 transition hover:brightness-110">{{ $tr('Envoyer le lien') }}</button>
                        </form>
                        <p class="mt-5 text-center text-sm text-slate-500"><a href="{{ route('login') }}" class="font-semibold text-brand hover:text-brand-600 transition">{{ $tr('Retour à la connexion') }}</a></p>
                    </section>
                </div>
            </section>
        </main>
    </body>
</html>
