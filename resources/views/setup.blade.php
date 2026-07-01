@php
    $isMaintenance = $isMaintenance ?? false;

    $steps = [
        'store'      => ['num' => 1, 'label' => 'Boutique'],
        'owner'      => ['num' => 2, 'label' => 'Propriétaire'],
        'locations'  => ['num' => 3, 'label' => 'Magasins'],
        'categories' => ['num' => 4, 'label' => 'Catégories'],
        'devices'    => ['num' => 5, 'label' => 'Appareils'],
        'review'     => ['num' => 6, 'label' => $isMaintenance ? 'Mise à jour' : 'Confirmation'],
        'done'       => ['num' => 7, 'label' => 'Terminé'],
    ];

    $orderedSteps = ['store', 'owner', 'locations', 'categories', 'devices', 'review', 'done'];
    $currentNum   = $steps[$step]['num'] ?? 0;
    $businessModes = $businessModes ?? \App\Support\BusinessMode::all();
    $categoryPresets = $categoryPresets ?? [];
    $setupSecretConfigured = $setupSecretConfigured ?? filled(config('app.setup_secret'));
    $selectedBusinessMode = \App\Support\BusinessMode::get(old('business_mode', $data['business_mode'] ?? $store['business_mode'] ?? \App\Support\BusinessMode::defaultKey()));
    $selectedModeKey = $selectedBusinessMode['key'];
    $selectedModeLabel = $selectedBusinessMode['label'];

    $timezones = [
        'Africa/Casablanca' => 'Casablanca (UTC+1)',
        'Africa/Cairo'      => 'Cairo (UTC+2)',
        'Africa/Tunis'      => 'Tunis (UTC+1)',
        'Africa/Algiers'    => 'Alger (UTC+1)',
        'Europe/Paris'      => 'Paris (UTC+1/+2)',
        'Europe/London'     => 'Londres (UTC+0/+1)',
        'Asia/Dubai'        => 'Dubaï (UTC+4)',
        'America/New_York'  => 'New York (UTC-5/-4)',
    ];

    $modeLabel = $isMaintenance
        ? ($step === 'secret' ? 'Accès maintenance' : 'Maintenance')
        : ($step === 'secret' ? 'Configuration initiale' : 'Installation');
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $isMaintenance ? 'Maintenance' : 'Configuration' }} · LibrairePro</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        :root {
            --brand: #3157D5;
            --brand-dark: #2446b8;
            --ink: #0f172a;
            --muted: #64748b;
            --line: #dbe4f0;
            --surface: #ffffff;
            --soft: #f8fafc;
        }
        * { box-sizing: border-box; }

        /* Allow text selection everywhere in setup */
        body, p, h1, h2, h3, h4, h5, span, label, li, strong, em, code, pre, td, th, div {
            -webkit-user-select: text;
            -moz-user-select: text;
            user-select: text;
        }
        input, textarea, select {
            -webkit-user-select: text;
            -moz-user-select: text;
            user-select: text;
        }

        body {
            margin: 0;
            background:
                radial-gradient(circle at top left, rgba(49,87,213,.16), transparent 32rem),
                radial-gradient(circle at bottom right, rgba(16,185,129,.12), transparent 28rem),
                linear-gradient(135deg, #f8fafc 0%, #eef4ff 48%, #f8fafc 100%);
            color: var(--ink);
        }
        .setup-panel {
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(219, 228, 240, .9);
            border-radius: 28px;
            background: rgba(255,255,255,.88);
            padding: 30px;
            box-shadow: 0 24px 70px rgba(15, 23, 42, .12);
            backdrop-filter: blur(18px);
        }
        .setup-panel::before {
            content: "";
            position: absolute;
            inset: 0 0 auto 0;
            height: 5px;
            background: linear-gradient(90deg, var(--brand), #10b981, #f59e0b);
        }
        .setup-input {
            width: 100%;
            min-height: 46px;
            border: 1px solid var(--line);
            border-radius: 14px;
            background: #fff;
            padding: 0 15px;
            color: var(--ink);
            font-size: 14px;
            line-height: 1.4;
            outline: none;
            transition: border-color .18s ease, box-shadow .18s ease, transform .18s ease;
            box-shadow: 0 1px 2px rgba(15,23,42,.03);
        }
        .setup-input::placeholder { color: #94a3b8; }
        .setup-input:hover { border-color: #bdcbe0; }
        .setup-input:focus {
            border-color: var(--brand);
            box-shadow: 0 0 0 4px rgba(49,87,213,.12);
        }
        select.setup-input {
            appearance: none;
            padding-right: 42px;
            background-image:
                linear-gradient(45deg, transparent 50%, #64748b 50%),
                linear-gradient(135deg, #64748b 50%, transparent 50%);
            background-position:
                calc(100% - 20px) 20px,
                calc(100% - 14px) 20px;
            background-size: 6px 6px, 6px 6px;
            background-repeat: no-repeat;
        }
        .setup-label {
            display: block;
            margin: 0 0 7px;
            color: #64748b;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .08em;
            text-transform: uppercase;
        }
        .setup-btn-primary,
        .setup-btn-ghost,
        .setup-btn-success {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            border-radius: 14px;
            padding: 11px 20px;
            font-size: 14px;
            font-weight: 800;
            text-decoration: none;
            transition: transform .18s ease, box-shadow .18s ease, filter .18s ease, background .18s ease;
            cursor: pointer;
        }
        .setup-btn-primary {
            border: 0;
            background: linear-gradient(135deg, var(--brand), #5b7cfa);
            color: #fff;
            box-shadow: 0 14px 30px rgba(49,87,213,.28);
        }
        .setup-btn-primary:hover { filter: brightness(1.04); box-shadow: 0 18px 38px rgba(49,87,213,.34); }
        .setup-btn-primary:disabled { opacity: .55; cursor: not-allowed; box-shadow: none; }
        .setup-btn-ghost {
            border: 1px solid var(--line);
            background: #fff;
            color: #334155;
            box-shadow: 0 8px 20px rgba(15,23,42,.05);
        }
        .setup-btn-ghost:hover { background: #f8fafc; border-color: #cbd5e1; }
        .setup-btn-success {
            border: 0;
            background: linear-gradient(135deg, #059669, #10b981);
            color: #fff;
            box-shadow: 0 14px 30px rgba(5,150,105,.25);
        }
        .setup-btn-primary:active,
        .setup-btn-ghost:active,
        .setup-btn-success:active {
            transform: translateY(1px) scale(.99);
        }
        .setup-choice {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 18px;
            background:
                linear-gradient(180deg, rgba(255,255,255,.96), rgba(248,250,252,.94));
            padding: 16px;
            box-shadow: 0 10px 24px rgba(15,23,42,.06);
            transition: border-color .18s ease, box-shadow .18s ease, transform .18s ease;
            cursor: pointer;
        }
        .setup-choice:hover {
            transform: translateY(-2px);
            border-color: rgba(49,87,213,.45);
            box-shadow: 0 18px 36px rgba(15,23,42,.11);
        }
        .setup-choice.is-selected {
            border-color: var(--brand);
            background: linear-gradient(160deg, rgba(235,242,255,.98), rgba(225,236,255,.96));
            box-shadow: 0 0 0 3px rgba(49,87,213,.14), 0 18px 36px rgba(49,87,213,.14);
        }
        .setup-choice.is-selected .preset-check {
            opacity: 1;
            transform: scale(1);
        }
        .setup-pill {
            display: inline-flex;
            align-items: center;
            border: 1px solid var(--line);
            border-radius: 999px;
            background: #f8fafc;
            padding: 7px 11px;
            color: #475569;
            font-size: 11px;
            font-weight: 800;
            cursor: pointer;
        }
        .step-done    { background:#10b981; color:#fff; }
        .step-active  { background:var(--brand); color:#fff; }
        .step-pending { background:rgba(255,255,255,.1); color:rgba(255,255,255,.35); }
        /* Costing method cards — pure CSS selection, no JS */
        .costing-card {
            position: relative;
            transition: border-color .18s ease, box-shadow .18s ease, background .18s ease, transform .18s ease;
        }
        .costing-card:has(input[type="radio"]:checked) {
            border-color: var(--brand);
            background: linear-gradient(160deg, rgba(235,242,255,.98), rgba(218,230,255,.95));
            box-shadow: 0 0 0 3px rgba(49,87,213,.14), 0 14px 28px rgba(49,87,213,.12);
            transform: translateY(-1px);
        }
        .costing-card:has(input[type="radio"]:checked) .costing-tick {
            opacity: 1;
            transform: scale(1);
        }
        .costing-card:has(input[type="radio"]:checked) .costing-icon {
            background: var(--brand);
            color: #fff;
        }
        .costing-tick {
            position: absolute;
            top: 12px; right: 12px;
            width: 20px; height: 20px;
            border-radius: 50%;
            background: var(--brand);
            color: #fff;
            font-size: 11px;
            display: flex; align-items: center; justify-content: center;
            opacity: 0;
            transform: scale(.6);
            transition: opacity .18s ease, transform .18s ease;
        }
        .costing-icon {
            width: 36px; height: 36px;
            border-radius: 10px;
            background: #f1f5f9;
            color: #475569;
            display: flex; align-items: center; justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
            transition: background .18s ease, color .18s ease;
            margin-bottom: 10px;
        }
        @media (max-width: 640px) {
            .setup-panel { padding: 22px; border-radius: 22px; }
        }
    </style>
</head>
<body class="min-h-screen bg-slate-100 antialiased">
<div class="flex min-h-screen items-stretch">

    {{-- ── Left sidebar ──────────────────────────────────────────────────────── --}}
    <aside class="hidden w-64 shrink-0 flex-col lg:flex" style="background:#0c1221">
        <div class="flex h-full flex-col px-6 py-8">
            {{-- Branding --}}
            <div class="flex items-center gap-3">
                <div class="grid size-9 shrink-0 place-items-center rounded-xl text-xs font-black text-white" style="background:var(--brand)">LP</div>
                <div class="min-w-0">
                    <p class="truncate text-[11px] font-semibold uppercase tracking-widest" style="color:rgba(255,255,255,.4)">LibrairePro</p>
                    <p class="truncate text-sm font-semibold text-white">{{ $modeLabel }}</p>
                </div>
            </div>

            {{-- Mode badge --}}
            @if ($isMaintenance)
            <div class="mt-5 flex items-center gap-2 rounded-xl px-3 py-2.5" style="background:rgba(5,150,105,.12); border:1px solid rgba(5,150,105,.25)">
                <span class="grid size-5 shrink-0 place-items-center rounded-full" style="background:#059669">
                    <svg class="size-2.5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                </span>
                <span class="text-xs font-semibold" style="color:#34d399">Tenant configuré</span>
            </div>
            @else
            <div class="mt-5 flex items-center gap-2 rounded-xl px-3 py-2.5" style="background:rgba(234,179,8,.1); border:1px solid rgba(234,179,8,.2)">
                <span class="grid size-5 shrink-0 place-items-center rounded-full" style="background:#ca8a04">
                    <svg class="size-2.5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="2" x2="12" y2="6"/><line x1="12" y1="18" x2="12" y2="22"/><line x1="4.93" y1="4.93" x2="7.76" y2="7.76"/><line x1="16.24" y1="16.24" x2="19.07" y2="19.07"/><line x1="2" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="22" y2="12"/></svg>
                </span>
                <span class="text-xs font-semibold" style="color:#fbbf24">Premier déploiement</span>
            </div>
            @endif

            {{-- Steps (only when past secret) --}}
            @if ($step !== 'secret')
            <nav class="mt-8 flex flex-col gap-0.5">
                @foreach ($orderedSteps as $s)
                    @php
                        $info      = $steps[$s];
                        $sNum      = $info['num'];
                        $isDone    = $sNum < $currentNum;
                        $isActive  = $s === $step;
                        $stateClass = $isDone ? 'step-done' : ($isActive ? 'step-active' : 'step-pending');
                        $labelOpacity = $isActive ? 'text-white' : ($isDone ? 'opacity-80 text-white' : 'text-white/35');
                    @endphp
                    <div class="flex items-center gap-3 rounded-xl px-3 py-2.5 {{ $isActive ? '' : '' }}" style="{{ $isActive ? 'background:rgba(255,255,255,.07)' : '' }}">
                        <span class="grid size-6 shrink-0 place-items-center rounded-full text-[11px] font-bold {{ $stateClass }}">
                            @if ($isDone)
                                <svg class="size-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                            @else
                                {{ $sNum }}
                            @endif
                        </span>
                        <span class="text-sm font-medium {{ $labelOpacity }}">{{ $info['label'] }}</span>
                    </div>
                @endforeach
            </nav>
            @endif

            <div class="mt-auto pt-6">
                <p class="text-[11px] leading-5" style="color:rgba(255,255,255,.25)">
                    @if ($isMaintenance)
                        /setup reste disponible via SETUP_SECRET. Les données affichées reflètent l'état actuel en base.
                    @else
                        Déploiement rapide : activité, propriétaire, magasins, catégories et valeurs par défaut.
                    @endif
                </p>
            </div>
        </div>
    </aside>

    {{-- ── Main content ──────────────────────────────────────────────────────── --}}
    <main class="flex flex-1 flex-col bg-slate-50">
        {{-- Mobile top bar --}}
        <div class="flex items-center gap-3 border-b border-slate-200 bg-white px-5 py-3.5 lg:hidden">
            <div class="grid size-8 shrink-0 place-items-center rounded-lg text-xs font-black text-white" style="background:var(--brand)">LP</div>
            <p class="flex-1 text-sm font-semibold text-slate-900">{{ $modeLabel }} · LibrairePro</p>
            @if ($step !== 'secret')
                <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold text-white" style="background:var(--brand)">{{ $steps[$step]['label'] ?? '' }}</span>
            @endif
        </div>

        <div class="flex flex-1 items-start justify-center px-5 py-10 sm:px-8">
            <div class="setup-panel w-full max-w-2xl">

                {{-- Errors --}}
                @if ($errors->any())
                <div class="mb-6 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
                    <p class="font-semibold">Veuillez corriger les erreurs suivantes :</p>
                    <ul class="mt-2 list-inside list-disc space-y-1">
                        @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                    </ul>
                </div>
                @endif

                {{-- ═══════════════════════════════════════════════════════════
                     STEP: SECRET
                ════════════════════════════════════════════════════════════ --}}
                @if ($step === 'secret')
                <div>
                    <div class="mb-8 flex items-center gap-3.5">
                        <div class="grid size-12 shrink-0 place-items-center rounded-2xl" style="background:rgba(49,87,213,.10); border:1px solid rgba(49,87,213,.15)">
                            <svg class="size-5" style="color:var(--brand)" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        </div>
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-widest" style="color:var(--brand)">{{ $isMaintenance ? 'Maintenance' : 'Installation' }}</p>
                            <h1 class="text-xl font-semibold tracking-tight text-slate-900">
                                {{ $isMaintenance ? 'Accès maintenance' : 'Configuration initiale' }}
                            </h1>
                        </div>
                    </div>

                    @if ($isMaintenance)
                    <div class="mb-6 rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-600 shadow-sm">
                        <p class="font-semibold text-slate-800">Tenant déjà configuré</p>
                        <p class="mt-1 text-slate-500">Ce formulaire vous permet de consulter et modifier les données de configuration sans passer par le tableau de bord.</p>
                    </div>
                    @endif

                    @unless ($setupSecretConfigured)
                    <div class="mb-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 shadow-sm">
                        <p class="font-semibold">SETUP_SECRET manquant</p>
                        <p class="mt-1">Ajoutez <code class="rounded bg-white/70 px-1.5 py-0.5 font-mono text-xs">SETUP_SECRET=un-code-fort</code> dans <code class="rounded bg-white/70 px-1.5 py-0.5 font-mono text-xs">.env</code>, puis exécutez <code class="rounded bg-white/70 px-1.5 py-0.5 font-mono text-xs">php artisan optimize:clear</code>. La page /setup restera disponible ensuite pour déploiement et maintenance.</p>
                    </div>
                    @endunless

                    <form action="{{ route('setup.secret') }}" method="POST" class="space-y-5">
                        @csrf
                        <div>
                            <label class="setup-label" for="secret">Code secret de maintenance</label>
                            <input id="secret" name="secret" type="password" autocomplete="off" required autofocus
                                   class="setup-input @error('secret') border-rose-400 ring-2 ring-rose-400/10 @enderror"
                                   placeholder="••••••••••••">
                            @error('secret')<p class="mt-1.5 text-xs font-semibold text-rose-600">{{ $message }}</p>@enderror
                        </div>
                        <button type="submit" class="setup-btn-primary w-full justify-center py-3 text-base" @disabled(! $setupSecretConfigured)>
                            {{ $isMaintenance ? 'Accéder à la maintenance' : 'Démarrer la configuration' }}
                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                        </button>
                    </form>
                </div>

                {{-- ═══════════════════════════════════════════════════════════
                     STEP: STORE INFO
                ════════════════════════════════════════════════════════════ --}}
                @elseif ($step === 'store')
                <div>
                    <p class="text-xs font-semibold uppercase tracking-widest" style="color:var(--brand)">Étape 1 / 6</p>
                    <h1 class="mt-1.5 text-2xl font-semibold tracking-tight text-slate-900">Informations de la boutique</h1>
                    <p class="mt-1.5 text-sm leading-6 text-slate-500">
                        {{ $isMaintenance ? 'Modifiez les informations du tenant. Ces changements s\'appliquent immédiatement.' : 'Ces données définissent l\'identité de votre tenant dans le système.' }}
                    </p>

                    <form action="{{ route('setup.store.save') }}" method="POST" class="mt-7 space-y-4">
                        @csrf
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <label class="setup-label" for="name">Nom de la boutique *</label>
                                <input id="name" name="name" type="text" required
                                       value="{{ old('name', $data['name'] ?? '') }}"
                                       class="setup-input" placeholder="Ex: Librairie El Amal">
                            </div>
                            <div>
                                <label class="setup-label" for="business_mode">Type d'activité *</label>
                                <select id="business_mode" name="business_mode" required class="setup-input">
                                    @foreach ($businessModes as $modeKey => $mode)
                                        <option value="{{ $modeKey }}" @selected($selectedModeKey === $modeKey)>{{ $mode['label'] }} — {{ $mode['subtitle'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="setup-label" for="currency">Devise *</label>
                                <select id="currency" name="currency" required class="setup-input">
                                    <option value="MAD" @selected(old('currency', $data['currency'] ?? 'MAD') === 'MAD')>MAD — Dirham marocain</option>
                                    <option value="EUR" @selected(old('currency', $data['currency'] ?? '') === 'EUR')>EUR — Euro</option>
                                    <option value="USD" @selected(old('currency', $data['currency'] ?? '') === 'USD')>USD — Dollar</option>
                                    <option value="TND" @selected(old('currency', $data['currency'] ?? '') === 'TND')>TND — Dinar tunisien</option>
                                    <option value="DZD" @selected(old('currency', $data['currency'] ?? '') === 'DZD')>DZD — Dinar algérien</option>
                                    <option value="AED" @selected(old('currency', $data['currency'] ?? '') === 'AED')>AED — Dirham EAU</option>
                                </select>
                            </div>
                            <div>
                                <label class="setup-label" for="timezone">Fuseau horaire *</label>
                                <select id="timezone" name="timezone" required class="setup-input">
                                    @foreach ($timezones as $tz => $label)
                                        <option value="{{ $tz }}" @selected(old('timezone', $data['timezone'] ?? 'Africa/Casablanca') === $tz)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="setup-label" for="language">Langue principale *</label>
                                <select id="language" name="language" required class="setup-input">
                                    <option value="fr" @selected(old('language', $data['language'] ?? 'fr') === 'fr')>Français</option>
                                    <option value="ar" @selected(old('language', $data['language'] ?? '') === 'ar')>العربية</option>
                                </select>
                            </div>
                            <div>
                                <label class="setup-label" for="email">Email</label>
                                <input id="email" name="email" type="email"
                                       value="{{ old('email', $data['email'] ?? '') }}"
                                       class="setup-input" placeholder="contact@boutique.ma">
                            </div>
                            <div>
                                <label class="setup-label" for="phone">Téléphone</label>
                                <input id="phone" name="phone" type="tel"
                                       value="{{ old('phone', $data['phone'] ?? '') }}"
                                       class="setup-input" placeholder="+212 6xx xxx xxx">
                            </div>
                            <div class="sm:col-span-2">
                                <label class="setup-label" for="address">Adresse</label>
                                <input id="address" name="address" type="text"
                                       value="{{ old('address', $data['address'] ?? '') }}"
                                       class="setup-input" placeholder="Rue, Ville, Code postal">
                            </div>
                        </div>

                        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                            <div class="flex items-start gap-3">
                                <div class="flex size-9 shrink-0 items-center justify-center rounded-xl text-lg" style="background:#f1f5f9">📦</div>
                                <div>
                                    <p class="text-sm font-semibold text-slate-800">Méthode de valorisation des stocks</p>
                                    <p class="mt-0.5 text-xs leading-5 text-slate-500">Détermine comment le coût des marchandises vendues (CMV) est calculé. Peut être modifié plus tard dans les paramètres.</p>
                                </div>
                            </div>

                            @php
                                $selectedCosting = old('costing_method', $data['costing_method'] ?? 'lifo');
                                $costingOptions = [
                                    'lifo' => [
                                        'icon'    => '⬆️',
                                        'label'   => 'LIFO',
                                        'sub'     => 'Dernier entré, premier sorti',
                                        'note'    => 'CMV calculé sur les lots les plus récents. Idéal pour les articles dont le coût fluctue.',
                                        'badge'   => 'Par défaut',
                                    ],
                                    'fifo' => [
                                        'icon'    => '⬇️',
                                        'label'   => 'FIFO',
                                        'sub'     => 'Premier entré, premier sorti',
                                        'note'    => 'CMV calculé sur les lots les plus anciens. Standard pour la distribution et l\'alimentaire.',
                                        'badge'   => null,
                                    ],
                                    'wac'  => [
                                        'icon'    => '⚖️',
                                        'label'   => 'CMUP',
                                        'sub'     => 'Coût moyen pondéré',
                                        'note'    => 'CMV lissé sur tous les lots en stock. Simplifie la comptabilité à fort volume.',
                                        'badge'   => null,
                                    ],
                                ];
                            @endphp

                            <div class="mt-4 grid gap-3 sm:grid-cols-3">
                                @foreach ($costingOptions as $key => $opt)
                                    <label class="setup-choice costing-card cursor-pointer text-left">
                                        <input type="radio" name="costing_method" value="{{ $key }}"
                                               class="sr-only"
                                               @checked($selectedCosting === $key)>

                                        {{-- Checkmark tick shown when selected --}}
                                        <span class="costing-tick" aria-hidden="true">
                                            <svg viewBox="0 0 10 8" fill="none" width="10" height="8"><path d="M1 4l3 3 5-6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                        </span>

                                        <span class="costing-icon">{{ $opt['icon'] }}</span>

                                        <span class="flex items-center gap-2">
                                            <span class="text-sm font-bold text-slate-900">{{ $opt['label'] }}</span>
                                            @if ($opt['badge'])
                                                <span class="rounded-full px-2 py-0.5 text-[10px] font-bold" style="background:rgba(49,87,213,.1);color:var(--brand)">{{ $opt['badge'] }}</span>
                                            @endif
                                        </span>
                                        <span class="mt-0.5 block text-xs font-semibold text-slate-500">{{ $opt['sub'] }}</span>
                                        <span class="mt-2 block text-xs leading-5 text-slate-400">{{ $opt['note'] }}</span>
                                    </label>
                                @endforeach
                            </div>

                            @error('costing_method')
                                <p class="mt-3 text-xs text-rose-500">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                            <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Presets inclus</p>
                            <div class="mt-3 grid gap-3 sm:grid-cols-3">
                                @foreach (['library', 'restaurant', 'coffee'] as $presetMode)
                                    @php $preset = \App\Support\BusinessMode::get($presetMode); @endphp
                                    <button type="button"
                                            class="setup-choice text-left {{ $selectedModeKey === $preset['key'] ? 'is-selected' : '' }}"
                                            data-select-business-mode="{{ $preset['key'] }}"
                                            data-catalog-label="{{ $preset['catalog_label'] }}"
                                            data-primary-item="{{ $preset['primary_item'] }}"
                                            data-book-label="{{ $preset['type_labels']['book'] }}"
                                            data-supply-label="{{ $preset['type_labels']['supply'] }}">
                                        <span class="flex items-center justify-between gap-3">
                                            <span class="text-sm font-semibold text-slate-900">{{ $preset['short_label'] }}</span>
                                            <span class="preset-check grid size-5 shrink-0 place-items-center rounded-full text-white opacity-0 transition" style="background:var(--brand);transform:scale(.85)">
                                                <svg class="size-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                            </span>
                                        </span>
                                        <span class="mt-1 block text-xs leading-5 text-slate-500">{{ $preset['subtitle'] }}</span>
                                        <span class="mt-3 flex flex-wrap gap-1.5">
                                            <span class="rounded-full bg-white px-2 py-0.5 text-[10px] font-bold text-slate-500 ring-1 ring-slate-200">{{ $preset['type_labels']['book'] }}</span>
                                            <span class="rounded-full bg-white px-2 py-0.5 text-[10px] font-bold text-slate-500 ring-1 ring-slate-200">{{ $preset['type_labels']['supply'] }}</span>
                                        </span>
                                    </button>
                                @endforeach
                            </div>
                            <div id="business-mode-preview" class="mt-4 rounded-xl border border-indigo-100 bg-indigo-50/70 p-3 text-xs leading-5 text-indigo-900">
                                <span class="font-bold">Adaptation active :</span>
                                <span data-preview-catalog>{{ $selectedBusinessMode['catalog_label'] }}</span>
                                · type principal <span class="font-bold" data-preview-primary>{{ $selectedBusinessMode['primary_item'] }}</span>
                                · labels <span class="font-bold" data-preview-labels>{{ $selectedBusinessMode['type_labels']['book'] }} / {{ $selectedBusinessMode['type_labels']['supply'] }}</span>.
                            </div>
                            <p class="mt-3 text-xs leading-5 text-slate-500">Le type d’activité prépare les catégories, unités, libellés catalogue et recherche. Vous pouvez tout modifier plus tard.</p>
                        </div>

                        <div class="flex flex-wrap justify-end gap-3 pt-2">
                            @if ($isMaintenance)
                                <a href="{{ route('setup.owner') }}" class="setup-btn-ghost">
                                    Ignorer cette étape
                                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                                </a>
                            @endif
                            <button type="submit" class="setup-btn-primary">
                                Continuer
                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                            </button>
                        </div>
                    </form>
                </div>

                {{-- ═══════════════════════════════════════════════════════════
                     STEP: OWNER
                ════════════════════════════════════════════════════════════ --}}
                @elseif ($step === 'owner')
                <div>
                    <p class="text-xs font-semibold uppercase tracking-widest" style="color:var(--brand)">Étape 2 / 6</p>
                    <h1 class="mt-1.5 text-2xl font-semibold tracking-tight text-slate-900">Compte propriétaire</h1>
                    <p class="mt-1.5 text-sm leading-6 text-slate-500">
                        {{ $isMaintenance ? 'Modifiez le nom ou l\'email. Laissez le mot de passe vide pour ne pas le changer.' : 'Ce compte est le super-administrateur. Il ne peut pas être restreint.' }}
                    </p>

                    <form action="{{ route('setup.owner.save') }}" method="POST" class="mt-7 space-y-4">
                        @csrf
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <label class="setup-label" for="oname">Nom complet *</label>
                                <input id="oname" name="name" type="text" required
                                       value="{{ old('name', $data['name'] ?? '') }}"
                                       class="setup-input" placeholder="Ex: Karim Benali">
                            </div>
                            <div class="sm:col-span-2">
                                <label class="setup-label" for="oemail">Email *</label>
                                <input id="oemail" name="email" type="email" required
                                       value="{{ old('email', $data['email'] ?? '') }}"
                                       class="setup-input" placeholder="proprietaire@boutique.ma">
                            </div>
                            @if ($isMaintenance)
                            <div class="sm:col-span-2 rounded-xl border border-slate-200 bg-slate-50 p-4">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <p class="text-sm font-semibold text-slate-900">Mot de passe inchangé</p>
                                        <p class="mt-1 text-xs leading-5 text-slate-500">Les champs sont désactivés pour éviter l’autofill navigateur. Activez-les seulement si vous voulez vraiment changer le mot de passe owner.</p>
                                    </div>
                                    <button type="button" id="enable-password-change" class="setup-pill">
                                        Changer le mot de passe
                                    </button>
                                </div>
                            </div>
                            @endif
                            <div>
                                <label class="setup-label" for="password">
                                    Mot de passe {{ $isMaintenance ? '(laisser vide = inchangé)' : '*' }}
                                </label>
                                <input id="password" name="password" type="password"
                                       {{ $isMaintenance ? 'disabled autocomplete=new-password' : 'required autocomplete=new-password' }} minlength="8"
                                       class="setup-input" placeholder="{{ $isMaintenance ? 'Laisser vide si inchangé' : 'Minimum 8 caractères' }}">
                            </div>
                            <div>
                                <label class="setup-label" for="password_confirmation">Confirmer</label>
                                <input id="password_confirmation" name="password_confirmation" type="password"
                                       {{ $isMaintenance ? 'disabled autocomplete=new-password' : 'autocomplete=new-password' }}
                                       class="setup-input" placeholder="Répétez le mot de passe">
                            </div>
                        </div>
                        <div class="rounded-xl px-4 py-3 text-xs" style="background:rgba(49,87,213,.05); border:1px solid rgba(49,87,213,.15); color:var(--brand)">
                            <strong class="font-semibold">Rôle Owner :</strong> accès illimité, protégé contre la modification par d'autres utilisateurs.
                        </div>
                        <div class="flex flex-wrap justify-between gap-3 pt-2">
                            <a href="{{ route('setup.store') }}" class="setup-btn-ghost">
                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                                Retour
                            </a>
                            <div class="flex flex-wrap justify-end gap-3">
                                @if ($isMaintenance)
                                    <a href="{{ route('setup.locations') }}" class="setup-btn-ghost">
                                        Ignorer cette étape
                                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                                    </a>
                                @endif
                                <button type="submit" class="setup-btn-primary">
                                    Continuer
                                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                {{-- ═══════════════════════════════════════════════════════════
                     STEP: LOCATIONS
                ════════════════════════════════════════════════════════════ --}}
                @elseif ($step === 'locations')
                <div>
                    <p class="text-xs font-semibold uppercase tracking-widest" style="color:var(--brand)">Étape 3 / 6</p>
                    <h1 class="mt-1.5 text-2xl font-semibold tracking-tight text-slate-900">Magasins & dépôts</h1>
                    <p class="mt-1.5 text-sm leading-6 text-slate-500">
                        {{ $isMaintenance ? 'Les magasins existants sont modifiables. Ajoutez-en de nouveaux. Aucun magasin existant ne sera supprimé.' : 'Définissez au moins un point de vente. D\'autres pourront être ajoutés plus tard.' }}
                    </p>

                    <form action="{{ route('setup.locations.save') }}" method="POST" class="mt-7" id="locations-form">
                        @csrf
                        <div id="locations-list" class="space-y-3">
                            @php $locs = old('locations', $data ?? [['name'=>'','address'=>'','phone'=>'']]); @endphp
                            @foreach ($locs as $i => $loc)
                            <div class="location-item rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                                <div class="mb-3 flex items-center justify-between">
                                    <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400">{{ $i === 0 ? 'Magasin principal' : 'Magasin ' . ($i + 1) }}</span>
                                    @if ($i > 0)
                                        <button type="button" onclick="this.closest('.location-item').remove(); reindexLocations()" class="grid size-6 place-items-center rounded-lg border border-rose-200 text-rose-500 transition hover:bg-rose-50">
                                            <svg class="size-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                        </button>
                                    @endif
                                </div>
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div class="sm:col-span-2">
                                        <label class="setup-label">Nom *</label>
                                        <input name="locations[{{ $i }}][name]" type="text" required
                                               value="{{ $loc['name'] ?? '' }}"
                                               class="setup-input" placeholder="Ex: Magasin Centre-ville">
                                    </div>
                                    <div>
                                        <label class="setup-label">Adresse</label>
                                        <input name="locations[{{ $i }}][address]" type="text"
                                               value="{{ $loc['address'] ?? '' }}"
                                               class="setup-input" placeholder="Rue, Ville">
                                    </div>
                                    <div>
                                        <label class="setup-label">Téléphone</label>
                                        <input name="locations[{{ $i }}][phone]" type="tel"
                                               value="{{ $loc['phone'] ?? '' }}"
                                               class="setup-input" placeholder="+212 ...">
                                    </div>
                                </div>
                            </div>
                            @endforeach
                        </div>
                        <button type="button" id="add-location"
                                class="mt-3 flex w-full items-center justify-center gap-2 rounded-xl border border-dashed border-slate-300 py-3 text-sm font-semibold text-slate-500 transition hover:border-[var(--brand)] hover:text-[var(--brand)]">
                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                            Ajouter un magasin
                        </button>
                        <div class="mt-6 flex flex-wrap justify-between gap-3">
                            <a href="{{ route('setup.owner') }}" class="setup-btn-ghost">
                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                                Retour
                            </a>
                            <div class="flex flex-wrap justify-end gap-3">
                                @if ($isMaintenance)
                                    <a href="{{ route('setup.categories') }}" class="setup-btn-ghost">
                                        Ignorer cette étape
                                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                                    </a>
                                @endif
                                <button type="submit" class="setup-btn-primary">
                                    Continuer
                                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                {{-- ═══════════════════════════════════════════════════════════
                     STEP: CATEGORIES
                ════════════════════════════════════════════════════════════ --}}
                @elseif ($step === 'categories')
                <div>
                    <p class="text-xs font-semibold uppercase tracking-widest" style="color:var(--brand)">Étape 4 / 6</p>
                    <h1 class="mt-1.5 text-2xl font-semibold tracking-tight text-slate-900">Catégories de produits</h1>
                    <p class="mt-1.5 text-sm leading-6 text-slate-500">
                        {{ $isMaintenance ? 'Liste actuelle des catégories. Les catégories retirées seront supprimées, les nouvelles seront créées.' : 'Pré-remplissez les catégories principales. Optionnel — modifiable plus tard.' }}
                    </p>

                    <form action="{{ route('setup.categories.save') }}" method="POST" class="mt-7">
                        @csrf
                        <div class="mb-5 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Démarrage rapide</p>
                                    <p class="mt-1 text-sm text-slate-600">Chargez les catégories standards selon l’activité.</p>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    @foreach (['library', 'restaurant', 'coffee'] as $presetMode)
                                        @php $preset = \App\Support\BusinessMode::get($presetMode); @endphp
                                        <button type="button"
                                                class="setup-pill transition hover:border-indigo-200 hover:bg-indigo-50 hover:text-indigo-700"
                                                data-category-preset="{{ $preset['key'] }}">
                                            {{ $preset['short_label'] }}
                                        </button>
                                    @endforeach
                                </div>
                            </div>
                        </div>

                        <div id="cats-list" class="space-y-2">
                            @php $cats = old('categories', count($data ?? []) ? $data : ['']); @endphp
                            @foreach ($cats as $i => $cat)
                            <div class="cat-item flex items-center gap-2">
                                <input name="categories[{{ $i }}]" type="text"
                                       value="{{ $cat }}"
                                       class="setup-input" placeholder="Ex: Romans, Scolaire, BD…">
                                @if ($i > 0)
                                    <button type="button" onclick="this.closest('.cat-item').remove()" class="grid size-9 shrink-0 place-items-center rounded-xl border border-rose-200 text-rose-500 transition hover:bg-rose-50">
                                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                    </button>
                                @else
                                    <div class="size-9 shrink-0"></div>
                                @endif
                            </div>
                            @endforeach
                        </div>
                        <button type="button" id="add-cat"
                                class="mt-3 flex items-center gap-2 text-sm font-semibold transition hover:brightness-110" style="color:var(--brand)">
                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                            Ajouter une catégorie
                        </button>
                        <div class="mt-6 flex flex-wrap justify-between gap-3">
                            <a href="{{ route('setup.locations') }}" class="setup-btn-ghost">
                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                                Retour
                            </a>
                            <div class="flex flex-wrap justify-end gap-3">
                                @if ($isMaintenance)
                                    <a href="{{ route('setup.devices') }}" class="setup-btn-ghost">
                                        Ignorer cette étape
                                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                                    </a>
                                @endif
                                <button type="submit" class="setup-btn-primary">
                                    Continuer
                                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                {{-- ═══════════════════════════════════════════════════════════
                     STEP: DEVICES
                ════════════════════════════════════════════════════════════ --}}
                @elseif ($step === 'devices')
                <div>
                    <p class="text-xs font-semibold uppercase tracking-widest" style="color:var(--brand)">Étape 5 / 6</p>
                    <h1 class="mt-1.5 text-2xl font-semibold tracking-tight text-slate-900">Appareils virtuels</h1>
                    <p class="mt-1.5 text-sm leading-6 text-slate-500">
                        Activez les terminaux virtuels pour tracer chaque vente par caisse/appareil. Par défaut, le déploiement crée 2 caisses web et 3 terminaux mobiles.
                    </p>

                    <form action="{{ route('setup.devices.save') }}" method="POST" class="mt-7 space-y-5">
                        @csrf
                        @php
                            $deviceData = old('devices', $data['devices'] ?? []);
                            $devicesEnabled = old('enabled', ($data['enabled'] ?? true) ? '1' : '0') === '1' || old('enabled', $data['enabled'] ?? true) === true;
                            $deviceTypes = ['computer' => 'Web / ordinateur', 'mobile' => 'Mobile', 'tablet' => 'Tablette', 'other' => 'Autre'];
                        @endphp
                        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                            <label class="flex flex-wrap items-center justify-between gap-4">
                                <span>
                                    <span class="block text-sm font-semibold text-slate-900">Activer les appareils virtuels</span>
                                    <span class="mt-1 block text-xs leading-5 text-slate-500">Si activé, la caisse web/mobile demandera un terminal avant les ventes et actions POS auditables.</span>
                                </span>
                                <span class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-700">
                                    <input type="hidden" name="enabled" value="0">
                                    <input id="devices-enabled" type="checkbox" name="enabled" value="1" class="size-4 accent-[#3157D5]" @checked($devicesEnabled)>
                                    Activé
                                </span>
                            </label>
                        </div>

                        <div id="devices-list" class="space-y-3">
                            @foreach ($deviceData as $i => $device)
                            <div class="device-item rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                                <div class="mb-3 flex items-center justify-between">
                                    <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Appareil {{ $i + 1 }}</span>
                                    @if ($i > 0)
                                        <button type="button" onclick="this.closest('.device-item').remove(); reindexDevices()" class="grid size-6 place-items-center rounded-lg border border-rose-200 text-rose-500 transition hover:bg-rose-50">
                                            <svg class="size-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                        </button>
                                    @endif
                                </div>
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div>
                                        <label class="setup-label">Nom *</label>
                                        <input name="devices[{{ $i }}][name]" type="text" value="{{ $device['name'] ?? '' }}" class="setup-input" placeholder="Ex: Caisse Web 1">
                                    </div>
                                    <div>
                                        <label class="setup-label">Code</label>
                                        <input name="devices[{{ $i }}][code]" type="text" value="{{ $device['code'] ?? '' }}" class="setup-input" placeholder="web-pos-01">
                                    </div>
                                    <div>
                                        <label class="setup-label">Type</label>
                                        <select name="devices[{{ $i }}][type]" class="setup-input">
                                            @foreach ($deviceTypes as $typeKey => $typeLabel)
                                                <option value="{{ $typeKey }}" @selected(($device['type'] ?? 'other') === $typeKey)>{{ $typeLabel }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <label class="flex items-end gap-2 pb-3 text-sm font-semibold text-slate-700">
                                        <input type="hidden" name="devices[{{ $i }}][is_active]" value="0">
                                        <input type="checkbox" name="devices[{{ $i }}][is_active]" value="1" class="size-4 accent-[#3157D5]" @checked($device['is_active'] ?? true)>
                                        Appareil actif
                                    </label>
                                    <div class="sm:col-span-2">
                                        <label class="setup-label">Description</label>
                                        <input name="devices[{{ $i }}][description]" type="text" value="{{ $device['description'] ?? '' }}" class="setup-input" placeholder="Ex: Terminal navigateur principal">
                                    </div>
                                </div>
                            </div>
                            @endforeach
                        </div>

                        <button type="button" id="add-device"
                                class="flex w-full items-center justify-center gap-2 rounded-xl border border-dashed border-slate-300 py-3 text-sm font-semibold text-slate-500 transition hover:border-[var(--brand)] hover:text-[var(--brand)]">
                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                            Ajouter un appareil
                        </button>

                        <div class="mt-6 flex flex-wrap justify-between gap-3">
                            <a href="{{ route('setup.categories') }}" class="setup-btn-ghost">
                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                                Retour
                            </a>
                            <div class="flex flex-wrap justify-end gap-3">
                                @if ($isMaintenance)
                                    <a href="{{ route('setup.review') }}" class="setup-btn-ghost">
                                        Ignorer cette étape
                                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                                    </a>
                                @endif
                                <button type="submit" class="setup-btn-primary">
                                    Continuer
                                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                {{-- ═══════════════════════════════════════════════════════════
                     STEP: REVIEW
                ════════════════════════════════════════════════════════════ --}}
                @elseif ($step === 'review')
                <div>
                    <p class="text-xs font-semibold uppercase tracking-widest" style="color:var(--brand)">Étape 6 / 6</p>
                    <h1 class="mt-1.5 text-2xl font-semibold tracking-tight text-slate-900">
                        {{ $isMaintenance ? 'Récapitulatif des modifications' : 'Confirmation' }}
                    </h1>
                    <p class="mt-1.5 text-sm leading-6 text-slate-500">
                        {{ $isMaintenance ? 'Vérifiez les changements avant d\'appliquer.' : 'Vérifiez les informations avant de lancer la configuration.' }}
                    </p>

                    <div class="mt-7 space-y-3">
                        {{-- Store --}}
                        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                            <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Boutique</p>
                                <a href="{{ route('setup.store') }}" class="text-xs font-semibold hover:underline" style="color:var(--brand)">Modifier</a>
                            </div>
                            <div class="grid gap-x-6 gap-y-2.5 p-4 text-sm sm:grid-cols-2">
                                <div><span class="text-slate-500">Nom</span><br><strong class="text-slate-900">{{ $store['name'] ?? '—' }}</strong></div>
                                <div><span class="text-slate-500">Activité</span><br><strong class="text-slate-900">{{ \App\Support\BusinessMode::get($store['business_mode'] ?? null)['label'] }}</strong></div>
                                <div><span class="text-slate-500">Devise</span><br><strong class="text-slate-900">{{ $store['currency'] ?? '—' }}</strong></div>
                                <div><span class="text-slate-500">Fuseau</span><br><strong class="text-slate-900">{{ $store['timezone'] ?? '—' }}</strong></div>
                                <div><span class="text-slate-500">Langue</span><br><strong class="text-slate-900">{{ $store['language'] === 'ar' ? 'العربية' : 'Français' }}</strong></div>
                                @if (!empty($store['email'])) <div><span class="text-slate-500">Email</span><br><strong class="text-slate-900">{{ $store['email'] }}</strong></div> @endif
                                @if (!empty($store['phone'])) <div><span class="text-slate-500">Téléphone</span><br><strong class="text-slate-900">{{ $store['phone'] }}</strong></div> @endif
                                @if (!empty($store['address'])) <div class="sm:col-span-2"><span class="text-slate-500">Adresse</span><br><strong class="text-slate-900">{{ $store['address'] }}</strong></div> @endif
                            </div>
                        </div>

                        {{-- Owner --}}
                        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                            <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Propriétaire</p>
                                <a href="{{ route('setup.owner') }}" class="text-xs font-semibold hover:underline" style="color:var(--brand)">Modifier</a>
                            </div>
                            <div class="grid gap-x-6 gap-y-2.5 p-4 text-sm sm:grid-cols-2">
                                <div><span class="text-slate-500">Nom</span><br><strong class="text-slate-900">{{ $owner['name'] ?? '—' }}</strong></div>
                                <div><span class="text-slate-500">Email</span><br><strong class="text-slate-900">{{ $owner['email'] ?? '—' }}</strong></div>
                                <div><span class="text-slate-500">Mot de passe</span><br><strong class="text-slate-900">{{ empty($owner['password']) ? 'Inchangé' : '●●●●●●●●' }}</strong></div>
                                <div><span class="text-slate-500">Rôle</span><br><strong style="color:var(--brand)">Owner (système)</strong></div>
                            </div>
                        </div>

                        {{-- Locations --}}
                        @if (count($locations))
                        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                            <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Magasins ({{ count($locations) }})</p>
                                <a href="{{ route('setup.locations') }}" class="text-xs font-semibold hover:underline" style="color:var(--brand)">Modifier</a>
                            </div>
                            <ul class="divide-y divide-slate-100 px-4 text-sm">
                                @foreach ($locations as $idx => $loc)
                                <li class="py-3 flex items-baseline gap-2">
                                    <strong class="text-slate-900">{{ $loc['name'] }}</strong>
                                    @if ($idx === 0) <span class="rounded-full px-2 py-0.5 text-[10px] font-bold text-white" style="background:var(--brand)">Principal</span> @endif
                                    @if (!empty($loc['address'])) <span class="text-slate-500">· {{ $loc['address'] }}</span> @endif
                                </li>
                                @endforeach
                            </ul>
                        </div>
                        @endif

                        {{-- Categories --}}
                        @if (count($categories))
                        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                            <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Catégories ({{ count($categories) }})</p>
                                <a href="{{ route('setup.categories') }}" class="text-xs font-semibold hover:underline" style="color:var(--brand)">Modifier</a>
                            </div>
                            <div class="flex flex-wrap gap-2 p-4">
                                @foreach ($categories as $cat)
                                <span class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-700">{{ $cat }}</span>
                                @endforeach
                            </div>
                        </div>
                        @endif

                        {{-- Virtual devices --}}
                        @php
                            $reviewDevicesEnabled = (bool) ($devices['enabled'] ?? true);
                            $reviewDevices = $devices['devices'] ?? [];
                        @endphp
                        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                            <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Appareils virtuels</p>
                                <a href="{{ route('setup.devices') }}" class="text-xs font-semibold hover:underline" style="color:var(--brand)">Modifier</a>
                            </div>
                            <div class="p-4 text-sm">
                                <div class="mb-3 flex flex-wrap items-center gap-2">
                                    <span class="rounded-full px-3 py-1 text-xs font-bold {{ $reviewDevicesEnabled ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">
                                        {{ $reviewDevicesEnabled ? 'Activé' : 'Désactivé' }}
                                    </span>
                                    <span class="text-slate-500">{{ count($reviewDevices) }} appareil(s) configuré(s)</span>
                                </div>
                                @if ($reviewDevicesEnabled && count($reviewDevices))
                                    <div class="grid gap-2 sm:grid-cols-2">
                                        @foreach ($reviewDevices as $device)
                                            <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
                                                <p class="font-semibold text-slate-900">{{ $device['name'] ?? 'Appareil' }}</p>
                                                <p class="mt-0.5 text-xs text-slate-500">{{ $device['code'] ?? '—' }} · {{ $device['type'] ?? 'other' }} · {{ ($device['is_active'] ?? true) ? 'actif' : 'désactivé' }}</p>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </div>

                        @if (!$isMaintenance)
                        <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-xs text-slate-500">
                            Créés automatiquement : <strong class="text-slate-700">4 rôles</strong>, <strong class="text-slate-700">4 unités</strong>, <strong class="text-slate-700">3 taxes</strong>, <strong class="text-slate-700">4 modes de paiement</strong>, <strong class="text-slate-700">1 compte caisse</strong>, <strong class="text-slate-700">5 appareils virtuels</strong>.
                        </div>
                        @endif
                    </div>

                    <form action="{{ route('setup.commit') }}" method="POST" class="mt-5">
                        @csrf
                        <div class="flex justify-between gap-3">
                            <a href="{{ route('setup.devices') }}" class="setup-btn-ghost">
                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                                Retour
                            </a>
                            <button type="submit" class="{{ $isMaintenance ? 'setup-btn-primary' : 'setup-btn-success' }}">
                                @if ($isMaintenance)
                                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v14a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                                    Enregistrer les modifications
                                @else
                                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                    Lancer la configuration
                                @endif
                            </button>
                        </div>
                    </form>
                </div>

                {{-- ═══════════════════════════════════════════════════════════
                     STEP: DONE
                ════════════════════════════════════════════════════════════ --}}
                @elseif ($step === 'done')
                <div class="py-4 text-center">
                    <div class="mx-auto grid size-16 place-items-center rounded-full" style="background:{{ $isMaintenance ? 'rgba(49,87,213,.1)' : 'rgba(5,150,105,.1)' }}">
                        <svg class="size-8" style="color:{{ $isMaintenance ? 'var(--brand)' : '#059669' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            @if ($isMaintenance)
                                <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v14a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/>
                            @else
                                <polyline points="20 6 9 17 4 12"/>
                            @endif
                        </svg>
                    </div>
                    <h1 class="mt-5 text-2xl font-semibold tracking-tight text-slate-900">
                        {{ $isMaintenance ? 'Modifications enregistrées' : 'Configuration terminée !' }}
                    </h1>
                    <p class="mx-auto mt-2 max-w-sm text-sm leading-6 text-slate-500">
                        {{ $isMaintenance ? 'Les données du tenant ont été mises à jour avec succès.' : 'Le tenant a été créé avec succès. Connectez-vous avec les identifiants du propriétaire.' }}
                    </p>

                    <div class="mx-auto mt-7 max-w-sm rounded-xl border border-slate-200 bg-white p-4 text-left shadow-sm">
                        <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Que faire maintenant ?</p>
                        <ul class="mt-3 space-y-2 text-sm text-slate-700">
                            @if ($isMaintenance)
                                <li class="flex items-start gap-2.5">
                                    <span class="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full text-[10px] font-bold text-white" style="background:var(--brand)">1</span>
                                    Vérifiez les changements en vous reconnectant au tableau de bord
                                </li>
                                <li class="flex items-start gap-2.5">
                                    <span class="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full text-[10px] font-bold text-white" style="background:var(--brand)">2</span>
                                    Revenez sur cette page à tout moment pour d'autres modifications
                                </li>
                            @else
                                <li class="flex items-start gap-2.5">
                                    <span class="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full text-[10px] font-bold text-white" style="background:var(--brand)">1</span>
                                    Connectez-vous avec l'email et le mot de passe du propriétaire
                                </li>
                                <li class="flex items-start gap-2.5">
                                    <span class="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full text-[10px] font-bold text-white" style="background:var(--brand)">2</span>
                                    Personnalisez le thème dans Paramètres → Apparence
                                </li>
                                <li class="flex items-start gap-2.5">
                                    <span class="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full text-[10px] font-bold text-white" style="background:var(--brand)">3</span>
                                    Invitez votre équipe et configurez leurs rôles
                                </li>
                            @endif
                        </ul>
                    </div>

                    <div class="mt-7 flex flex-wrap justify-center gap-3">
                        <a href="{{ route('login') }}" class="setup-btn-primary">
                            Se connecter
                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                        </a>
                        <a href="{{ route('setup.index') }}" class="setup-btn-ghost">
                            Retour à la maintenance
                        </a>
                    </div>
                </div>
                @endif

            </div>
        </div>
    </main>
</div>

<script>
var categoryPresets = @json($categoryPresets);

var enablePasswordButton = document.getElementById('enable-password-change');
if (enablePasswordButton) {
    enablePasswordButton.addEventListener('click', function() {
        ['password', 'password_confirmation'].forEach(function(id) {
            var input = document.getElementById(id);
            if (!input) return;
            input.disabled = false;
            input.value = '';
        });
        enablePasswordButton.textContent = 'Changement activé';
        enablePasswordButton.disabled = true;
        var password = document.getElementById('password');
        if (password) password.focus();
    });
}

function updateBusinessPresetState(mode) {
    document.querySelectorAll('[data-select-business-mode]').forEach(function(button) {
        var selected = button.getAttribute('data-select-business-mode') === mode;
        button.classList.toggle('is-selected', selected);

        if (selected) {
            var catalog = document.querySelector('[data-preview-catalog]');
            var primary = document.querySelector('[data-preview-primary]');
            var labels = document.querySelector('[data-preview-labels]');
            if (catalog) catalog.textContent = button.getAttribute('data-catalog-label') || '';
            if (primary) primary.textContent = button.getAttribute('data-primary-item') || '';
            if (labels) labels.textContent = (button.getAttribute('data-book-label') || '') + ' / ' + (button.getAttribute('data-supply-label') || '');
        }
    });
}

var businessModeSelect = document.getElementById('business_mode');
if (businessModeSelect) {
    businessModeSelect.addEventListener('change', function() {
        updateBusinessPresetState(businessModeSelect.value);
    });
    updateBusinessPresetState(businessModeSelect.value);
}

document.querySelectorAll('[data-select-business-mode]').forEach(function(button) {
    button.addEventListener('click', function() {
        var select = document.getElementById('business_mode');
        if (!select) return;
        select.value = button.getAttribute('data-select-business-mode');
        select.dispatchEvent(new Event('change', { bubbles: true }));
        select.focus();
    });
});

function reindexLocations() {
    document.querySelectorAll('.location-item').forEach(function(item, i) {
        var label = item.querySelector('.text-slate-400');
        if (label) label.textContent = i === 0 ? 'Magasin principal' : 'Magasin ' + (i + 1);
        item.querySelectorAll('input').forEach(function(inp) {
            inp.name = inp.name.replace(/locations\[\d+\]/, 'locations[' + i + ']');
        });
    });
}

function reindexDevices() {
    document.querySelectorAll('.device-item').forEach(function(item, i) {
        var label = item.querySelector('.text-slate-400');
        if (label) label.textContent = 'Appareil ' + (i + 1);
        item.querySelectorAll('input, select').forEach(function(inp) {
            inp.name = inp.name.replace(/devices\[\d+\]/, 'devices[' + i + ']');
        });
    });
}

var addLocBtn = document.getElementById('add-location');
if (addLocBtn) {
    addLocBtn.addEventListener('click', function() {
        var list = document.getElementById('locations-list');
        var idx  = list.querySelectorAll('.location-item').length;
        var cls  = 'setup-input';
        var lbl  = 'setup-label';
        var item = document.createElement('div');
        item.className = 'location-item rounded-xl border border-slate-200 bg-white p-4 shadow-sm';
        item.innerHTML =
            '<div class="mb-3 flex items-center justify-between">'
            + '<span class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Magasin ' + (idx + 1) + '</span>'
            + '<button type="button" onclick="this.closest(\'.location-item\').remove(); reindexLocations()" class="grid size-6 place-items-center rounded-lg border border-rose-200 text-rose-500 transition hover:bg-rose-50">'
            + '<svg class="size-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>'
            + '</button></div>'
            + '<div class="grid gap-3 sm:grid-cols-2">'
            + '<div class="sm:col-span-2"><label class="' + lbl + '">Nom *</label><input name="locations[' + idx + '][name]" type="text" required class="' + cls + '" placeholder="Ex: Magasin Agdal"></div>'
            + '<div><label class="' + lbl + '">Adresse</label><input name="locations[' + idx + '][address]" type="text" class="' + cls + '" placeholder="Rue, Ville"></div>'
            + '<div><label class="' + lbl + '">Téléphone</label><input name="locations[' + idx + '][phone]" type="tel" class="' + cls + '" placeholder="+212 ..."></div>'
            + '</div>';
        list.appendChild(item);
        item.querySelector('input').focus();
    });
}

var addCatBtn = document.getElementById('add-cat');
if (addCatBtn) {
    addCatBtn.addEventListener('click', function() {
        var list = document.getElementById('cats-list');
        var idx  = list.querySelectorAll('.cat-item').length;
        var cls  = 'setup-input';
        var item = document.createElement('div');
        item.className = 'cat-item flex items-center gap-2';
        item.innerHTML =
            '<input name="categories[' + idx + ']" type="text" class="' + cls + '" placeholder="Ex: Romans, Scolaire…">'
            + '<button type="button" onclick="this.closest(\'.cat-item\').remove()" class="grid size-9 shrink-0 place-items-center rounded-xl border border-rose-200 text-rose-500 transition hover:bg-rose-50">'
            + '<svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>'
            + '</button>';
        list.appendChild(item);
        item.querySelector('input').focus();
    });
}

var addDeviceBtn = document.getElementById('add-device');
if (addDeviceBtn) {
    addDeviceBtn.addEventListener('click', function() {
        var list = document.getElementById('devices-list');
        var idx = list.querySelectorAll('.device-item').length;
        var item = document.createElement('div');
        item.className = 'device-item rounded-xl border border-slate-200 bg-white p-4 shadow-sm';
        item.innerHTML =
            '<div class="mb-3 flex items-center justify-between">'
            + '<span class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Appareil ' + (idx + 1) + '</span>'
            + '<button type="button" onclick="this.closest(\'.device-item\').remove(); reindexDevices()" class="grid size-6 place-items-center rounded-lg border border-rose-200 text-rose-500 transition hover:bg-rose-50">'
            + '<svg class="size-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>'
            + '</button></div>'
            + '<div class="grid gap-3 sm:grid-cols-2">'
            + '<div><label class="setup-label">Nom *</label><input name="devices[' + idx + '][name]" type="text" class="setup-input" placeholder="Ex: Mobile POS 4"></div>'
            + '<div><label class="setup-label">Code</label><input name="devices[' + idx + '][code]" type="text" class="setup-input" placeholder="mobile-pos-04"></div>'
            + '<div><label class="setup-label">Type</label><select name="devices[' + idx + '][type]" class="setup-input"><option value="computer">Web / ordinateur</option><option value="mobile" selected>Mobile</option><option value="tablet">Tablette</option><option value="other">Autre</option></select></div>'
            + '<label class="flex items-end gap-2 pb-3 text-sm font-semibold text-slate-700"><input type="hidden" name="devices[' + idx + '][is_active]" value="0"><input type="checkbox" name="devices[' + idx + '][is_active]" value="1" class="size-4 accent-[#3157D5]" checked> Appareil actif</label>'
            + '<div class="sm:col-span-2"><label class="setup-label">Description</label><input name="devices[' + idx + '][description]" type="text" class="setup-input" placeholder="Terminal mobile supplémentaire"></div>'
            + '</div>';
        list.appendChild(item);
        item.querySelector('input').focus();
    });
}

function renderCategories(categories) {
    var list = document.getElementById('cats-list');
    if (!list) return;

    var cls = 'setup-input';
    list.innerHTML = '';

    (categories.length ? categories : ['']).forEach(function(category, idx) {
        var item = document.createElement('div');
        item.className = 'cat-item flex items-center gap-2';
        item.innerHTML =
            '<input name="categories[' + idx + ']" type="text" class="' + cls + '" placeholder="Ex: Romans, Scolaire…" value="">'
            + (idx > 0
                ? '<button type="button" onclick="this.closest(\'.cat-item\').remove()" class="grid size-9 shrink-0 place-items-center rounded-xl border border-rose-200 text-rose-500 transition hover:bg-rose-50"><svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>'
                : '<div class="size-9 shrink-0"></div>');
        list.appendChild(item);
        item.querySelector('input').value = category;
    });
}

document.querySelectorAll('[data-category-preset]').forEach(function(button) {
    button.addEventListener('click', function() {
        var mode = button.getAttribute('data-category-preset');
        renderCategories(categoryPresets[mode] || []);
    });
});
</script>
</body>
</html>
