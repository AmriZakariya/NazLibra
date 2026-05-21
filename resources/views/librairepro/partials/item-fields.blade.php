@php
    $input = 'mt-1 h-10 w-full rounded-lg border border-slate-200 px-3 text-sm transition focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900';
    $select = 'mt-1 h-10 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm transition focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900';
    $section = 'lg:col-span-4 -mb-1 mt-2 border-t border-slate-200 pt-4 text-xs font-semibold uppercase text-slate-500 dark:border-white/10';
    $required = '<span class="ms-1 text-rose-500">*</span>';
@endphp

<div class="{{ $section }}">Identification</div>
<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Code de l'article</span>
    <input name="item_code" value="{{ old('item_code', $item?->item_code) }}" class="{{ $input }}" placeholder="Auto si vide">
</label>
<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Type {!! $required !!}</span>
    <select name="type" required class="{{ $select }}">
        @foreach (['book' => 'Livre', 'supply' => 'Produit physique', 'service' => 'Service'] as $value => $label)
            <option value="{{ $value }}" @selected(old('type', $item?->type ?? 'book') === $value)>{{ $label }}</option>
        @endforeach
    </select>
</label>
<label class="block lg:col-span-2">
    <span class="text-xs font-semibold uppercase text-slate-500">Nom de l'article {!! $required !!}</span>
    <input name="title" required value="{{ old('title', $item?->title) }}" class="{{ $input }}" placeholder="Titre ou nom produit">
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
        <span class="text-xs font-semibold uppercase text-slate-500">Catégorie {!! $required !!}</span>
        @unless ($item)
            <button class="inline-create-open text-xs font-semibold text-brand hover:underline" type="button" data-dialog="category-dialog">+ Ajouter</button>
        @endunless
    </div>
    <select name="category_id" required data-searchable-select data-placeholder="Rechercher une catégorie..." class="{{ $select }}">
        <option value="">Sans catégorie</option>
        @foreach ($categories as $category)
            <option value="{{ $category->id }}" @selected((string) old('category_id', $item?->category_id) === (string) $category->id)>{{ $category->name }}</option>
        @endforeach
    </select>
</div>
<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Groupe d'articles</span>
    <select name="item_group" class="{{ $select }}">
        @foreach (['Single' => 'Single', 'Pack' => 'Pack', 'Variants' => 'Variantes', 'Group' => 'Groupe'] as $value => $label)
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

<div class="{{ $section }}">Codes et livre</div>
<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Nombre d'unités</span>
    <input name="nb_item" type="number" min="0" value="{{ old('nb_item', $item?->nb_item) }}" class="{{ $input }}" placeholder="Pour pack">
</label>
<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">SKU</span>
    <input name="sku" value="{{ old('sku', $item?->sku) }}" class="{{ $input }}" placeholder="Référence interne">
</label>
<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">HSN</span>
    <input name="hsn" value="{{ old('hsn', $item?->hsn) }}" class="{{ $input }}">
</label>
<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">SAC</span>
    <input name="sac" value="{{ old('sac', $item?->sac) }}" class="{{ $input }}">
</label>
<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Auteur</span>
    <input name="author" value="{{ old('author', $item?->author) }}" class="{{ $input }}" placeholder="Auteur">
</label>
<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Éditeur</span>
    <input name="editor" value="{{ old('editor', $item?->editor) }}" class="{{ $input }}" placeholder="Éditeur texte">
</label>
<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Vérificateur</span>
    <input name="verifier" value="{{ old('verifier', $item?->verifier) }}" class="{{ $input }}">
</label>
<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Traducteur / thème ancien</span>
    <input name="translator" value="{{ old('translator', $item?->translator) }}" class="{{ $input }}">
</label>
<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">ISBN</span>
    <input name="isbn" value="{{ old('isbn', $item?->isbn) }}" class="{{ $input }}" placeholder="978...">
</label>
<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Num édition</span>
    <input name="edition_number" value="{{ old('edition_number', $item?->edition_number) }}" class="{{ $input }}">
</label>
<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Année édition</span>
    <input name="edition_year" value="{{ old('edition_year', $item?->edition_year) }}" class="{{ $input }}">
</label>
<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Thème</span>
    <input name="theme" value="{{ old('theme', $item?->theme) }}" class="{{ $input }}">
</label>
<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Nature de papier</span>
    <input name="paper_type" value="{{ old('paper_type', $item?->paper_type) }}" class="{{ $input }}">
</label>
<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Couverture</span>
    <input name="cover_type" value="{{ old('cover_type', $item?->cover_type) }}" class="{{ $input }}">
</label>
<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Collection</span>
    <input name="collection" value="{{ old('collection', $item?->collection) }}" class="{{ $input }}">
</label>
<div class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Code à barre</span>
    <div class="mt-1 flex gap-2">
        <input name="barcode" value="{{ old('barcode', $item?->barcode) }}" class="barcode-input h-10 min-w-0 flex-1 rounded-lg border border-slate-200 px-3 text-sm transition focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900" placeholder="EAN / code scanner">
        <button class="barcode-scan-btn h-10 shrink-0 rounded-lg border border-slate-200 px-3 text-sm font-semibold text-slate-700 hover:border-brand hover:text-brand dark:border-white/10 dark:text-slate-200" type="button">Scanner</button>
    </div>
</div>
<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Autre code article</span>
    <input name="custom_barcode1" value="{{ old('custom_barcode1', $item?->custom_barcode1) }}" class="{{ $input }}">
</label>

<div class="{{ $section }}">Prix, taxes et stock</div>
<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Type de remise</span>
    <select name="discount_type" class="{{ $select }}">
        @foreach (['Percentage' => 'Pourcentage', 'Fixed' => 'Montant fixe'] as $value => $label)
            <option value="{{ $value }}" @selected(old('discount_type', $item?->discount_type ?? 'Percentage') === $value)>{{ $label }}</option>
        @endforeach
    </select>
</label>
<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Remise</span>
    <input name="discount" type="number" step="0.01" min="0" value="{{ old('discount', $item?->discount ?? 0) }}" class="{{ $input }}">
</label>
<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Prix d'achat TTC</span>
    <input name="price" type="number" step="0.01" min="0" value="{{ old('price', $item?->price ?? $item?->purchase_price ?? 0) }}" class="{{ $input }}">
</label>
<div class="block">
    <div class="flex items-center justify-between gap-2">
        <span class="text-xs font-semibold uppercase text-slate-500">Impôt {!! $required !!}</span>
        @unless ($item)
            <button class="inline-create-open text-xs font-semibold text-brand hover:underline" type="button" data-dialog="tax-dialog">+ Ajouter</button>
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
    <span class="text-xs font-semibold uppercase text-slate-500">Prix d'achat {!! $required !!}</span>
    <input name="purchase_price" required type="number" step="0.01" min="0" value="{{ old('purchase_price', $item?->purchase_price ?? 0) }}" class="{{ $input }}">
</label>
<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Type de taxe</span>
    <select name="tax_type" class="{{ $select }}">
        @foreach (['Inclusive' => 'Incluse', 'Exclusive' => 'Exclue'] as $value => $label)
            <option value="{{ $value }}" @selected(old('tax_type', $item?->tax_type ?? 'Exclusive') === $value)>{{ $label }}</option>
        @endforeach
    </select>
</label>
<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Marge bénéficiaire (%)</span>
    <input name="profit_margin" type="number" step="0.01" min="0" value="{{ old('profit_margin', $item?->profit_margin ?? 0) }}" class="{{ $input }}">
</label>
<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Prix de vente {!! $required !!}</span>
    <input name="sale_price" required type="number" step="0.01" min="0" value="{{ old('sale_price', $item?->sale_price ?? 0) }}" class="{{ $input }}">
</label>
<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Prix revendeur TTC</span>
    <input name="reseller_sale_price" type="number" step="0.01" min="0" value="{{ old('reseller_sale_price', $item?->reseller_sale_price ?? 0) }}" class="{{ $input }}">
</label>
<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">MRP</span>
    <input name="mrp" type="number" step="0.01" min="0" value="{{ old('mrp', $item?->mrp ?? 0) }}" class="{{ $input }}">
</label>
<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Stock d'ouverture</span>
    <input name="opening_stock" type="number" min="0" value="{{ old('opening_stock', $item?->opening_stock ?? $item?->stock_quantity ?? 0) }}" class="{{ $input }}">
</label>
<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Stock {!! $required !!}</span>
    <input name="stock_quantity" required type="number" min="0" value="{{ old('stock_quantity', $item?->stock_quantity ?? 0) }}" class="{{ $input }}">
</label>
<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Quantité d'alerte {!! $required !!}</span>
    <input name="min_stock_threshold" required type="number" min="0" value="{{ old('min_stock_threshold', $item?->min_stock_threshold ?? 3) }}" class="{{ $input }}">
</label>
<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Points vendeur</span>
    <input name="seller_points" type="number" step="0.01" min="0" value="{{ old('seller_points', $item?->seller_points ?? 0) }}" class="{{ $input }}">
</label>

<div class="{{ $section }}">Magasin et médias</div>
<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Magasin</span>
    <input name="warehouse" value="{{ old('warehouse', $item?->warehouse ?? 'Oubra store') }}" class="{{ $input }}">
</label>
<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Emplacement</span>
    <input name="location" value="{{ old('location', $item?->location) }}" class="{{ $input }}" placeholder="Rayon A-02">
</label>
<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">BL</span>
    <input name="delivery_note" value="{{ old('delivery_note', $item?->delivery_note) }}" class="{{ $input }}">
</label>
<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">FA</span>
    <input name="invoice_reference" value="{{ old('invoice_reference', $item?->invoice_reference) }}" class="{{ $input }}">
</label>
<label class="block">
    <span class="text-xs font-semibold uppercase text-slate-500">Statut</span>
    <select name="status" class="{{ $select }}">
        @foreach (['active' => 'Actif', 'archived' => 'Archivé', 'out_of_stock' => 'Rupture'] as $value => $label)
            <option value="{{ $value }}" @selected(old('status', $item?->status ?? 'active') === $value)>{{ $label }}</option>
        @endforeach
    </select>
</label>
<label class="block lg:col-span-3">
    <span class="text-xs font-semibold uppercase text-slate-500">Sélectionnez une image</span>
    <input name="item_image" type="file" accept="image/*" class="mt-1 block w-full rounded-lg border border-dashed border-slate-200 px-3 py-2 text-sm dark:border-white/10 dark:bg-slate-900">
    <span class="mt-1 block text-xs text-slate-500">Max 1 Mo. L’image est conservée avec la fiche article.</span>
</label>
<label class="block lg:col-span-4">
    <span class="text-xs font-semibold uppercase text-slate-500">Description</span>
    <textarea name="description" rows="3" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm transition focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900" placeholder="Description, notes internes, détails d’édition">{{ old('description', $item?->description) }}</textarea>
</label>

@if (! $item)
    <dialog id="brand-dialog" class="w-[min(460px,calc(100vw-2rem))] rounded-2xl border border-slate-200 bg-white p-0 text-slate-950 shadow-2xl backdrop:bg-slate-950/40">
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

    <dialog id="category-dialog" class="w-[min(460px,calc(100vw-2rem))] rounded-2xl border border-slate-200 bg-white p-0 text-slate-950 shadow-2xl backdrop:bg-slate-950/40">
        <div class="p-5">
            <div class="flex items-start justify-between gap-4">
                <div><h3 class="font-semibold">Ajouter une catégorie</h3><p class="mt-1 text-sm text-slate-500">La catégorie sera sélectionnée après création.</p></div>
                <button class="dialog-close text-2xl leading-none text-slate-400" type="button">&times;</button>
            </div>
            <div class="mt-5 space-y-4" data-inline-create data-endpoint="{{ route('catalog.categories.store') }}" data-target="category_id">
                <label class="block"><span class="text-xs font-semibold uppercase text-slate-500">Nom {!! $required !!}</span><input name="name" required class="{{ $input }}"></label>
                <label class="block"><span class="text-xs font-semibold uppercase text-slate-500">Parent</span><select name="parent_id" data-searchable-select data-placeholder="Rechercher un parent..." class="{{ $select }}"><option value="">Aucun</option>@foreach ($categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select></label>
                <div class="grid gap-4 sm:grid-cols-2"><label class="block"><span class="text-xs font-semibold uppercase text-slate-500">Icône</span><input name="icon" class="{{ $input }}" placeholder="book-open"></label><label class="block"><span class="text-xs font-semibold uppercase text-slate-500">Couleur</span><input name="color" type="color" value="#4F46E5" class="{{ $input }} p-1"></label></div>
                <input name="loan_duration_days" value="14" type="hidden"><input name="daily_fine_amount" value="2" type="hidden">
                <p class="inline-create-error hidden rounded-lg bg-rose-50 px-3 py-2 text-sm font-medium text-rose-700"></p>
                <button class="inline-create-submit w-full rounded-lg bg-brand px-4 py-2.5 text-sm font-semibold text-white" type="button">Ajouter</button>
            </div>
        </div>
    </dialog>

    <dialog id="unit-dialog" class="w-[min(420px,calc(100vw-2rem))] rounded-2xl border border-slate-200 bg-white p-0 text-slate-950 shadow-2xl backdrop:bg-slate-950/40">
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

    <dialog id="tax-dialog" class="w-[min(420px,calc(100vw-2rem))] rounded-2xl border border-slate-200 bg-white p-0 text-slate-950 shadow-2xl backdrop:bg-slate-950/40">
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
