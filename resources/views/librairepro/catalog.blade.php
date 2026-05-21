@php
    $money = fn ($amount) => number_format((float) $amount, 2, ',', ' ').' DH';
    $panel = request('panel', 'articles');
    $sortLink = fn ($key) => route('catalog', array_merge(request()->query(), [
        'sort' => $key,
        'direction' => $sort === $key && $direction === 'asc' ? 'desc' : 'asc',
    ]));
    $exportLink = route('catalog.export', request()->query());
    $statusLabel = fn ($status) => ['active' => 'Actif', 'archived' => 'Archivé', 'out_of_stock' => 'Rupture'][$status] ?? $status;
    $typeLabel = fn ($type) => ['book' => 'Livre', 'supply' => 'Produit', 'service' => 'Service'][$type] ?? $type;
    $sortIndicator = fn ($key) => $sort === $key ? ($direction === 'asc' ? ' ↑' : ' ↓') : '';
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
            'variantes' => ['label' => 'Variantes', 'hint' => 'Options', 'href' => route('catalog', ['panel' => 'variantes'])],
        ],
    ];
@endphp

<x-layouts.app :tenant="$tenant" :active="$active" title="LibrairePro · Catalogue">
    <section class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
        <div>
            <p class="text-sm font-medium text-brand">Catalogue & inventaire</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-normal">Catalogue opérationnel</h1>
            <p class="mt-2 max-w-4xl text-sm text-slate-600 dark:text-slate-300">Articles, services, imports et référentiels structurés pour une vraie journée de test en magasin.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('catalog.labels') }}" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold dark:border-white/10 dark:bg-white/5">Imprimer étiquettes</a>
            <a href="{{ route('catalog', ['panel' => 'import', 'kind' => 'items']) }}" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold dark:border-white/10 dark:bg-white/5">Importer</a>
            <a href="{{ route('catalog', ['panel' => 'ajouter']) }}" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white shadow-sm">Nouvel article</a>
        </div>
    </section>

    <section class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><p class="text-sm text-slate-500">Articles physiques</p><p class="mt-2 text-2xl font-semibold">{{ $catalogStats['items'] }}</p></article>
        <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><p class="text-sm text-slate-500">Services</p><p class="mt-2 text-2xl font-semibold">{{ $catalogStats['services'] }}</p></article>
        <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><p class="text-sm text-slate-500">Alertes stock</p><p class="mt-2 text-2xl font-semibold">{{ $catalogStats['low'] }}</p></article>
        <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><p class="text-sm text-slate-500">Valorisation achat</p><p class="mt-2 text-2xl font-semibold">{{ $money($catalogStats['value']) }}</p></article>
    </section>

    <section class="mt-6">
        <nav class="grid gap-3 rounded-xl border border-slate-200 bg-white p-3 shadow-sm dark:border-white/10 dark:bg-white/[0.03] xl:grid-cols-3" aria-label="Sections catalogue">
            @foreach ($sections as $group => $links)
                <div class="rounded-lg bg-slate-50 p-2 dark:bg-white/5">
                    <p class="px-2 pb-2 text-[11px] font-bold uppercase text-slate-500">{{ $group }}</p>
                    <div class="grid gap-1 sm:grid-cols-3">
                        @foreach ($links as $key => $section)
                            <a href="{{ $section['href'] }}" class="rounded-lg px-3 py-2.5 transition {{ $panel === $key ? 'bg-brand text-white shadow-sm' : 'text-slate-600 hover:bg-white dark:text-slate-300 dark:hover:bg-white/10' }}">
                                <span class="block text-sm font-semibold">{{ $section['label'] }}</span>
                                <span class="mt-0.5 block text-xs {{ $panel === $key ? 'text-white/70' : 'text-slate-400' }}">{{ $section['hint'] }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </nav>
    </section>

    @if (in_array($panel, ['articles', 'services'], true))
        <section class="mt-6 space-y-6">
            <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                <form action="{{ route('catalog') }}" class="grid gap-3 lg:grid-cols-[minmax(220px,1fr)_150px_190px_140px_130px_110px_120px] lg:items-end">
                    <input type="hidden" name="panel" value="{{ $panel }}">
                    <label class="block"><span class="text-xs font-semibold uppercase text-slate-500">Recherche rapide</span><input name="q" value="{{ $query }}" class="mt-1 h-10 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 text-sm outline-none focus:border-brand focus:ring-4 focus:ring-brand/10 dark:border-white/10 dark:bg-white/5" placeholder="Nom, code barre, ISBN, SKU"></label>
                    <label class="block"><span class="text-xs font-semibold uppercase text-slate-500">Type</span><select name="type" class="mt-1 h-10 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900" @disabled($panel === 'services')><option value="all" @selected($type === 'all')>Tous</option><option value="book" @selected($type === 'book')>Livre</option><option value="supply" @selected($type === 'supply')>Papeterie</option><option value="service" @selected($type === 'service')>Service</option></select></label>
                    <label class="block"><span class="text-xs font-semibold uppercase text-slate-500">Catégorie</span><select name="category" class="mt-1 h-10 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="all">Toutes</option>@foreach ($categories as $category)<option value="{{ $category->id }}" @selected((string) $categoryFilter === (string) $category->id)>{{ $category->name }}</option>@endforeach</select></label>
                    <label class="block"><span class="text-xs font-semibold uppercase text-slate-500">Stock</span><select name="stock" class="mt-1 h-10 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="all" @selected($stock === 'all')>Tout</option><option value="low" @selected($stock === 'low')>Stock bas</option><option value="out" @selected($stock === 'out')>Rupture</option></select></label>
                    <label class="block"><span class="text-xs font-semibold uppercase text-slate-500">Statut</span><select name="status" class="mt-1 h-10 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="all" @selected($status === 'all')>Tous</option><option value="active" @selected($status === 'active')>Actif</option><option value="archived" @selected($status === 'archived')>Archivé</option><option value="out_of_stock" @selected($status === 'out_of_stock')>Rupture</option></select></label>
                    <label class="block"><span class="text-xs font-semibold uppercase text-slate-500">Lignes</span><select name="per_page" class="mt-1 h-10 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="10" @selected($perPage === 10)>10</option><option value="25" @selected($perPage === 25)>25</option><option value="50" @selected($perPage === 50)>50</option><option value="100" @selected($perPage === 100)>100</option></select></label>
                    <button class="h-10 rounded-lg bg-brand px-4 text-sm font-semibold text-white" type="submit">Filtrer</button>
                </form>
            </article>

            <article class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.03] catalog-grid-shell">
                <div class="flex flex-col gap-3 border-b border-slate-200 p-4 dark:border-white/10 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h2 class="text-base font-semibold">{{ $panel === 'services' ? 'Liste des services' : 'Liste des articles' }}</h2>
                        <p class="mt-1 text-sm text-slate-500">{{ $items->total() }} résultat(s), page {{ $items->currentPage() }} sur {{ $items->lastPage() }}. Recherche serveur, tri et export alignés sur les filtres.</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button class="catalog-labels rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold dark:border-white/10" type="button">Étiquettes sélectionnées</button>
                        <a href="{{ $exportLink }}" class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold dark:border-white/10">Exporter CSV</a>
                        <a href="{{ route('catalog', ['panel' => $panel === 'services' ? 'ajouter-service' : 'ajouter']) }}" class="rounded-lg bg-brand px-3 py-2 text-sm font-semibold text-white">Ajouter</a>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="catalog-data-table w-full min-w-[1180px] text-left text-sm">
                        <thead class="sticky top-0 z-10 bg-slate-50 text-xs uppercase text-slate-500 shadow-[inset_0_-1px_0_var(--border-soft)] dark:bg-slate-900 dark:text-slate-400">
                            <tr>
                                <th class="px-4 py-3"><input class="catalog-check-all rounded border-slate-300" type="checkbox"></th>
                                <th class="px-4 py-3">Image</th>
                                <th class="px-4 py-3"><a href="{{ $sortLink('barcode') }}">Code de barre{{ $sortIndicator('barcode') }}</a></th>
                                <th class="px-4 py-3"><a href="{{ $sortLink('title') }}">Nom de l'article{{ $sortIndicator('title') }}</a></th>
                                <th class="px-4 py-3">Catégorie/<br>Type d'élément</th>
                                <th class="px-4 py-3">Unité</th>
                                <th class="px-4 py-3"><a href="{{ $sortLink('stock_quantity') }}">Stock{{ $sortIndicator('stock_quantity') }}</a></th>
                                <th class="px-4 py-3"><a href="{{ $sortLink('min_stock_threshold') }}">Quantité d'alerte{{ $sortIndicator('min_stock_threshold') }}</a></th>
                                <th class="px-4 py-3"><a href="{{ $sortLink('sale_price') }}">Prix de vente{{ $sortIndicator('sale_price') }}</a></th>
                                <th class="px-4 py-3">Impôt</th>
                                <th class="px-4 py-3">Statut</th>
                                <th class="px-4 py-3 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-white/10">
                            @forelse ($items as $item)
                                <tr class="align-top transition hover:bg-slate-50/80 dark:hover:bg-white/[0.03]">
                                    <td class="px-4 py-4"><input class="catalog-item-check rounded border-slate-300" value="{{ $item->id }}" type="checkbox"></td>
                                    <td class="px-4 py-4">
                                        @php($image = collect($item->images)->first())
                                        @if ($image)
                                            <img src="{{ asset('storage/'.$image) }}" alt="" class="size-11 rounded-lg object-cover">
                                        @else
                                            <div class="grid size-11 place-items-center rounded-lg bg-slate-100 text-xs font-bold text-slate-500 dark:bg-white/10 dark:text-slate-300">{{ mb_substr($item->title, 0, 2) }}</div>
                                        @endif
                                    </td>
                                    <td class="px-4 py-4 font-mono text-xs">{{ $item->barcode ?? $item->isbn ?? $item->sku ?? '—' }}</td>
                                    <td class="px-4 py-4"><div class="max-w-[320px]"><p class="font-semibold">{{ $item->title }}</p><p class="mt-1 text-xs text-slate-500">{{ $item->item_code ?? 'Sans code interne' }}@if ($item->brand?->name) · {{ $item->brand->name }}@endif</p>@if ($item->variants->isNotEmpty())<p class="mt-1 text-xs font-medium text-brand">{{ $item->variants->count() }} variante(s)</p>@endif</div></td>
                                    <td class="px-4 py-4"><span class="font-medium">{{ $item->category?->name ?? 'Sans catégorie' }}</span><span class="mt-1 block text-xs text-slate-500">{{ $typeLabel($item->type) }}</span></td>
                                    <td class="px-4 py-4">{{ $item->unit?->name ?? '—' }}</td>
                                    <td class="px-4 py-4"><x-status-pill :tone="$item->is_low_stock && $item->type !== 'service' ? 'warning' : 'success'">{{ $item->type === 'service' ? 'Illimité' : number_format($item->stock_quantity, 0, ',', ' ') }}</x-status-pill></td>
                                    <td class="px-4 py-4">{{ $item->type === 'service' ? '—' : number_format($item->min_stock_threshold, 0, ',', ' ') }}</td>
                                    <td class="px-4 py-4 font-semibold">{{ $money($item->sale_price) }}</td>
                                    <td class="px-4 py-4">{{ $item->tax ? $item->tax->name.' ('.number_format((float) $item->tax->rate, 2, ',', ' ').'%)' : '—' }}</td>
                                    <td class="px-4 py-4"><x-status-pill :tone="$item->status === 'out_of_stock' ? 'danger' : ($item->status === 'archived' ? 'neutral' : 'primary')">{{ $statusLabel($item->status) }}</x-status-pill></td>
                                    <td class="px-4 py-4 text-right"><a href="{{ route('catalog', array_merge(request()->query(), ['panel' => $panel, 'edit' => $item->id])) }}#edit-item" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold dark:border-white/10">Détail / modifier</a></td>
                                </tr>
                            @empty
                                <tr><td colspan="12" class="px-4 py-12 text-center text-sm text-slate-500">Aucune donnée trouvée. Ajustez les filtres ou lancez un import.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="flex flex-col gap-3 border-t border-slate-200 px-4 py-3 dark:border-white/10 lg:flex-row lg:items-center lg:justify-between">
                    <p class="text-sm text-slate-500">Affichage {{ $items->firstItem() ?? 0 }}-{{ $items->lastItem() ?? 0 }} sur {{ $items->total() }}</p>
                    <div>{{ $items->links() }}</div>
                </div>
            </article>

            @if ($editItem)
                <article id="edit-item" class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <div class="flex flex-col gap-3 border-b border-slate-200 pb-4 dark:border-white/10 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <h2 class="text-base font-semibold">Détail / modifier: {{ $editItem->title }}</h2>
                            <p class="mt-1 text-sm text-slate-500">{{ $editItem->item_code ?? 'Sans code' }} · {{ $editItem->barcode ?? $editItem->isbn ?? 'Sans code-barres' }} · {{ $editItem->category?->name ?? 'Sans catégorie' }}</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <x-status-pill :tone="$editItem->type === 'service' ? 'info' : 'primary'">{{ $editItem->type === 'service' ? 'Service' : 'Article' }}</x-status-pill>
                            <x-status-pill :tone="$editItem->is_low_stock ? 'warning' : 'success'">{{ $editItem->type === 'service' ? 'Stock illimité' : $editItem->stock_quantity.' unités' }}</x-status-pill>
                        </div>
                    </div>

                    <form action="{{ route('catalog.items.update', $editItem) }}" method="POST" enctype="multipart/form-data" class="mt-5 grid gap-4 lg:grid-cols-4">
                        @csrf
                        @method('PUT')
                        @if ($editItem->type === 'service')
                            <input type="hidden" name="type" value="service">
                            @include('librairepro.partials.service-fields', ['item' => $editItem, 'categories' => $categories, 'brands' => $brands, 'units' => $units, 'taxes' => $taxes])
                        @else
                            @include('librairepro.partials.item-fields', ['item' => $editItem, 'categories' => $categories, 'brands' => $brands, 'units' => $units, 'taxes' => $taxes])
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
            <article id="form-article" class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                <h2 class="text-base font-semibold">Ajouter un article</h2>
                <p class="mt-1 text-sm text-slate-500">Les champs obligatoires sont marqués, les référentiels peuvent être créés depuis le formulaire.</p>
                <form action="{{ route('catalog.items.store') }}" method="POST" enctype="multipart/form-data" class="mt-5 grid gap-4 lg:grid-cols-4">@csrf @include('librairepro.partials.item-fields', ['item' => null, 'categories' => $categories, 'brands' => $brands, 'units' => $units, 'taxes' => $taxes])<div class="lg:col-span-4 flex justify-end"><button class="rounded-lg bg-brand px-5 py-2.5 text-sm font-semibold text-white">Ajouter au catalogue</button></div></form>
            </article>
        </section>
    @endif

    @if ($panel === 'ajouter-service')
        <section class="mt-6">
            <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                <h2 class="text-base font-semibold">Ajouter un service</h2>
                <p class="mt-1 text-sm text-slate-500">Frais d’adhésion, impression, pénalités, livraison et prestations sans stock physique.</p>
                <form action="{{ route('catalog.items.store') }}" method="POST" enctype="multipart/form-data" class="mt-5 grid gap-4 lg:grid-cols-4">@csrf <input type="hidden" name="type" value="service">@include('librairepro.partials.service-fields', ['categories' => $categories, 'brands' => $brands, 'units' => $units, 'taxes' => $taxes])<div class="lg:col-span-4 flex justify-end"><button class="rounded-lg bg-brand px-5 py-2.5 text-sm font-semibold text-white">Ajouter le service</button></div></form>
            </article>
        </section>
    @endif

    @if ($panel === 'import')
        <section class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
            <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                <h2 class="text-base font-semibold">Importer des articles ou services</h2>
                <p class="mt-1 text-sm text-slate-500">Compatible avec les exports mylibrairie fournis: Liste d’articles, Liste des catégories, Liste des marques et Liste des variantes. La ligne titre est ignorée automatiquement.</p>
                <form action="{{ route('catalog.import') }}" method="POST" enctype="multipart/form-data" class="mt-5 grid gap-4 lg:grid-cols-[220px_1fr_140px]">@csrf <select name="kind" class="h-11 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="items" @selected(request('kind', 'items') === 'items')>Articles</option><option value="services" @selected(request('kind') === 'services')>Services</option><option value="categories" @selected(request('kind') === 'categories')>Catégories</option><option value="brands" @selected(request('kind') === 'brands')>Marques / éditeurs</option><option value="variants" @selected(request('kind') === 'variants')>Variantes</option></select><input name="catalog_file" required type="file" accept=".csv,.tsv,.xlsx" class="rounded-lg border border-dashed border-slate-300 p-2 text-sm dark:border-white/10"><button class="rounded-lg bg-brand px-4 text-sm font-semibold text-white">Importer</button></form>
                <div class="mt-5 grid gap-3 md:grid-cols-3"><div class="rounded-lg bg-slate-50 p-4 text-sm dark:bg-white/5"><strong>Détection</strong><p class="mt-1 text-slate-500">Colonnes legacy FR/EN et accents reconnues.</p></div><div class="rounded-lg bg-slate-50 p-4 text-sm dark:bg-white/5"><strong>Mise à jour</strong><p class="mt-1 text-slate-500">Articles par barcode/ISBN, référentiels par nom.</p></div><div class="rounded-lg bg-slate-50 p-4 text-sm dark:bg-white/5"><strong>Rapport</strong><p class="mt-1 text-slate-500">Créés, mis à jour, ignorés après import.</p></div></div>
            </article>
            <aside class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><h2 class="text-base font-semibold">Préparation</h2><ul class="mt-4 space-y-2 text-sm text-slate-600 dark:text-slate-300"><li>1. Choisir le type qui correspond au fichier.</li><li>2. XLSX, CSV et TSV sont acceptés.</li><li>3. Les catégories d’articles suppriment automatiquement le suffixe [ITEM].</li><li>4. Les doublons mettent à jour au lieu de planter.</li></ul></aside>
        </section>
    @endif

    @if (in_array($panel, ['categories', 'marques'], true))
        <section class="mt-6 grid gap-6 xl:grid-cols-2">
            <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><h2 class="text-base font-semibold">Catégories</h2><form action="{{ route('catalog.categories.store') }}" method="POST" class="mt-5 grid gap-4 sm:grid-cols-2">@csrf <input name="name" required class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Nom"><select name="parent_id" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="">Parent</option>@foreach ($categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select><input name="icon" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Icône"><input name="color" type="color" value="#4F46E5" class="h-10 rounded-lg border border-slate-200 px-2 dark:border-white/10 dark:bg-slate-900"><input name="loan_duration_days" value="14" type="hidden"><input name="daily_fine_amount" value="2" type="hidden"><button class="h-10 rounded-lg bg-brand px-4 text-sm font-semibold text-white sm:col-span-2">Créer</button></form><div class="mt-5 grid gap-2">@foreach ($categories as $category)<div class="rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-white/10"><strong>{{ $category->name }}</strong><span class="ms-2 text-slate-500">{{ $category->items_count }} articles</span></div>@endforeach</div></article>
            <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><h2 class="text-base font-semibold">Marques / éditeurs</h2><form action="{{ route('catalog.brands.store') }}" method="POST" class="mt-5 grid gap-4 sm:grid-cols-2">@csrf <input name="name" required class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Nom"><select name="type" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="publisher">Éditeur</option><option value="brand">Marque</option></select><input name="phone" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Téléphone"><input name="email" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Email"><button class="h-10 rounded-lg bg-brand px-4 text-sm font-semibold text-white sm:col-span-2">Ajouter</button></form><div class="mt-5 grid gap-2">@foreach ($brands as $brand)<div class="rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-white/10"><strong>{{ $brand->name }}</strong><span class="ms-2 text-slate-500">{{ $brand->type }}</span></div>@endforeach</div></article>
        </section>
    @endif

    @if ($panel === 'variantes')
        <section class="mt-6">
            <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><h2 class="text-base font-semibold">Gestion des variantes</h2><form action="{{ route('catalog.variants.store') }}" method="POST" class="mt-5 grid gap-4 lg:grid-cols-8">@csrf <select name="item_id" required data-searchable-select data-placeholder="Rechercher un article..." class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900 lg:col-span-2">@foreach ($variantItems as $item)<option value="{{ $item->id }}">{{ $item->title }}</option>@endforeach</select><input name="name" required class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Nom"><input name="format" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Format"><input name="size" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Taille"><input name="color" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Couleur"><input name="sale_price" required type="number" step="0.01" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Prix"><button class="h-10 rounded-lg bg-brand px-4 text-sm font-semibold text-white">Ajouter</button><input name="barcode" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900 lg:col-span-2" placeholder="Code-barres"><input name="purchase_price" required type="number" step="0.01" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Coût"><input name="stock_quantity" required type="number" value="0" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Stock"></form></article>
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
</x-layouts.app>
