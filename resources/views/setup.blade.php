@php
    $isMaintenance = $isMaintenance ?? false;

    $steps = [
        'store'      => ['num' => 1, 'label' => 'Boutique'],
        'owner'      => ['num' => 2, 'label' => 'Propriétaire'],
        'locations'  => ['num' => 3, 'label' => 'Magasins'],
        'categories' => ['num' => 4, 'label' => 'Catégories'],
        'review'     => ['num' => 5, 'label' => $isMaintenance ? 'Mise à jour' : 'Confirmation'],
        'done'       => ['num' => 6, 'label' => 'Terminé'],
    ];

    $orderedSteps = ['store', 'owner', 'locations', 'categories', 'review', 'done'];
    $currentNum   = $steps[$step]['num'] ?? 0;

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
        :root { --brand: #3157D5; }
        .setup-input {
            @apply h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-900 outline-none transition placeholder:text-slate-400;
            @apply focus:border-[var(--brand)] focus:ring-2 focus:ring-[var(--brand)]/10;
        }
        .setup-label { @apply block text-xs font-semibold uppercase tracking-wider text-slate-500 mb-1.5; }
        .setup-btn-primary { @apply inline-flex items-center gap-2 rounded-xl bg-[var(--brand)] px-5 py-2.5 text-sm font-semibold text-white shadow-sm shadow-indigo-500/20 transition hover:brightness-110 active:scale-[0.98]; }
        .setup-btn-ghost   { @apply inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50; }
        .setup-btn-success { @apply inline-flex items-center gap-2 rounded-xl px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:brightness-110 active:scale-[0.98]; background:#059669; box-shadow:0 1px 4px rgba(5,150,105,.25); }
        .step-done    { background:#10b981; color:#fff; }
        .step-active  { background:var(--brand); color:#fff; }
        .step-pending { background:rgba(255,255,255,.1); color:rgba(255,255,255,.35); }
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
                        Accès permanent via SETUP_SECRET. Les données affichées reflètent l'état actuel en base.
                    @else
                        Cette session crée la configuration initiale du tenant. Accessible à tout moment pour maintenance.
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
            <div class="w-full max-w-xl">

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
                        <div class="grid size-12 shrink-0 place-items-center rounded-2xl" style="background:var(--brand)/10; border:1px solid rgba(49,87,213,.15)">
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

                    <form action="{{ route('setup.secret') }}" method="POST" class="space-y-5">
                        @csrf
                        <div>
                            <label class="setup-label" for="secret">Code secret de maintenance</label>
                            <input id="secret" name="secret" type="password" autocomplete="off" required autofocus
                                   class="setup-input @error('secret') border-rose-400 ring-2 ring-rose-400/10 @enderror"
                                   placeholder="••••••••••••">
                            @error('secret')<p class="mt-1.5 text-xs font-semibold text-rose-600">{{ $message }}</p>@enderror
                        </div>
                        <button type="submit" class="setup-btn-primary w-full justify-center py-3 text-base">
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
                    <p class="text-xs font-semibold uppercase tracking-widest" style="color:var(--brand)">Étape 1 / 5</p>
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
                                    <option value="bookstore" @selected(old('business_mode', $data['business_mode'] ?? 'bookstore') === 'bookstore')>Librairie / Livres</option>
                                    <option value="retail"    @selected(old('business_mode', $data['business_mode'] ?? '') === 'retail')>Commerce de détail</option>
                                    <option value="service"   @selected(old('business_mode', $data['business_mode'] ?? '') === 'service')>Services</option>
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
                        <div class="flex justify-end pt-2">
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
                    <p class="text-xs font-semibold uppercase tracking-widest" style="color:var(--brand)">Étape 2 / 5</p>
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
                            <div>
                                <label class="setup-label" for="password">
                                    Mot de passe {{ $isMaintenance ? '(laisser vide = inchangé)' : '*' }}
                                </label>
                                <input id="password" name="password" type="password"
                                       {{ $isMaintenance ? '' : 'required' }} minlength="8"
                                       class="setup-input" placeholder="{{ $isMaintenance ? 'Laisser vide si inchangé' : 'Minimum 8 caractères' }}">
                            </div>
                            <div>
                                <label class="setup-label" for="password_confirmation">Confirmer</label>
                                <input id="password_confirmation" name="password_confirmation" type="password"
                                       class="setup-input" placeholder="Répétez le mot de passe">
                            </div>
                        </div>
                        <div class="rounded-xl px-4 py-3 text-xs" style="background:rgba(49,87,213,.05); border:1px solid rgba(49,87,213,.15); color:var(--brand)">
                            <strong class="font-semibold">Rôle Owner :</strong> accès illimité, protégé contre la modification par d'autres utilisateurs.
                        </div>
                        <div class="flex justify-between gap-3 pt-2">
                            <a href="{{ route('setup.store') }}" class="setup-btn-ghost">
                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                                Retour
                            </a>
                            <button type="submit" class="setup-btn-primary">
                                Continuer
                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                            </button>
                        </div>
                    </form>
                </div>

                {{-- ═══════════════════════════════════════════════════════════
                     STEP: LOCATIONS
                ════════════════════════════════════════════════════════════ --}}
                @elseif ($step === 'locations')
                <div>
                    <p class="text-xs font-semibold uppercase tracking-widest" style="color:var(--brand)">Étape 3 / 5</p>
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
                        <div class="mt-6 flex justify-between gap-3">
                            <a href="{{ route('setup.owner') }}" class="setup-btn-ghost">
                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                                Retour
                            </a>
                            <button type="submit" class="setup-btn-primary">
                                Continuer
                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                            </button>
                        </div>
                    </form>
                </div>

                {{-- ═══════════════════════════════════════════════════════════
                     STEP: CATEGORIES
                ════════════════════════════════════════════════════════════ --}}
                @elseif ($step === 'categories')
                <div>
                    <p class="text-xs font-semibold uppercase tracking-widest" style="color:var(--brand)">Étape 4 / 5</p>
                    <h1 class="mt-1.5 text-2xl font-semibold tracking-tight text-slate-900">Catégories de produits</h1>
                    <p class="mt-1.5 text-sm leading-6 text-slate-500">
                        {{ $isMaintenance ? 'Liste actuelle des catégories. Les catégories retirées seront supprimées, les nouvelles seront créées.' : 'Pré-remplissez les catégories principales. Optionnel — modifiable plus tard.' }}
                    </p>

                    <form action="{{ route('setup.categories.save') }}" method="POST" class="mt-7">
                        @csrf
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
                        <div class="mt-6 flex justify-between gap-3">
                            <a href="{{ route('setup.locations') }}" class="setup-btn-ghost">
                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                                Retour
                            </a>
                            <button type="submit" class="setup-btn-primary">
                                Continuer
                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                            </button>
                        </div>
                    </form>
                </div>

                {{-- ═══════════════════════════════════════════════════════════
                     STEP: REVIEW
                ════════════════════════════════════════════════════════════ --}}
                @elseif ($step === 'review')
                <div>
                    <p class="text-xs font-semibold uppercase tracking-widest" style="color:var(--brand)">Étape 5 / 5</p>
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
                                <div><span class="text-slate-500">Activité</span><br><strong class="text-slate-900">{{ ucfirst($store['business_mode'] ?? '—') }}</strong></div>
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

                        @if (!$isMaintenance)
                        <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-xs text-slate-500">
                            Créés automatiquement : <strong class="text-slate-700">4 rôles</strong>, <strong class="text-slate-700">4 unités</strong>, <strong class="text-slate-700">3 taxes</strong>, <strong class="text-slate-700">4 modes de paiement</strong>, <strong class="text-slate-700">1 compte caisse</strong>.
                        </div>
                        @endif
                    </div>

                    <form action="{{ route('setup.commit') }}" method="POST" class="mt-5">
                        @csrf
                        <div class="flex justify-between gap-3">
                            <a href="{{ route('setup.categories') }}" class="setup-btn-ghost">
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
function reindexLocations() {
    document.querySelectorAll('.location-item').forEach(function(item, i) {
        var label = item.querySelector('.text-slate-400');
        if (label) label.textContent = i === 0 ? 'Magasin principal' : 'Magasin ' + (i + 1);
        item.querySelectorAll('input').forEach(function(inp) {
            inp.name = inp.name.replace(/locations\[\d+\]/, 'locations[' + i + ']');
        });
    });
}

var addLocBtn = document.getElementById('add-location');
if (addLocBtn) {
    addLocBtn.addEventListener('click', function() {
        var list = document.getElementById('locations-list');
        var idx  = list.querySelectorAll('.location-item').length;
        var cls  = 'h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-[#3157D5] focus:ring-2 focus:ring-[#3157D5]/10';
        var lbl  = 'block text-xs font-semibold uppercase tracking-wider text-slate-500 mb-1.5';
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
        var cls  = 'h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-[#3157D5] focus:ring-2 focus:ring-[#3157D5]/10';
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
</script>
</body>
</html>
