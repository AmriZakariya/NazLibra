@php
    $tr = $tr ?? fn (string $text): string => \App\Support\Locale::t($text, $locale ?? null);
    $statusLabels = [
        'active' => 'Actif',
        'inactive' => 'Inactif',
        'out_of_stock' => 'Rupture de stock',
    ];
    $statusTones = [
        'active' => 'success',
        'inactive' => 'neutral',
        'out_of_stock' => 'danger',
    ];
    $formatIcons = [
        'paperback' => 'Livre broché',
        'hardcover' => 'Relié',
        'ebook' => 'E-book',
        'audiobook' => 'Livre audio',
    ];
@endphp

<x-layouts.app :tenant="$tenant" :active="$active" title="LibrairePro · {{ $variant->name }}">
    <header class="mb-8 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
        <div class="flex flex-col gap-6 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-3 mb-2">
                    <x-status-pill :tone="$statusTones[$variant->status] ?? 'neutral'">{{ $statusLabels[$variant->status] ?? $variant->status }}</x-status-pill>
                    @if ($variant->is_active)
                        <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300">
                            <svg class="size-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
                            {{ $tr('Actif en POS') }}
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-400">
                            <svg class="size-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M18 6 6 18"/><path d="M6 6 18 18"/></svg>
                            {{ $tr('Inactif en POS') }}
                        </span>
                    @endif
                </div>
                <h1 class="text-[1.75rem] font-bold tracking-tight text-slate-950 dark:text-white">{{ $variant->name }}</h1>
                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                    {{ $tr('Variante de') }} <a href="{{ route('catalog', ['panel' => 'articles', 'q' => $variant->item?->title]) }}" class="text-brand hover:underline">{{ $variant->item?->title ?? '—' }}</a>
                    @if ($variant->item?->category)
                        · {{ $variant->item->category->name }}
                    @endif
                </p>
            </div>
            <div class="flex gap-3">
                <a href="{{ route('variants.index') }}" class="inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:border-brand/40 hover:text-brand dark:border-white/10 dark:bg-transparent dark:text-slate-200">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
                    {{ $tr('Retour') }}
                </a>
                <a href="{{ route('variants.edit', $variant) }}" class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-brand px-4 text-sm font-semibold text-white shadow-sm transition hover:brightness-110">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                    {{ $tr('Modifier') }}
                </a>
            </div>
        </div>
    </header>

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- Main Info --}}
        <div class="space-y-6 lg:col-span-2">
            {{-- Identifiants --}}
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-200">{{ $tr('Identifiants') }}</h2>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    @if ($variant->barcode)
                        <div class="rounded-xl border border-slate-100 bg-slate-50 p-4 dark:border-white/5 dark:bg-slate-900">
                            <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $tr('Code-barres') }}</p>
                            <p class="mt-1 font-mono text-sm text-slate-800 dark:text-slate-200">{{ $variant->barcode }}</p>
                        </div>
                    @endif
                    @if ($variant->sku)
                        <div class="rounded-xl border border-slate-100 bg-slate-50 p-4 dark:border-white/5 dark:bg-slate-900">
                            <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $tr('SKU') }}</p>
                            <p class="mt-1 font-mono text-sm text-slate-800 dark:text-slate-200">{{ $variant->sku }}</p>
                        </div>
                    @endif
                    @if ($variant->isbn)
                        <div class="rounded-xl border border-slate-100 bg-slate-50 p-4 dark:border-white/5 dark:bg-slate-900">
                            <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $tr('ISBN') }}</p>
                            <p class="mt-1 font-mono text-sm text-slate-800 dark:text-slate-200">{{ $variant->isbn }}</p>
                        </div>
                    @endif
                    <div class="rounded-xl border border-slate-100 bg-slate-50 p-4 dark:border-white/5 dark:bg-slate-900">
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $tr('ID Variante') }}</p>
                        <p class="mt-1 font-mono text-sm text-slate-800 dark:text-slate-200">#{{ $variant->id }}</p>
                    </div>
                </div>
            </div>

            {{-- Détails produit --}}
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-200">{{ $tr('Détails produit') }}</h2>
                <div class="mt-4 grid gap-4 sm:grid-cols-3">
                    @if ($variant->format)
                        <div class="rounded-xl border border-slate-100 bg-slate-50 p-4 dark:border-white/5 dark:bg-slate-900">
                            <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $tr('Format') }}</p>
                            <p class="mt-1 text-sm font-semibold text-slate-800 dark:text-slate-200">{{ $formatIcons[$variant->format] ?? $variant->format }}</p>
                        </div>
                    @endif
                    @if ($variant->language)
                        <div class="rounded-xl border border-slate-100 bg-slate-50 p-4 dark:border-white/5 dark:bg-slate-900">
                            <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $tr('Langue') }}</p>
                            <p class="mt-1 text-sm font-semibold text-slate-800 dark:text-slate-200">{{ $variant->language }}</p>
                        </div>
                    @endif
                    @if ($variant->edition)
                        <div class="rounded-xl border border-slate-100 bg-slate-50 p-4 dark:border-white/5 dark:bg-slate-900">
                            <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $tr('Édition') }}</p>
                            <p class="mt-1 text-sm font-semibold text-slate-800 dark:text-slate-200">{{ $variant->edition }}</p>
                        </div>
                    @endif
                    @if ($variant->publisher)
                        <div class="rounded-xl border border-slate-100 bg-slate-50 p-4 dark:border-white/5 dark:bg-slate-900">
                            <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $tr('Éditeur') }}</p>
                            <p class="mt-1 text-sm font-semibold text-slate-800 dark:text-slate-200">{{ $variant->publisher }}</p>
                        </div>
                    @endif
                    @if ($variant->author)
                        <div class="rounded-xl border border-slate-100 bg-slate-50 p-4 dark:border-white/5 dark:bg-slate-900">
                            <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $tr('Auteur') }}</p>
                            <p class="mt-1 text-sm font-semibold text-slate-800 dark:text-slate-200">{{ $variant->author }}</p>
                        </div>
                    @endif
                    @if ($variant->sort_order > 0)
                        <div class="rounded-xl border border-slate-100 bg-slate-50 p-4 dark:border-white/5 dark:bg-slate-900">
                            <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $tr('Ordre d\'affichage') }}</p>
                            <p class="mt-1 text-sm font-semibold text-slate-800 dark:text-slate-200">{{ $variant->sort_order }}</p>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Prix --}}
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-200">{{ $tr('Prix et marge') }}</h2>
                <div class="mt-4 grid gap-4 sm:grid-cols-3">
                    <div class="rounded-xl border border-emerald-100 bg-emerald-50/50 p-4 dark:border-emerald-500/20 dark:bg-emerald-500/10">
                        <p class="text-xs font-semibold uppercase tracking-wider text-emerald-600">{{ $tr('Prix de vente') }}</p>
                        <p class="mt-1 text-2xl font-bold text-emerald-700 dark:text-emerald-300">{{ number_format($variant->sale_price, 2, ',', ' ') }} <span class="text-sm font-normal">DH</span></p>
                    </div>
                    <div class="rounded-xl border border-slate-100 bg-slate-50 p-4 dark:border-white/5 dark:bg-slate-900">
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $tr('Prix d\'achat') }}</p>
                        <p class="mt-1 text-2xl font-bold text-slate-800 dark:text-slate-200">{{ number_format($variant->purchase_price, 2, ',', ' ') }} <span class="text-sm font-normal">DH</span></p>
                    </div>
                    @if ($variant->margin_percent > 0)
                        <div class="rounded-xl border border-indigo-100 bg-indigo-50/50 p-4 dark:border-indigo-500/20 dark:bg-indigo-500/10">
                            <p class="text-xs font-semibold uppercase tracking-wider text-indigo-600">{{ $tr('Marge') }}</p>
                            <p class="mt-1 text-2xl font-bold text-indigo-700 dark:text-indigo-300">{{ $variant->margin_percent }}%</p>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Stock --}}
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-200">{{ $tr('Stock') }}</h2>
                <div class="mt-4 grid gap-4 sm:grid-cols-3">
                    <div class="rounded-xl border border-slate-100 bg-slate-50 p-4 dark:border-white/5 dark:bg-slate-900">
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $tr('Quantité en stock') }}</p>
                        <p class="mt-1 text-2xl font-bold {{ $variant->is_out_of_stock ? 'text-rose-600' : ($variant->is_low_stock ? 'text-amber-600' : 'text-slate-800 dark:text-slate-200') }}">{{ $variant->stock_quantity }}</p>
                    </div>
                    <div class="rounded-xl border border-slate-100 bg-slate-50 p-4 dark:border-white/5 dark:bg-slate-900">
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $tr('Seuil d\'alerte') }}</p>
                        <p class="mt-1 text-2xl font-bold text-slate-800 dark:text-slate-200">{{ $variant->min_stock_threshold }}</p>
                    </div>
                    @if ($variant->is_low_stock || $variant->is_out_of_stock)
                        <div class="rounded-xl border border-amber-100 bg-amber-50/50 p-4 dark:border-amber-500/20 dark:bg-amber-500/10">
                            <p class="text-xs font-semibold uppercase tracking-wider text-amber-600">{{ $tr('Alerte') }}</p>
                            <p class="mt-1 text-sm font-semibold text-amber-700 dark:text-amber-300">
                                @if ($variant->is_out_of_stock)
                                    {{ $tr('Rupture de stock') }}
                                @else
                                    {{ $tr('Stock faible') }}
                                @endif
                            </p>
                        </div>
                    @endif
                </div>
            </div>

            @if ($variant->notes)
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-200">{{ $tr('Notes') }}</h2>
                    <p class="mt-2 text-sm text-slate-600 dark:text-slate-400 whitespace-pre-wrap">{{ $variant->notes }}</p>
                </div>
            @endif
        </div>

        {{-- Sidebar --}}
        <aside class="space-y-6">
            @if ($variant->image)
                <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <img src="{{ asset('storage/' . $variant->image) }}" class="w-full rounded-xl object-cover" alt="{{ $variant->name }}">
                </div>
            @endif

            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-200">{{ $tr('Article parent') }}</h2>
                <div class="mt-3 space-y-3">
                    <div class="flex items-center gap-3">
                        @if ($variant->item?->image)
                            <img src="{{ asset('storage/' . $variant->item->image) }}" class="size-12 rounded-lg object-cover" alt="">
                        @else
                            <span class="grid size-12 place-items-center rounded-lg bg-brand/10 text-sm font-bold text-brand">{{ Str::upper(Str::substr($variant->item?->title ?? '?', 0, 2)) }}</span>
                        @endif
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-slate-900 dark:text-white">{{ $variant->item?->title ?? '—' }}</p>
                            <p class="text-xs text-slate-400">{{ $variant->item?->item_code ?? '—' }}</p>
                        </div>
                    </div>
                    <a href="{{ route('catalog', ['panel' => 'articles', 'q' => $variant->item?->title]) }}" class="block w-full rounded-xl border border-slate-200 px-4 py-2 text-center text-sm font-semibold text-slate-700 transition hover:border-brand hover:text-brand dark:border-white/10 dark:text-slate-200">
                        {{ $tr('Voir l\'article parent') }}
                    </a>
                </div>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-200">{{ $tr('Actions rapides') }}</h2>
                <div class="mt-3 space-y-2">
                    <form action="{{ route('variants.toggle', $variant) }}" method="POST">
                        @csrf
                        <button type="submit" class="w-full rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-brand hover:text-brand dark:border-white/10 dark:text-slate-200">
                            @if ($variant->is_active)
                                {{ $tr('Désactiver') }}
                            @else
                                {{ $tr('Activer') }}
                            @endif
                        </button>
                    </form>
                    <form action="{{ route('variants.duplicate', $variant) }}" method="POST">
                        @csrf
                        <button type="submit" class="w-full rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-brand hover:text-brand dark:border-white/10 dark:text-slate-200">
                            {{ $tr('Dupliquer') }}
                        </button>
                    </form>
                    <form action="{{ route('variants.destroy', $variant) }}" method="POST" onsubmit="return confirm('{{ $tr('Désactiver cette variante ? Elle ne sera plus visible en POS mais les historiques de ventes seront conservés.') }}')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="w-full rounded-xl border border-rose-200 px-4 py-2 text-sm font-semibold text-rose-600 transition hover:bg-rose-50 dark:border-rose-500/30 dark:text-rose-400 dark:hover:bg-rose-500/10">
                            {{ $tr('Désactiver') }}
                        </button>
                    </form>
                </div>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-200">{{ $tr('Métadonnées') }}</h2>
                <div class="mt-3 space-y-2 text-xs text-slate-500 dark:text-slate-400">
                    <p>{{ $tr('Créé le') }} {{ $variant->created_at?->format('d/m/Y H:i') ?? '—' }}</p>
                    <p>{{ $tr('Modifié le') }} {{ $variant->updated_at?->format('d/m/Y H:i') ?? '—' }}</p>
                    @if ($variant->deleted_at)
                        <p class="text-rose-500">{{ $tr('Désactivé le') }} {{ $variant->deleted_at->format('d/m/Y H:i') }}</p>
                    @endif
                </div>
            </div>
        </aside>
    </div>
</x-layouts.app>
