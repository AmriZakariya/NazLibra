@php
    $input = 'mt-1 h-10 w-full rounded-lg border border-slate-200 px-3 text-sm transition focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900';
    $select = 'mt-1 h-10 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm transition focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900';
    $item = $item ?? null;
    $serviceUnit = $units->firstWhere('name', 'Service') ?? $units->first();
@endphp

<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Code de l'article</span>
    <input name="item_code" value="{{ old('item_code', $item?->item_code) }}" class="{{ $input }}" placeholder="Auto si vide">
</label>
<label class="block lg:col-span-2">
    <span class="text-xs font-semibold uppercase text-slate-500">Nom de l'article</span>
    <input name="title" required value="{{ old('title', $item?->title) }}" class="{{ $input }}" placeholder="Impression A4, adhésion, pénalité">
</label>
<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Catégorie</span>
    <select name="category_id" required data-searchable-select data-placeholder="Rechercher une catégorie..." class="{{ $select }}">
        @foreach ($categories as $category)
            <option value="{{ $category->id }}" @selected(old('category_id', $item?->category_id) ? (string) old('category_id', $item?->category_id) === (string) $category->id : $category->name === 'Services')>{{ $category->name }}</option>
        @endforeach
    </select>
</label>
<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Code à barre</span>
    <input name="barcode" value="{{ old('barcode', $item?->barcode) }}" class="{{ $input }}" placeholder="Code scanner optionnel">
</label>
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
    <span class="text-xs font-semibold uppercase text-slate-500">Prix (Expenses)</span>
    <input name="price" required type="number" step="0.01" min="0" value="{{ old('price', $item?->price ?? 0) }}" class="{{ $input }}" placeholder="Prix hors taxe">
</label>
<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Impôt</span>
    <select name="tax_id" required data-searchable-select data-placeholder="Rechercher une taxe..." class="{{ $select }}">
        <option value="">- choisir -</option>
        @foreach ($taxes as $tax)
            <option value="{{ $tax->id }}" @selected((string) old('tax_id', $item?->tax_id) === (string) $tax->id)>{{ $tax->name }} ({{ number_format((float) $tax->rate, 2, ',', ' ') }}%)</option>
        @endforeach
    </select>
</label>
<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Prix d'achat</span>
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
    <span class="text-xs font-semibold uppercase text-slate-500">Prix de vente</span>
    <input name="sale_price" required type="number" step="0.01" min="0" value="{{ old('sale_price', $item?->sale_price ?? 0) }}" class="{{ $input }}">
</label>
<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Sélectionnez une image</span>
    <input name="item_image" type="file" accept="image/*" class="mt-1 block w-full rounded-lg border border-dashed border-slate-200 px-3 py-2 text-sm dark:border-white/10 dark:bg-slate-900">
</label>
<label class="block lg:col-span-3">
    <span class="text-xs font-semibold uppercase text-slate-500">La description</span>
    <textarea name="description" rows="3" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm transition focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900" placeholder="Règles, taxe, note de caisse">{{ old('description', $item?->description) }}</textarea>
</label>
<input type="hidden" name="unit_id" value="{{ old('unit_id', $item?->unit_id ?? $serviceUnit?->id) }}">
<input type="hidden" name="stock_quantity" value="9999">
<input type="hidden" name="min_stock_threshold" value="0">
<input type="hidden" name="status" value="active">
