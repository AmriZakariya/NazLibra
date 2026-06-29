@php
    $steps = [
        'secret'     => ['num' => 0,  'label' => 'Accès',        'icon' => 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z'],
        'store'      => ['num' => 1,  'label' => 'Boutique',      'icon' => 'M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z M9 22V12h6v10'],
        'owner'      => ['num' => 2,  'label' => 'Propriétaire',  'icon' => 'M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2 M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z'],
        'locations'  => ['num' => 3,  'label' => 'Magasins',      'icon' => 'M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z M12 10a1 1 0 1 0 0-2 1 1 0 0 0 0 2z'],
        'categories' => ['num' => 4,  'label' => 'Catégories',    'icon' => 'M4 6h16M4 12h8m-8 6h16'],
        'review'     => ['num' => 5,  'label' => 'Confirmation',  'icon' => 'M9 11l3 3L22 4 M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11'],
        'done'       => ['num' => 6,  'label' => 'Terminé',       'icon' => 'M22 11.08V12a10 10 0 1 1-5.93-9.14 M22 4L12 14.01l-3-3'],
    ];

    $orderedSteps = ['store', 'owner', 'locations', 'categories', 'review', 'done'];
    $currentNum   = $steps[$step]['num'] ?? 0;

    $timezones = [
        'Africa/Casablanca'    => 'Casablanca (UTC+1)',
        'Africa/Cairo'         => 'Cairo (UTC+2)',
        'Africa/Tunis'         => 'Tunis (UTC+1)',
        'Africa/Algiers'       => 'Alger (UTC+1)',
        'Europe/Paris'         => 'Paris (UTC+1/+2)',
        'Europe/London'        => 'Londres (UTC+0/+1)',
        'Asia/Dubai'           => 'Dubaï (UTC+4)',
        'America/New_York'     => 'New York (UTC-5/-4)',
    ];
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Configuration · LibrairePro</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        :root {
            --brand: #3157D5;
            --brand-dark: #2445b8;
            --setup-sidebar: #0c1221;
            --setup-sidebar-border: rgba(255,255,255,0.08);
        }
        .setup-input {
            @apply h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-900 outline-none transition placeholder:text-slate-400;
            @apply focus:border-[var(--brand)] focus:ring-2 focus:ring-[var(--brand)]/12;
            @apply dark:border-white/10 dark:bg-slate-900 dark:text-white;
        }
        .setup-label {
            @apply block text-xs font-semibold uppercase tracking-wider text-slate-500;
        }
        .setup-btn-primary {
            @apply inline-flex items-center gap-2 rounded-xl bg-[var(--brand)] px-5 py-2.5 text-sm font-semibold text-white shadow-sm shadow-indigo-500/20 transition hover:brightness-110 active:scale-[0.98];
        }
        .setup-btn-ghost {
            @apply inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-white/10 dark:bg-transparent dark:text-slate-300;
        }
        .step-done   { @apply bg-emerald-500 text-white; }
        .step-active { background: var(--brand); @apply text-white; }
        .step-pending{ @apply bg-white/10 text-white/40; }
    </style>
</head>
<body class="min-h-screen bg-slate-100 antialiased">
<div class="flex min-h-screen items-stretch">

    {{-- ── Left sidebar ──────────────────────────────────────────────────────── --}}
    <aside class="hidden w-72 shrink-0 flex-col bg-[var(--setup-sidebar)] lg:flex">
        <div class="flex h-full flex-col p-8">
            {{-- Logo --}}
            <div class="flex items-center gap-3">
                <div class="grid size-10 shrink-0 place-items-center rounded-xl bg-[var(--brand)] text-sm font-black text-white shadow">LP</div>
                <div>
                    <p class="text-xs font-semibold uppercase tracking-widest text-white/40">LibrairePro</p>
                    <p class="text-sm font-semibold text-white">Configuration initiale</p>
                </div>
            </div>

            {{-- Steps --}}
            <nav class="mt-10 flex flex-col gap-1">
                @foreach ($orderedSteps as $s)
                    @php
                        $info      = $steps[$s];
                        $sNum      = $info['num'];
                        $isDone    = $sNum < $currentNum;
                        $isActive  = $s === $step;
                        $isPending = $sNum > $currentNum;
                        $stateClass = $isDone ? 'step-done' : ($isActive ? 'step-active' : 'step-pending');
                        $labelColor = $isActive ? 'text-white' : ($isDone ? 'text-white/80' : 'text-white/35');
                    @endphp
                    <div class="flex items-center gap-3 rounded-xl px-3 py-2.5 {{ $isActive ? 'bg-white/[0.07]' : '' }}">
                        <span class="grid size-7 shrink-0 place-items-center rounded-full text-xs font-bold {{ $stateClass }}">
                            @if ($isDone)
                                <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                            @else
                                {{ $sNum }}
                            @endif
                        </span>
                        <span class="text-sm font-medium {{ $labelColor }}">{{ $info['label'] }}</span>
                    </div>
                @endforeach
            </nav>

            {{-- Bottom note --}}
            <div class="mt-auto">
                <p class="text-xs leading-5 text-white/30">Cette page n'est accessible qu'une seule fois. Une fois la configuration terminée, elle sera verrouillée automatiquement.</p>
            </div>
        </div>
    </aside>

    {{-- ── Main content ──────────────────────────────────────────────────────── --}}
    <main class="flex flex-1 flex-col">
        {{-- Mobile header --}}
        <div class="flex items-center gap-3 border-b border-slate-200 bg-white px-5 py-4 lg:hidden">
            <div class="grid size-8 shrink-0 place-items-center rounded-lg bg-[var(--brand)] text-xs font-black text-white">LP</div>
            <div class="flex-1">
                <p class="text-sm font-semibold text-slate-900">Configuration · LibrairePro</p>
            </div>
            <span class="rounded-full bg-[var(--brand)]/10 px-2.5 py-0.5 text-xs font-semibold text-[var(--brand)]">
                {{ $steps[$step]['label'] ?? '' }}
            </span>
        </div>

        <div class="flex flex-1 items-start justify-center px-5 py-10 sm:px-8">
            <div class="w-full max-w-xl">

                {{-- Validation errors --}}
                @if ($errors->any())
                    <div class="mb-6 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300">
                        <p class="font-semibold">Veuillez corriger les erreurs suivantes :</p>
                        <ul class="mt-2 list-inside list-disc space-y-1">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{-- ── Step: Secret ──────────────────────────────────────────── --}}
                @if ($step === 'secret')
                <div>
                    <p class="text-xs font-semibold uppercase tracking-widest text-[var(--brand)]">Accès sécurisé</p>
                    <h1 class="mt-2 text-2xl font-semibold tracking-tight text-slate-900">Entrez le code de configuration</h1>
                    <p class="mt-2 text-sm leading-6 text-slate-500">Ce code est partagé par votre intégrateur. Il n'est demandé qu'une seule fois.</p>

                    <form action="{{ route('setup.secret') }}" method="POST" class="mt-8 space-y-5">
                        @csrf
                        <div class="space-y-1.5">
                            <label class="setup-label" for="secret">Code secret</label>
                            <input id="secret" name="secret" type="password" autocomplete="off" required
                                   class="setup-input @error('secret') border-rose-400 @enderror"
                                   placeholder="••••••••••••">
                        </div>
                        <button type="submit" class="setup-btn-primary w-full justify-center py-3">
                            Accéder à la configuration
                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                        </button>
                    </form>
                </div>

                {{-- ── Step: Store info ───────────────────────────────────────── --}}
                @elseif ($step === 'store')
                <div>
                    <p class="text-xs font-semibold uppercase tracking-widest text-[var(--brand)]">Étape 1 / 5</p>
                    <h1 class="mt-2 text-2xl font-semibold tracking-tight text-slate-900">Informations de la boutique</h1>
                    <p class="mt-2 text-sm leading-6 text-slate-500">Ces données définissent l'identité de votre tenant dans le système.</p>

                    <form action="{{ route('setup.store.save') }}" method="POST" class="mt-8 space-y-5">
                        @csrf
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="space-y-1.5 sm:col-span-2">
                                <label class="setup-label" for="name">Nom de la boutique *</label>
                                <input id="name" name="name" type="text" required
                                       value="{{ old('name', $data['name'] ?? '') }}"
                                       class="setup-input" placeholder="Ex: Librairie El Amal">
                            </div>
                            <div class="space-y-1.5">
                                <label class="setup-label" for="business_mode">Type d'activité *</label>
                                <select id="business_mode" name="business_mode" required class="setup-input">
                                    <option value="bookstore" @selected(old('business_mode', $data['business_mode'] ?? 'bookstore') === 'bookstore')>Librairie / Livres</option>
                                    <option value="retail"    @selected(old('business_mode', $data['business_mode'] ?? '') === 'retail')>Commerce de détail</option>
                                    <option value="service"   @selected(old('business_mode', $data['business_mode'] ?? '') === 'service')>Services</option>
                                </select>
                            </div>
                            <div class="space-y-1.5">
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
                            <div class="space-y-1.5">
                                <label class="setup-label" for="timezone">Fuseau horaire *</label>
                                <select id="timezone" name="timezone" required class="setup-input">
                                    @foreach ($timezones as $tz => $label)
                                        <option value="{{ $tz }}" @selected(old('timezone', $data['timezone'] ?? 'Africa/Casablanca') === $tz)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="space-y-1.5">
                                <label class="setup-label" for="language">Langue principale *</label>
                                <select id="language" name="language" required class="setup-input">
                                    <option value="fr" @selected(old('language', $data['language'] ?? 'fr') === 'fr')>Français</option>
                                    <option value="ar" @selected(old('language', $data['language'] ?? '') === 'ar')>العربية</option>
                                </select>
                            </div>
                            <div class="space-y-1.5">
                                <label class="setup-label" for="email">Email</label>
                                <input id="email" name="email" type="email"
                                       value="{{ old('email', $data['email'] ?? '') }}"
                                       class="setup-input" placeholder="contact@boutique.ma">
                            </div>
                            <div class="space-y-1.5">
                                <label class="setup-label" for="phone">Téléphone</label>
                                <input id="phone" name="phone" type="tel"
                                       value="{{ old('phone', $data['phone'] ?? '') }}"
                                       class="setup-input" placeholder="+212 6xx xxx xxx">
                            </div>
                            <div class="space-y-1.5 sm:col-span-2">
                                <label class="setup-label" for="address">Adresse</label>
                                <input id="address" name="address" type="text"
                                       value="{{ old('address', $data['address'] ?? '') }}"
                                       class="setup-input" placeholder="Rue, Ville, Code postal">
                            </div>
                        </div>
                        <div class="flex justify-end">
                            <button type="submit" class="setup-btn-primary">
                                Continuer
                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                            </button>
                        </div>
                    </form>
                </div>

                {{-- ── Step: Owner ────────────────────────────────────────────── --}}
                @elseif ($step === 'owner')
                <div>
                    <p class="text-xs font-semibold uppercase tracking-widest text-[var(--brand)]">Étape 2 / 5</p>
                    <h1 class="mt-2 text-2xl font-semibold tracking-tight text-slate-900">Compte propriétaire</h1>
                    <p class="mt-2 text-sm leading-6 text-slate-500">Ce compte est le super-administrateur du tenant. Il ne peut pas être supprimé ni restreint.</p>

                    <form action="{{ route('setup.owner.save') }}" method="POST" class="mt-8 space-y-5">
                        @csrf
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="space-y-1.5 sm:col-span-2">
                                <label class="setup-label" for="oname">Nom complet *</label>
                                <input id="oname" name="name" type="text" required
                                       value="{{ old('name', $data['name'] ?? '') }}"
                                       class="setup-input" placeholder="Ex: Karim Benali">
                            </div>
                            <div class="space-y-1.5 sm:col-span-2">
                                <label class="setup-label" for="oemail">Adresse email *</label>
                                <input id="oemail" name="email" type="email" required
                                       value="{{ old('email', $data['email'] ?? '') }}"
                                       class="setup-input" placeholder="proprietaire@boutique.ma">
                            </div>
                            <div class="space-y-1.5">
                                <label class="setup-label" for="password">Mot de passe *</label>
                                <input id="password" name="password" type="password" required minlength="8"
                                       class="setup-input" placeholder="Minimum 8 caractères">
                            </div>
                            <div class="space-y-1.5">
                                <label class="setup-label" for="password_confirmation">Confirmer *</label>
                                <input id="password_confirmation" name="password_confirmation" type="password" required
                                       class="setup-input" placeholder="Répétez le mot de passe">
                            </div>
                        </div>
                        <div class="rounded-xl border border-[var(--brand)]/20 bg-[var(--brand)]/5 px-4 py-3 text-xs text-[var(--brand)]">
                            <strong class="font-semibold">Rôle Owner :</strong> accès illimité à tout le système, protégé contre la modification par d'autres utilisateurs.
                        </div>
                        <div class="flex justify-between gap-3">
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

                {{-- ── Step: Locations ────────────────────────────────────────── --}}
                @elseif ($step === 'locations')
                <div>
                    <p class="text-xs font-semibold uppercase tracking-widest text-[var(--brand)]">Étape 3 / 5</p>
                    <h1 class="mt-2 text-2xl font-semibold tracking-tight text-slate-900">Magasins & dépôts</h1>
                    <p class="mt-2 text-sm leading-6 text-slate-500">Définissez au moins un point de vente. D'autres pourront être ajoutés plus tard.</p>

                    <form action="{{ route('setup.locations.save') }}" method="POST" class="mt-8" id="locations-form">
                        @csrf
                        <div id="locations-list" class="space-y-4">
                            @php $locs = old('locations', $data ?? [['name'=>'','address'=>'','phone'=>'']]); @endphp
                            @foreach ($locs as $i => $loc)
                            <div class="location-item rounded-xl border border-slate-200 bg-white p-4 shadow-sm" data-index="{{ $i }}">
                                <div class="mb-3 flex items-center justify-between">
                                    <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Magasin {{ $i + 1 }}</span>
                                    @if ($i > 0)
                                        <button type="button" onclick="this.closest('.location-item').remove(); reindexLocations()" class="grid size-7 place-items-center rounded-lg border border-rose-200 text-rose-500 transition hover:bg-rose-50">
                                            <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                        </button>
                                    @endif
                                </div>
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div class="space-y-1.5 sm:col-span-2">
                                        <label class="setup-label">Nom *</label>
                                        <input name="locations[{{ $i }}][name]" type="text" required
                                               value="{{ $loc['name'] ?? '' }}"
                                               class="setup-input" placeholder="Ex: Magasin Centre-ville">
                                    </div>
                                    <div class="space-y-1.5">
                                        <label class="setup-label">Adresse</label>
                                        <input name="locations[{{ $i }}][address]" type="text"
                                               value="{{ $loc['address'] ?? '' }}"
                                               class="setup-input" placeholder="Rue, Ville">
                                    </div>
                                    <div class="space-y-1.5">
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
                                class="mt-4 flex w-full items-center justify-center gap-2 rounded-xl border border-dashed border-slate-300 py-3 text-sm font-semibold text-slate-500 transition hover:border-[var(--brand)] hover:text-[var(--brand)]">
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

                {{-- ── Step: Categories ───────────────────────────────────────── --}}
                @elseif ($step === 'categories')
                <div>
                    <p class="text-xs font-semibold uppercase tracking-widest text-[var(--brand)]">Étape 4 / 5</p>
                    <h1 class="mt-2 text-2xl font-semibold tracking-tight text-slate-900">Catégories de produits</h1>
                    <p class="mt-2 text-sm leading-6 text-slate-500">Pré-remplissez les catégories principales. Cette étape est optionnelle — vous pouvez les créer plus tard.</p>

                    <form action="{{ route('setup.categories.save') }}" method="POST" class="mt-8">
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
                                class="mt-3 flex items-center gap-2 text-sm font-semibold text-[var(--brand)] transition hover:brightness-110">
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

                {{-- ── Step: Review ───────────────────────────────────────────── --}}
                @elseif ($step === 'review')
                <div>
                    <p class="text-xs font-semibold uppercase tracking-widest text-[var(--brand)]">Étape 5 / 5</p>
                    <h1 class="mt-2 text-2xl font-semibold tracking-tight text-slate-900">Confirmation</h1>
                    <p class="mt-2 text-sm leading-6 text-slate-500">Vérifiez les informations avant de lancer la configuration. Cette action est irréversible.</p>

                    <div class="mt-8 space-y-4">
                        {{-- Store summary --}}
                        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                            <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Boutique</p>
                                <a href="{{ route('setup.store') }}" class="text-xs font-semibold text-[var(--brand)] hover:underline">Modifier</a>
                            </div>
                            <div class="grid gap-x-6 gap-y-3 p-4 text-sm sm:grid-cols-2">
                                <div><span class="text-slate-500">Nom</span><br><strong>{{ $store['name'] ?? '—' }}</strong></div>
                                <div><span class="text-slate-500">Activité</span><br><strong>{{ ucfirst($store['business_mode'] ?? '—') }}</strong></div>
                                <div><span class="text-slate-500">Devise</span><br><strong>{{ $store['currency'] ?? '—' }}</strong></div>
                                <div><span class="text-slate-500">Fuseau</span><br><strong>{{ $store['timezone'] ?? '—' }}</strong></div>
                                @if (!empty($store['email'])) <div><span class="text-slate-500">Email</span><br><strong>{{ $store['email'] }}</strong></div> @endif
                                @if (!empty($store['phone'])) <div><span class="text-slate-500">Téléphone</span><br><strong>{{ $store['phone'] }}</strong></div> @endif
                            </div>
                        </div>

                        {{-- Owner summary --}}
                        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                            <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Propriétaire</p>
                                <a href="{{ route('setup.owner') }}" class="text-xs font-semibold text-[var(--brand)] hover:underline">Modifier</a>
                            </div>
                            <div class="grid gap-x-6 gap-y-3 p-4 text-sm sm:grid-cols-2">
                                <div><span class="text-slate-500">Nom</span><br><strong>{{ $owner['name'] ?? '—' }}</strong></div>
                                <div><span class="text-slate-500">Email</span><br><strong>{{ $owner['email'] ?? '—' }}</strong></div>
                                <div><span class="text-slate-500">Mot de passe</span><br><strong>●●●●●●●●</strong></div>
                                <div><span class="text-slate-500">Rôle</span><br><strong class="text-[var(--brand)]">Owner (système)</strong></div>
                            </div>
                        </div>

                        {{-- Locations summary --}}
                        @if (count($locations))
                        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                            <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Magasins ({{ count($locations) }})</p>
                                <a href="{{ route('setup.locations') }}" class="text-xs font-semibold text-[var(--brand)] hover:underline">Modifier</a>
                            </div>
                            <ul class="divide-y divide-slate-100 px-4 text-sm">
                                @foreach ($locations as $idx => $loc)
                                    <li class="py-3">
                                        <strong>{{ $loc['name'] }}</strong>
                                        @if ($idx === 0) <span class="ml-2 rounded-full bg-[var(--brand)]/10 px-2 py-0.5 text-xs font-semibold text-[var(--brand)]">Principal</span> @endif
                                        @if (!empty($loc['address'])) <br><span class="text-slate-500">{{ $loc['address'] }}</span> @endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                        @endif

                        {{-- Categories summary --}}
                        @if (count($categories))
                        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                            <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Catégories ({{ count($categories) }})</p>
                                <a href="{{ route('setup.categories') }}" class="text-xs font-semibold text-[var(--brand)] hover:underline">Modifier</a>
                            </div>
                            <div class="flex flex-wrap gap-2 p-4">
                                @foreach ($categories as $cat)
                                    <span class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-700">{{ $cat }}</span>
                                @endforeach
                            </div>
                        </div>
                        @endif

                        {{-- Defaults note --}}
                        <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-xs text-slate-500">
                            Les éléments suivants seront créés automatiquement : <strong class="text-slate-700">4 rôles système</strong>, <strong class="text-slate-700">4 unités</strong> (Pièce, Pack, Boîte, Service), <strong class="text-slate-700">3 taxes</strong> (0%, 7%, 20%), <strong class="text-slate-700">4 modes de paiement</strong>, <strong class="text-slate-700">1 compte caisse</strong>.
                        </div>
                    </div>

                    <form action="{{ route('setup.commit') }}" method="POST" class="mt-6">
                        @csrf
                        <div class="flex justify-between gap-3">
                            <a href="{{ route('setup.categories') }}" class="setup-btn-ghost">
                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                                Retour
                            </a>
                            <button type="submit" class="setup-btn-primary bg-emerald-600 shadow-emerald-500/20 hover:brightness-110" style="background:#059669">
                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                Lancer la configuration
                            </button>
                        </div>
                    </form>
                </div>

                {{-- ── Step: Done ──────────────────────────────────────────────── --}}
                @elseif ($step === 'done')
                <div class="py-6 text-center">
                    <div class="mx-auto grid size-20 place-items-center rounded-full bg-emerald-100 text-emerald-600">
                        <svg class="size-10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    </div>
                    <h1 class="mt-6 text-2xl font-semibold tracking-tight text-slate-900">Configuration terminée !</h1>
                    <p class="mx-auto mt-3 max-w-sm text-sm leading-6 text-slate-500">Le tenant a été créé avec succès. Vous pouvez maintenant vous connecter avec les identifiants du propriétaire.</p>

                    <div class="mx-auto mt-8 max-w-sm rounded-xl border border-slate-200 bg-white p-5 text-left shadow-sm">
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Prochaines étapes</p>
                        <ul class="mt-3 space-y-2 text-sm text-slate-700">
                            <li class="flex items-start gap-2.5">
                                <span class="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full bg-[var(--brand)]/10 text-[10px] font-bold text-[var(--brand)]">1</span>
                                Connectez-vous avec l'email et le mot de passe du propriétaire
                            </li>
                            <li class="flex items-start gap-2.5">
                                <span class="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full bg-[var(--brand)]/10 text-[10px] font-bold text-[var(--brand)]">2</span>
                                Personnalisez le thème dans Paramètres → Apparence
                            </li>
                            <li class="flex items-start gap-2.5">
                                <span class="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full bg-[var(--brand)]/10 text-[10px] font-bold text-[var(--brand)]">3</span>
                                Invitez votre équipe et configurez leurs rôles
                            </li>
                        </ul>
                    </div>

                    <a href="{{ route('login') }}" class="setup-btn-primary mx-auto mt-8 inline-flex">
                        Se connecter
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                    </a>
                </div>
                @endif

            </div>
        </div>
    </main>
</div>

<script>
// ── Locations: add/reindex ─────────────────────────────────────────────────
function reindexLocations() {
    document.querySelectorAll('.location-item').forEach(function(item, i) {
        item.dataset.index = i;
        item.querySelector('.text-slate-500').textContent = 'Magasin ' + (i + 1);
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
        var item = document.createElement('div');
        item.className = 'location-item rounded-xl border border-slate-200 bg-white p-4 shadow-sm';
        item.dataset.index = idx;
        item.innerHTML = '<div class="mb-3 flex items-center justify-between">'
            + '<span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Magasin ' + (idx + 1) + '</span>'
            + '<button type="button" onclick="this.closest(\'.location-item\').remove(); reindexLocations()" class="grid size-7 place-items-center rounded-lg border border-rose-200 text-rose-500 transition hover:bg-rose-50">'
            + '<svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>'
            + '</button></div>'
            + '<div class="grid gap-3 sm:grid-cols-2">'
            + '<div class="space-y-1.5 sm:col-span-2"><label class="block text-xs font-semibold uppercase tracking-wider text-slate-500">Nom *</label>'
            + '<input name="locations[' + idx + '][name]" type="text" required class="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-[#3157D5] focus:ring-2 focus:ring-[#3157D5]/12" placeholder="Ex: Magasin Centre-ville"></div>'
            + '<div class="space-y-1.5"><label class="block text-xs font-semibold uppercase tracking-wider text-slate-500">Adresse</label>'
            + '<input name="locations[' + idx + '][address]" type="text" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-[#3157D5] focus:ring-2 focus:ring-[#3157D5]/12" placeholder="Rue, Ville"></div>'
            + '<div class="space-y-1.5"><label class="block text-xs font-semibold uppercase tracking-wider text-slate-500">Téléphone</label>'
            + '<input name="locations[' + idx + '][phone]" type="tel" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-[#3157D5] focus:ring-2 focus:ring-[#3157D5]/12" placeholder="+212 ..."></div>'
            + '</div>';
        list.appendChild(item);
    });
}

// ── Categories: add ────────────────────────────────────────────────────────
var addCatBtn = document.getElementById('add-cat');
if (addCatBtn) {
    addCatBtn.addEventListener('click', function() {
        var list = document.getElementById('cats-list');
        var idx  = list.querySelectorAll('.cat-item').length;
        var item = document.createElement('div');
        item.className = 'cat-item flex items-center gap-2';
        item.innerHTML = '<input name="categories[' + idx + ']" type="text" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-[#3157D5] focus:ring-2 focus:ring-[#3157D5]/12" placeholder="Ex: Romans, Scolaire, BD…">'
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
