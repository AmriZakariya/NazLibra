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
        <main class="grid min-h-screen place-items-center px-4 py-10">
            <section class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <div class="flex items-center gap-3">
                    <span class="grid size-11 place-items-center rounded-xl bg-brand text-sm font-bold text-white">LP</span>
                    <div>
                        <p class="text-sm font-semibold text-brand">{{ $tenant?->name ?? 'LibrairePro' }}</p>
                        <h1 class="text-xl font-semibold">Connexion</h1>
                    </div>
                </div>

                @if (session('status'))
                    <div class="mt-5 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm font-medium text-emerald-700">{{ session('status') }}</div>
                @endif
                @if ($errors->any())
                    <div class="mt-5 rounded-lg border border-rose-200 bg-rose-50 px-4 py-2 text-sm font-medium text-rose-700">{{ $errors->first() }}</div>
                @endif

                <form action="{{ route('login.store') }}" method="POST" class="mt-6 space-y-4">
                    @csrf
                    <label class="block space-y-1.5">
                        <span class="text-xs font-semibold uppercase text-slate-500">Email</span>
                        <input name="email" value="{{ old('email') }}" type="email" required autofocus class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm" placeholder="vous@librairie.ma">
                    </label>
                    <label class="block space-y-1.5">
                        <span class="text-xs font-semibold uppercase text-slate-500">Mot de passe</span>
                        <input name="password" type="password" required class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm" placeholder="••••••••">
                    </label>
                    <label class="flex items-center gap-2 text-sm text-slate-600">
                        <input name="remember" value="1" type="checkbox" class="size-4 accent-[var(--brand-primary)]">
                        Se souvenir de moi
                    </label>
                    <button class="h-11 w-full rounded-lg bg-brand px-4 text-sm font-semibold text-white">Se connecter</button>
                </form>
            </section>
        </main>
    </body>
</html>
