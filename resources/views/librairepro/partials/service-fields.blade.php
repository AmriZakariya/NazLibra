@php
    $input = 'mt-1 h-11 w-full rounded-lg border border-slate-200 px-3 text-sm shadow-sm transition focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900';
    $readonlyInput = $input.' bg-slate-50 text-slate-500';
    $select = 'mt-1 h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm shadow-sm transition focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900';
    $section = 'lg:col-span-4 mt-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-xs font-semibold uppercase text-slate-500 shadow-sm dark:border-white/10 dark:bg-white/5';
    $required = '<span class="ms-1 text-rose-500">*</span>';
    $item = $item ?? null;
    $serviceUnit = $units->firstWhere('name', 'Service') ?? $units->first();
    $typeValue = old('type', $item?->type ?? 'service');
    $typeOptions = [
        'service' => ['label' => 'Service', 'hint' => 'Sans stock'],
        'supply' => ['label' => 'Produit', 'hint' => 'Stock physique'],
        'book' => ['label' => 'Livre', 'hint' => 'ISBN possible'],
    ];
    $storeOptions = collect($stores ?? [])->filter(fn ($store) => data_get($store, 'is_active', true));
    $defaultStoreName = data_get($currentStore ?? [], 'name', 'Magasin principal');
    $warehouseValue = old('warehouse', $item?->warehouse ?? $defaultStoreName);
    $isEnabled = old('is_enabled', $item?->is_enabled ?? true);
    $checkoutVisible = old('checkout_visible', $item?->checkout_visible ?? true);
    $currentImage = collect($item?->images ?? [])->first();
@endphp

<div class="{{ $section }}">Identification</div>
<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Code de l'article</span>
    <input name="item_code" value="{{ old('item_code', $item?->item_code ?? $suggestedItemCode ?? '') }}" class="{{ $item ? $input : $readonlyInput }}" placeholder="Auto" @readonly(! $item)>
</label>
<div class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Type d'élément {!! $required !!}</span>
    <div class="mt-1 grid gap-2 sm:grid-cols-3">
        @foreach ($typeOptions as $value => $option)
            <label class="app-type-choice">
                <input type="radio" name="type" value="{{ $value }}" @checked($typeValue === $value)>
                <span class="app-type-choice-card">
                    {{ $option['label'] }}
                    <small>{{ $option['hint'] }}</small>
                </span>
            </label>
        @endforeach
    </div>
</div>
<label class="block lg:col-span-2">
    <span class="text-xs font-semibold uppercase text-slate-500">Nom du service {!! $required !!}</span>
    <input name="title" required value="{{ old('title', $item?->title) }}" class="{{ $input }}" placeholder="Impression A4, adhésion, pénalité">
</label>
<div class="block">
    <div class="flex items-center justify-between gap-2">
        <span class="text-xs font-semibold uppercase text-slate-500">Catégorie {!! $required !!}</span>
        @unless ($item)
            <button class="inline-create-open text-xs font-semibold text-brand hover:underline" type="button" data-dialog="service-category-dialog">+ Ajouter</button>
        @endunless
    </div>
    <select name="category_id" required data-searchable-select data-placeholder="Rechercher une catégorie..." class="{{ $select }}">
        @foreach ($categories as $category)
            <option value="{{ $category->id }}" @selected(old('category_id', $item?->category_id) ? (string) old('category_id', $item?->category_id) === (string) $category->id : $category->name === 'Services')>{{ $category->name }}</option>
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
        <input name="barcode" value="{{ old('barcode', $item?->barcode) }}" class="barcode-input h-11 min-w-0 flex-1 rounded-lg border border-slate-200 px-3 text-sm shadow-sm transition focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900" placeholder="Code scanner optionnel">
        <button class="barcode-scan-btn h-11 shrink-0 rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 shadow-sm hover:border-brand hover:text-brand dark:border-white/10 dark:bg-slate-900 dark:text-slate-200" type="button">Scanner</button>
    </div>
</label>

<div class="{{ $section }}">Tarification</div>
<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">SAC</span>
    <input name="sac" value="{{ old('sac', $item?->sac) }}" class="{{ $input }}">
</label>
<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">HSN</span>
    <input name="hsn" value="{{ old('hsn', $item?->hsn) }}" class="{{ $input }}">
</label>
<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Points vendeur</span>
    <input name="seller_points" type="number" step="0.01" min="0" value="{{ old('seller_points', $item?->seller_points ?? 0) }}" class="{{ $input }}">
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
    <span class="text-xs font-semibold uppercase text-slate-500">Prix HT {!! $required !!}</span>
    <input name="price" required type="number" step="0.01" min="0" value="{{ old('price', $item?->price ?? 0) }}" class="{{ $input }}" placeholder="Prix hors taxe">
</label>
<div class="block">
    <div class="flex items-center justify-between gap-2">
        <span class="text-xs font-semibold uppercase text-slate-500">Impôt {!! $required !!}</span>
        @unless ($item)
            <button class="inline-create-open text-xs font-semibold text-brand hover:underline" type="button" data-dialog="service-tax-dialog">+ Ajouter</button>
        @endunless
    </div>
    <select name="tax_id" required data-searchable-select data-placeholder="Rechercher une taxe..." class="{{ $select }}">
        <option value="">- choisir -</option>
        @foreach ($taxes as $tax)
            <option value="{{ $tax->id }}" @selected((string) old('tax_id', $item?->tax_id) === (string) $tax->id)>{{ $tax->name }} ({{ number_format((float) $tax->rate, 2, ',', ' ') }}%)</option>
        @endforeach
    </select>
</div>
<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Coût interne {!! $required !!}</span>
    <input name="purchase_price" required type="number" step="0.01" min="0" value="{{ old('purchase_price', $item?->purchase_price ?? 0) }}" class="{{ $input }}">
</label>
<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Type de taxe de vente</span>
    <select name="tax_type" class="{{ $select }}">
        <option value="Exclusive" @selected(old('tax_type', $item?->tax_type ?? 'Exclusive') === 'Exclusive')>Exclue</option>
        <option value="Inclusive" @selected(old('tax_type', $item?->tax_type) === 'Inclusive')>Incluse</option>
    </select>
</label>
<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Prix de vente {!! $required !!}</span>
    <input name="sale_price" required type="number" step="0.01" min="0" value="{{ old('sale_price', $item?->sale_price ?? 0) }}" class="{{ $input }}">
</label>

<div class="{{ $section }}">Magasin, médias et notes</div>
<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Stock si produit</span>
    <input name="stock_quantity" type="number" min="0" value="{{ old('stock_quantity', $item?->type === 'service' ? 9999 : ($item?->stock_quantity ?? 0)) }}" class="{{ $input }}">
    <span class="mt-1 block text-xs text-slate-500">Ignoré automatiquement pour les services.</span>
</label>
<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Quantité d'alerte</span>
    <input name="min_stock_threshold" type="number" min="0" value="{{ old('min_stock_threshold', $item?->type === 'service' ? 0 : ($item?->min_stock_threshold ?? 0)) }}" class="{{ $input }}">
</label>
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
    <span class="text-xs font-semibold uppercase text-slate-500">Image service</span>
    <div class="mt-1 grid gap-3 rounded-lg border border-dashed border-slate-200 bg-white p-3 dark:border-white/10 dark:bg-slate-900 md:grid-cols-[88px_minmax(0,1fr)]">
        <div class="grid size-20 place-items-center overflow-hidden rounded-lg bg-slate-100 text-xs font-semibold text-slate-500 dark:bg-white/10">
            @if ($currentImage)
                <img src="{{ asset('storage/'.$currentImage) }}" alt="{{ $item?->title ? 'Image '.$item->title : 'Image service' }}" class="h-full w-full object-cover">
            @else
                Aucune image
            @endif
        </div>
        <div class="min-w-0">
            <input name="item_image" type="file" accept="image/*" class="block w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-white/10 dark:bg-slate-950">
            <span class="mt-1 block text-xs text-slate-500">Une nouvelle image devient l’image principale.</span>
            @if ($currentImage)
                <label class="mt-2 flex items-center gap-2 text-xs font-semibold text-slate-600 dark:text-slate-300">
                    <input type="checkbox" name="remove_item_image" value="1" class="rounded border-slate-300 text-brand focus:ring-brand">
                    Supprimer l'image actuelle
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
<label class="block lg:col-span-2">
    <span class="text-xs font-semibold uppercase text-slate-500">La description</span>
    <textarea name="description" rows="3" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm transition focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900" placeholder="Règles, taxe, note de caisse">{{ old('description', $item?->description) }}</textarea>
</label>

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
