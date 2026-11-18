@php
    $starterAvailable = \App\Services\Catalogue\StarterCatalogue::availableFor($tenant);
    $starterPreview = $starterAvailable ? app(\App\Services\Catalogue\StarterCatalogue::class)->preview() : null;
@endphp
@if ($starterAvailable)
    <article data-starter-catalogue class="rounded-xl border border-brand/30 bg-brand/5 p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase text-brand">Démarrage rapide</p>
                <h2 class="mt-1 text-base font-semibold">Catalogue type salon (Maroc)</h2>
                <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-600 dark:text-slate-300">Ajoutez en un clic les prestations courantes d’un salon, avec catégories, prix, durées, variantes, options et photos. Vous pourrez ensuite tout ajuster.</p>
            </div>
        </div>
        <details class="mt-4">
            <summary class="inline-flex h-11 cursor-pointer items-center rounded-lg bg-brand px-4 text-sm font-semibold text-white shadow-sm">Voir le catalogue type</summary>
            <div class="mt-4 rounded-xl border border-slate-200 bg-white p-4 dark:border-white/10 dark:bg-slate-950/40">
                <p class="text-sm font-semibold" data-starter-count>{{ $starterPreview['services'] }} prestations dans {{ count($starterPreview['categories']) }} catégories</p>
                <ul class="mt-3 flex flex-wrap gap-2 text-xs">
                    @foreach ($starterPreview['categories'] as $starterCategory)
                        <li class="rounded-full border border-slate-200 px-3 py-1 dark:border-white/10">{{ $starterCategory['name'] }} · {{ $starterCategory['count'] }}</li>
                    @endforeach
                </ul>
                <p class="mt-3 text-xs text-slate-500">Les prestations déjà présentes (même nom) sont mises à jour avec les prix d’origine, jamais dupliquées. Vos photos existantes sont conservées.</p>
                <form action="{{ route('catalog.starter.apply') }}" method="POST" class="mt-4">
                    @csrf
                    <button class="h-11 rounded-lg bg-brand px-4 text-sm font-semibold text-white shadow-sm">Confirmer l’ajout du catalogue type</button>
                </form>
            </div>
        </details>
    </article>
@endif
