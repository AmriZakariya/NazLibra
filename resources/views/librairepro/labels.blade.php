@php
    $money = fn ($amount) => number_format((float) $amount, 2, ',', ' ').' DH';
    $labelClass = [
        'small' => 'label-small',
        'medium' => 'label-medium',
        'large' => 'label-large',
    ][$template] ?? 'label-medium';
    $templates = [
        'small' => ['label' => 'Petit', 'hint' => 'Code-barres seul', 'size' => '38 x 20 mm'],
        'medium' => ['label' => 'Moyen', 'hint' => 'Nom + prix + code', 'size' => '50 x 30 mm'],
        'large' => ['label' => 'Grand', 'hint' => 'Détail complet', 'size' => '70 x 42 mm'],
    ];
    $totalLabels = $items->sum(fn ($item) => $quantities->get($item->id, $defaultCopies));
@endphp

<x-layouts.app :tenant="$tenant" :active="$active" title="LibrairePro · Étiquettes">
    <section class="no-print flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <p class="text-sm font-medium text-brand">Étiquettes & code-barres</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-normal">Atelier d'impression</h1>
            <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Sélectionnez les articles, ajustez les quantités, choisissez le format puis imprimez la planche.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <button onclick="window.print()" class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white dark:bg-white dark:text-slate-950">Imprimer {{ $totalLabels ?: '' }}</button>
            <a href="{{ route('catalog') }}" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold dark:border-white/10 dark:bg-white/5">Retour catalogue</a>
        </div>
    </section>

    <form method="GET" action="{{ route('catalog.labels') }}" class="label-workbench no-print mt-6 grid gap-5 xl:grid-cols-[420px_minmax(0,1fr)]">
        <aside class="space-y-4">
            <article class="label-selection-summary rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase text-slate-500">Sélection</p>
                        <p class="mt-1 text-2xl font-semibold"><span class="label-selected-count">{{ $selectedIds->count() }}</span> article(s)</p>
                    </div>
                    <div class="rounded-xl bg-brand/10 px-3 py-2 text-right text-brand">
                        <p class="text-xs font-semibold uppercase">Planche</p>
                        <p class="text-lg font-semibold"><span class="label-total-count">{{ $totalLabels }}</span></p>
                    </div>
                </div>
                <p class="mt-3 text-sm text-slate-500">Les articles sélectionnés restent affichés en haut même après une recherche.</p>
            </article>

            <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                <h2 class="font-semibold">Format</h2>
                <div class="mt-3 grid gap-2">
                    @foreach ($templates as $key => $option)
                        <label class="flex cursor-pointer items-center justify-between gap-3 rounded-lg border border-slate-200 p-3 transition hover:border-brand dark:border-white/10 {{ $template === $key ? 'bg-brand text-white' : 'bg-white dark:bg-white/5' }}">
                            <span>
                                <span class="block text-sm font-semibold">{{ $option['label'] }} · {{ $option['size'] }}</span>
                                <span class="block text-xs {{ $template === $key ? 'text-white/75' : 'text-slate-500' }}">{{ $option['hint'] }}</span>
                            </span>
                            <input name="template" value="{{ $key }}" type="radio" @checked($template === $key) class="accent-[var(--brand-primary)]">
                        </label>
                    @endforeach
                </div>
                <label class="mt-4 block">
                    <span class="text-xs font-semibold uppercase text-slate-500">Quantité par défaut</span>
                    <input name="copies" value="{{ $defaultCopies }}" min="1" max="100" type="number" class="mt-1 h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10">
                </label>
            </article>

            <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                <h2 class="font-semibold">Recherche</h2>
                <div class="mt-3 grid gap-2">
                    <input name="q" value="{{ $query }}" class="h-11 rounded-lg border border-slate-200 bg-slate-50 px-3 text-sm outline-none focus:border-brand focus:ring-4 focus:ring-brand/10 dark:border-white/10 dark:bg-white/5" placeholder="Titre, mots-clés, code-barres, ISBN...">
                    <select name="category" class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">
                        <option value="">Toutes les catégories</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected((string) $categoryFilter === (string) $category->id)>{{ $category->name }}</option>
                        @endforeach
                    </select>
                    <select name="type" class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">
                        <option value="all" @selected($type === 'all')>Tous les types</option>
                        <option value="book" @selected($type === 'book')>Livres</option>
                        <option value="supply" @selected($type === 'supply')>Papeterie</option>
                        <option value="service" @selected($type === 'service')>Services</option>
                    </select>
                    <div class="grid grid-cols-2 gap-2">
                        <button class="rounded-lg bg-brand px-4 py-2.5 text-sm font-semibold text-white">Actualiser</button>
                        <a href="{{ route('catalog.labels') }}" class="rounded-lg border border-slate-200 px-4 py-2.5 text-center text-sm font-semibold dark:border-white/10">Reset</a>
                    </div>
                </div>
            </article>
        </aside>

        <article class="rounded-xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
            <div class="flex flex-col gap-3 border-b border-slate-200 p-4 dark:border-white/10 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="font-semibold">Articles à imprimer</h2>
                    <p class="mt-1 text-sm text-slate-500">{{ $productOptions->count() }} résultat(s), {{ $selectedIds->count() }} sélectionné(s), {{ $totalLabels }} étiquette(s).</p>
                </div>
                <div class="flex gap-2">
                    <button class="label-select-all rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold dark:border-white/10" type="button">{{ $productOptions->count() > 0 && $productOptions->every(fn ($item) => $selectedIds->contains($item->id)) ? 'Tout désélectionner' : 'Tout sélectionner' }}</button>
                    <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Prévisualiser</button>
                </div>
            </div>

            <div class="max-h-[560px] overflow-y-auto">
                <table class="w-full min-w-[760px] text-left text-sm">
                    <thead class="sticky top-0 bg-slate-50 text-xs uppercase text-slate-500 dark:bg-slate-900">
                        <tr>
                            <th class="px-4 py-3">Sel.</th>
                            <th class="px-4 py-3">Article</th>
                            <th class="px-4 py-3">Code</th>
                            <th class="px-4 py-3">Catégorie</th>
                            <th class="px-4 py-3 text-right">Prix</th>
                            <th class="px-4 py-3 text-center">Qté</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-white/10">
                        @forelse ($productOptions as $item)
                            @php($checked = $selectedIds->contains($item->id))
                            <tr class="label-selection-row transition hover:bg-slate-50/80 dark:hover:bg-white/5" data-label-row>
                                <td class="px-4 py-3">
                                    <input name="selected_items[]" value="{{ $item->id }}" type="checkbox" @checked($checked) class="label-item-check size-5 rounded border-slate-300">
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-xs font-semibold text-slate-500 dark:bg-white/10">
                                            {{ Str::upper(Str::substr($item->type === 'service' ? 'SR' : 'AR', 0, 2)) }}
                                        </div>
                                        <div class="min-w-0">
                                            <p class="truncate font-semibold">{{ $item->title }}</p>
                                            <p class="mt-1 text-xs text-slate-500">{{ $item->brand?->name ?? 'Sans marque' }} · stock {{ $item->type === 'service' ? 'illimité' : $item->stock_quantity }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3 font-mono text-xs">{{ $item->barcode ?? $item->isbn ?? $item->sku ?? 'LP-'.$item->id }}</td>
                                <td class="px-4 py-3">{{ $item->category?->name ?? 'Sans catégorie' }}</td>
                                <td class="px-4 py-3 text-right font-semibold">{{ $money($item->sale_price) }}</td>
                                <td class="px-4 py-3 text-center">
                                    <input name="quantities[{{ $item->id }}]" value="{{ $quantities->get($item->id, $defaultCopies) }}" min="1" max="100" type="number" class="label-quantity h-10 w-20 rounded-lg border border-slate-200 px-2 text-center text-sm dark:border-white/10">
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-12 text-center text-sm text-slate-500">Aucun article trouvé. Essayez une autre recherche.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </article>
    </form>

    <section class="mt-6 rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03] print-area">
        <div class="no-print mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="font-semibold">Aperçu impression</h2>
                <p class="text-sm text-slate-500">{{ $templates[$template]['label'] }} · {{ $templates[$template]['size'] }} · {{ $totalLabels }} étiquette(s)</p>
            </div>
            <button onclick="window.print()" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Imprimer la planche</button>
        </div>

        @if ($items->isEmpty())
            <div class="no-print rounded-xl border border-dashed border-slate-300 p-10 text-center dark:border-white/10">
                <h3 class="font-semibold">Aucune étiquette sélectionnée</h3>
                <p class="mt-2 text-sm text-slate-500">Cochez les articles dans la liste, ajustez les quantités, puis cliquez sur Prévisualiser.</p>
            </div>
        @else
            <div class="label-sheet {{ $labelClass }}">
                @foreach ($items as $item)
                    @for ($copy = 0; $copy < $quantities->get($item->id, $defaultCopies); $copy++)
                        @php($code = $item->barcode ?? $item->isbn ?? $item->sku ?? 'LP-'.$item->id)
                        <article class="label-card">
                            <div class="label-title">{{ $item->title }}</div>
                            @if ($template !== 'small')
                                <div class="label-meta">{{ $item->category?->name ?? 'Catalogue' }} · {{ $item->location ?? 'Stock' }}</div>
                            @endif
                            <div class="label-barcode" aria-label="{{ $code }}"></div>
                            <div class="label-code">{{ $code }}</div>
                            @if ($template !== 'small')
                                <div class="label-price">{{ $money($item->sale_price) }}</div>
                            @endif
                            @if ($template === 'large')
                                <div class="label-meta">Réf. {{ $item->item_code ?? 'LP-'.$item->id }} · {{ $item->brand?->name ?? 'LibrairePro' }}</div>
                            @endif
                        </article>
                    @endfor
                @endforeach
            </div>
        @endif
    </section>
</x-layouts.app>
