@php
    $tr = fn (string $text): string => \App\Support\Locale::t($text);
    $input = 'mt-1 h-11 w-full rounded-lg border border-slate-200 px-3 text-sm shadow-sm transition focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900';
    $readonlyInput = $input.' bg-slate-50 text-slate-400 cursor-default';
    $select = 'mt-1 h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm shadow-sm transition focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900';
    $section = 'lg:col-span-4 mt-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-xs font-semibold uppercase text-slate-500 shadow-sm dark:border-white/10 dark:bg-white/5';
    $required = '<span class="ms-1 text-rose-500">*</span>';
    $item = $item ?? null;
    $serviceUnit = $units->firstWhere('name', 'Service') ?? $units->first();
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

{{-- Type forcé à "service" — pas de choix possible ici --}}
<input type="hidden" name="type" value="service">

{{-- ══ IDENTIFICATION ════════════════════════════════════════════════════════════ --}}
<div class="{{ $section }}">Identification</div>

<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Code de l'article</span>
    <input name="item_code"
           value="{{ old('item_code', $item?->item_code ?? '') }}"
           class="{{ $item ? $input : $readonlyInput }}"
           placeholder="{{ $item ? 'Auto' : ($suggestedItemCode ?? 'Généré automatiquement') }}"
           @readonly(! $item)>
    @unless ($item)
        <span class="mt-1 block text-xs text-slate-400">Généré automatiquement — modifiable après création.</span>
    @endunless
</label>

<label class="block lg:col-span-2">
    <span class="text-xs font-semibold uppercase text-slate-500">{{ $tr('Nom du service') }} {!! $required !!}</span>
    <input name="title" required value="{{ old('title', $item?->title) }}" class="{{ $input }}" placeholder="Impression A4, adhésion, consultation…">
</label>

<div class="block">
    <div class="flex items-center justify-between gap-2">
        <span class="text-xs font-semibold uppercase text-slate-500">Catégorie</span>
        @unless ($item)
            <button class="inline-create-open text-xs font-semibold text-brand hover:underline" type="button" data-dialog="service-category-dialog">+ Ajouter</button>
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
    <span class="text-xs font-semibold uppercase text-slate-500">Unité {!! $required !!}</span>
    <select name="unit_id" required data-searchable-select data-placeholder="Rechercher une unité..." class="{{ $select }}">
        @foreach ($units as $unit)
            <option value="{{ $unit->id }}" @selected((string) old('unit_id', $item?->unit_id ?? $serviceUnit?->id) === (string) $unit->id)>{{ $unit->name }}</option>
        @endforeach
    </select>
</label>

<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Code à barre</span>
    <div class="mt-1 flex gap-2">
        <input name="barcode" value="{{ old('barcode', $item?->barcode) }}" class="barcode-input h-11 min-w-0 flex-1 rounded-lg border border-slate-200 px-3 text-sm shadow-sm transition focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900" placeholder="{{ $tr('Code scanner optionnel') }}">
        <button class="barcode-scan-btn h-11 shrink-0 rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 shadow-sm hover:border-brand hover:text-brand dark:border-white/10 dark:bg-slate-900 dark:text-slate-200" type="button">Scanner</button>
    </div>
</label>

{{-- ══ TARIFICATION ══════════════════════════════════════════════════════════════ --}}
<div class="{{ $section }}">Tarification</div>

<div class="block">
    <div class="flex items-center justify-between gap-2">
        <span class="text-xs font-semibold uppercase text-slate-500">TVA {!! $required !!}</span>
        @unless ($item)
            <button class="inline-create-open text-xs font-semibold text-brand hover:underline" type="button" data-dialog="service-tax-dialog">+ Ajouter</button>
        @endunless
    </div>
    <select name="tax_id" required data-searchable-select data-placeholder="Rechercher une taxe..." data-price-tax class="{{ $select }}">
        <option value="">- choisir -</option>
        @foreach ($taxes as $tax)
            <option value="{{ $tax->id }}" data-rate="{{ (float) $tax->rate }}" @selected((string) $taxValue === (string) $tax->id)>{{ $tax->name }} ({{ number_format((float) $tax->rate, 2, ',', ' ') }}%)</option>
        @endforeach
    </select>
</div>

<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Type de taxe de vente</span>
    <select name="tax_type" data-price-tax-type class="{{ $select }}">
        <option value="Exclusive" @selected(old('tax_type', $item?->tax_type ?? 'Exclusive') === 'Exclusive')>Exclue (TTC = HT + taxe)</option>
        <option value="Inclusive" @selected(old('tax_type', $item?->tax_type) === 'Inclusive')>Incluse (TTC = HT)</option>
    </select>
</label>

<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Coût interne {!! $required !!}</span>
    <input name="purchase_price" required type="number" step="0.01" min="0" inputmode="decimal"
           data-price-purchase
           value="{{ $moneyValue('purchase_price', $item?->purchase_price ?? 0) }}" class="{{ $input }}"
           placeholder="0.00">
</label>

<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Prix TTC <span class="ms-1 rounded bg-indigo-50 px-1 py-0.5 text-xs font-semibold text-indigo-600 dark:bg-indigo-900/30 dark:text-indigo-300">Auto</span></span>
    <input name="price" type="number" step="0.01" min="0" inputmode="decimal"
           data-price-ttc readonly
           value="{{ $moneyValue('price', $item?->price ?? 0) }}" class="{{ $readonlyInput }}"
           placeholder="0.00">
    <span class="mt-1 block text-xs text-slate-400">Calculé depuis le coût interne + TVA.</span>
</label>

<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Prix de vente HT {!! $required !!}</span>
    <input name="sale_price" required type="number" step="0.01" min="0" inputmode="decimal"
           data-price-sale
           value="{{ $moneyValue('sale_price', $item?->sale_price ?? 0) }}" class="{{ $input }}"
           placeholder="0.00">
</label>

<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Type de remise</span>
    <select name="discount_type" class="{{ $select }}">
        <option value="Percentage" @selected(old('discount_type', $item?->discount_type ?? 'Percentage') === 'Percentage')>Pourcentage</option>
        <option value="Fixed" @selected(old('discount_type', $item?->discount_type) === 'Fixed')>Montant fixe</option>
    </select>
</label>

<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Remise</span>
    <input name="discount" type="number" step="0.01" min="0" value="{{ old('discount', $item?->discount ?? 0) }}" class="{{ $input }}">
</label>

<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Points vendeur</span>
    <input name="seller_points" type="number" step="0.01" min="0" value="{{ old('seller_points', $item?->seller_points ?? 0) }}" class="{{ $input }}">
</label>

{{-- ══ MAGASIN, MÉDIAS ET NOTES ═════════════════════════════════════════════════ --}}
<div class="{{ $section }}">Magasin, médias et notes</div>

{{-- Services ne stockent pas de stock physique — champs figés --}}
<input type="hidden" name="stock_quantity" value="9999">
<input type="hidden" name="min_stock_threshold" value="0">

<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Magasin</span>
    <select name="warehouse" data-searchable-select data-placeholder="Choisir un magasin..." class="{{ $select }}">
        @forelse ($storeOptions as $store)
            <option value="{{ data_get($store, 'name') }}" @selected($warehouseValue === data_get($store, 'name'))>{{ data_get($store, 'name') }}{{ data_get($store, 'type') === 'warehouse' ? ' · Dépôt' : '' }}</option>
        @empty
            <option value="{{ $warehouseValue }}">{{ $warehouseValue }}</option>
        @endforelse
    </select>
</label>

<div class="block lg:col-span-2">
    <span class="text-xs font-semibold uppercase text-slate-500">{{ $tr('Image service') }}</span>
    <div class="mt-1 grid gap-3 rounded-lg border border-dashed border-slate-200 bg-white p-3 dark:border-white/10 dark:bg-slate-900 md:grid-cols-[88px_minmax(0,1fr)]">
        <div class="grid size-20 place-items-center overflow-hidden rounded-lg bg-slate-100 text-xs font-semibold text-slate-500 dark:bg-white/10">
            @if ($currentImage)
                <img src="{{ asset('storage/'.$currentImage) }}" alt="{{ $item?->title ? $tr('Image').' '.$item->title : $tr('Image service') }}" class="h-full w-full object-cover">
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
            <span class="mt-1 block text-xs text-slate-500">{{ $tr('Une nouvelle image devient image principale.') }}</span>
            @if ($currentImage)
                <label class="mt-2 flex items-center gap-2 text-xs font-semibold text-slate-600 dark:text-slate-300">
                    <input type="checkbox" name="remove_item_image" value="1" class="rounded border-slate-300 text-brand focus:ring-brand">
                    {{ $tr("Supprimer l'image actuelle") }}
                </label>
            @endif
        </div>
    </div>
</div>

<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Statut</span>
    <select name="status" class="{{ $select }}">
        @foreach (['active' => 'Actif', 'archived' => 'Archivé'] as $value => $label)
            <option value="{{ $value }}" @selected(old('status', $item?->status ?? 'active') === $value)>{{ $label }}</option>
        @endforeach
    </select>
</label>

<label class="block rounded-lg border border-slate-200 bg-white p-3 shadow-sm dark:border-white/10 dark:bg-slate-900">
    <input type="hidden" name="is_enabled" value="0">
    <span class="flex items-start gap-3">
        <input name="is_enabled" value="1" type="checkbox" @checked((bool) $isEnabled) class="mt-1 rounded border-slate-300 text-brand focus:ring-brand">
        <span>
            <span class="block text-xs font-semibold uppercase text-slate-500">Service activé</span>
            <small class="mt-1 block text-xs text-slate-500">Décochez pour désactiver ce service sans le supprimer.</small>
        </span>
    </span>
</label>

<label class="block rounded-lg border border-slate-200 bg-white p-3 shadow-sm dark:border-white/10 dark:bg-slate-900">
    <input type="hidden" name="checkout_visible" value="0">
    <span class="flex items-start gap-3">
        <input name="checkout_visible" value="1" type="checkbox" @checked((bool) $checkoutVisible) class="mt-1 rounded border-slate-300 text-brand focus:ring-brand">
        <span>
            <span class="block text-xs font-semibold uppercase text-slate-500">Visible sur la caisse</span>
            <small class="mt-1 block text-xs text-slate-500">Décochez pour masquer ce service pendant l'encaissement.</small>
        </span>
    </span>
</label>

<label class="block rounded-lg border border-slate-200 bg-white p-3 shadow-sm dark:border-white/10 dark:bg-slate-900">
    <input type="hidden" name="online_store_visible" value="0">
    <span class="flex items-start gap-3">
        <input name="online_store_visible" value="1" type="checkbox" @checked((bool) $onlineStoreVisible) class="mt-1 rounded border-slate-300 text-brand focus:ring-brand">
        <span>
            <span class="block text-xs font-semibold uppercase text-slate-500">Visible sur la boutique en ligne</span>
            <small class="mt-1 block text-xs text-slate-500">Décochez pour masquer ce service côté client.</small>
        </span>
    </span>
</label>

<label class="block lg:col-span-2">
    <span class="text-xs font-semibold uppercase text-slate-500">{{ $tr('Tags boutique') }}</span>
    <input name="tags" value="{{ old('tags', collect($item?->tags ?? [])->implode(', ')) }}" class="{{ $input }}" placeholder="{{ $tr('Ex: impression, adhésion, rapide') }}">
    <span class="mt-1 block text-xs text-slate-500">{{ $tr('Séparez les tags par des virgules. Ils servent aux filtres de la boutique en ligne.') }}</span>
</label>

<label class="block lg:col-span-2">
    <span class="text-xs font-semibold uppercase text-slate-500">Description</span>
    <textarea name="description" rows="3" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm transition focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900" placeholder="Règles, conditions, notes de caisse">{{ old('description', $item?->description) }}</textarea>
</label>

{{-- ══ INLINE-CREATE DIALOGS (create mode only) ════════════════════════════════ --}}
@if (! $item)
    <dialog id="service-category-dialog" class="app-dialog w-[min(460px,calc(100vw-2rem))] rounded-2xl border border-slate-200 bg-white p-0 text-slate-950 shadow-2xl backdrop:bg-slate-950/40">
        <div class="p-5">
            <div class="flex items-start justify-between gap-4">
                <div><h3 class="font-semibold">Ajouter une catégorie</h3><p class="mt-1 text-sm text-slate-500">La catégorie sera sélectionnée après création.</p></div>
                <button class="dialog-close text-2xl leading-none text-slate-400" type="button">&times;</button>
            </div>
            <div class="mt-5 space-y-4" data-inline-create data-endpoint="{{ route('catalog.categories.store') }}" data-target="category_id">
                <label class="block"><span class="text-xs font-semibold uppercase text-slate-500">Nom {!! $required !!}</span><input name="name" required class="{{ $input }}"></label>
                <input name="loan_duration_days" value="14" type="hidden"><input name="daily_fine_amount" value="2" type="hidden">
                <p class="inline-create-error hidden rounded-lg bg-rose-50 px-3 py-2 text-sm font-medium text-rose-700"></p>
                <button class="inline-create-submit w-full rounded-lg bg-brand px-4 py-2.5 text-sm font-semibold text-white" type="button">Ajouter</button>
            </div>
        </div>
    </dialog>

    <dialog id="service-tax-dialog" class="app-dialog w-[min(420px,calc(100vw-2rem))] rounded-2xl border border-slate-200 bg-white p-0 text-slate-950 shadow-2xl backdrop:bg-slate-950/40">
        <div class="p-5">
            <div class="flex items-start justify-between gap-4"><div><h3 class="font-semibold">Ajouter un impôt</h3><p class="mt-1 text-sm text-slate-500">Ex: TVA 20%, Sans TVA.</p></div><button class="dialog-close text-2xl leading-none text-slate-400" type="button">&times;</button></div>
            <div class="mt-5 space-y-4" data-inline-create data-endpoint="{{ route('catalog.taxes.store') }}" data-target="tax_id">
                <label class="block"><span class="text-xs font-semibold uppercase text-slate-500">Nom {!! $required !!}</span><input name="name" required class="{{ $input }}"></label>
                <label class="block"><span class="text-xs font-semibold uppercase text-slate-500">Pourcentage {!! $required !!}</span><input name="rate" required type="number" step="0.01" min="0" max="100" value="20" class="{{ $input }}"></label>
                <p class="inline-create-error hidden rounded-lg bg-rose-50 px-3 py-2 text-sm font-medium text-rose-700"></p>
                <button class="inline-create-submit w-full rounded-lg bg-brand px-4 py-2.5 text-sm font-semibold text-white" type="button">Ajouter</button>
            </div>
        </div>
    </dialog>
@endif

{{-- ══ TTC AUTO-CALCULATION ════════════════════════════════════════════════════ --}}
<script>
(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var form = document.querySelector('[data-service-form]') || document.querySelector('form[action*="ajouter-service"]') || document.querySelector('#panel-ajouter-service form') || document.querySelector('#panel-service-edit form');
        if (! form) {
            // Fallback: find the form that contains our hidden type=service field
            var typeInput = document.querySelector('input[name="type"][value="service"]');
            form = typeInput ? typeInput.closest('form') : null;
        }
        if (! form) return;

        var purchaseEl  = form.querySelector('[data-price-purchase]');
        var saleEl      = form.querySelector('[data-price-sale]');
        var ttcEl       = form.querySelector('[data-price-ttc]');
        var taxSel      = form.querySelector('[data-price-tax]');
        var taxTypeSel  = form.querySelector('[data-price-tax-type]');

        if (! purchaseEl || ! ttcEl) return;

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

        function syncTtc() {
            var purchase = parseFloat(purchaseEl.value) || 0;
            ttcEl.value  = calcTtc(purchase).toFixed(2);
        }

        purchaseEl.addEventListener('input', syncTtc);
        if (taxSel)     taxSel.addEventListener('change', syncTtc);
        if (taxTypeSel) taxTypeSel.addEventListener('change', syncTtc);

        syncTtc();
    });
})();
</script>
