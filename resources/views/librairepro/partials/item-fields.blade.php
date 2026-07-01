@php
    $tr = fn (string $text): string => \App\Support\Locale::t($text);
    $input = 'mt-1 h-11 w-full rounded-lg border border-slate-200 px-3 text-sm shadow-sm transition focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900';
    $readonlyInput = $input.' bg-slate-50 text-slate-400 cursor-default';
    $select = 'mt-1 h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm shadow-sm transition focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900';
    $section = 'lg:col-span-4 mt-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-xs font-semibold uppercase text-slate-500 shadow-sm dark:border-white/10 dark:bg-white/5';
    $required = '<span class="ms-1 text-rose-500">*</span>';
    // $itemTypeConfig is passed from the controller (activity-aware physical types, no service).
    // Fall back to bookstore types when the variable is absent (e.g. partial includes).
    $typeConfig  = $itemTypeConfig ?? \App\Support\ItemTypes::physicalTypes(\App\Support\ItemTypes::defaultActivity());
    $defaultType = array_key_first($typeConfig) ?? 'supply';
    $typeValue   = old('type', $item?->type ?? $defaultType);
    // Ensure the current item's type is always an option even if activity changed.
    if ($item && ! isset($typeConfig[$item->type]) && $item->type !== 'service') {
        $typeConfig[$item->type] = ['label' => ucfirst($item->type), 'hint' => '', 'fields' => null];
    }
    $typeOptions = $typeConfig;
    $storeOptions = collect($stores ?? [])->filter(fn ($store) => data_get($store, 'is_active', true));
    $defaultStoreName = data_get($currentStore ?? [], 'name', 'Magasin principal');
    $warehouseValue = old('warehouse', $item?->warehouse ?? $defaultStoreName);
    $isEnabled = old('is_enabled', $item?->is_enabled ?? true);
    $checkoutVisible = old('checkout_visible', $item?->checkout_visible ?? true);
    $onlineStoreVisible = old('online_store_visible', $item?->online_store_visible ?? true);
    $currentImage = collect($item?->images ?? [])->first();
    $defaultTax = $taxes->firstWhere('name', 'Sans TVA') ?? $taxes->first(fn ($tax) => (float) $tax->rate === 0.0);
    $taxValue = old('tax_id', $item?->tax_id ?? $defaultTax?->id);
    $moneyValue = fn (string $name, mixed $fallback = 0) => old($name) !== null ? old($name) : number_format((float) ($fallback ?? 0), 2, '.', '');
@endphp

{{-- ══ IDENTIFICATION ══════════════════════════════════════════════════════════ --}}
<div class="{{ $section }}">Identification</div>

<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Code de l'article</span>
    <input name="item_code" value="{{ old('item_code', $item?->item_code ?? '') }}"
           class="{{ $item ? $input : $readonlyInput }}"
           placeholder="{{ $item ? 'Auto' : ($suggestedItemCode ?? 'Généré automatiquement') }}"
           @readonly(! $item)>
    @unless ($item)
        <span class="mt-1 block text-xs text-slate-400">Généré automatiquement — modifiable après création.</span>
    @endunless
</label>

<div class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Type d'élément {!! $required !!}</span>
    @php $typeCols = match(count($typeOptions)) { 1 => 'sm:grid-cols-1', 2 => 'sm:grid-cols-2', default => 'sm:grid-cols-3' }; @endphp
    <div class="mt-1 grid gap-2 {{ $typeCols }}" data-type-selector>
        @foreach ($typeOptions as $value => $option)
            <label class="app-type-choice">
                <input type="radio" name="type" value="{{ $value }}" @checked($typeValue === $value)>
                <span class="app-type-choice-card">{{ $option['label'] }}<small>{{ $option['hint'] }}</small></span>
            </label>
        @endforeach
    </div>
</div>

<label class="block lg:col-span-2">
    <span class="text-xs font-semibold uppercase text-slate-500">Nom de l'article {!! $required !!}</span>
    <input name="title" required value="{{ old('title', $item?->title) }}" class="{{ $input }}" placeholder="{{ $tr('Titre ou nom produit') }}">
</label>

<div class="block">
    <div class="flex items-center justify-between gap-2">
        <span class="text-xs font-semibold uppercase text-slate-500">Marque / éditeur</span>
        @unless ($item)
            <button class="inline-create-open text-xs font-semibold text-brand hover:underline" type="button" data-dialog="brand-dialog">+ Ajouter</button>
        @endunless
    </div>
    <select name="brand_id" data-searchable-select data-placeholder="Rechercher une marque..." class="{{ $select }}">
        <option value="">Aucun</option>
        @foreach ($brands as $brand)
            <option value="{{ $brand->id }}" @selected((string) old('brand_id', $item?->brand_id) === (string) $brand->id)>{{ $brand->name }}</option>
        @endforeach
    </select>
</div>

<div class="block">
    <div class="flex items-center justify-between gap-2">
        <span class="text-xs font-semibold uppercase text-slate-500">Catégorie</span>
        @unless ($item)
            <button class="inline-create-open text-xs font-semibold text-brand hover:underline" type="button" data-dialog="category-dialog">+ Ajouter</button>
        @endunless
    </div>
    <select name="category_id" data-searchable-select data-placeholder="Rechercher une catégorie..." class="{{ $select }}">
        <option value="">Sans catégorie</option>
        @foreach ($categories as $category)
            <option value="{{ $category->id }}" @selected((string) old('category_id', $item?->category_id) === (string) $category->id)>{{ $category->name }}</option>
        @endforeach
    </select>
</div>

<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Groupe d'articles</span>
    <select name="item_group" class="{{ $select }}">
        @foreach (['Single' => 'Article simple', 'Pack' => 'Pack', 'Variants' => 'Variantes', 'Group' => 'Groupe'] as $value => $label)
            <option value="{{ $value }}" @selected(old('item_group', $item?->item_group ?? 'Single') === $value)>{{ $label }}</option>
        @endforeach
    </select>
</label>

<div class="block">
    <div class="flex items-center justify-between gap-2">
        <span class="text-xs font-semibold uppercase text-slate-500">Unité {!! $required !!}</span>
        @unless ($item)
            <button class="inline-create-open text-xs font-semibold text-brand hover:underline" type="button" data-dialog="unit-dialog">+ Ajouter</button>
        @endunless
    </div>
    <select name="unit_id" required data-searchable-select data-placeholder="Rechercher une unité..." class="{{ $select }}">
        <option value="">- choisir -</option>
        @foreach ($units as $unit)
            <option value="{{ $unit->id }}" @selected((string) old('unit_id', $item?->unit_id) === (string) $unit->id)>{{ $unit->name }}</option>
        @endforeach
    </select>
</div>

{{-- ══ CODES & RÉFÉRENCES ══════════════════════════════════════════════════════ --}}
<div class="{{ $section }}">Codes &amp; références</div>

<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">ISBN</span>
    <input name="isbn" value="{{ old('isbn', $item?->isbn) }}" class="{{ $input }}" placeholder="978...">
</label>

<div class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Code-barres (EAN)</span>
    <div class="mt-1 flex gap-2">
        <input name="barcode" value="{{ old('barcode', $item?->barcode) }}"
               class="barcode-input h-11 min-w-0 flex-1 rounded-lg border border-slate-200 px-3 text-sm shadow-sm transition focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900"
               placeholder="EAN / code scanner">
        <button class="barcode-scan-btn h-11 shrink-0 rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 shadow-sm hover:border-brand hover:text-brand dark:border-white/10 dark:bg-slate-900 dark:text-slate-200" type="button">Scanner</button>
    </div>
</div>

<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">SKU / Référence interne</span>
    <input name="sku" value="{{ old('sku', $item?->sku) }}" class="{{ $input }}" placeholder="Référence interne">
</label>

<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Autre code article</span>
    <input name="custom_barcode1" value="{{ old('custom_barcode1', $item?->custom_barcode1) }}" class="{{ $input }}">
</label>

<label class="block" data-pack-field style="display:none">
    <span class="text-xs font-semibold uppercase text-slate-500">Nombre d'unités</span>
    <input name="nb_item" type="number" min="0" value="{{ old('nb_item', $item?->nb_item) }}" class="{{ $input }}" placeholder="Qté dans le pack">
</label>

{{-- ══ FICHE LIVRE (book-only fields) ══════════════════════════════════════════ --}}
<div class="{{ $section }} item-book-field">Fiche livre</div>

<label class="block item-book-field">
    <span class="text-xs font-semibold uppercase text-slate-500">Auteur</span>
    <input name="author" value="{{ old('author', $item?->author) }}" class="{{ $input }}" placeholder="Prénom Nom">
</label>

<label class="block item-book-field">
    <span class="text-xs font-semibold uppercase text-slate-500">Éditeur</span>
    <input name="editor" value="{{ old('editor', $item?->editor) }}" class="{{ $input }}" placeholder="Maison d'édition">
</label>

<label class="block item-book-field">
    <span class="text-xs font-semibold uppercase text-slate-500">Traducteur</span>
    <input name="translator" value="{{ old('translator', $item?->translator) }}" class="{{ $input }}">
</label>

<label class="block item-book-field">
    <span class="text-xs font-semibold uppercase text-slate-500">N° édition</span>
    <input name="edition_number" value="{{ old('edition_number', $item?->edition_number) }}" class="{{ $input }}" placeholder="1ère, 2ème…">
</label>

<label class="block item-book-field">
    <span class="text-xs font-semibold uppercase text-slate-500">Année édition</span>
    <input name="edition_year" value="{{ old('edition_year', $item?->edition_year) }}" class="{{ $input }}" placeholder="{{ date('Y') }}">
</label>

<label class="block item-book-field">
    <span class="text-xs font-semibold uppercase text-slate-500">Thème</span>
    <input name="theme" value="{{ old('theme', $item?->theme) }}" class="{{ $input }}" placeholder="Sciences, Romans…">
</label>

<label class="block item-book-field">
    <span class="text-xs font-semibold uppercase text-slate-500">Nature du papier</span>
    <input name="paper_type" value="{{ old('paper_type', $item?->paper_type) }}" class="{{ $input }}" placeholder="Couché, offset…">
</label>

<label class="block item-book-field">
    <span class="text-xs font-semibold uppercase text-slate-500">Couverture</span>
    <input name="cover_type" value="{{ old('cover_type', $item?->cover_type) }}" class="{{ $input }}" placeholder="Rigide, souple…">
</label>

<label class="block item-book-field">
    <span class="text-xs font-semibold uppercase text-slate-500">Collection / Série</span>
    <input name="collection" value="{{ old('collection', $item?->collection) }}" class="{{ $input }}">
</label>

{{-- ══ PRIX, TVA & STOCK ════════════════════════════════════════════════════════ --}}
<div class="{{ $section }}">Prix, TVA &amp; stock</div>

{{-- Tax select (first — rate needed for other calculations) --}}
<div class="block">
    <div class="flex items-center justify-between gap-2">
        <span class="text-xs font-semibold uppercase text-slate-500">TVA / Impôt {!! $required !!}</span>
        @unless ($item)
            <button class="inline-create-open text-xs font-semibold text-brand hover:underline" type="button" data-dialog="tax-dialog">+ Ajouter</button>
        @endunless
    </div>
    <select name="tax_id" required data-searchable-select data-price-tax data-placeholder="Rechercher une taxe..." class="{{ $select }}">
        <option value="">- choisir -</option>
        @foreach ($taxes as $tax)
            <option value="{{ $tax->id }}" data-rate="{{ (float) $tax->rate }}" @selected((string) $taxValue === (string) $tax->id)>{{ $tax->name }} ({{ number_format((float) $tax->rate, 2, ',', ' ') }}%)</option>
        @endforeach
    </select>
</div>

<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Type de taxe</span>
    <select name="tax_type" data-price-tax-type class="{{ $select }}">
        @foreach (['Exclusive' => 'HT — taxe exclue (prix saisi HT)', 'Inclusive' => 'TTC — taxe incluse (prix saisi TTC)'] as $value => $label)
            <option value="{{ $value }}" @selected(old('tax_type', $item?->tax_type ?? 'Exclusive') === $value)>{{ $label }}</option>
        @endforeach
    </select>
</label>

{{-- Purchase price (manual) --}}
<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Prix d'achat HT {!! $required !!}</span>
    <input name="purchase_price" required type="number" step="0.01" min="0" inputmode="decimal"
           data-price-purchase
           value="{{ $moneyValue('purchase_price', $item?->purchase_price ?? 0) }}"
           class="{{ $input }}" placeholder="0.00">
</label>

{{-- Purchase price TTC — auto-calculated, readonly --}}
<label class="block">
    <span class="flex items-center gap-1.5 text-xs font-semibold uppercase text-slate-500">
        Prix d'achat TTC
        <span class="inline-flex items-center gap-0.5 rounded-full bg-indigo-50 px-1.5 py-0.5 text-[10px] font-semibold text-indigo-600 dark:bg-indigo-500/10 dark:text-indigo-300">
            <svg class="size-2.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.59 14.37a6 6 0 0 1-5.84 7.38v-4.8m5.84-2.58a14.98 14.98 0 0 0 6.16-12.12A14.98 14.98 0 0 0 9.631 8.41m5.96 5.96a14.926 14.926 0 0 1-5.841 2.58m-.119-8.54a6 6 0 0 0-7.381 5.84h4.8m2.581-5.84a14.927 14.927 0 0 0-2.58 5.84m2.699 2.7c-.103.021-.207.041-.311.06a15.09 15.09 0 0 1-2.448-2.448 14.9 14.9 0 0 1 .06-.312m-2.24 2.39a4.493 4.493 0 0 0-1.757 4.306 4.496 4.496 0 0 0 4.306-1.758M16.5 9a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0Z"/></svg>
            Auto
        </span>
    </span>
    <input name="price" type="number" step="0.01" min="0" inputmode="decimal"
           data-price-ttc
           value="{{ $moneyValue('price', $item?->price ?? $item?->purchase_price ?? 0) }}"
           class="{{ $readonlyInput }}" readonly tabindex="-1">
</label>

{{-- Sale price (manual) --}}
<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Prix de vente {!! $required !!}</span>
    <input name="sale_price" required type="number" step="0.01" min="0" inputmode="decimal"
           data-price-sale
           value="{{ $moneyValue('sale_price', $item?->sale_price ?? 0) }}"
           class="{{ $input }}" placeholder="0.00">
</label>

{{-- Profit margin — auto-calculated but also editable to drive sale_price --}}
<label class="block">
    <span class="flex items-center gap-1.5 text-xs font-semibold uppercase text-slate-500">
        Marge (%)
        <span class="inline-flex items-center gap-0.5 rounded-full bg-emerald-50 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300">
            <svg class="size-2.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.59 14.37a6 6 0 0 1-5.84 7.38v-4.8m5.84-2.58a14.98 14.98 0 0 0 6.16-12.12A14.98 14.98 0 0 0 9.631 8.41m5.96 5.96a14.926 14.926 0 0 1-5.841 2.58m-.119-8.54a6 6 0 0 0-7.381 5.84h4.8m2.581-5.84a14.927 14.927 0 0 0-2.58 5.84m2.699 2.7c-.103.021-.207.041-.311.06a15.09 15.09 0 0 1-2.448-2.448 14.9 14.9 0 0 1 .06-.312m-2.24 2.39a4.493 4.493 0 0 0-1.757 4.306 4.496 4.496 0 0 0 4.306-1.758M16.5 9a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0Z"/></svg>
            Auto · éditable
        </span>
    </span>
    <input name="profit_margin" type="number" step="0.01" min="-100" inputmode="decimal"
           data-price-margin
           value="{{ old('profit_margin', $item?->profit_margin ?? 0) }}"
           class="{{ $input }}" placeholder="0.00">
    <span class="mt-1 block text-xs text-slate-400">Calculé depuis les prix. Saisir une marge recalcule automatiquement le prix de vente.</span>
</label>

<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Prix revendeur TTC</span>
    <input name="reseller_sale_price" type="number" step="0.01" min="0" inputmode="decimal"
           value="{{ $moneyValue('reseller_sale_price', $item?->reseller_sale_price ?? 0) }}"
           class="{{ $input }}" placeholder="0.00">
</label>

<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Type de remise</span>
    <select name="discount_type" class="{{ $select }}">
        @foreach (['Percentage' => 'Pourcentage (%)', 'Fixed' => 'Montant fixe (DH)'] as $value => $label)
            <option value="{{ $value }}" @selected(old('discount_type', $item?->discount_type ?? 'Percentage') === $value)>{{ $label }}</option>
        @endforeach
    </select>
</label>

<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Remise par défaut</span>
    <input name="discount" type="number" step="0.01" min="0" inputmode="decimal"
           value="{{ $moneyValue('discount', $item?->discount ?? 0) }}"
           class="{{ $input }}" placeholder="0.00">
</label>

{{-- Stock section --}}
<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">
        @if ($item) Stock actuel @else Stock initial @endif
        {!! $required !!}
    </span>
    <input name="stock_quantity" required type="number" min="0"
           value="{{ old('stock_quantity', $item?->stock_quantity ?? 0) }}"
           class="{{ $input }}" placeholder="0">
    @if ($item)
        <span class="mt-1 block text-xs text-slate-400">Pour ajuster le stock, utilisez les <a href="{{ route('stock', ['panel' => 'stock-adjustment-add']) }}" class="font-semibold text-brand hover:underline">ajustements</a>.</span>
    @endif
</label>

<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Seuil d'alerte stock {!! $required !!}</span>
    <input name="min_stock_threshold" required type="number" min="0"
           value="{{ old('min_stock_threshold', $item?->min_stock_threshold ?? data_get($tenant->settings, 'pos.default_min_stock_threshold', 3)) }}"
           class="{{ $input }}" placeholder="3">
</label>

@unless ($item)
<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Stock d'ouverture</span>
    <input name="opening_stock" type="number" min="0"
           value="{{ old('opening_stock', 0) }}"
           class="{{ $input }}" placeholder="0">
    <span class="mt-1 block text-xs text-slate-400">Stock initial comptable. Laisser 0 si non applicable.</span>
</label>
@endunless

{{-- ══ MAGASIN & MÉDIAS ════════════════════════════════════════════════════════ --}}
<div class="{{ $section }}">Magasin &amp; médias</div>

<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Emplacement / Rayon</span>
    <input name="location" value="{{ old('location', $item?->location) }}" class="{{ $input }}" placeholder="Rayon A-02">
</label>

<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">BL (bon de livraison)</span>
    <input name="delivery_note" value="{{ old('delivery_note', $item?->delivery_note) }}" class="{{ $input }}" placeholder="BL-2026-001">
</label>

<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">FA (référence facture)</span>
    <input name="invoice_reference" value="{{ old('invoice_reference', $item?->invoice_reference) }}" class="{{ $input }}" placeholder="FA-2026-001">
</label>

<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Points vendeur</span>
    <input name="seller_points" type="number" step="0.01" min="0"
           value="{{ old('seller_points', $item?->seller_points ?? 0) }}"
           class="{{ $input }}" placeholder="0">
</label>

<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Statut</span>
    <select name="status" class="{{ $select }}">
        @foreach (['active' => 'Actif', 'archived' => 'Archivé', 'out_of_stock' => 'Rupture'] as $value => $label)
            <option value="{{ $value }}" @selected(old('status', $item?->status ?? 'active') === $value)>{{ $label }}</option>
        @endforeach
    </select>
    <span class="mt-1 block text-xs text-slate-400">Calculé automatiquement selon le stock. Utilisez Archivé pour retirer un article.</span>
</label>

<label class="block rounded-lg border border-slate-200 bg-white p-3 shadow-sm dark:border-white/10 dark:bg-slate-900">
    <input type="hidden" name="is_enabled" value="0">
    <span class="flex items-start gap-3">
        <input name="is_enabled" value="1" type="checkbox" @checked((bool) $isEnabled) class="mt-1 rounded border-slate-300 text-brand focus:ring-brand">
        <span>
            <span class="block text-xs font-semibold uppercase text-slate-500">Article activé</span>
            <small class="mt-1 block text-xs text-slate-500">Décochez pour désactiver sans supprimer.</small>
        </span>
    </span>
</label>

<label class="block rounded-lg border border-slate-200 bg-white p-3 shadow-sm dark:border-white/10 dark:bg-slate-900">
    <input type="hidden" name="checkout_visible" value="0">
    <span class="flex items-start gap-3">
        <input name="checkout_visible" value="1" type="checkbox" @checked((bool) $checkoutVisible) class="mt-1 rounded border-slate-300 text-brand focus:ring-brand">
        <span>
            <span class="block text-xs font-semibold uppercase text-slate-500">Visible sur la caisse</span>
            <small class="mt-1 block text-xs text-slate-500">Décochez pour garder au catalogue sans afficher au checkout.</small>
        </span>
    </span>
</label>

<label class="block rounded-lg border border-slate-200 bg-white p-3 shadow-sm dark:border-white/10 dark:bg-slate-900">
    <input type="hidden" name="online_store_visible" value="0">
    <span class="flex items-start gap-3">
        <input name="online_store_visible" value="1" type="checkbox" @checked((bool) $onlineStoreVisible) class="mt-1 rounded border-slate-300 text-brand focus:ring-brand">
        <span>
            <span class="block text-xs font-semibold uppercase text-slate-500">Visible sur la boutique en ligne</span>
            <small class="mt-1 block text-xs text-slate-500">Décochez pour masquer côté client.</small>
        </span>
    </span>
</label>

<div class="block lg:col-span-3">
    <span class="text-xs font-semibold uppercase text-slate-500">{{ $tr('Image article') }}</span>
    <div class="mt-1 grid gap-3 rounded-lg border border-dashed border-slate-200 bg-white p-3 dark:border-white/10 dark:bg-slate-900 md:grid-cols-[96px_minmax(0,1fr)]">
        <div class="grid size-24 place-items-center overflow-hidden rounded-lg bg-slate-100 text-xs font-semibold text-slate-500 dark:bg-white/10">
            @if ($currentImage)
                <img src="{{ asset('storage/'.$currentImage) }}" alt="{{ $item?->title ? $tr('Image').' '.$item->title : $tr('Image article') }}" class="h-full w-full object-cover">
            @else
                {{ $tr('Aucune image') }}
            @endif
        </div>
        <div class="min-w-0">
            <label class="catalog-file-control" data-file-upload>
                <input name="item_image" type="file" accept="image/*" class="sr-only" data-file-input>
                <span class="catalog-file-button">{{ $tr('Choisir une image') }}</span>
                <span class="catalog-file-name" data-file-name data-empty-label="{{ $tr('Aucun fichier choisi') }}">{{ $tr('Aucun fichier choisi') }}</span>
            </label>
            <span class="mt-1 block text-xs text-slate-500">{{ $tr('Max 1 Mo. Une nouvelle image devient image principale de la fiche.') }}</span>
            @if ($currentImage)
                <label class="mt-2 flex items-center gap-2 text-xs font-semibold text-slate-600 dark:text-slate-300">
                    <input type="checkbox" name="remove_item_image" value="1" class="rounded border-slate-300 text-brand focus:ring-brand">
                    {{ $tr("Supprimer l'image actuelle") }}
                </label>
            @endif
        </div>
    </div>
</div>

<label class="block lg:col-span-4">
    <span class="text-xs font-semibold uppercase text-slate-500">{{ $tr('Tags boutique') }}</span>
    <input name="tags" value="{{ old('tags', collect($item?->tags ?? [])->implode(', ')) }}" class="{{ $input }}" placeholder="{{ $tr('Ex: rentrée, scolaire, nouveautés') }}">
    <span class="mt-1 block text-xs text-slate-500">{{ $tr('Séparez les tags par des virgules. Filtres de la boutique en ligne.') }}</span>
</label>

<label class="block lg:col-span-4">
    <span class="text-xs font-semibold uppercase text-slate-500">Description</span>
    <textarea name="description" rows="3"
              class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm transition focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900"
              placeholder="Description, notes internes, détails d'édition">{{ old('description', $item?->description) }}</textarea>
</label>

{{-- ══ PRICE AUTO-CALCULATION ══════════════════════════════════════════════════ --}}
<script>
(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var form = document.querySelector('[data-item-form]') || document.querySelector('form');
        if (! form) return;

        var purchaseEl  = form.querySelector('[data-price-purchase]');
        var saleEl      = form.querySelector('[data-price-sale]');
        var marginEl    = form.querySelector('[data-price-margin]');
        var ttcEl       = form.querySelector('[data-price-ttc]');
        var taxSel      = form.querySelector('[data-price-tax]');
        var taxTypeSel  = form.querySelector('[data-price-tax-type]');
        var typeRadios  = form.querySelectorAll('[name="type"]');
        var groupSel    = form.querySelector('[name="item_group"]');

        if (! purchaseEl || ! saleEl || ! marginEl || ! ttcEl) return;

        function taxRate() {
            var opt = taxSel ? taxSel.options[taxSel.selectedIndex] : null;
            return opt ? (parseFloat(opt.dataset.rate) || 0) : 0;
        }

        function calcTtc(purchase) {
            var type = taxTypeSel ? taxTypeSel.value : 'Exclusive';
            return type === 'Exclusive'
                ? purchase * (1 + taxRate() / 100)
                : purchase;
        }

        function syncFromPurchaseOrSale() {
            var purchase = parseFloat(purchaseEl.value) || 0;
            var sale     = parseFloat(saleEl.value)     || 0;
            ttcEl.value  = calcTtc(purchase).toFixed(2);
            if (purchase > 0) {
                marginEl.value = (((sale - purchase) / purchase) * 100).toFixed(2);
            } else {
                marginEl.value = '0.00';
            }
        }

        function syncFromMargin() {
            var purchase = parseFloat(purchaseEl.value) || 0;
            var margin   = parseFloat(marginEl.value)   || 0;
            ttcEl.value  = calcTtc(purchase).toFixed(2);
            saleEl.value = (purchase * (1 + margin / 100)).toFixed(2);
        }

        purchaseEl.addEventListener('input', syncFromPurchaseOrSale);
        saleEl.addEventListener('input', syncFromPurchaseOrSale);
        marginEl.addEventListener('change', syncFromMargin);
        if (taxSel)     taxSel.addEventListener('change', syncFromPurchaseOrSale);
        if (taxTypeSel) taxTypeSel.addEventListener('change', syncFromPurchaseOrSale);

        // Book-only field visibility
        var bookFields = form.querySelectorAll('.item-book-field');
        var packField  = form.querySelector('[data-pack-field]');

        function updateVisibility() {
            var type = Array.from(typeRadios).find(r => r.checked)?.value || 'book';
            bookFields.forEach(function (el) {
                el.style.display = type === 'book' ? '' : 'none';
            });
        }

        function updatePackField() {
            if (! packField) return;
            packField.style.display = (groupSel && groupSel.value === 'Pack') ? '' : 'none';
        }

        typeRadios.forEach(function (r) { r.addEventListener('change', updateVisibility); });
        if (groupSel) groupSel.addEventListener('change', updatePackField);

        updateVisibility();
        updatePackField();

        // Initial sync on edit (prices already filled)
        syncFromPurchaseOrSale();
    });
})();
</script>

{{-- ══ INLINE-CREATE DIALOGS (create mode only) ════════════════════════════════ --}}
@if (! $item)
    <dialog id="brand-dialog" class="app-dialog w-[min(460px,calc(100vw-2rem))] rounded-2xl border border-slate-200 bg-white p-0 text-slate-950 shadow-2xl backdrop:bg-slate-950/40">
        <div class="p-5">
            <div class="flex items-start justify-between gap-4">
                <div><h3 class="font-semibold">Ajouter une marque / éditeur</h3><p class="mt-1 text-sm text-slate-500">Disponible immédiatement dans le champ Marque.</p></div>
                <button class="dialog-close text-2xl leading-none text-slate-400" type="button">&times;</button>
            </div>
            <div class="mt-5 space-y-4" data-inline-create data-endpoint="{{ route('catalog.brands.store') }}" data-target="brand_id">
                <label class="block"><span class="text-xs font-semibold uppercase text-slate-500">Nom {!! $required !!}</span><input name="name" required class="{{ $input }}"></label>
                <label class="block"><span class="text-xs font-semibold uppercase text-slate-500">Type</span><select name="type" class="{{ $select }}"><option value="publisher">Éditeur</option><option value="brand">Marque</option></select></label>
                <label class="block"><span class="text-xs font-semibold uppercase text-slate-500">Description</span><textarea name="description" rows="2" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm"></textarea></label>
                <p class="inline-create-error hidden rounded-lg bg-rose-50 px-3 py-2 text-sm font-medium text-rose-700"></p>
                <button class="inline-create-submit w-full rounded-lg bg-brand px-4 py-2.5 text-sm font-semibold text-white" type="button">Ajouter</button>
            </div>
        </div>
    </dialog>

    <dialog id="category-dialog" class="app-dialog w-[min(460px,calc(100vw-2rem))] rounded-2xl border border-slate-200 bg-white p-0 text-slate-950 shadow-2xl backdrop:bg-slate-950/40">
        <div class="p-5">
            <div class="flex items-start justify-between gap-4">
                <div><h3 class="font-semibold">Ajouter une catégorie</h3><p class="mt-1 text-sm text-slate-500">La catégorie sera sélectionnée après création.</p></div>
                <button class="dialog-close text-2xl leading-none text-slate-400" type="button">&times;</button>
            </div>
            <div class="mt-5 space-y-4" data-inline-create data-endpoint="{{ route('catalog.categories.store') }}" data-target="category_id">
                @php $catIcons = ['📚','📖','✏️','📝','📐','🎨','🧮','🔬','🌍','📜','🎵','💻','🏫','👶','🧑‍🎓','📦','🎒','👕','⚽','🎮','🍎','🔧','🧪','📏','🖼️','🗺️','⏰','💡','🛒','🏪','📓','📕']; @endphp
                <label class="block"><span class="text-xs font-semibold uppercase text-slate-500">Nom {!! $required !!}</span><input name="name" required class="{{ $input }}"></label>
                <label class="block"><span class="text-xs font-semibold uppercase text-slate-500">Parent</span><select name="parent_id" data-searchable-select data-placeholder="Rechercher un parent..." class="{{ $select }}"><option value="">Aucun</option>@foreach ($categories->where('parent_id', null) as $rootCat)<option value="{{ $rootCat->id }}">{{ $rootCat->name }}</option>@foreach ($categories->where('parent_id', $rootCat->id) as $childCat)<option value="{{ $childCat->id }}"> └ {{ $childCat->name }}</option>@endforeach @endforeach</select></label>
                <div>
                    <div class="flex flex-wrap gap-1.5 rounded-lg border border-slate-200 bg-slate-50 p-2 dark:border-white/10 dark:bg-white/5">
                        <input name="icon" class="category-icon-input h-10 flex-1 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Icône" id="icon-input-inline" readonly onclick="document.getElementById('icon-picker-inline').classList.toggle('hidden')">
                        <input name="color" type="color" value="#4F46E5" class="h-10 w-16 rounded-lg border border-slate-200 bg-white px-1 dark:border-white/10 dark:bg-slate-900">
                    </div>
                    <div id="icon-picker-inline" class="mt-1 hidden flex-wrap gap-1 rounded-lg border border-slate-200 bg-white p-2 dark:border-white/10 dark:bg-slate-900">
                        @foreach($catIcons as $ico)
                            <button type="button" class="rounded-lg px-2.5 py-1.5 text-sm hover:bg-slate-100 dark:hover:bg-white/10" onclick="document.getElementById('icon-input-inline').value='{{ $ico }}'; document.getElementById('icon-picker-inline').classList.add('hidden')">{{ $ico }}</button>
                        @endforeach
                    </div>
                </div>
                <input name="loan_duration_days" value="14" type="hidden"><input name="daily_fine_amount" value="2" type="hidden">
                <p class="inline-create-error hidden rounded-lg bg-rose-50 px-3 py-2 text-sm font-medium text-rose-700"></p>
                <button class="inline-create-submit w-full rounded-lg bg-brand px-4 py-2.5 text-sm font-semibold text-white" type="button">Ajouter</button>
            </div>
        </div>
    </dialog>

    <dialog id="unit-dialog" class="app-dialog w-[min(420px,calc(100vw-2rem))] rounded-2xl border border-slate-200 bg-white p-0 text-slate-950 shadow-2xl backdrop:bg-slate-950/40">
        <div class="p-5">
            <div class="flex items-start justify-between gap-4"><div><h3 class="font-semibold">Ajouter une unité</h3><p class="mt-1 text-sm text-slate-500">Ex: Pièce, Pack, Boîte.</p></div><button class="dialog-close text-2xl leading-none text-slate-400" type="button">&times;</button></div>
            <div class="mt-5 space-y-4" data-inline-create data-endpoint="{{ route('catalog.units.store') }}" data-target="unit_id">
                <label class="block"><span class="text-xs font-semibold uppercase text-slate-500">Nom {!! $required !!}</span><input name="name" required class="{{ $input }}"></label>
                <label class="block"><span class="text-xs font-semibold uppercase text-slate-500">Description</span><textarea name="description" rows="2" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm"></textarea></label>
                <p class="inline-create-error hidden rounded-lg bg-rose-50 px-3 py-2 text-sm font-medium text-rose-700"></p>
                <button class="inline-create-submit w-full rounded-lg bg-brand px-4 py-2.5 text-sm font-semibold text-white" type="button">Ajouter</button>
            </div>
        </div>
    </dialog>

    <dialog id="tax-dialog" class="app-dialog w-[min(420px,calc(100vw-2rem))] rounded-2xl border border-slate-200 bg-white p-0 text-slate-950 shadow-2xl backdrop:bg-slate-950/40">
        <div class="p-5">
            <div class="flex items-start justify-between gap-4"><div><h3 class="font-semibold">Ajouter un taux de TVA</h3><p class="mt-1 text-sm text-slate-500">Ex: TVA 20%, Sans TVA.</p></div><button class="dialog-close text-2xl leading-none text-slate-400" type="button">&times;</button></div>
            <div class="mt-5 space-y-4" data-inline-create data-endpoint="{{ route('catalog.taxes.store') }}" data-target="tax_id">
                <label class="block"><span class="text-xs font-semibold uppercase text-slate-500">Nom {!! $required !!}</span><input name="name" required class="{{ $input }}"></label>
                <label class="block"><span class="text-xs font-semibold uppercase text-slate-500">Taux (%) {!! $required !!}</span><input name="rate" required type="number" step="0.01" min="0" max="100" value="20" class="{{ $input }}"></label>
                <p class="inline-create-error hidden rounded-lg bg-rose-50 px-3 py-2 text-sm font-medium text-rose-700"></p>
                <button class="inline-create-submit w-full rounded-lg bg-brand px-4 py-2.5 text-sm font-semibold text-white" type="button">Ajouter</button>
            </div>
        </div>
    </dialog>
@endif
