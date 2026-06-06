@php
    $money = fn ($amount) => number_format((float) $amount, 2, ',', ' ').' DH';
    $panel = request('panel', 'articles');
    $isStockWorkspace = request()->routeIs('stock');
    $pageTitle = $isStockWorkspace ? 'Stock opérationnel' : 'Catalogue opérationnel';
    $pageEyebrow = $isStockWorkspace ? 'Stock & mouvements' : 'Catalogue & inventaire';
    $pageDescription = $isStockWorkspace
        ? 'Ajustements, transferts et suivi des mouvements pour garder le stock fiable en magasin.'
        : 'Articles, services, imports et référentiels structurés pour une vraie journée de test en magasin.';
    $sortLink = fn ($key) => route('catalog', array_merge(request()->query(), [
        'sort' => $key,
        'direction' => $sort === $key && $direction === 'asc' ? 'desc' : 'asc',
    ]));
    $exportLink = route('catalog.export', request()->query());
    $statusLabel = fn ($status) => ['active' => 'Actif', 'archived' => 'Archivé', 'out_of_stock' => 'Rupture'][$status] ?? $status;
    $typeLabel = fn ($type) => ['book' => 'Livre', 'supply' => 'Produit', 'service' => 'Service'][$type] ?? $type;
    $sortIndicator = fn ($key) => $sort === $key ? ($direction === 'asc' ? ' ↑' : ' ↓') : '';
    $importLabels = [
        'items' => ['title' => 'Importer articles', 'hint' => "Fichier Liste d'articles .xlsx", 'example' => 'Exemple articles'],
        'services' => ['title' => 'Importer services', 'hint' => 'Prestations et services sans stock réel', 'example' => 'Exemple services'],
        'categories' => ['title' => 'Importer catégories', 'hint' => 'Fichier Liste des catégories .xlsx', 'example' => 'Exemple catégories'],
        'brands' => ['title' => 'Importer marques', 'hint' => 'Fichier Liste des marques .xlsx', 'example' => 'Exemple marques'],
        'variants' => ['title' => 'Importer variantes', 'hint' => 'Fichier Liste des variantes .xlsx', 'example' => 'Exemple variantes'],
    ];
    $sections = [
        'Gérer' => [
            'articles' => ['label' => 'Articles', 'hint' => 'Stock et prix', 'href' => route('catalog', ['panel' => 'articles'])],
            'services' => ['label' => 'Services', 'hint' => 'Prestations', 'href' => route('catalog', ['panel' => 'services'])],
        ],
        'Créer' => [
            'ajouter' => ['label' => 'Ajouter article', 'hint' => 'Livre ou produit', 'href' => route('catalog', ['panel' => 'ajouter'])],
            'ajouter-service' => ['label' => 'Ajouter service', 'hint' => 'Non physique', 'href' => route('catalog', ['panel' => 'ajouter-service'])],
            'import' => ['label' => 'Importer', 'hint' => 'Excel / CSV', 'href' => route('catalog', ['panel' => 'import', 'kind' => request('kind', 'items')])],
        ],
        'Référentiels' => [
            'categories' => ['label' => 'Catégories', 'hint' => 'Structure', 'href' => route('catalog', ['panel' => 'categories'])],
            'marques' => ['label' => 'Marques', 'hint' => 'Éditeurs', 'href' => route('catalog', ['panel' => 'marques'])],
            'unites' => ['label' => 'Unités', 'hint' => 'Mesures', 'href' => route('catalog', ['panel' => 'unites'])],
            'impots' => ['label' => 'Impôts', 'hint' => 'TVA', 'href' => route('catalog', ['panel' => 'impots'])],
            'variantes' => ['label' => 'Variantes', 'hint' => 'Options', 'href' => route('catalog', ['panel' => 'variantes'])],
        ],
        'Stock' => [
            'stock-adjustment-add' => ['label' => 'Ajouter ajustement', 'hint' => 'Corriger', 'href' => route('stock', ['panel' => 'stock-adjustment-add'])],
            'stock-adjustments' => ['label' => "Liste d'ajustement", 'hint' => 'Historique', 'href' => route('stock', ['panel' => 'stock-adjustments'])],
            'stock-transfer-add' => ['label' => 'Ajouter transfert', 'hint' => 'Déplacer', 'href' => route('stock', ['panel' => 'stock-transfer-add'])],
            'stock-transfers' => ['label' => 'Liste de transfert', 'hint' => 'Suivi', 'href' => route('stock', ['panel' => 'stock-transfers'])],
        ],
    ];
    $activeSectionLabel = collect($sections)->flatMap(fn ($links) => $links)->get($panel)['label'] ?? 'Navigation rapide';
@endphp

<x-layouts.app :tenant="$tenant" :active="$active" :title="$isStockWorkspace ? 'LibrairePro · Stock' : 'LibrairePro · Catalogue'">
    <section class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
        <div>
            <p class="text-sm font-medium text-brand">{{ $pageEyebrow }}</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-normal">{{ $pageTitle }}</h1>
            <p class="mt-2 max-w-4xl text-sm text-slate-600 dark:text-slate-300">{{ $pageDescription }}</p>
        </div>
        <div class="app-action-row">
            @if ($isStockWorkspace)
                <a href="{{ route('stock', ['panel' => 'stock-adjustments']) }}" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold dark:border-white/10 dark:bg-white/5">Ajustements</a>
                <a href="{{ route('stock', ['panel' => 'stock-transfers']) }}" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold dark:border-white/10 dark:bg-white/5">Transferts</a>
                <a href="{{ route('stock', ['panel' => 'stock-adjustment-add']) }}" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white shadow-sm">Nouvel ajustement</a>
            @else
                <a href="{{ route('catalog.labels') }}" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold dark:border-white/10 dark:bg-white/5">Imprimer étiquettes</a>
                <a href="{{ route('catalog', ['panel' => 'import', 'kind' => 'items']) }}" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold dark:border-white/10 dark:bg-white/5">Importer</a>
                <a href="{{ route('catalog', ['panel' => 'ajouter']) }}" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white shadow-sm">Nouvel article</a>
            @endif
        </div>
    </section>

    <section class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <article class="rounded-xl border border-slate-200 bg-white p-4 text-slate-950 shadow-sm dark:border-white/10 dark:bg-slate-950 dark:text-white"><p class="text-sm font-medium text-slate-600 dark:text-slate-300">Articles physiques</p><p class="mt-2 text-2xl font-semibold text-slate-950 dark:text-white">{{ $catalogStats['items'] }}</p></article>
        <article class="rounded-xl border border-slate-200 bg-white p-4 text-slate-950 shadow-sm dark:border-white/10 dark:bg-slate-950 dark:text-white"><p class="text-sm font-medium text-slate-600 dark:text-slate-300">Services</p><p class="mt-2 text-2xl font-semibold text-slate-950 dark:text-white">{{ $catalogStats['services'] }}</p></article>
        <article class="rounded-xl border border-slate-200 bg-white p-4 text-slate-950 shadow-sm dark:border-white/10 dark:bg-slate-950 dark:text-white"><p class="text-sm font-medium text-slate-600 dark:text-slate-300">Alertes stock</p><p class="mt-2 text-2xl font-semibold text-slate-950 dark:text-white">{{ $catalogStats['low'] }}</p></article>
        <article class="rounded-xl border border-slate-200 bg-white p-4 text-slate-950 shadow-sm dark:border-white/10 dark:bg-slate-950 dark:text-white"><p class="text-sm font-medium text-slate-600 dark:text-slate-300">Valorisation achat</p><p class="mt-2 text-2xl font-semibold text-slate-950 dark:text-white">{{ $money($catalogStats['value']) }}</p></article>
    </section>

    <details class="app-collapsible-menu mt-6" data-collapsible-menu data-menu-key="catalog-sections">
        <summary class="app-collapsible-menu-summary">
            <span><strong>{{ $isStockWorkspace ? 'Sections stock' : 'Sections catalogue' }}</strong><small>{{ $activeSectionLabel }}</small></span>
            <em data-collapsible-menu-state>Afficher</em>
        </summary>
        <nav class="app-section-nav" aria-label="Sections catalogue">
                @foreach ($sections as $group => $links)
                    <div class="app-section-group">
                        <p class="app-section-title">{{ $group }}</p>
                        <div class="app-section-links">
                            @foreach ($links as $key => $section)
                                <a href="{{ $section['href'] }}" class="app-section-link {{ $panel === $key ? 'is-active' : '' }}" @if ($panel === $key) aria-current="page" @endif>
                                    <span class="app-section-label">{{ $section['label'] }}</span>
                                    <span class="app-section-hint">{{ $section['hint'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </nav>
    </details>

    @if ($panel === 'stock-adjustment-add')
        @php
            $prefillAdjustmentItemId = (int) request('item');
            $defaultAdjustmentLine = $prefillAdjustmentItemId > 0
                ? [['item_id' => $prefillAdjustmentItemId, 'direction' => 'add', 'quantity' => 1]]
                : [['direction' => 'add', 'quantity' => 1]];
            $adjustmentLines = collect(old('items', $defaultAdjustmentLine))->values();
            if ($adjustmentLines->isEmpty()) {
                $adjustmentLines = collect($defaultAdjustmentLine);
            }
        @endphp
        <section class="mt-6 grid gap-6 2xl:grid-cols-[minmax(0,1fr)_360px]">
            <form action="{{ route('catalog.stock-adjustments.store') }}" method="POST" data-stock-adjustment-builder data-stock-adjustment-search-url="{{ route('catalog.stock-items.search') }}" data-stock-adjustment-initial-query="{{ request('stock_q') }}" data-next-index="{{ $adjustmentLines->count() }}" class="overflow-visible rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                @csrf
                <div class="border-b border-slate-200 bg-slate-50/70 p-5 dark:border-white/10 dark:bg-white/[0.04]">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                        <div class="flex items-start gap-3">
                            <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-brand text-lg font-semibold text-white">±</span>
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-brand">Stock · correction</p>
                                <h2 class="mt-1 text-xl font-semibold">Ajustement des stocks</h2>
                                <p class="mt-1 max-w-2xl text-sm text-slate-500">Corrigez les écarts d'inventaire, casse ou perte avec une trace claire par article.</p>
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <a href="{{ route('stock', ['panel' => 'stock-adjustments']) }}" class="rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-brand/40 hover:text-brand dark:border-white/10 dark:bg-slate-950 dark:text-slate-200">Historique</a>
                            <button class="rounded-lg bg-brand px-4 py-2.5 text-sm font-semibold text-white shadow-sm shadow-brand/20 transition hover:bg-brand-600">Enregistrer</button>
                        </div>
                    </div>
                </div>
                <div class="space-y-5 p-5">
                    <div class="grid gap-4 lg:grid-cols-4">
                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Date</span><input name="adjusted_at" value="{{ old('adjusted_at', now()->format('Y-m-d\TH:i')) }}" type="datetime-local" class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900"></label>
                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Entrepôt</span><input name="warehouse" value="{{ old('warehouse') }}" class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900" placeholder="Principal, dépôt..."></label>
                        <label class="space-y-1.5 lg:col-span-2"><span class="text-xs font-semibold uppercase text-slate-500">Raison @if(data_get($tenant->settings, 'pos.require_adjustment_reason', true))*@endif</span><input name="reason" value="{{ old('reason') }}" @if(data_get($tenant->settings, 'pos.require_adjustment_reason', true)) required @endif class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900" placeholder="Inventaire, casse, perte, correction..."></label>
                    </div>

                    <div class="stock-adjustment-card overflow-visible rounded-xl border border-slate-200 dark:border-white/10">
                        <div class="border-b border-slate-200 bg-white px-4 py-3 dark:border-white/10 dark:bg-slate-950/40">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                <h3 class="font-semibold">Articles à ajuster</h3>
                                <p class="mt-1 text-sm text-slate-500">Ajoutez uniquement les articles concernés. Le stock actuel reste visible dans la liste.</p>
                                </div>
                                <button type="button" data-stock-adjustment-add class="inline-flex items-center justify-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:border-brand/40 hover:text-brand dark:border-white/10 dark:bg-slate-900 dark:text-slate-100">+ Ajouter une ligne</button>
                            </div>
                            <div class="stock-adjustment-toolbar mt-4">
                                <label class="stock-adjustment-search">
                                    <span>Rechercher article, code-barres, ISBN...</span>
                                    <input type="search" data-stock-adjustment-search value="{{ request('stock_q') }}" placeholder="Ex: cahier, 978..., code article" autocomplete="off">
                                </label>
                                <button type="button" data-stock-adjustment-add-match class="stock-adjustment-tool-button">Ajouter le 1er résultat</button>
                                <button type="button" data-stock-adjustment-clear class="stock-adjustment-tool-button is-muted">Effacer</button>
                                <p class="stock-adjustment-search-meta"><strong data-stock-adjustment-search-count>{{ $stockItems->count() }}</strong> résultat(s)<span data-stock-adjustment-search-state> disponibles</span></p>
                            </div>
                            <div data-stock-adjustment-suggestions class="stock-adjustment-suggestions mt-3" hidden></div>
                        </div>
                        <div class="hidden grid-cols-[44px_minmax(260px,1.7fr)_150px_130px_minmax(180px,1fr)_42px] gap-3 border-b border-slate-200 bg-slate-50 px-4 py-2 text-xs font-semibold uppercase text-slate-500 dark:border-white/10 dark:bg-white/5 lg:grid">
                            <span>N°</span><span>Article</span><span>Action</span><span>Quantité</span><span>Note ligne</span><span></span>
                        </div>
                        <div data-stock-adjustment-lines class="divide-y divide-slate-200 dark:divide-white/10">
                            @foreach ($adjustmentLines as $i => $line)
                                <div data-stock-adjustment-row class="grid gap-3 bg-white p-4 transition focus-within:bg-brand/5 dark:bg-transparent lg:grid-cols-[44px_minmax(260px,1.7fr)_150px_130px_minmax(180px,1fr)_42px] lg:items-end">
                                    <span data-stock-adjustment-index class="hidden h-10 place-items-center rounded-lg bg-slate-100 text-xs font-semibold text-slate-500 dark:bg-white/5 lg:grid">{{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}</span>
                                    <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500 lg:hidden">Article <span class="text-rose-500">*</span></span><select name="items[{{ $i }}][item_id]" data-stock-adjustment-item-select class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900"><option value="">Choisir un article</option>@foreach ($stockItems as $item)<option value="{{ $item->id }}" data-title="{{ $item->title }}" data-stock="{{ $item->stock_quantity }}" data-threshold="{{ $item->min_stock_threshold }}" data-code="{{ $item->barcode ?? $item->isbn ?? $item->sku ?? $item->item_code }}" data-category="{{ $item->category?->name }}" data-brand="{{ $item->brand?->name }}" @selected(data_get($line, 'item_id') == $item->id)>{{ $item->title }} · stock {{ $item->stock_quantity }} · {{ $item->barcode ?? $item->isbn ?? $item->sku ?? $item->item_code }}{{ $item->category?->name ? ' · '.$item->category->name : '' }}</option>@endforeach</select></label>
                                    <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500 lg:hidden">Action</span><select name="items[{{ $i }}][direction]" class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900"><option value="add" @selected(data_get($line, 'direction', 'add') === 'add')>Ajouter</option><option value="remove" @selected(data_get($line, 'direction') === 'remove')>Retirer</option><option value="set" @selected(data_get($line, 'direction') === 'set')>Définir stock</option></select></label>
                                    <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500 lg:hidden">Quantité <span class="text-rose-500">*</span></span><input name="items[{{ $i }}][quantity]" value="{{ data_get($line, 'quantity') }}" data-stock-adjustment-quantity type="number" min="0" step="1" class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900" placeholder="0"></label>
                                    <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500 lg:hidden">Note ligne</span><input name="items[{{ $i }}][note]" value="{{ data_get($line, 'note') }}" class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900" placeholder="Motif ligne"></label>
                                    <button type="button" data-stock-adjustment-remove class="grid h-11 place-items-center rounded-lg border border-slate-200 text-lg font-semibold text-slate-400 transition hover:border-rose-200 hover:bg-rose-50 hover:text-rose-600 disabled:pointer-events-none disabled:opacity-40 dark:border-white/10 dark:hover:border-rose-500/30 dark:hover:bg-rose-500/10">×</button>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <template data-stock-adjustment-row-template>
                        <div data-stock-adjustment-row class="grid gap-3 bg-white p-4 transition focus-within:bg-brand/5 dark:bg-transparent lg:grid-cols-[44px_minmax(260px,1.7fr)_150px_130px_minmax(180px,1fr)_42px] lg:items-end">
                            <span data-stock-adjustment-index class="hidden h-10 place-items-center rounded-lg bg-slate-100 text-xs font-semibold text-slate-500 dark:bg-white/5 lg:grid">01</span>
                            <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500 lg:hidden">Article <span class="text-rose-500">*</span></span><select name="items[__INDEX__][item_id]" data-stock-adjustment-item-select class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900"><option value="">Choisir un article</option>@foreach ($stockItems as $item)<option value="{{ $item->id }}" data-title="{{ $item->title }}" data-stock="{{ $item->stock_quantity }}" data-threshold="{{ $item->min_stock_threshold }}" data-code="{{ $item->barcode ?? $item->isbn ?? $item->sku ?? $item->item_code }}" data-category="{{ $item->category?->name }}" data-brand="{{ $item->brand?->name }}">{{ $item->title }} · stock {{ $item->stock_quantity }} · {{ $item->barcode ?? $item->isbn ?? $item->sku ?? $item->item_code }}{{ $item->category?->name ? ' · '.$item->category->name : '' }}</option>@endforeach</select></label>
                            <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500 lg:hidden">Action</span><select name="items[__INDEX__][direction]" class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900"><option value="add">Ajouter</option><option value="remove">Retirer</option><option value="set">Définir stock</option></select></label>
                            <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500 lg:hidden">Quantité <span class="text-rose-500">*</span></span><input name="items[__INDEX__][quantity]" value="1" data-stock-adjustment-quantity type="number" min="0" step="1" class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900" placeholder="0"></label>
                            <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500 lg:hidden">Note ligne</span><input name="items[__INDEX__][note]" class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900" placeholder="Motif ligne"></label>
                            <button type="button" data-stock-adjustment-remove class="grid h-11 place-items-center rounded-lg border border-slate-200 text-lg font-semibold text-slate-400 transition hover:border-rose-200 hover:bg-rose-50 hover:text-rose-600 disabled:pointer-events-none disabled:opacity-40 dark:border-white/10 dark:hover:border-rose-500/30 dark:hover:bg-rose-500/10">×</button>
                        </div>
                    </template>

                    <label class="block space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Note globale</span><textarea name="note" class="min-h-24 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900" placeholder="Détails de l'inventaire...">{{ old('note') }}</textarea></label>
                </div>
                <div class="sticky bottom-0 flex flex-col gap-3 border-t border-slate-200 bg-white/95 p-4 backdrop-blur dark:border-white/10 dark:bg-slate-950/95 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-sm text-slate-500"><strong data-stock-adjustment-count class="text-slate-900 dark:text-white">0</strong> article(s) sélectionné(s), <strong data-stock-adjustment-total class="text-slate-900 dark:text-white">0</strong> unité(s) saisie(s).</p>
                    <button class="rounded-lg bg-brand px-5 py-2.5 text-sm font-semibold text-white shadow-sm shadow-brand/20 transition hover:bg-brand-600">Valider l'ajustement</button>
                </div>
            </form>
            <aside class="space-y-4 2xl:sticky 2xl:top-24 2xl:self-start">
                <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><h3 class="font-semibold">Résumé stock</h3><dl class="mt-4 space-y-3 text-sm"><div class="flex justify-between"><dt class="text-slate-500">Ajustements</dt><dd class="font-semibold">{{ $stockStats['adjustments'] }}</dd></div><div class="flex justify-between"><dt class="text-slate-500">Qté ajustée ce mois</dt><dd class="font-semibold">{{ number_format($stockStats['adjusted_month'], 0, ',', ' ') }}</dd></div><div class="flex justify-between"><dt class="text-slate-500">Alertes stock</dt><dd class="font-semibold text-amber-600">{{ $catalogStats['low'] }}</dd></div></dl></article>
            </aside>
        </section>
    @elseif ($panel === 'stock-transfer-add')
        <section class="mt-6 grid gap-6 xl:grid-cols-[1fr_340px]">
            <form action="{{ route('catalog.stock-transfers.store') }}" method="POST" class="space-y-5 rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                @csrf
                <div class="flex flex-col gap-2 border-b border-slate-200 pb-4 dark:border-white/10 sm:flex-row sm:items-center sm:justify-between">
                    <div><h2 class="text-lg font-semibold">Transfert de stock</h2><p class="mt-1 text-sm text-slate-500">Suivez les déplacements entre magasin, dépôt ou rayon sans modifier le stock global.</p></div>
                    <button class="rounded-lg bg-brand px-4 py-2.5 text-sm font-semibold text-white">Créer transfert</button>
                </div>
                <div class="grid gap-4 lg:grid-cols-5">
                    <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Date</span><input name="transferred_at" value="{{ old('transferred_at', now()->format('Y-m-d\TH:i')) }}" type="datetime-local" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                    <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Magasin source</span><input name="store_from" value="{{ old('store_from') }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Atlas"></label>
                    <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Entrepôt source</span><input name="warehouse_from" value="{{ old('warehouse_from') }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Dépôt A"></label>
                    <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Magasin destination</span><input name="store_to" value="{{ old('store_to') }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Boutique"></label>
                    <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Entrepôt destination</span><input name="warehouse_to" value="{{ old('warehouse_to') }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Rayon scolaire"></label>
                </div>
                <div class="space-y-3">
                    <div class="grid gap-2 text-xs font-semibold uppercase text-slate-500 lg:grid-cols-[2fr_120px_1fr_40px]"><span>Article</span><span>Quantité</span><span>Note ligne</span><span></span></div>
                    @for ($i = 0; $i < 8; $i++)
                        <div class="stock-line grid gap-2 lg:grid-cols-[2fr_120px_1fr_40px]">
                            <select name="items[{{ $i }}][item_id]" data-searchable-select data-placeholder="Rechercher article..." class="h-10 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="">Article</option>@foreach ($stockItems as $item)<option value="{{ $item->id }}" @selected(old("items.$i.item_id") == $item->id)>{{ $item->title }} · stock {{ $item->stock_quantity }} · {{ $item->barcode ?? $item->item_code }}</option>@endforeach</select>
                            <input name="items[{{ $i }}][quantity]" value="{{ old("items.$i.quantity") }}" type="number" min="1" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="0">
                            <input name="items[{{ $i }}][note]" value="{{ old("items.$i.note") }}" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Carton, rayon...">
                            <span class="grid h-10 place-items-center rounded-lg bg-slate-50 text-xs font-semibold text-slate-400 dark:bg-white/5">{{ $i + 1 }}</span>
                        </div>
                    @endfor
                </div>
                <label class="block space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Note globale</span><textarea name="note" class="min-h-24 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Transporteur, responsable, commentaire...">{{ old('note') }}</textarea></label>
            </form>
            <aside class="space-y-4">
                <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><h3 class="font-semibold">Résumé transferts</h3><dl class="mt-4 space-y-3 text-sm"><div class="flex justify-between"><dt class="text-slate-500">Transferts</dt><dd class="font-semibold">{{ $stockStats['transfers'] }}</dd></div><div class="flex justify-between"><dt class="text-slate-500">Qté transférée ce mois</dt><dd class="font-semibold">{{ number_format($stockStats['transferred_month'], 0, ',', ' ') }}</dd></div></dl></article>
                <article class="rounded-xl border border-slate-200 bg-white p-5 text-sm shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><h3 class="font-semibold">Version multi-dépôt</h3><p class="mt-2 text-slate-500">Le transfert prépare l'historique magasin/dépôt. Le stock par dépôt pourra ensuite s'appuyer sur ces lignes sans migration douloureuse.</p></article>
            </aside>
        </section>
    @elseif ($panel === 'stock-adjustments')
        <section class="mt-6 space-y-5">
            <div class="stock-kpi-grid grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                <article class="rounded-xl border border-slate-200 bg-white p-4 text-slate-950 shadow-sm dark:border-white/10 dark:bg-slate-950 dark:text-white">
                    <span class="text-xs font-semibold uppercase text-slate-500">Ajustements</span>
                    <p class="mt-2 text-2xl font-semibold text-slate-950 dark:text-white">{{ $stockStats['adjustments'] }}</p>
                    <p class="mt-1 text-xs font-medium text-slate-600 dark:text-slate-300">{{ number_format($stockStats['adjusted_month'], 0, ',', ' ') }} unité(s) ce mois</p>
                </article>
                <article class="stock-kpi-card is-volume rounded-xl border border-slate-200 bg-white p-4 text-slate-950 shadow-sm dark:border-white/10 dark:bg-slate-950 dark:text-white">
                    <span class="text-xs font-semibold uppercase text-slate-500">Volume stock</span>
                    <p class="mt-2 font-semibold text-slate-950 dark:text-white">{{ number_format($stockStats['stock_units'], 0, ',', ' ') }}</p>
                    <p class="mt-1 text-xs font-medium text-slate-600 dark:text-slate-300">Unités physiques disponibles</p>
                </article>
                <article class="stock-kpi-card is-value rounded-xl border border-slate-200 bg-white p-4 text-slate-950 shadow-sm dark:border-white/10 dark:bg-slate-950 dark:text-white">
                    <span class="text-xs font-semibold uppercase text-slate-500">Valeur achat</span>
                    <p class="mt-2 font-semibold text-slate-950 dark:text-white">{{ $money($stockStats['stock_purchase_value']) }}</p>
                    <p class="mt-1 text-xs font-medium text-slate-600 dark:text-slate-300">Stock × prix d'achat</p>
                </article>
                <article class="stock-kpi-card is-value rounded-xl border border-slate-200 bg-white p-4 text-slate-950 shadow-sm dark:border-white/10 dark:bg-slate-950 dark:text-white">
                    <span class="text-xs font-semibold uppercase text-slate-500">Valeur vente</span>
                    <p class="mt-2 font-semibold text-slate-950 dark:text-white">{{ $money($stockStats['stock_sale_value']) }}</p>
                    <p class="mt-1 text-xs font-medium text-slate-600 dark:text-slate-300">Potentiel de vente</p>
                </article>
                <article class="rounded-xl border border-amber-300 bg-amber-100 p-4 shadow-sm dark:border-amber-400/30 dark:bg-amber-950/60">
                    <span class="text-xs font-semibold uppercase text-amber-900 dark:text-amber-100">Alertes stock</span>
                    <p class="mt-2 text-2xl font-semibold text-amber-950 dark:text-amber-50">{{ $catalogStats['low'] }}</p>
                    <p class="mt-1 text-xs font-semibold text-amber-900 dark:text-amber-100">À vérifier avant vente</p>
                </article>
            </div>

            <article class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                <div class="border-b border-slate-200 p-4 dark:border-white/10">
                    <div class="flex flex-col gap-3 xl:flex-row xl:items-end xl:justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-brand">Inventaire courant</p>
                            <h2 class="mt-1 text-lg font-semibold text-slate-950 dark:text-white">Stock par article</h2>
                            <p class="mt-1 text-sm text-slate-500">Tous les articles physiques avec volume, valeur d'achat, valeur de vente et accès direct à l'historique.</p>
                        </div>
                        <form method="GET" action="{{ route('stock') }}" class="grid w-full gap-2 sm:grid-cols-[minmax(220px,1fr)_180px_auto_auto] xl:max-w-4xl">
                            <input type="hidden" name="panel" value="stock-adjustments">
                            <input name="stock_inventory_q" value="{{ $stockInventoryQuery }}" class="h-11 rounded-lg border border-slate-200 bg-slate-50 px-3 text-sm outline-none transition focus:border-brand focus:ring-4 focus:ring-brand/10 dark:border-white/10 dark:bg-white/5" placeholder="Rechercher article, code, ISBN, emplacement...">
                            <select name="stock_inventory_state" class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-brand focus:ring-4 focus:ring-brand/10 dark:border-white/10 dark:bg-slate-900">
                                <option value="all" @selected($stockInventoryState === 'all')>Tous les stocks</option>
                                <option value="available" @selected($stockInventoryState === 'available')>Disponible</option>
                                <option value="low" @selected($stockInventoryState === 'low')>Sous alerte</option>
                                <option value="out" @selected($stockInventoryState === 'out')>Rupture</option>
                            </select>
                            <button class="h-11 rounded-lg bg-brand px-4 text-sm font-semibold text-white shadow-sm shadow-indigo-500/20">Filtrer</button>
                            <a href="{{ route('stock', ['panel' => 'stock-adjustments']) }}" class="grid h-11 place-items-center rounded-lg border border-slate-200 px-4 text-sm font-semibold text-slate-700 transition hover:border-brand/40 hover:text-brand dark:border-white/10 dark:text-slate-200">Reset</a>
                        </form>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[1120px] text-left text-sm">
                        <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-white/5">
                            <tr>
                                <th class="px-4 py-3">Article</th>
                                <th class="px-3 py-3">Références</th>
                                <th class="px-3 py-3">Emplacement</th>
                                <th class="px-3 py-3 text-right">Volume</th>
                                <th class="px-3 py-3 text-right">Valeur achat</th>
                                <th class="px-3 py-3 text-right">Valeur vente</th>
                                <th class="px-3 py-3">État</th>
                                <th class="px-4 py-3 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-white/10">
                            @forelse ($stockInventoryItems as $stockItem)
                                @php
                                    $purchaseValue = (float) $stockItem->purchase_price * (int) $stockItem->stock_quantity;
                                    $saleValue = (float) $stockItem->sale_price * (int) $stockItem->stock_quantity;
                                    $isOut = (int) $stockItem->stock_quantity <= 0;
                                    $isLow = ! $isOut && (int) $stockItem->stock_quantity <= (int) $stockItem->min_stock_threshold;
                                    $historyUrl = route('stock', ['panel' => 'stock-adjustments', 'inventory_item' => $stockItem->id]).'#inventory-history';
                                    $adjustUrl = route('stock', ['panel' => 'stock-adjustment-add', 'stock_q' => $stockItem->item_code ?? $stockItem->barcode ?? $stockItem->title]);
                                @endphp
                                <tr class="cursor-pointer transition hover:bg-slate-50/80 dark:hover:bg-white/[0.03]" onclick="if (! event.target.closest('a,button')) window.location.href = @json($historyUrl)">
                                    <td class="px-4 py-3">
                                        <div class="flex items-center gap-3">
                                            <div class="grid size-10 shrink-0 place-items-center rounded-lg bg-slate-100 text-xs font-bold text-slate-600 dark:bg-white/10 dark:text-slate-200">{{ \Illuminate\Support\Str::of($stockItem->title)->substr(0, 2)->upper() }}</div>
                                            <div>
                                                <p class="font-semibold text-slate-950 dark:text-white">{{ $stockItem->title }}</p>
                                                <p class="mt-1 text-xs text-slate-500">{{ $stockItem->category?->name ?? 'Sans catégorie' }} · {{ $stockItem->brand?->name ?? 'Sans marque' }}</p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-3 py-3 text-xs text-slate-500">
                                        <span class="block font-semibold text-slate-700 dark:text-slate-200">{{ $stockItem->item_code ?? 'Sans code' }}</span>
                                        <span>{{ $stockItem->barcode ?? $stockItem->isbn ?? 'Sans code-barres' }}</span>
                                    </td>
                                    <td class="px-3 py-3 text-sm text-slate-500">{{ $stockItem->location ?: $stockItem->warehouse ?: 'Principal' }}</td>
                                    <td class="px-3 py-3 text-right">
                                        <span class="stock-table-number is-volume">{{ number_format((int) $stockItem->stock_quantity, 0, ',', ' ') }}</span>
                                        <span class="block text-xs text-slate-500">{{ $stockItem->unit?->name ?? 'unité(s)' }}</span>
                                    </td>
                                    <td class="px-3 py-3 text-right"><span class="stock-table-number">{{ $money($purchaseValue) }}</span></td>
                                    <td class="px-3 py-3 text-right"><span class="stock-table-number">{{ $money($saleValue) }}</span></td>
                                    <td class="px-3 py-3">
                                        @if ($isOut)
                                            <span class="inline-flex rounded-full bg-rose-50 px-3 py-1 text-xs font-semibold text-rose-700 ring-1 ring-inset ring-rose-200 dark:bg-rose-500/10 dark:text-rose-100 dark:ring-rose-500/20">Rupture</span>
                                        @elseif ($isLow)
                                            <span class="inline-flex rounded-full bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-200 dark:bg-amber-500/10 dark:text-amber-100 dark:ring-amber-500/20">Sous alerte</span>
                                        @else
                                            <span class="inline-flex rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-100 dark:ring-emerald-500/20">Disponible</span>
                                        @endif
                                        <span class="mt-1 block text-xs text-slate-500">{{ (int) $stockItem->stock_movements_count }} mouvement(s)</span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex justify-end gap-2">
                                            <a href="{{ $historyUrl }}" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold transition hover:border-brand hover:text-brand dark:border-white/10">Historique</a>
                                            <a href="{{ $adjustUrl }}" class="rounded-lg bg-slate-900 px-3 py-2 text-xs font-semibold text-white transition hover:bg-brand dark:bg-white dark:text-slate-950">Ajuster</a>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="px-4 py-12 text-center text-sm text-slate-500">Aucun article trouvé pour cet inventaire.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="border-t border-slate-200 px-4 py-3 dark:border-white/10">{{ $stockInventoryItems->links() }}</div>
            </article>

            <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h2 class="font-semibold">Liste d'ajustement</h2>
                        <p class="mt-1 text-sm text-slate-500">Corrections de stock, inventaires, pertes, casses et détails par ligne.</p>
                    </div>
                    <a href="{{ route('stock', ['panel' => 'stock-adjustment-add']) }}" class="inline-flex h-10 items-center justify-center rounded-lg bg-brand px-4 text-sm font-semibold text-white shadow-sm shadow-indigo-500/20">Nouvel ajustement</a>
                </div>
                <form method="GET" action="{{ route('stock') }}" class="mt-4 grid gap-3 lg:grid-cols-[minmax(240px,1fr)_170px_170px_auto]">
                    <input type="hidden" name="panel" value="stock-adjustments">
                    <input name="q" value="{{ request('q') }}" class="h-11 rounded-lg border border-slate-200 bg-slate-50 px-3 text-sm outline-none transition focus:border-brand focus:ring-4 focus:ring-brand/10 dark:border-white/10 dark:bg-white/5" placeholder="N°, article, raison, entrepôt...">
                    <input name="from" value="{{ request('from') }}" type="date" class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-brand focus:ring-4 focus:ring-brand/10 dark:border-white/10 dark:bg-slate-900">
                    <input name="to" value="{{ request('to') }}" type="date" class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-brand focus:ring-4 focus:ring-brand/10 dark:border-white/10 dark:bg-slate-900">
                    <div class="grid grid-cols-2 gap-2">
                        <button class="h-11 rounded-lg bg-brand px-4 text-sm font-semibold text-white">Filtrer</button>
                        <a href="{{ route('stock', ['panel' => 'stock-adjustments']) }}" class="grid h-11 place-items-center rounded-lg border border-slate-200 px-4 text-sm font-semibold dark:border-white/10">Reset</a>
                    </div>
                </form>
            </article>

            <article class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[1040px] text-left text-sm">
                        <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-white/5">
                            <tr>
                                <th class="px-3 py-3">N°</th>
                                <th class="px-3 py-3">Date</th>
                                <th class="px-3 py-3">Entrepôt</th>
                                <th class="px-3 py-3">Raison</th>
                                <th class="px-3 py-3 text-right">Quantité</th>
                                <th class="px-3 py-3">Articles</th>
                                <th class="px-3 py-3 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-white/10">
                            @forelse ($stockAdjustments as $adjustment)
                                @php
                                    $adjustmentLines = collect($adjustment->lines ?? []);
                                    $positiveLines = $adjustmentLines->where('quantity_delta', '>', 0)->count();
                                    $negativeLines = $adjustmentLines->where('quantity_delta', '<', 0)->count();
                                    $stockValueImpact = $adjustmentLines->sum(fn ($line) => abs((int) ($line['quantity_delta'] ?? 0)));
                                @endphp
                                <tr class="transition hover:bg-slate-50/80 dark:hover:bg-white/[0.03]">
                                    <td class="px-3 py-3 font-semibold">{{ $adjustment->number }}</td>
                                    <td class="px-3 py-3">{{ $adjustment->adjusted_at?->format('d/m/Y H:i') }}</td>
                                    <td class="px-3 py-3">{{ $adjustment->warehouse ?? 'Principal' }}</td>
                                    <td class="px-3 py-3">
                                        <span class="font-medium">{{ $adjustment->reason ?? '—' }}</span>
                                        @if ($adjustment->note)
                                            <span class="mt-1 block max-w-xs truncate text-xs text-slate-500">{{ $adjustment->note }}</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-3 text-right font-semibold">{{ number_format($adjustment->total_quantity, 0, ',', ' ') }}</td>
                                    <td class="px-3 py-3 text-sm text-slate-500">
                                        <span class="font-medium text-slate-700 dark:text-slate-200">{{ $adjustmentLines->pluck('name')->take(2)->implode(', ') ?: '—' }}</span>{{ $adjustmentLines->count() > 2 ? '…' : '' }}
                                        <span class="mt-1 block text-xs">{{ $positiveLines }} entrée(s), {{ $negativeLines }} sortie(s)</span>
                                    </td>
                                    <td class="px-3 py-3 text-right">
                                        <button type="button" onclick="document.getElementById('adjustment-detail-{{ $adjustment->id }}').showModal()" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold transition hover:border-brand hover:text-brand dark:border-white/10">Détail</button>
                                    </td>
                                </tr>
                                <dialog id="adjustment-detail-{{ $adjustment->id }}" class="app-dialog w-[min(980px,calc(100vw-2rem))] rounded-2xl border border-slate-200 bg-white p-0 text-slate-950 shadow-2xl backdrop:bg-slate-950/45 dark:border-white/10 dark:bg-slate-950 dark:text-slate-100">
                                    <div class="border-b border-slate-200 p-5 dark:border-white/10">
                                        <div class="flex items-start justify-between gap-4">
                                            <div>
                                                <p class="text-sm font-semibold text-brand">Détail ajustement stock</p>
                                                <h3 class="mt-1 text-xl font-semibold">{{ $adjustment->number }}</h3>
                                                <p class="mt-1 text-sm text-slate-500">{{ $adjustment->reason ?? 'Sans raison' }} · {{ $adjustment->adjusted_at?->format('d/m/Y H:i') }} · {{ $adjustment->warehouse ?? 'Principal' }}</p>
                                            </div>
                                            <button class="dialog-close grid size-9 shrink-0 place-items-center rounded-lg border border-slate-200 text-lg font-semibold dark:border-white/10" type="button">×</button>
                                        </div>
                                    </div>
                                    <div class="grid gap-5 p-5 lg:grid-cols-[1fr_260px]">
                                        <div class="space-y-3">
                                            @foreach ($adjustmentLines as $line)
                                                @php
                                                    $delta = (int) ($line['quantity_delta'] ?? 0);
                                                    $deltaTone = $delta >= 0 ? 'text-emerald-700 bg-emerald-50 ring-emerald-200 dark:text-emerald-100 dark:bg-emerald-500/10 dark:ring-emerald-500/20' : 'text-rose-700 bg-rose-50 ring-rose-200 dark:text-rose-100 dark:bg-rose-500/10 dark:ring-rose-500/20';
                                                @endphp
                                                <div class="rounded-xl border border-slate-200 p-4 dark:border-white/10">
                                                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                                        <div>
                                                            <h4 class="font-semibold">{{ $line['name'] }}</h4>
                                                            <p class="mt-1 text-xs text-slate-500">{{ $line['item_code'] ?? 'Sans code' }} · {{ $line['barcode'] ?? 'Sans code-barres' }}</p>
                                                        </div>
                                                        <span class="inline-flex w-fit items-center rounded-full px-3 py-1 text-sm font-bold ring-1 ring-inset {{ $deltaTone }}">{{ $delta > 0 ? '+' : '' }}{{ number_format($delta, 0, ',', ' ') }}</span>
                                                    </div>
                                                    <div class="mt-4 grid gap-2 sm:grid-cols-3">
                                                        <div class="rounded-lg bg-slate-50 p-3 dark:bg-white/5"><span class="block text-xs text-slate-500">Avant</span><strong>{{ number_format((int) ($line['quantity_before'] ?? 0), 0, ',', ' ') }}</strong></div>
                                                        <div class="rounded-lg bg-slate-50 p-3 dark:bg-white/5"><span class="block text-xs text-slate-500">Mouvement</span><strong>{{ $line['direction'] === 'set' ? 'Définir' : ($line['direction'] === 'remove' ? 'Retirer' : 'Ajouter') }}</strong></div>
                                                        <div class="rounded-lg bg-slate-50 p-3 dark:bg-white/5"><span class="block text-xs text-slate-500">Après</span><strong>{{ number_format((int) ($line['quantity_after'] ?? 0), 0, ',', ' ') }}</strong></div>
                                                    </div>
                                                    @if (! empty($line['note']))
                                                        <p class="mt-3 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600 dark:bg-white/5 dark:text-slate-300">{{ $line['note'] }}</p>
                                                    @endif
                                                    <div class="mt-3 flex flex-wrap gap-2">
                                                        <a href="{{ route('stock', ['panel' => 'stock-adjustments', 'inventory_item' => $line['item_id']]) }}#inventory-history" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold transition hover:border-brand hover:text-brand dark:border-white/10">Historique article</a>
                                                        <a href="{{ route('stock', ['panel' => 'stock-adjustment-add', 'stock_q' => $line['item_code'] ?? $line['barcode'] ?? $line['name']]) }}" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold transition hover:border-brand hover:text-brand dark:border-white/10">Ajuster encore</a>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                        <aside class="space-y-3">
                                            <div class="rounded-xl bg-slate-50 p-4 dark:bg-white/5">
                                                <h4 class="font-semibold">Résumé</h4>
                                                <dl class="mt-3 space-y-2 text-sm">
                                                    <div class="flex justify-between"><dt class="text-slate-500">Lignes</dt><dd class="font-semibold">{{ $adjustmentLines->count() }}</dd></div>
                                                    <div class="flex justify-between"><dt class="text-slate-500">Entrées</dt><dd class="font-semibold text-emerald-600">{{ $positiveLines }}</dd></div>
                                                    <div class="flex justify-between"><dt class="text-slate-500">Sorties</dt><dd class="font-semibold text-rose-600">{{ $negativeLines }}</dd></div>
                                                    <div class="flex justify-between"><dt class="text-slate-500">Volume touché</dt><dd class="font-semibold">{{ number_format($stockValueImpact, 0, ',', ' ') }}</dd></div>
                                                </dl>
                                            </div>
                                            <div class="rounded-xl border border-slate-200 p-4 text-sm dark:border-white/10">
                                                <span class="block text-xs font-semibold uppercase text-slate-500">Note globale</span>
                                                <p class="mt-2 text-slate-600 dark:text-slate-300">{{ $adjustment->note ?: 'Aucune note ajoutée.' }}</p>
                                            </div>
                                        </aside>
                                    </div>
                                </dialog>
                            @empty
                                <tr><td colspan="7" class="px-4 py-12 text-center text-sm text-slate-500">Aucun ajustement trouvé.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="border-t border-slate-200 px-4 py-3 dark:border-white/10">{{ $stockAdjustments->links() }}</div>
            </article>

            <article id="inventory-history" class="inventory-history-card rounded-xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                <div class="inventory-history-head">
                    <div class="min-w-0">
                        <div class="flex items-start gap-3">
                            <span class="inventory-history-icon">ST</span>
                            <div class="min-w-0">
                                <h2 class="font-semibold text-slate-950 dark:text-white">Historique inventaire par article</h2>
                                <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">{{ $stockStats['movement_count'] }} mouvement(s) enregistrés: ventes, achats, retours, transferts et ajustements.</p>
                            </div>
                        </div>
                        @if ($selectedInventoryItem)
                            <div class="inventory-selected-item mt-4">
                                <div class="min-w-0">
                                    <span class="block truncate font-semibold text-slate-950 dark:text-white">{{ $selectedInventoryItem->title }}</span>
                                    <small class="mt-1 block truncate text-slate-600 dark:text-slate-300">{{ $selectedInventoryItem->item_code ?? 'Sans code' }} · Stock {{ number_format((int) $selectedInventoryItem->stock_quantity, 0, ',', ' ') }} · {{ $selectedInventoryItem->category?->name ?? 'Sans catégorie' }}</small>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <a href="{{ route('stock', ['panel' => 'stock-adjustment-add', 'item' => $selectedInventoryItem->id, 'stock_q' => $selectedInventoryItem->item_code ?? $selectedInventoryItem->barcode ?? $selectedInventoryItem->title]) }}" class="inventory-action-button is-primary">Ajuster maintenant</a>
                                    <a href="{{ route('stock', ['panel' => 'stock-adjustments']) }}#inventory-history" class="inventory-action-button">Tous les articles</a>
                                </div>
                            </div>
                        @endif
                    </div>
                    <form method="GET" action="{{ route('stock') }}" class="inventory-search-form" data-inventory-item-picker data-inventory-item-search-url="{{ route('catalog.stock-items.search') }}">
                        <input type="hidden" name="panel" value="stock-adjustments">
                        <input type="hidden" name="inventory_item" value="{{ $selectedInventoryItem?->id }}" data-inventory-item-id>
                        <div class="inventory-item-picker">
                            <input
                                name="inventory_q"
                                value="{{ $selectedInventoryItem ? $selectedInventoryItem->title : $inventoryQuery }}"
                                class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-brand focus:ring-4 focus:ring-brand/10 dark:border-white/10 dark:bg-slate-950"
                                placeholder="Rechercher article, code-barres, ISBN..."
                                autocomplete="off"
                                data-inventory-item-input
                            >
                            <div class="inventory-item-results" data-inventory-item-results hidden></div>
                        </div>
                        <button class="inventory-action-button is-primary">Chercher</button>
                        <a href="{{ route('stock', ['panel' => 'stock-adjustments']) }}#inventory-history" class="inventory-action-button">Effacer</a>
                    </form>
                </div>
                <div class="inventory-movement-list" data-inventory-movement-list>
                    @forelse ($stockMovements as $movement)
                        @php
                            $delta = (int) $movement->quantity_delta;
                            $movementLabels = [
                                'sale' => ['label' => 'Vente', 'tone' => 'danger', 'icon' => '-'],
                                'purchase' => ['label' => 'Achat', 'tone' => 'success', 'icon' => '+'],
                                'return' => ['label' => 'Retour vente', 'tone' => 'info', 'icon' => '+'],
                                'purchase_return' => ['label' => 'Retour achat', 'tone' => 'warning', 'icon' => '-'],
                                'adjustment' => ['label' => 'Ajustement', 'tone' => 'primary', 'icon' => '±'],
                                'transfer' => ['label' => 'Transfert', 'tone' => 'neutral', 'icon' => '↔'],
                                'opening_stock' => ['label' => 'Stock initial', 'tone' => 'success', 'icon' => '+'],
                                'item_update' => ['label' => 'Fiche article', 'tone' => 'info', 'icon' => '✎'],
                                'import_opening_stock' => ['label' => 'Import initial', 'tone' => 'success', 'icon' => '+'],
                                'import_stock_update' => ['label' => 'Import stock', 'tone' => 'info', 'icon' => '↥'],
                            ];
                            $movementMeta = $movementLabels[$movement->type] ?? ['label' => $movement->type, 'tone' => 'neutral', 'icon' => '•'];
                            $relatedAction = match ($movement->reference_type) {
                                \App\Models\Sale::class => ['label' => 'Voir vente', 'icon' => 'VE', 'href' => route('module', ['module' => 'sales', 'section' => 'list', 'detail_sale' => $movement->reference_id])],
                                \App\Models\SaleReturn::class => ['label' => 'Voir retour', 'icon' => 'RV', 'href' => route('module', ['module' => 'sales', 'section' => 'returns', 'detail_return' => $movement->reference_id])],
                                \App\Models\Purchase::class => ['label' => 'Voir achat', 'icon' => 'AC', 'href' => route('module', ['module' => 'purchases', 'section' => 'list', 'detail_purchase' => $movement->reference_id])],
                                \App\Models\PurchaseReturn::class => ['label' => 'Voir retour achat', 'icon' => 'RA', 'href' => route('module', ['module' => 'purchases', 'section' => 'returns', 'detail_purchase_return' => $movement->reference_id])],
                                \App\Models\StockAdjustment::class => ['label' => 'Voir ajustement', 'icon' => 'AJ', 'href' => route('stock', ['panel' => 'stock-adjustments', 'detail_adjustment' => $movement->reference_id])],
                                \App\Models\StockTransfer::class => ['label' => 'Voir transfert', 'icon' => 'TR', 'href' => route('stock', ['panel' => 'stock-transfers', 'detail_transfer' => $movement->reference_id])],
                                \App\Models\Item::class => ['label' => 'Voir article', 'icon' => 'AR', 'href' => route('catalog', ['panel' => 'articles', 'edit' => $movement->reference_id]).'#edit-item'],
                                default => null,
                            };
                            $valueImpact = abs($delta) * (float) $movement->purchase_price;
                        @endphp
                        <div class="inventory-movement-row is-{{ $movementMeta['tone'] }}">
                            <div class="inventory-movement-type">
                                <span class="inventory-movement-badge"><span aria-hidden="true">{{ $movementMeta['icon'] }}</span>{{ $movementMeta['label'] }}</span>
                                <time>{{ \Illuminate\Support\Carbon::parse($movement->created_at)->format('d/m/Y H:i') }}</time>
                            </div>
                            <div class="min-w-0">
                                <p class="font-semibold text-slate-950 dark:text-white">{{ $movement->item_title }}</p>
                                <p class="mt-1 text-xs text-slate-600 dark:text-slate-300">{{ $movement->item_code ?? 'Sans code' }} · {{ $movement->barcode ?? 'Sans code-barres' }} · {{ $movement->user_name ?? 'Système' }}</p>
                                @if ($movement->note)
                                    <p class="inventory-movement-note">{{ $movement->note }}</p>
                                @endif
                            </div>
                            <div class="inventory-movement-metrics">
                                <div class="inventory-metric">
                                    <span>Mouvement</span>
                                    <strong class="{{ $delta >= 0 ? 'is-positive' : 'is-negative' }}">{{ $delta > 0 ? '+' : '' }}{{ number_format($delta, 0, ',', ' ') }}</strong>
                                </div>
                                <div class="inventory-metric">
                                    <span>Stock après</span>
                                    <strong>{{ number_format((int) $movement->quantity_after, 0, ',', ' ') }}</strong>
                                </div>
                            </div>
                            <div class="inventory-movement-actions">
                                <span class="inventory-value">{{ $money($valueImpact) }}</span>
                                @if ($relatedAction)
                                    <a href="{{ $relatedAction['href'] }}" class="inventory-action-button is-linked">
                                        <span aria-hidden="true">{{ $relatedAction['icon'] }}</span>
                                        {{ $relatedAction['label'] }}
                                    </a>
                                @endif
                                <a href="{{ route('stock', ['panel' => 'stock-adjustment-add', 'item' => $movement->item_id, 'stock_q' => $movement->item_code ?? $movement->barcode ?? $movement->item_title]) }}" class="inventory-action-button is-dark">Ajuster</a>
                            </div>
                        </div>
                    @empty
                        <div class="p-10 text-center text-sm text-slate-500">Aucun mouvement de stock trouvé pour ces filtres.</div>
                    @endforelse
                </div>
                @if ($stockMovements->hasPages() || $stockMovements->total() > 0)
                    <div class="inventory-movement-footer">
                        <span>
                            Affichage {{ $stockMovements->firstItem() ?? 0 }}-{{ $stockMovements->lastItem() ?? 0 }}
                            sur {{ number_format($stockMovements->total(), 0, ',', ' ') }} mouvement(s)
                        </span>
                        <div>{{ $stockMovements->links() }}</div>
                    </div>
                @endif
            </article>
        </section>
    @elseif ($panel === 'stock-transfers')
        <section class="mt-6 space-y-5">
            <div class="grid gap-3 md:grid-cols-2"><article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><span class="text-xs font-semibold uppercase text-slate-500">Transferts</span><p class="mt-2 text-2xl font-semibold">{{ $stockStats['transfers'] }}</p></article><article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><span class="text-xs font-semibold uppercase text-slate-500">Quantité ce mois</span><p class="mt-2 text-2xl font-semibold">{{ number_format($stockStats['transferred_month'], 0, ',', ' ') }}</p></article></div>
            <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between"><div><h2 class="font-semibold">Liste de transfert</h2><p class="mt-1 text-sm text-slate-500">Suivi des déplacements entre magasins, dépôts et rayons.</p></div><a href="{{ route('stock', ['panel' => 'stock-transfer-add']) }}" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Nouveau transfert</a></div><form method="GET" action="{{ route('stock') }}" class="app-action-form mt-4"><input type="hidden" name="panel" value="stock-transfers"><input name="q" value="{{ request('q') }}" class="h-11 rounded-lg border border-slate-200 bg-slate-50 px-3 text-sm dark:border-white/10 dark:bg-white/5" placeholder="Rechercher n°, article, magasin, entrepôt..."><input name="from" value="{{ request('from') }}" type="date" class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><input name="to" value="{{ request('to') }}" type="date" class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><div class="flex gap-2"><button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Filtrer</button><a href="{{ route('stock', ['panel' => 'stock-transfers']) }}" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold dark:border-white/10">Reset</a></div></form></article>
            <article class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><div class="overflow-x-auto"><table class="w-full min-w-[1040px] text-left text-sm"><thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-white/5"><tr><th class="px-3 py-3">N°</th><th class="px-3 py-3">Date</th><th class="px-3 py-3">Source</th><th class="px-3 py-3">Destination</th><th class="px-3 py-3 text-right">Quantité</th><th class="px-3 py-3">Articles</th><th class="px-3 py-3 text-right">Action</th></tr></thead><tbody class="divide-y divide-slate-200 dark:divide-white/10">@forelse ($stockTransfers as $transfer)<tr><td class="px-3 py-3 font-semibold">{{ $transfer->number }}</td><td class="px-3 py-3">{{ $transfer->transferred_at?->format('d/m/Y H:i') }}</td><td class="px-3 py-3">{{ collect([$transfer->store_from, $transfer->warehouse_from])->filter()->implode(' · ') ?: '—' }}</td><td class="px-3 py-3">{{ collect([$transfer->store_to, $transfer->warehouse_to])->filter()->implode(' · ') ?: '—' }}</td><td class="px-3 py-3 text-right font-semibold">{{ number_format($transfer->total_quantity, 0, ',', ' ') }}</td><td class="px-3 py-3 text-sm text-slate-500">{{ collect($transfer->lines)->pluck('name')->take(2)->implode(', ') }}{{ count($transfer->lines ?? []) > 2 ? '…' : '' }}</td><td class="px-3 py-3 text-right"><button type="button" onclick="document.getElementById('transfer-detail-{{ $transfer->id }}').showModal()" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold dark:border-white/10">Détail</button></td></tr><dialog id="transfer-detail-{{ $transfer->id }}" class="w-full max-w-2xl rounded-2xl border border-slate-200 bg-white p-0 text-slate-950 shadow-2xl backdrop:bg-slate-950/40 dark:border-white/10 dark:bg-slate-950 dark:text-slate-100"><div class="border-b border-slate-200 p-5 dark:border-white/10"><div class="flex justify-between gap-4"><div><p class="text-sm font-semibold text-brand">Transfert stock</p><h3 class="mt-1 text-xl font-semibold">{{ $transfer->number }}</h3><p class="mt-1 text-sm text-slate-500">{{ collect([$transfer->store_from, $transfer->warehouse_from])->filter()->implode(' · ') ?: 'Source' }} → {{ collect([$transfer->store_to, $transfer->warehouse_to])->filter()->implode(' · ') ?: 'Destination' }}</p></div><button class="dialog-close grid size-9 place-items-center rounded-lg border border-slate-200 dark:border-white/10" type="button">×</button></div></div><div class="space-y-2 p-5">@foreach ($transfer->lines as $line)<div class="flex justify-between rounded-lg bg-slate-50 p-3 text-sm dark:bg-white/5"><strong>{{ $line['name'] }}</strong><span>{{ $line['quantity'] }} unité(s)</span></div>@endforeach</div></dialog>@empty<tr><td colspan="7" class="px-4 py-12 text-center text-sm text-slate-500">Aucun transfert trouvé.</td></tr>@endforelse</tbody></table></div><div class="border-t border-slate-200 px-4 py-3 dark:border-white/10">{{ $stockTransfers->links() }}</div></article>
        </section>
    @elseif (in_array($panel, ['articles', 'services'], true))
        <section class="mt-6 space-y-6">
            <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                <form action="{{ route('catalog') }}" class="flex flex-wrap items-end gap-3">
                    <input type="hidden" name="panel" value="{{ $panel }}">
                    <label class="block min-w-[220px] flex-[1_1_280px]"><span class="text-xs font-semibold uppercase text-slate-500">Recherche rapide</span><input name="q" value="{{ $query }}" class="mt-1 h-11 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 text-sm outline-none focus:border-brand focus:ring-4 focus:ring-brand/10 dark:border-white/10 dark:bg-white/5" placeholder="Nom, code barre, ISBN, SKU"></label>
                    <label class="block min-w-[135px] flex-[1_1_145px]"><span class="text-xs font-semibold uppercase text-slate-500">Type</span><select name="type" class="mt-1 h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900" @disabled($panel === 'services')><option value="all" @selected($type === 'all')>Tous</option><option value="book" @selected($type === 'book')>Livre</option><option value="supply" @selected($type === 'supply')>Papeterie</option><option value="service" @selected($type === 'service')>Service</option></select></label>
                    <label class="block min-w-[180px] flex-[1_1_210px]"><span class="text-xs font-semibold uppercase text-slate-500">Catégorie</span><select name="category" class="mt-1 h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="all">Toutes</option>@foreach ($categories as $category)<option value="{{ $category->id }}" @selected((string) $categoryFilter === (string) $category->id)>{{ $category->name }}</option>@endforeach</select></label>
                    <label class="block min-w-[170px] flex-[1_1_190px]"><span class="text-xs font-semibold uppercase text-slate-500">Marque / éditeur</span><select name="brand" class="mt-1 h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="all">Toutes</option>@foreach ($brands as $brand)<option value="{{ $brand->id }}" @selected((string) $brandFilter === (string) $brand->id)>{{ $brand->name }}</option>@endforeach</select></label>
                    <label class="block min-w-[140px] flex-[1_1_150px]"><span class="text-xs font-semibold uppercase text-slate-500">Unité</span><select name="unit" class="mt-1 h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="all">Toutes</option>@foreach ($units as $unit)<option value="{{ $unit->id }}" @selected((string) $unitFilter === (string) $unit->id)>{{ $unit->name }}</option>@endforeach</select></label>
                    <label class="block min-w-[140px] flex-[1_1_150px]"><span class="text-xs font-semibold uppercase text-slate-500">Impôt</span><select name="tax" class="mt-1 h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="all">Tous</option>@foreach ($taxes as $tax)<option value="{{ $tax->id }}" @selected((string) $taxFilter === (string) $tax->id)>{{ $tax->name }}</option>@endforeach</select></label>
                    <label class="block min-w-[135px] flex-[1_1_145px]"><span class="text-xs font-semibold uppercase text-slate-500">Stock</span><select name="stock" class="mt-1 h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="all" @selected($stock === 'all')>Tout</option><option value="low" @selected($stock === 'low')>Stock bas</option><option value="out" @selected($stock === 'out')>Rupture</option></select></label>
                    <label class="block min-w-[135px] flex-[1_1_145px]"><span class="text-xs font-semibold uppercase text-slate-500">Statut</span><select name="status" class="mt-1 h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="all" @selected($status === 'all')>Tous</option><option value="active" @selected($status === 'active')>Actif</option><option value="archived" @selected($status === 'archived')>Archivé</option><option value="out_of_stock" @selected($status === 'out_of_stock')>Rupture</option></select></label>
                    <label class="block min-w-[105px] flex-[1_1_115px]"><span class="text-xs font-semibold uppercase text-slate-500">Prix min</span><input name="min_price" value="{{ request('min_price') }}" inputmode="decimal" class="mt-1 h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Min DH"></label>
                    <label class="block min-w-[105px] flex-[1_1_115px]"><span class="text-xs font-semibold uppercase text-slate-500">Prix max</span><input name="max_price" value="{{ request('max_price') }}" inputmode="decimal" class="mt-1 h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Max DH"></label>
                    <div class="grid min-w-[180px] flex-[0_1_220px] grid-cols-2 gap-2 max-sm:flex-1">
                        <button class="h-11 rounded-lg bg-brand px-4 text-sm font-semibold text-white" type="submit">Filtrer</button>
                        <a href="{{ route('catalog', ['panel' => $panel]) }}" class="grid h-11 place-items-center rounded-lg border border-slate-200 px-4 text-sm font-semibold dark:border-white/10">Reset</a>
                    </div>
                </form>
            </article>

            <article class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.03] catalog-grid-shell">
                <div class="flex flex-col gap-3 border-b border-slate-200 p-4 dark:border-white/10 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h2 class="text-base font-semibold">{{ $panel === 'services' ? 'Liste des services' : 'Liste des articles' }}</h2>
                        <p class="mt-1 text-sm text-slate-500">{{ $items->total() }} résultat(s), page {{ $items->currentPage() }} sur {{ $items->lastPage() }}. Recherche serveur, tri et export alignés sur les filtres.</p>
                    </div>
                    <div class="app-action-row">
                        <button class="catalog-labels rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold dark:border-white/10" type="button">Étiquettes sélectionnées</button>
                        <a href="{{ $exportLink }}" class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold dark:border-white/10">Exporter vue</a>
                        <a href="{{ route('catalog.export', ['all' => 1]) }}" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-semibold dark:border-white/10 dark:bg-white/5">Exporter tout</a>
                        <a href="{{ route('catalog', ['panel' => $panel === 'services' ? 'ajouter-service' : 'ajouter']) }}" class="rounded-lg bg-brand px-3 py-2 text-sm font-semibold text-white">Ajouter</a>
                    </div>
                </div>
                @php
                    $listImportKind = $panel === 'services' ? 'services' : 'items';
                @endphp
                <details class="m-4 rounded-xl border border-dashed border-slate-300 bg-slate-50 p-4 dark:border-white/10 dark:bg-white/5">
                    <summary class="flex cursor-pointer list-none flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <span>
                            <span class="block text-sm font-semibold">{{ $importLabels[$listImportKind]['title'] }}</span>
                            <span class="mt-1 block text-xs text-slate-500">{{ $importLabels[$listImportKind]['hint'] }}</span>
                        </span>
                        <span class="inline-flex items-center justify-center rounded-lg bg-brand px-3 py-2 text-xs font-semibold text-white">Uploader Excel</span>
                    </summary>
                    <form action="{{ route('catalog.import') }}" method="POST" enctype="multipart/form-data" class="app-action-form mt-4">
                        @csrf
                        <input type="hidden" name="kind" value="{{ $listImportKind }}">
                        <input name="catalog_file" required type="file" accept=".csv,.tsv,.xlsx" class="rounded-lg border border-dashed border-slate-300 bg-white p-2 text-sm dark:border-white/10 dark:bg-slate-900">
                        <a href="{{ route('catalog.import.example', $listImportKind) }}" class="rounded-lg border border-slate-200 px-4 py-2 text-center text-sm font-semibold dark:border-white/10">{{ $importLabels[$listImportKind]['example'] }}</a>
                        <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Importer</button>
                    </form>
                </details>
                <div class="overflow-x-auto">
                    <table class="catalog-data-table w-full min-w-[1180px] text-left text-sm" data-yajra-table data-ajax-url="{{ route('catalog.data', request()->query()) }}" data-panel="{{ $panel }}" data-length="{{ $perPage }}">
                        <thead class="sticky top-0 z-10 bg-slate-50 text-xs uppercase text-slate-500 shadow-[inset_0_-1px_0_var(--border-soft)] dark:bg-slate-900 dark:text-slate-400">
                            <tr>
                                <th class="px-4 py-3"><input class="catalog-check-all rounded border-slate-300" type="checkbox"></th>
                                <th class="px-4 py-3">Image</th>
                                <th class="px-4 py-3">Code de barre</th>
                                <th class="px-4 py-3">Nom de l'article</th>
                                <th class="px-4 py-3">Catégorie/<br>Type d'élément</th>
                                <th class="px-4 py-3">Unité</th>
                                <th class="px-4 py-3">Stock</th>
                                @if ($panel !== 'services')
                                    <th class="px-4 py-3">Quantité d'alerte</th>
                                @endif
                                <th class="px-4 py-3">Prix de vente</th>
                                <th class="px-4 py-3">Impôt</th>
                                <th class="px-4 py-3">Statut</th>
                                <th class="px-4 py-3 text-right min-w-[170px]">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-white/10">
                            <tr><td colspan="{{ $panel === 'services' ? 11 : 12 }}" class="px-4 py-12 text-center text-sm text-slate-500">Chargement de la table...</td></tr>
                        </tbody>
                    </table>
                </div>
            </article>

            @if ($editItem)
                @php
                    $editItemCode = $editItem->barcode ?: ($editItem->isbn ?: ($editItem->sku ?: $editItem->item_code));
                    $editItemSearch = $editItemCode ?: $editItem->title;
                    $editItemIsService = $editItem->type === 'service';
                    $editItemQuickActions = [
                        [
                            'label' => 'Vendre en caisse',
                            'hint' => 'Ouvrir le POS avec ce produit',
                            'icon' => 'DH',
                            'href' => route('pos', ['q' => $editItemSearch, 'stock' => 'all']),
                            'tone' => 'primary',
                        ],
                        [
                            'label' => 'Créer un achat',
                            'hint' => 'Préparer une commande fournisseur',
                            'icon' => 'PO',
                            'href' => route('module', ['module' => 'purchases', 'section' => 'add', 'item' => $editItem->id]),
                            'tone' => 'success',
                            'hidden' => $editItemIsService,
                        ],
                        [
                            'label' => 'Ajuster le stock',
                            'hint' => 'Corriger quantité ou inventaire',
                            'icon' => '±',
                            'href' => route('stock', ['panel' => 'stock-adjustment-add', 'item' => $editItem->id, 'stock_q' => $editItemSearch]),
                            'tone' => 'warning',
                            'hidden' => $editItemIsService,
                        ],
                        [
                            'label' => 'Historique stock',
                            'hint' => 'Voir tous les mouvements',
                            'icon' => 'ST',
                            'href' => route('stock', ['panel' => 'stock-adjustments', 'inventory_item' => $editItem->id]).'#inventory-history',
                            'tone' => 'neutral',
                            'hidden' => $editItemIsService,
                        ],
                    ];
                @endphp
                <article id="edit-item" class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <div class="flex flex-col gap-3 border-b border-slate-200 pb-4 dark:border-white/10 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <h2 class="text-base font-semibold">Détail / modifier: {{ $editItem->title }}</h2>
                            <p class="mt-1 text-sm text-slate-500">{{ $editItem->item_code ?? 'Sans code' }} · {{ $editItem->barcode ?? $editItem->isbn ?? 'Sans code-barres' }} · {{ $editItem->category?->name ?? 'Sans catégorie' }}</p>
                        </div>
                        <div class="app-action-row">
                            <x-status-pill :tone="$editItem->type === 'service' ? 'info' : 'primary'">{{ $editItem->type === 'service' ? 'Service' : 'Article' }}</x-status-pill>
                            <x-status-pill :tone="$editItem->is_low_stock ? 'warning' : 'success'">{{ $editItem->type === 'service' ? 'Stock illimité' : $editItem->stock_quantity.' unités' }}</x-status-pill>
                        </div>
                    </div>
                    <div class="catalog-quick-actions mt-4">
                        @foreach ($editItemQuickActions as $action)
                            @continue($action['hidden'] ?? false)
                            <a href="{{ $action['href'] }}" class="catalog-quick-action is-{{ $action['tone'] }}" aria-label="{{ $action['label'] }} - {{ $action['hint'] }}">
                                <span class="catalog-quick-action-icon" aria-hidden="true">{{ $action['icon'] }}</span>
                                <span class="catalog-quick-action-copy">
                                    <span class="catalog-quick-action-title">{{ $action['label'] }}</span>
                                    <small>{{ $action['hint'] }}</small>
                                </span>
                            </a>
                        @endforeach
                    </div>

                    <form action="{{ route('catalog.items.update', $editItem) }}" method="POST" enctype="multipart/form-data" data-smart-validation data-error-fields='@json($errors->keys())' class="mt-5 grid gap-4 lg:grid-cols-4">
                        @csrf
                        @method('PUT')
                        <div data-validation-summary class="{{ $errors->any() ? '' : 'hidden' }} app-validation-summary lg:col-span-4">
                            <strong class="block">Veuillez corriger les champs indiqués.</strong>
                            <ul class="mt-2 list-disc space-y-1 pl-5">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                        @if ($editItem->type === 'service')
                            @include('librairepro.partials.service-fields', ['item' => $editItem, 'categories' => $categories, 'brands' => $brands, 'units' => $units, 'taxes' => $taxes, 'stores' => $stores, 'currentStore' => $currentStore, 'suggestedItemCode' => $suggestedItemCode])
                        @else
                            @include('librairepro.partials.item-fields', ['item' => $editItem, 'categories' => $categories, 'brands' => $brands, 'units' => $units, 'taxes' => $taxes, 'stores' => $stores, 'currentStore' => $currentStore, 'suggestedItemCode' => $suggestedItemCode])
                        @endif
                        <div class="lg:col-span-4 flex flex-wrap justify-between gap-3 border-t border-slate-200 pt-4 dark:border-white/10">
                            <a href="{{ route('catalog', ['panel' => $panel]) }}" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold dark:border-white/10">Fermer</a>
                            <button class="rounded-lg bg-brand px-5 py-2.5 text-sm font-semibold text-white">Enregistrer les modifications</button>
                        </div>
                    </form>

                    @if ($editItem->variants->isNotEmpty())
                        <div class="mt-6 border-t border-slate-200 pt-5 dark:border-white/10">
                            <h3 class="text-sm font-semibold">Variantes liées</h3>
                            <div class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                                @foreach ($editItem->variants as $variant)
                                    <div class="rounded-xl border border-slate-200 p-4 text-sm dark:border-white/10">
                                        <div class="flex items-start justify-between gap-3"><strong>{{ $variant->name }}</strong><x-status-pill tone="neutral">{{ $variant->stock_quantity }} unités</x-status-pill></div>
                                        <p class="mt-2 text-xs text-slate-500">{{ collect($variant->attributes)->filter()->map(fn($value, $key) => $key.': '.$value)->implode(' · ') ?: 'Sans attribut' }}</p>
                                        <p class="mt-3 font-semibold">{{ $money($variant->sale_price) }} <span class="text-xs font-normal text-slate-500">achat {{ $money($variant->purchase_price) }}</span></p>
                                        <p class="mt-1 text-xs text-slate-500">{{ $variant->barcode ?? 'Sans code-barres' }}</p>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </article>
            @endif
        </section>
    @endif

    @if ($panel === 'ajouter')
        <section class="mt-6">
            <article id="form-article" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                <div class="border-b border-slate-200 bg-slate-50/80 p-5 dark:border-white/10 dark:bg-white/[0.04]">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div class="flex items-start gap-3">
                            <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-brand text-lg font-semibold text-white shadow-sm shadow-brand/20">+</span>
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-brand">Catalogue · création</p>
                                <h2 class="mt-1 text-xl font-semibold text-slate-950 dark:text-white">Ajouter un article</h2>
                                <p class="mt-1 max-w-3xl text-sm text-slate-500">Créez un livre ou produit physique avec les champs utiles à la caisse, au stock, aux étiquettes et aux imports depuis l’ancienne solution.</p>
                            </div>
                        </div>
                        <div class="app-action-row">
                            <x-status-pill tone="primary">Livre / produit</x-status-pill>
                            <x-status-pill tone="info">Référentiels rapides</x-status-pill>
                        </div>
                    </div>
                </div>
                <form action="{{ route('catalog.items.store') }}" method="POST" enctype="multipart/form-data" data-smart-validation data-error-fields='@json($errors->keys())' class="grid gap-4 p-5 lg:grid-cols-4">
                    @csrf
                    <div data-validation-summary class="{{ $errors->any() ? '' : 'hidden' }} app-validation-summary lg:col-span-4">
                        <strong class="block">Le formulaire contient des informations à corriger.</strong>
                        <p class="mt-1">Les champs concernés sont surlignés ci-dessous.</p>
                        <ul class="mt-2 list-disc space-y-1 pl-5">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                    @include('librairepro.partials.item-fields', ['item' => null, 'categories' => $categories, 'brands' => $brands, 'units' => $units, 'taxes' => $taxes, 'stores' => $stores, 'currentStore' => $currentStore, 'suggestedItemCode' => $suggestedItemCode])
                    <div class="sticky bottom-0 z-10 -mx-5 -mb-5 mt-2 flex flex-col gap-3 border-t border-slate-200 bg-white/95 p-5 text-slate-700 shadow-[0_-10px_30px_rgba(15,23,42,0.06)] backdrop-blur dark:border-white/10 dark:bg-slate-950/95 dark:text-slate-100 sm:flex-row sm:items-center sm:justify-between lg:col-span-4">
                        <p class="text-sm text-slate-600 dark:text-slate-300">Les champs marqués <span class="font-semibold text-rose-500">*</span> sont obligatoires.</p>
                        <div class="flex justify-end gap-2">
                            <a href="{{ route('catalog', ['panel' => 'articles']) }}" class="rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 dark:border-white/10 dark:bg-white/5 dark:text-slate-100">Annuler</a>
                            <button class="rounded-lg bg-brand px-5 py-2.5 text-sm font-semibold text-white shadow-sm shadow-brand/20">Ajouter au catalogue</button>
                        </div>
                    </div>
                </form>
            </article>
        </section>
    @endif

    @if ($panel === 'ajouter-service')
        <section class="mt-6">
            <article class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                <div class="border-b border-slate-200 bg-slate-50/80 p-5 dark:border-white/10 dark:bg-white/[0.04]">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div class="flex items-start gap-3">
                            <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-brand text-lg font-semibold text-white shadow-sm shadow-brand/20">+</span>
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-brand">Catalogue · prestation</p>
                                <h2 class="mt-1 text-xl font-semibold text-slate-950 dark:text-white">Ajouter un service</h2>
                                <p class="mt-1 max-w-3xl text-sm text-slate-500">Frais d’adhésion, impression, pénalités, livraison et prestations sans stock physique.</p>
                            </div>
                        </div>
                        <div class="app-action-row">
                            <x-status-pill tone="info">Service</x-status-pill>
                            <x-status-pill tone="primary">Référentiels rapides</x-status-pill>
                        </div>
                    </div>
                </div>
                <form action="{{ route('catalog.items.store') }}" method="POST" enctype="multipart/form-data" data-smart-validation data-error-fields='@json($errors->keys())' class="grid gap-4 p-5 lg:grid-cols-4">
                    @csrf
                    <div data-validation-summary class="{{ $errors->any() ? '' : 'hidden' }} app-validation-summary lg:col-span-4">
                        <strong class="block">Le formulaire contient des informations à corriger.</strong>
                        <p class="mt-1">Les champs concernés sont surlignés ci-dessous.</p>
                        <ul class="mt-2 list-disc space-y-1 pl-5">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                    @include('librairepro.partials.service-fields', ['categories' => $categories, 'brands' => $brands, 'units' => $units, 'taxes' => $taxes, 'stores' => $stores, 'currentStore' => $currentStore, 'suggestedItemCode' => $suggestedItemCode])
                    <div class="sticky bottom-0 z-10 -mx-5 -mb-5 mt-2 flex flex-col gap-3 border-t border-slate-200 bg-white/95 p-5 text-slate-700 shadow-[0_-10px_30px_rgba(15,23,42,0.06)] backdrop-blur dark:border-white/10 dark:bg-slate-950/95 dark:text-slate-100 sm:flex-row sm:items-center sm:justify-between lg:col-span-4">
                        <p class="text-sm text-slate-600 dark:text-slate-300">Les champs marqués <span class="font-semibold text-rose-500">*</span> sont obligatoires.</p>
                        <div class="flex justify-end gap-2">
                            <a href="{{ route('catalog', ['panel' => 'services']) }}" class="rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 dark:border-white/10 dark:bg-white/5 dark:text-slate-100">Annuler</a>
                            <button class="rounded-lg bg-brand px-5 py-2.5 text-sm font-semibold text-white shadow-sm shadow-brand/20">Ajouter le service</button>
                        </div>
                    </div>
                </form>
            </article>
        </section>
    @endif

    @if ($panel === 'import')
        <section class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
            <article class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                <div class="border-b border-slate-200 bg-slate-50 p-5 dark:border-white/10 dark:bg-white/5">
                    <p class="text-xs font-semibold uppercase text-brand">Import Excel / CSV</p>
                    <h2 class="mt-1 text-lg font-semibold">Importer des articles ou services</h2>
                    <p class="mt-1 max-w-3xl text-sm leading-6 text-slate-600 dark:text-slate-300">Compatible avec les exports mylibrairie fournis: Liste d’articles, Liste des catégories, Liste des marques et Liste des variantes. La ligne titre est ignorée automatiquement.</p>
                </div>
                @php
                    $selectedImportKind = request('kind', 'items');
                @endphp
                <div class="p-5">
                    <form action="{{ route('catalog.import') }}" method="POST" enctype="multipart/form-data" class="app-action-form rounded-2xl border border-dashed border-slate-300 bg-white p-4 dark:border-white/10 dark:bg-slate-950/40">
                        @csrf
                        <select name="kind" data-import-kind-select class="h-11 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="items" @selected($selectedImportKind === 'items')>Articles</option><option value="services" @selected($selectedImportKind === 'services')>Services</option><option value="categories" @selected($selectedImportKind === 'categories')>Catégories</option><option value="brands" @selected($selectedImportKind === 'brands')>Marques / éditeurs</option><option value="variants" @selected($selectedImportKind === 'variants')>Variantes</option></select>
                        <input name="catalog_file" required type="file" accept=".csv,.tsv,.xlsx" class="min-h-11 rounded-lg border border-dashed border-slate-300 p-2 text-sm dark:border-white/10">
                        <a href="{{ route('catalog.import.example', $selectedImportKind) }}" data-import-example-base="{{ url('/catalogue/import/exemple') }}" class="grid h-11 place-items-center rounded-lg border border-slate-200 px-4 text-sm font-semibold dark:border-white/10">Exemple Excel</a>
                        <button class="rounded-lg bg-brand px-4 text-sm font-semibold text-white shadow-sm shadow-indigo-500/20">Importer</button>
                    </form>
                    <div class="mt-5 grid gap-3 md:grid-cols-3"><div class="rounded-lg bg-slate-50 p-4 text-sm dark:bg-white/5"><strong>Détection</strong><p class="mt-1 text-slate-500">Colonnes legacy FR/EN et accents reconnues.</p></div><div class="rounded-lg bg-slate-50 p-4 text-sm dark:bg-white/5"><strong>Mise à jour</strong><p class="mt-1 text-slate-500">Articles par barcode/ISBN, référentiels par nom.</p></div><div class="rounded-lg bg-slate-50 p-4 text-sm dark:bg-white/5"><strong>Rapport</strong><p class="mt-1 text-slate-500">Créés, mis à jour, ignorés après import.</p></div></div>
                </div>
            </article>
            <aside class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><h2 class="text-base font-semibold">Préparation</h2><ul class="mt-4 space-y-2 text-sm text-slate-600 dark:text-slate-300"><li>1. Choisir le type qui correspond au fichier.</li><li>2. XLSX, CSV et TSV sont acceptés.</li><li>3. Les catégories d’articles suppriment automatiquement le suffixe [ITEM].</li><li>4. Les doublons mettent à jour au lieu de planter.</li></ul></aside>
        </section>
    @endif

    @if (in_array($panel, ['categories', 'marques', 'unites', 'impots'], true))
        <section class="mt-6 grid gap-6 xl:grid-cols-[420px_minmax(0,1fr)]">
            <aside class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                @if ($panel === 'categories')
                    <h2 class="text-base font-semibold">Créer une catégorie</h2>
                    <p class="mt-1 text-sm text-slate-500">Structurez le catalogue avec des catégories parents/enfants.</p>
                    <form action="{{ route('catalog.categories.store') }}" method="POST" class="mt-5 grid gap-4">
                        @csrf
                        <input name="name" required class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Nom de catégorie">
                        <select name="parent_id" data-searchable-select data-placeholder="Rechercher un parent..." class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900">
                            <option value="">Aucun parent</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                            @endforeach
                        </select>
                        <div class="grid grid-cols-[1fr_72px] gap-3">
                            <input name="icon" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Icône">
                            <input name="color" type="color" value="#4F46E5" class="h-10 rounded-lg border border-slate-200 px-2 dark:border-white/10 dark:bg-slate-900">
                        </div>
                        <textarea name="description" rows="3" class="rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Description"></textarea>
                        <input name="loan_duration_days" value="14" type="hidden">
                        <input name="daily_fine_amount" value="2" type="hidden">
                        <button class="h-10 rounded-lg bg-brand px-4 text-sm font-semibold text-white">Créer</button>
                    </form>
                @elseif ($panel === 'marques')
                    <h2 class="text-base font-semibold">Ajouter une marque / éditeur</h2>
                    <p class="mt-1 text-sm text-slate-500">Référencez éditeurs, fabricants et fournisseurs éditoriaux.</p>
                    <form action="{{ route('catalog.brands.store') }}" method="POST" class="mt-5 grid gap-4">
                        @csrf
                        <input name="name" required class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Nom">
                        <select name="type" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900">
                            <option value="publisher">Éditeur</option>
                            <option value="brand">Marque</option>
                        </select>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <input name="phone" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Téléphone">
                            <input name="email" type="email" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Email">
                        </div>
                        <textarea name="description" rows="3" class="rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Description"></textarea>
                        <button class="h-10 rounded-lg bg-brand px-4 text-sm font-semibold text-white">Ajouter</button>
                    </form>
                @elseif ($panel === 'unites')
                    <h2 class="text-base font-semibold">Ajouter une unité</h2>
                    <p class="mt-1 text-sm text-slate-500">Pièce, boîte, pack, service, kg, lot...</p>
                    <form action="{{ route('catalog.units.store') }}" method="POST" class="mt-5 grid gap-4">
                        @csrf
                        <input name="name" required class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Nom de l'unité">
                        <textarea name="description" rows="3" class="rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Description"></textarea>
                        <button class="h-10 rounded-lg bg-brand px-4 text-sm font-semibold text-white">Ajouter</button>
                    </form>
                @else
                    <h2 class="text-base font-semibold">Ajouter un impôt</h2>
                    <p class="mt-1 text-sm text-slate-500">TVA, exonéré, taux spécifique ou import legacy.</p>
                    <form action="{{ route('catalog.taxes.store') }}" method="POST" class="mt-5 grid gap-4">
                        @csrf
                        <input name="name" required class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Nom">
                        <input name="rate" required type="number" step="0.01" min="0" max="100" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Taux %">
                        <textarea name="description" rows="3" class="rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Description"></textarea>
                        <button class="h-10 rounded-lg bg-brand px-4 text-sm font-semibold text-white">Ajouter</button>
                    </form>
                @endif
                @if (in_array($panel, ['categories', 'marques'], true))
                    @php
                        $referenceImportKind = $panel === 'categories' ? 'categories' : 'brands';
                    @endphp
                    <div class="mt-5 rounded-xl border border-dashed border-slate-300 bg-slate-50 p-4 dark:border-white/10 dark:bg-white/5">
                        <h3 class="text-sm font-semibold">{{ $importLabels[$referenceImportKind]['title'] }}</h3>
                        <p class="mt-1 text-xs text-slate-500">{{ $importLabels[$referenceImportKind]['hint'] }}</p>
                        <form action="{{ route('catalog.import') }}" method="POST" enctype="multipart/form-data" class="mt-3 grid gap-2">
                            @csrf
                            <input type="hidden" name="kind" value="{{ $referenceImportKind }}">
                            <input name="catalog_file" required type="file" accept=".csv,.tsv,.xlsx" class="rounded-lg border border-dashed border-slate-300 bg-white p-2 text-sm dark:border-white/10 dark:bg-slate-900">
                            <div class="grid gap-2 sm:grid-cols-2">
                                <a href="{{ route('catalog.import.example', $referenceImportKind) }}" class="rounded-lg border border-slate-200 px-3 py-2 text-center text-sm font-semibold dark:border-white/10">{{ $importLabels[$referenceImportKind]['example'] }}</a>
                                <button class="rounded-lg bg-brand px-3 py-2 text-sm font-semibold text-white">Importer</button>
                            </div>
                        </form>
                    </div>
                @endif
            </aside>

            <article class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                <div class="border-b border-slate-200 p-4 dark:border-white/10">
                    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <h2 class="text-base font-semibold">
                                @if ($panel === 'categories') Liste des catégories
                                @elseif ($panel === 'marques') Liste des marques / éditeurs
                                @elseif ($panel === 'unites') Liste des unités
                                @else Liste des impôts
                                @endif
                            </h2>
                            <p class="mt-1 text-sm text-slate-500">
                                @if ($panel === 'categories') {{ $categoryList->count() }} résultat(s) sur {{ $categories->count() }} catégorie(s)
                                @elseif ($panel === 'marques') {{ $brandList->count() }} résultat(s) sur {{ $brands->count() }} marque(s)
                                @elseif ($panel === 'unites') {{ $unitList->count() }} résultat(s) sur {{ $units->count() }} unité(s)
                                @else {{ $taxList->count() }} résultat(s) sur {{ $taxes->count() }} impôt(s)
                                @endif
                            </p>
                        </div>
                        <form action="{{ route('catalog') }}" class="flex min-w-full gap-2 sm:min-w-[340px]">
                            <input type="hidden" name="panel" value="{{ $panel }}">
                            <input name="reference_q" value="{{ $referenceQuery }}" class="h-10 min-w-0 flex-1 rounded-lg border border-slate-200 bg-slate-50 px-3 text-sm outline-none focus:border-brand focus:ring-4 focus:ring-brand/10 dark:border-white/10 dark:bg-white/5" placeholder="Rechercher dans la liste...">
                            <button class="h-10 rounded-lg bg-brand px-4 text-sm font-semibold text-white">Chercher</button>
                            @if ($referenceQuery !== '')
                                <a href="{{ route('catalog', ['panel' => $panel]) }}" class="grid h-10 place-items-center rounded-lg border border-slate-200 px-3 text-sm font-semibold dark:border-white/10">Effacer</a>
                            @endif
                        </form>
                    </div>
                </div>

                <div class="catalog-reference-scroll max-h-[620px] overflow-y-auto p-4">
                    <div class="grid gap-3">
                        @if ($panel === 'categories')
                            @forelse ($categoryList as $category)
                                <details class="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-white/10 dark:bg-white/5">
                                    <summary class="flex cursor-pointer list-none flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                        <div class="flex items-center gap-3">
                                            <span class="grid size-10 place-items-center rounded-lg text-xs font-bold text-white" style="background: {{ $category->color ?? '#4F46E5' }}">{{ Str::upper(Str::substr($category->name, 0, 2)) }}</span>
                                            <span><strong>{{ $category->name }}</strong><span class="mt-1 block text-xs text-slate-500">{{ $category->parent?->name ? 'Parent: '.$category->parent->name : 'Catégorie racine' }} · {{ $category->items_count }} article(s)</span></span>
                                        </div>
                                        <span class="text-xs font-semibold text-brand">Modifier</span>
                                    </summary>
                                    <div class="mt-4 border-t border-slate-200 pt-4 dark:border-white/10">
                                        <form action="{{ route('catalog.categories.update', $category) }}" method="POST" class="grid gap-3 lg:grid-cols-2">
                                            @csrf
                                            @method('PUT')
                                            <input name="name" required value="{{ $category->name }}" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900">
                                            <select name="parent_id" data-searchable-select data-placeholder="Rechercher un parent..." class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900">
                                                <option value="">Aucun parent</option>
                                                @foreach ($categories->where('id', '!=', $category->id) as $parent)
                                                    <option value="{{ $parent->id }}" @selected((string) $category->parent_id === (string) $parent->id)>{{ $parent->name }}</option>
                                                @endforeach
                                            </select>
                                            <input name="icon" value="{{ $category->icon }}" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Icône">
                                            <input name="color" type="color" value="{{ $category->color ?? '#4F46E5' }}" class="h-10 rounded-lg border border-slate-200 px-2 dark:border-white/10 dark:bg-slate-900">
                                            <textarea name="description" rows="2" class="rounded-lg border border-slate-200 px-3 py-2 text-sm lg:col-span-2 dark:border-white/10 dark:bg-slate-900" placeholder="Description">{{ $category->description }}</textarea>
                                            <input name="loan_duration_days" value="{{ $category->loan_duration_days ?? 14 }}" type="hidden">
                                            <input name="daily_fine_amount" value="{{ $category->daily_fine_amount ?? 2 }}" type="hidden">
                                            <div class="flex justify-end gap-2 lg:col-span-2">
                                                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Enregistrer</button>
                                            </div>
                                        </form>
                                        <form action="{{ route('catalog.categories.destroy', $category) }}" method="POST" class="mt-2 flex justify-end" onsubmit="return confirm('Supprimer cette catégorie ?')">
                                            @csrf
                                            @method('DELETE')
                                            <button class="rounded-lg border border-rose-200 px-3 py-2 text-xs font-semibold text-rose-600 dark:border-rose-500/30">Supprimer</button>
                                        </form>
                                    </div>
                                </details>
                            @empty
                                <p class="rounded-xl border border-dashed border-slate-300 p-10 text-center text-sm text-slate-500 dark:border-white/10">Aucune catégorie trouvée.</p>
                            @endforelse
                        @elseif ($panel === 'marques')
                            @forelse ($brandList as $brand)
                                <details class="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-white/10 dark:bg-white/5">
                                    <summary class="flex cursor-pointer list-none flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                        <div><strong>{{ $brand->name }}</strong><span class="mt-1 block text-xs text-slate-500">{{ $brand->type === 'publisher' ? 'Éditeur' : 'Marque' }} · {{ $brand->items_count }} article(s) · {{ $brand->phone ?: 'Sans téléphone' }}</span></div>
                                        <span class="text-xs font-semibold text-brand">Modifier</span>
                                    </summary>
                                    <div class="mt-4 border-t border-slate-200 pt-4 dark:border-white/10">
                                        <form action="{{ route('catalog.brands.update', $brand) }}" method="POST" class="grid gap-3 lg:grid-cols-2">
                                            @csrf
                                            @method('PUT')
                                            <input name="name" required value="{{ $brand->name }}" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900">
                                            <select name="type" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="publisher" @selected($brand->type === 'publisher')>Éditeur</option><option value="brand" @selected($brand->type === 'brand')>Marque</option></select>
                                            <input name="phone" value="{{ $brand->phone }}" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Téléphone">
                                            <input name="email" type="email" value="{{ $brand->email }}" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Email">
                                            <textarea name="description" rows="2" class="rounded-lg border border-slate-200 px-3 py-2 text-sm lg:col-span-2 dark:border-white/10 dark:bg-slate-900" placeholder="Description">{{ $brand->description }}</textarea>
                                            <div class="flex justify-end gap-2 lg:col-span-2"><button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Enregistrer</button></div>
                                        </form>
                                        <form action="{{ route('catalog.brands.destroy', $brand) }}" method="POST" class="mt-2 flex justify-end" onsubmit="return confirm('Supprimer cette marque / éditeur ?')">@csrf @method('DELETE')<button class="rounded-lg border border-rose-200 px-3 py-2 text-xs font-semibold text-rose-600 dark:border-rose-500/30">Supprimer</button></form>
                                    </div>
                                </details>
                            @empty
                                <p class="rounded-xl border border-dashed border-slate-300 p-10 text-center text-sm text-slate-500 dark:border-white/10">Aucune marque trouvée.</p>
                            @endforelse
                        @elseif ($panel === 'unites')
                            @forelse ($unitList as $unit)
                                <details class="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-white/10 dark:bg-white/5">
                                    <summary class="flex cursor-pointer list-none flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><div><strong>{{ $unit->name }}</strong><span class="mt-1 block text-xs text-slate-500">{{ $unit->items_count }} article(s) · {{ $unit->description ?: 'Sans description' }}</span></div><span class="text-xs font-semibold text-brand">Modifier</span></summary>
                                    <div class="mt-4 border-t border-slate-200 pt-4 dark:border-white/10">
                                        <form action="{{ route('catalog.units.update', $unit) }}" method="POST" class="grid gap-3 lg:grid-cols-[1fr_2fr_auto]">@csrf @method('PUT')<input name="name" required value="{{ $unit->name }}" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"><input name="description" value="{{ $unit->description }}" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Description"><button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Enregistrer</button></form>
                                        <form action="{{ route('catalog.units.destroy', $unit) }}" method="POST" class="mt-2 flex justify-end" onsubmit="return confirm('Supprimer cette unité ?')">@csrf @method('DELETE')<button class="rounded-lg border border-rose-200 px-3 py-2 text-xs font-semibold text-rose-600 dark:border-rose-500/30">Supprimer</button></form>
                                    </div>
                                </details>
                            @empty
                                <p class="rounded-xl border border-dashed border-slate-300 p-10 text-center text-sm text-slate-500 dark:border-white/10">Aucune unité trouvée.</p>
                            @endforelse
                        @else
                            @forelse ($taxList as $tax)
                                <details class="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-white/10 dark:bg-white/5">
                                    <summary class="flex cursor-pointer list-none flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><div><strong>{{ $tax->name }}</strong><span class="mt-1 block text-xs text-slate-500">{{ number_format((float) $tax->rate, 2, ',', ' ') }}% · {{ $tax->items_count }} article(s)</span></div><span class="text-xs font-semibold text-brand">Modifier</span></summary>
                                    <div class="mt-4 border-t border-slate-200 pt-4 dark:border-white/10">
                                        <form action="{{ route('catalog.taxes.update', $tax) }}" method="POST" class="grid gap-3 lg:grid-cols-[1fr_120px_2fr_auto]">@csrf @method('PUT')<input name="name" required value="{{ $tax->name }}" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"><input name="rate" required type="number" step="0.01" min="0" max="100" value="{{ $tax->rate }}" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"><input name="description" value="{{ $tax->description }}" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Description"><button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Enregistrer</button></form>
                                        <form action="{{ route('catalog.taxes.destroy', $tax) }}" method="POST" class="mt-2 flex justify-end" onsubmit="return confirm('Supprimer cet impôt ?')">@csrf @method('DELETE')<button class="rounded-lg border border-rose-200 px-3 py-2 text-xs font-semibold text-rose-600 dark:border-rose-500/30">Supprimer</button></form>
                                    </div>
                                </details>
                            @empty
                                <p class="rounded-xl border border-dashed border-slate-300 p-10 text-center text-sm text-slate-500 dark:border-white/10">Aucun impôt trouvé.</p>
                            @endforelse
                        @endif
                    </div>
                </div>
            </article>
        </section>
    @endif

    @if ($panel === 'variantes')
        <section class="mt-6">
            <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><h2 class="text-base font-semibold">Gestion des variantes</h2><form action="{{ route('catalog.variants.store') }}" method="POST" class="mt-5 grid gap-4 lg:grid-cols-8">@csrf <select name="item_id" required data-searchable-select data-placeholder="Rechercher un article..." class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900 lg:col-span-2">@foreach ($variantItems as $item)<option value="{{ $item->id }}">{{ $item->title }}</option>@endforeach</select><input name="name" required class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Nom"><input name="format" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Format"><input name="size" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Taille"><input name="color" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Couleur"><input name="sale_price" required type="number" step="0.01" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Prix"><button class="h-10 rounded-lg bg-brand px-4 text-sm font-semibold text-white">Ajouter</button><input name="barcode" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900 lg:col-span-2" placeholder="Code-barres"><input name="purchase_price" required type="number" step="0.01" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Coût"><input name="stock_quantity" required type="number" value="0" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Stock"></form></article>
            <article class="mt-4 rounded-xl border border-dashed border-slate-300 bg-slate-50 p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h2 class="text-base font-semibold">{{ $importLabels['variants']['title'] }}</h2>
                        <p class="mt-1 text-sm text-slate-500">{{ $importLabels['variants']['hint'] }}</p>
                    </div>
                    <form action="{{ route('catalog.import') }}" method="POST" enctype="multipart/form-data" class="app-action-form grid gap-2">
                        @csrf
                        <input type="hidden" name="kind" value="variants">
                        <input name="catalog_file" required type="file" accept=".csv,.tsv,.xlsx" class="rounded-lg border border-dashed border-slate-300 bg-white p-2 text-sm dark:border-white/10 dark:bg-slate-900">
                        <a href="{{ route('catalog.import.example', 'variants') }}" class="rounded-lg border border-slate-200 px-3 py-2 text-center text-sm font-semibold dark:border-white/10">{{ $importLabels['variants']['example'] }}</a>
                        <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Importer</button>
                    </form>
                </div>
            </article>
            <article class="mt-6 rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                <h2 class="text-base font-semibold">Liste des variantes</h2>
                @if ($variantOptions->isNotEmpty())
                    <div class="mt-4 rounded-lg bg-slate-50 p-4 dark:bg-white/5">
                        <p class="text-sm font-semibold">Variantes importées depuis mylibrairie</p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach ($variantOptions as $variantOption)
                                <span class="rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-semibold text-slate-600 dark:border-white/10 dark:bg-slate-900 dark:text-slate-200">{{ $variantOption->name }}</span>
                            @endforeach
                        </div>
                    </div>
                @endif
                <div class="mt-4 space-y-4">
                    @foreach ($variantItems as $item)
                        <div class="rounded-xl border border-slate-200 p-4 dark:border-white/10">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div><strong>{{ $item->title }}</strong><p class="mt-1 text-xs text-slate-500">{{ $item->barcode ?? $item->isbn ?? 'Sans code' }}</p></div>
                                <x-status-pill tone="neutral">{{ $item->variants->count() }} variante(s)</x-status-pill>
                            </div>
                            <div class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                                @forelse ($item->variants as $variant)
                                    <details class="rounded-lg bg-slate-50 p-3 text-sm dark:bg-white/5">
                                        <summary class="cursor-pointer list-none font-semibold">{{ $variant->name }} · {{ $money($variant->sale_price) }}</summary>
                                        <div class="mt-3 space-y-1 text-xs text-slate-500">
                                            <p>Code-barres: {{ $variant->barcode ?? 'Sans code' }}</p>
                                            <p>Stock: {{ $variant->stock_quantity }}</p>
                                            <p>Achat: {{ $money($variant->purchase_price) }}</p>
                                            <p>Attributs: {{ collect($variant->attributes)->filter()->map(fn($value, $key) => $key.': '.$value)->implode(' · ') ?: 'Sans attribut' }}</p>
                                        </div>
                                    </details>
                                @empty
                                    <p class="text-sm text-slate-500">Aucune variante pour cet article.</p>
                                @endforelse
                            </div>
                        </div>
                    @endforeach
                </div>
            </article>
        </section>
    @endif
    @php
        $stockAutoOpenDialogId = request('detail_adjustment')
            ? 'adjustment-detail-'.request('detail_adjustment')
            : (request('detail_transfer') ? 'transfer-detail-'.request('detail_transfer') : null);
    @endphp
    @if ($stockAutoOpenDialogId)
        <script>
            window.addEventListener('DOMContentLoaded', () => document.getElementById(@json($stockAutoOpenDialogId))?.showModal());
        </script>
    @endif
</x-layouts.app>
