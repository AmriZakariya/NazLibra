@php
    $tr = $tr ?? fn (string $text): string => \App\Support\Locale::t($text, $locale ?? null);
    $method = $request->method();
    $activeFilters = collect($filters)->filter(fn ($v) => $v !== '' && $v !== null && $v !== 'all');
    $statusLabels = [
        'active' => 'Actif',
        'inactive' => 'Inactif',
        'out_of_stock' => 'Rupture',
    ];
    $statusTones = [
        'active' => 'success',
        'inactive' => 'neutral',
        'out_of_stock' => 'danger',
    ];
@endphp

<x-layouts.app :tenant="$tenant" :active="$active" title="LibrairePro · {{ $tr('Variantes') }}">
    <header class="mb-8 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
        <div class="flex flex-col gap-6 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-brand">{{ $tr('Catalogue · Variantes') }}</p>
                <h1 class="mt-1 text-[1.75rem] font-bold tracking-tight text-slate-950 dark:text-white">{{ $tr('Variantes') }}</h1>
                <p class="mt-2 max-w-2xl text-sm leading-relaxed text-slate-500 dark:text-slate-400">{{ $tr('Gérez les variantes d\'articles : éditions, formats, langues, couleurs, tailles. Chaque variante a son propre code-barres, prix et stock.') }}</p>
            </div>
            <div class="flex gap-3">
                <a href="{{ route('catalog', ['panel' => 'variantes']) }}" class="inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:border-brand/40 hover:text-brand dark:border-white/10 dark:bg-transparent dark:text-slate-200">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 19-7-7 7-7"/><path d="M19 12H5"/></svg>
                    {{ $tr('Catalogue') }}
                </a>
                <button type="button" onclick="document.getElementById('variant-create-dialog')?.showModal()" class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-brand px-4 text-sm font-semibold text-white shadow-sm shadow-indigo-500/20 transition hover:brightness-110">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                    {{ $tr('Nouvelle variante') }}
                </button>
            </div>
        </div>
    </header>

    <dialog id="variant-create-dialog" class="app-dialog w-[min(920px,calc(100vw-1.5rem))] rounded-2xl border border-slate-200 bg-white p-0 text-slate-950 shadow-2xl backdrop:bg-slate-950/45 dark:border-white/10 dark:bg-slate-950 dark:text-slate-100">
        <form action="{{ route('variants.store') }}" method="POST" data-smart-validation data-error-fields='@json($errors->keys())'>
            @csrf
            <input type="hidden" name="status" value="active">
            <input type="hidden" name="is_active" value="1">
            <div class="flex items-start justify-between gap-4 border-b border-slate-200 p-5 dark:border-white/10">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-brand">{{ $tr('Catalogue · Variantes') }}</p>
                    <h3 class="mt-1 text-xl font-semibold">{{ $tr('Nouvelle variante') }}</h3>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $tr('Cherchez l’article parent, puis renseignez les attributs utiles à la caisse et au stock.') }}</p>
                </div>
                <button class="dialog-close grid size-10 shrink-0 place-items-center rounded-xl border border-slate-200 text-xl font-semibold text-slate-500 transition hover:border-brand hover:text-brand dark:border-white/10" type="button">×</button>
            </div>

            <div class="variant-dialog-body grid gap-5 p-5 lg:grid-cols-2">
                <div data-validation-summary class="{{ $errors->any() ? '' : 'hidden' }} app-validation-summary lg:col-span-2">
                    <strong class="block">{{ $tr('Le formulaire contient des informations à corriger.') }}</strong>
                    <p class="mt-1">{{ $tr('Les champs concernés sont surlignés ci-dessous.') }}</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>

                <div class="lg:col-span-2">
                    <label class="space-y-2">
                        <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $tr('Article parent') }} <span class="text-rose-500">*</span></span>
                        <span class="variant-picker" data-async-item-picker data-endpoint="{{ route('catalog.quick-search') }}" data-context="variants" data-empty-text="{{ $tr('Aucun article trouvé.') }}">
                            <input type="hidden" name="item_id" required value="{{ old('item_id') }}" data-async-item-value>
                            <input type="search" autocomplete="off" class="variant-picker-input" data-async-item-input placeholder="{{ $tr('Rechercher par titre, code-barres, ISBN, SKU...') }}">
                            <span class="variant-picker-results hidden" data-async-item-results></span>
                            <span class="variant-picker-selected hidden" data-async-item-selected></span>
                        </span>
                    </label>
                </div>

                <label class="space-y-2">
                    <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $tr('Nom de la variante') }} <span class="text-rose-500">*</span></span>
                    <input name="name" required value="{{ old('name') }}" maxlength="255" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900" placeholder="{{ $tr('Ex: Poche, Relié, Arabe...') }}">
                </label>
                <label class="space-y-2">
                    <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $tr('Code-barres') }}</span>
                    <input name="barcode" value="{{ old('barcode') }}" maxlength="120" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900" placeholder="{{ $tr('Code scanner optionnel') }}">
                </label>

                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-white/10 dark:bg-white/5 lg:col-span-2">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $tr('Attributs') }}</p>
                    <div class="mt-3 grid gap-3 sm:grid-cols-3">
                        <input name="format" value="{{ old('format') }}" maxlength="120" class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900" placeholder="{{ $tr('Format') }}">
                        <input name="language" value="{{ old('language') }}" maxlength="20" class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900" placeholder="{{ $tr('Langue') }}">
                        <input name="edition" value="{{ old('edition') }}" maxlength="120" class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900" placeholder="{{ $tr('Édition') }}">
                    </div>
                </div>

                <label class="space-y-2">
                    <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $tr('Prix de vente') }} <span class="text-rose-500">*</span></span>
                    <input name="sale_price" required min="0" type="number" step="0.01" value="{{ old('sale_price') }}" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900" placeholder="0,00">
                </label>
                <label class="space-y-2">
                    <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $tr('Coût d’achat') }} <span class="text-rose-500">*</span></span>
                    <input name="purchase_price" required min="0" type="number" step="0.01" value="{{ old('purchase_price') }}" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900" placeholder="0,00">
                </label>
                <label class="space-y-2">
                    <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $tr('Stock') }} <span class="text-rose-500">*</span></span>
                    <input name="stock_quantity" required min="0" type="number" step="1" value="{{ old('stock_quantity', 0) }}" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900">
                </label>
                <label class="space-y-2">
                    <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $tr('Quantité d’alerte') }}</span>
                    <input name="min_stock_threshold" min="0" type="number" step="1" value="{{ old('min_stock_threshold', 0) }}" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900">
                </label>
            </div>

            <div class="flex flex-col-reverse gap-2 border-t border-slate-200 bg-slate-50 p-5 dark:border-white/10 dark:bg-white/5 sm:flex-row sm:items-center sm:justify-end">
                <button type="button" class="dialog-close rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-brand hover:text-brand dark:border-white/10 dark:bg-slate-950 dark:text-slate-100">{{ $tr('Annuler') }}</button>
                <button class="rounded-xl bg-brand px-5 py-2.5 text-sm font-semibold text-white shadow-sm shadow-brand/20">{{ $tr('Créer la variante') }}</button>
            </div>
        </form>
    </dialog>

    @if ($errors->any())
        <script>
            window.addEventListener('DOMContentLoaded', () => document.getElementById('variant-create-dialog')?.showModal());
        </script>
    @endif

    {{-- Filters --}}
    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
        <form action="{{ route('variants.index') }}" method="GET" class="space-y-4">
            <div class="relative">
                <svg class="pointer-events-none absolute left-4 top-1/2 size-5 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                <input name="q" value="{{ $filters['q'] }}" placeholder="{{ $tr('Rechercher par nom, code-barres, SKU, ISBN, format, langue, édition...') }}" class="h-12 w-full rounded-xl border border-slate-200 bg-white py-3 pl-12 pr-4 text-sm text-slate-950 outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900 dark:text-white" autofocus>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5 xl:grid-cols-6">
                <label class="space-y-2">
                    <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $tr('Article parent') }}</span>
                    <select name="product" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-950 outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900 dark:text-white">
                        <option value="">{{ $tr('Tous les articles') }}</option>
                        @foreach ($products as $product)
                            <option value="{{ $product->id }}" @selected((string) $filters['product'] === (string) $product->id)>{{ $product->title }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="space-y-2">
                    <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $tr('Statut') }}</span>
                    <select name="status" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-950 outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900 dark:text-white">
                        <option value="all">{{ $tr('Tous') }}</option>
                        <option value="active" @selected($filters['status'] === 'active')>{{ $tr('Actif') }}</option>
                        <option value="inactive" @selected($filters['status'] === 'inactive')>{{ $tr('Inactif') }}</option>
                        <option value="out_of_stock" @selected($filters['status'] === 'out_of_stock')>{{ $tr('Rupture') }}</option>
                    </select>
                </label>
                <label class="space-y-2">
                    <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $tr('Stock') }}</span>
                    <select name="stock" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-950 outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900 dark:text-white">
                        <option value="all">{{ $tr('Tous') }}</option>
                        <option value="available" @selected($filters['stock'] === 'available')>{{ $tr('Disponible') }}</option>
                        <option value="low" @selected($filters['stock'] === 'low')>{{ $tr('Alerte') }}</option>
                        <option value="out" @selected($filters['stock'] === 'out')>{{ $tr('Rupture') }}</option>
                    </select>
                </label>
                <label class="space-y-2">
                    <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $tr('Tri') }}</span>
                    <select name="sort" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-950 outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900 dark:text-white">
                        <option value="name" @selected($filters['sort'] === 'name')>{{ $tr('Nom') }}</option>
                        <option value="product" @selected($filters['sort'] === 'product')>{{ $tr('Article') }}</option>
                        <option value="price" @selected($filters['sort'] === 'price')>{{ $tr('Prix') }}</option>
                        <option value="stock" @selected($filters['sort'] === 'stock')>{{ $tr('Stock') }}</option>
                        <option value="status" @selected($filters['sort'] === 'status')>{{ $tr('Statut') }}</option>
                        <option value="updated_at" @selected($filters['sort'] === 'updated_at')>{{ $tr('Modifié') }}</option>
                        <option value="created_at" @selected($filters['sort'] === 'created_at')>{{ $tr('Créé') }}</option>
                    </select>
                </label>
                <label class="space-y-2">
                    <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $tr('Ordre') }}</span>
                    <select name="direction" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-950 outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900 dark:text-white">
                        <option value="asc" @selected($filters['direction'] === 'asc')>{{ $tr('Croissant') }}</option>
                        <option value="desc" @selected($filters['direction'] === 'desc')>{{ $tr('Décroissant') }}</option>
                    </select>
                </label>
                <div class="flex items-end gap-2">
                    <button type="submit" class="h-11 flex-1 rounded-xl bg-brand px-4 text-sm font-semibold text-white shadow-sm shadow-indigo-500/20 transition hover:brightness-110">{{ $tr('Filtrer') }}</button>
                    <a href="{{ route('variants.index') }}" class="inline-flex h-11 shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:border-brand/40 hover:text-brand dark:border-white/10 dark:bg-transparent dark:text-slate-200">{{ $tr('Effacer') }}</a>
                </div>
            </div>

            @if ($activeFilters->isNotEmpty())
                <div class="flex flex-wrap items-center gap-2 rounded-xl border border-brand/20 bg-brand/5 px-4 py-3 dark:border-brand/30 dark:bg-brand/10">
                    <svg class="size-4 shrink-0 text-brand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                    <span class="text-xs font-semibold uppercase tracking-wider text-brand">{{ $tr('Filtres actifs') }}</span>
                    @if ($filters['q'])
                        <span class="inline-flex items-center gap-1.5 rounded-full border border-brand/30 bg-white px-3 py-1 text-xs font-semibold text-brand dark:border-brand/40 dark:bg-brand/10">"{{ Str::limit($filters['q'], 30) }}"<a href="{{ route('variants.index', array_merge($filters, ['q' => ''])) }}" class="grid size-4 place-items-center rounded-full hover:bg-brand/10">×</a></span>
                    @endif
                    @if ($filters['product'])
                        @php $product = $products->firstWhere('id', (int) $filters['product']); @endphp
                        <span class="inline-flex items-center gap-1.5 rounded-full border border-brand/30 bg-white px-3 py-1 text-xs font-semibold text-brand dark:border-brand/40 dark:bg-brand/10">{{ $product?->title ?? '#' . $filters['product'] }}<a href="{{ route('variants.index', array_merge($filters, ['product' => ''])) }}" class="grid size-4 place-items-center rounded-full hover:bg-brand/10">×</a></span>
                    @endif
                    @if ($filters['status'] && $filters['status'] !== 'all')
                        <span class="inline-flex items-center gap-1.5 rounded-full border border-brand/30 bg-white px-3 py-1 text-xs font-semibold text-brand dark:border-brand/40 dark:bg-brand/10">{{ $statusLabels[$filters['status']] }}<a href="{{ route('variants.index', array_merge($filters, ['status' => 'all'])) }}" class="grid size-4 place-items-center rounded-full hover:bg-brand/10">×</a></span>
                    @endif
                    @if ($filters['stock'] && $filters['stock'] !== 'all')
                        <span class="inline-flex items-center gap-1.5 rounded-full border border-brand/30 bg-white px-3 py-1 text-xs font-semibold text-brand dark:border-brand/40 dark:bg-brand/10">{{ $filters['stock'] }}<a href="{{ route('variants.index', array_merge($filters, ['stock' => 'all'])) }}" class="grid size-4 place-items-center rounded-full hover:bg-brand/10">×</a></span>
                    @endif
                    <span class="ml-auto text-xs text-slate-500">{{ $variants->total() }} {{ $tr('résultat(s)') }}</span>
                </div>
            @endif
        </form>
    </section>

    {{-- Variant List --}}
    <section class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:border-white/10 dark:bg-white/5 dark:text-slate-400">
                        <th class="px-5 py-3.5">{{ $tr('Variante') }}</th>
                        <th class="px-5 py-3.5">{{ $tr('Article parent') }}</th>
                        <th class="px-5 py-3.5">{{ $tr('Identifiants') }}</th>
                        <th class="px-5 py-3.5">{{ $tr('Prix') }}</th>
                        <th class="px-5 py-3.5">{{ $tr('Stock') }}</th>
                        <th class="px-5 py-3.5">{{ $tr('Statut') }}</th>
                        <th class="px-5 py-3.5 text-right">{{ $tr('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-white/5">
                    @forelse ($variants as $variant)
                        <tr class="transition hover:bg-slate-50/50 dark:hover:bg-white/[0.02]">
                            <td class="px-5 py-4">
                                <div class="flex items-center gap-3">
                                    @if ($variant->image)
                                        <img src="{{ asset('storage/' . $variant->image) }}" class="size-10 rounded-lg object-cover" alt="">
                                    @else
                                        <span class="grid size-10 place-items-center rounded-lg bg-brand/10 text-sm font-bold text-brand">{{ Str::upper(Str::substr($variant->name, 0, 2)) }}</span>
                                    @endif
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-semibold text-slate-900 dark:text-white">{{ $variant->name }}</p>
                                        @if ($variant->format || $variant->language || $variant->edition)
                                            <p class="truncate text-xs text-slate-400">{{ collect([$variant->format, $variant->language, $variant->edition])->filter()->implode(' · ') }}</p>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-4">
                                <p class="text-sm text-slate-800 dark:text-slate-200">{{ $variant->item?->title ?? '—' }}</p>
                                <p class="text-xs text-slate-400">{{ $variant->item?->category?->name ?? '—' }}</p>
                            </td>
                            <td class="px-5 py-4">
                                <div class="space-y-0.5">
                                    @if ($variant->barcode)
                                        <p class="text-xs font-mono text-slate-500">CB: {{ $variant->barcode }}</p>
                                    @endif
                                    @if ($variant->sku)
                                        <p class="text-xs font-mono text-slate-500">SKU: {{ $variant->sku }}</p>
                                    @endif
                                    @if ($variant->isbn)
                                        <p class="text-xs font-mono text-slate-500">ISBN: {{ $variant->isbn }}</p>
                                    @endif
                                    @if (! $variant->barcode && ! $variant->sku && ! $variant->isbn)
                                        <p class="text-xs text-slate-400">—</p>
                                    @endif
                                </div>
                            </td>
                            <td class="px-5 py-4">
                                <p class="text-sm font-semibold text-slate-800 dark:text-slate-200">{{ number_format($variant->sale_price, 2, ',', ' ') }} DH</p>
                                <p class="text-xs text-slate-400">Achat: {{ number_format($variant->purchase_price, 2, ',', ' ') }} DH</p>
                                @if ($variant->margin_percent > 0)
                                    <p class="text-xs text-emerald-600">Marge: {{ $variant->margin_percent }}%</p>
                                @endif
                            </td>
                            <td class="px-5 py-4">
                                @if ($variant->is_out_of_stock)
                                    <span class="inline-flex rounded-md bg-rose-50 px-2 py-0.5 text-xs font-bold text-rose-700 dark:bg-rose-500/15 dark:text-rose-300">0</span>
                                @elseif ($variant->is_low_stock)
                                    <span class="inline-flex rounded-md bg-amber-50 px-2 py-0.5 text-xs font-bold text-amber-700 dark:bg-amber-500/15 dark:text-amber-300">{{ $variant->stock_quantity }}</span>
                                @else
                                    <span class="inline-flex rounded-md bg-emerald-50 px-2 py-0.5 text-xs font-bold text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300">{{ $variant->stock_quantity }}</span>
                                @endif
                                <p class="text-xs text-slate-400">Seuil: {{ $variant->min_stock_threshold }}</p>
                            </td>
                            <td class="px-5 py-4">
                                <x-status-pill :tone="$statusTones[$variant->status] ?? 'neutral'">{{ $statusLabels[$variant->status] ?? $variant->status }}</x-status-pill>
                                @if (! $variant->is_active)
                                    <p class="mt-1 text-xs text-slate-400">{{ $tr('Désactivé') }}</p>
                                @endif
                            </td>
                            <td class="px-5 py-4 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="{{ route('variants.show', $variant) }}" class="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-semibold transition hover:border-brand hover:text-brand dark:border-white/10">
                                        {{ $tr('Détail') }}
                                    </a>
                                    <a href="{{ route('variants.edit', $variant) }}" class="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-semibold transition hover:border-brand hover:text-brand dark:border-white/10">
                                        {{ $tr('Modifier') }}
                                    </a>
                                    <form action="{{ route('variants.duplicate', $variant) }}" method="POST" class="inline">
                                        @csrf
                                        <button type="submit" class="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-semibold transition hover:border-brand hover:text-brand dark:border-white/10" title="{{ $tr('Dupliquer') }}">
                                            <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                        </button>
                                    </form>
                                    <form action="{{ route('variants.toggle', $variant) }}" method="POST" class="inline">
                                        @csrf
                                        <button type="submit" class="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-semibold transition hover:border-brand hover:text-brand dark:border-white/10" title="{{ $variant->is_active ? $tr('Désactiver') : $tr('Activer') }}">
                                            @if ($variant->is_active)
                                                <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/></svg>
                                            @else
                                                <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/></svg>
                                            @endif
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-5 py-16 text-center">
                                <div class="mx-auto max-w-sm">
                                    <span class="grid size-12 place-items-center rounded-xl bg-slate-100 mx-auto mb-4 dark:bg-white/5">
                                        <svg class="size-6 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
                                    </span>
                                    <p class="text-sm font-semibold text-slate-700 dark:text-slate-200">{{ $tr('Aucune variante trouvée') }}</p>
                                    <p class="mt-1 text-xs text-slate-400">{{ $tr('Ajoutez une nouvelle variante ou modifiez les filtres.') }}</p>
                                    <button type="button" onclick="document.getElementById('variant-create-dialog')?.showModal()" class="mt-4 inline-flex items-center gap-2 rounded-xl bg-brand px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:brightness-110">
                                        {{ $tr('Nouvelle variante') }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($variants->hasPages())
            <div class="border-t border-slate-200 px-5 py-4 dark:border-white/10">
                {{ $variants->links() }}
            </div>
        @endif
    </section>
</x-layouts.app>
