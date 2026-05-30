@php
    $money = fn ($amount) => number_format((float) $amount, 2, ',', ' ').' DH';
    $maxTrend = max(1, (float) $salesTrend->max('total'));
    $maxPayment = max(1, (float) $paymentBreakdown->max('total'));
    $maxTopItem = max(1, (float) $topItems->max('quantity'));
    $operationCards = [
        ['label' => 'Tickets en attente', 'value' => $operations['pending_tickets'], 'href' => route('pos'), 'hint' => 'Reprendre une caisse'],
        ['label' => 'Livraisons à suivre', 'value' => $operations['pending_deliveries'], 'href' => route('module', ['module' => 'sales', 'section' => 'delivery']), 'hint' => 'Préparation / dispatch'],
        ['label' => 'Devis ouverts', 'value' => $operations['open_quotes'], 'href' => route('module', ['module' => 'sales', 'section' => 'quotes']), 'hint' => 'À relancer'],
        ['label' => 'Achats ouverts', 'value' => $operations['draft_purchases'], 'href' => route('module', ['module' => 'purchases', 'section' => 'list']), 'hint' => 'Commandes fournisseur'],
    ];
@endphp

<x-layouts.app :tenant="$tenant" :active="$active" title="LibrairePro · Tableau de bord">
    <section class="dashboard-hero">
        <div class="min-w-0">
            <p class="text-sm font-semibold text-brand">Tableau de bord opérationnel</p>
            <h1 class="mt-2 text-2xl font-semibold tracking-normal text-slate-950 dark:text-white">Bonjour, {{ $tenant->name }}</h1>
            <p class="mt-2 max-w-3xl text-sm text-slate-600 dark:text-slate-300">Une vue rapide pour piloter ventes, stock, caisse, clients et préparation rentrée sans perdre le fil.</p>
        </div>
        <div class="dashboard-hero-actions">
            <a href="{{ route('catalog', ['panel' => 'ajouter']) }}" class="dashboard-secondary-action">Nouvel article</a>
            <a href="{{ route('catalog', ['panel' => 'stock-adjustment-add']) }}" class="dashboard-secondary-action">Ajuster stock</a>
            <a href="{{ route('pos') }}" class="dashboard-primary-action">Ouvrir la caisse</a>
        </div>
    </section>

    <section class="mt-6 grid gap-4 md:grid-cols-2 2xl:grid-cols-4">
        @foreach ($stats as $stat)
            <article class="dashboard-kpi">
                <div class="flex items-start justify-between gap-3">
                    <p class="text-sm font-semibold text-slate-500 dark:text-slate-400">{{ $stat['label'] }}</p>
                    <x-status-pill :tone="$stat['tone']">{{ $stat['delta'] }}</x-status-pill>
                </div>
                <p class="mt-4 text-3xl font-semibold tracking-normal text-slate-950 dark:text-white">{{ $stat['value'] }}</p>
                <div class="mt-5 h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-white/10">
                    <div class="h-full rounded-full bg-brand" style="width: {{ min(96, 54 + ($loop->index * 11)) }}%"></div>
                </div>
            </article>
        @endforeach
    </section>

    <section class="mt-6 grid gap-6 2xl:grid-cols-[minmax(0,1.55fr)_minmax(340px,.75fr)]">
        <article class="dashboard-panel">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 class="text-base font-semibold">Revenus des 7 derniers jours</h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Les jours sans vente restent visibles pour éviter les faux pics.</p>
                </div>
                <x-status-pill tone="info">MAD</x-status-pill>
            </div>

            <div class="mt-6 grid min-h-72 gap-4 lg:grid-cols-[minmax(0,1fr)_260px]">
                <div class="flex min-h-64 items-end gap-2 rounded-xl bg-slate-50 p-4 dark:bg-white/5">
                    @foreach ($salesTrend as $point)
                        <div class="group flex min-w-0 flex-1 flex-col items-center gap-2">
                            <div class="relative flex w-full items-end justify-center">
                                <span class="absolute -top-8 hidden rounded-lg bg-slate-950 px-2 py-1 text-xs font-semibold text-white shadow-sm group-hover:block">{{ $money($point->total) }}</span>
                                <div class="w-full rounded-t-lg bg-brand/90 transition group-hover:bg-brand" style="height: {{ max(16, ((float) $point->total / $maxTrend) * 210) }}px"></div>
                            </div>
                            <span class="text-xs font-medium text-slate-500 dark:text-slate-400">{{ \Illuminate\Support\Carbon::parse($point->day)->format('d/m') }}</span>
                        </div>
                    @endforeach
                </div>

                <div class="grid content-start gap-3">
                    <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-white/10 dark:bg-slate-950/40">
                        <p class="text-xs font-semibold uppercase text-slate-500">Santé stock</p>
                        <div class="mt-3 flex items-end justify-between gap-3">
                            <strong class="text-3xl font-semibold">{{ $stockSummary['health'] }}%</strong>
                            <span class="text-sm text-slate-500">{{ $money($stockSummary['value']) }}</span>
                        </div>
                        <div class="mt-4 h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-white/10">
                            <div class="h-full rounded-full bg-brand" style="width: {{ $stockSummary['health'] }}%"></div>
                        </div>
                        <p class="mt-3 text-xs text-slate-500">{{ $stockSummary['low'] }} alerte(s), {{ $stockSummary['out'] }} rupture(s)</p>
                    </div>

                    <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-white/10 dark:bg-slate-950/40">
                        <div class="flex items-center justify-between">
                            <p class="text-xs font-semibold uppercase text-slate-500">Paiements du jour</p>
                            <a href="{{ route('module', ['module' => 'sales', 'section' => 'payments']) }}" class="text-xs font-semibold text-brand">Voir</a>
                        </div>
                        <div class="mt-4 space-y-3">
                            @forelse ($paymentBreakdown as $payment)
                                <div>
                                    <div class="flex justify-between gap-3 text-sm">
                                        <span class="font-semibold">{{ ucfirst($payment->method) }}</span>
                                        <span class="text-slate-500">{{ $money($payment->total) }}</span>
                                    </div>
                                    <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-white/10">
                                        <div class="h-full rounded-full bg-brand" style="width: {{ max(8, ((float) $payment->total / $maxPayment) * 100) }}%"></div>
                                    </div>
                                </div>
                            @empty
                                <p class="rounded-lg border border-dashed border-slate-200 p-4 text-center text-sm text-slate-500 dark:border-white/10">Aucun paiement aujourd'hui.</p>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>
        </article>

        <aside class="dashboard-panel">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold">Centre d'action</h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Les éléments à traiter maintenant.</p>
                </div>
                <span class="grid size-10 place-items-center rounded-xl bg-brand/10 text-brand">⌁</span>
            </div>

            <div class="mt-5 grid gap-3">
                @foreach ($operationCards as $card)
                    <a href="{{ $card['href'] }}" class="dashboard-action-row">
                        <span>
                            <strong>{{ $card['label'] }}</strong>
                            <small>{{ $card['hint'] }}</small>
                        </span>
                        <em>{{ $card['value'] }}</em>
                    </a>
                @endforeach
            </div>

            <div class="mt-5 rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-white/10 dark:bg-white/5">
                <p class="text-xs font-semibold uppercase text-slate-500">Raccourcis</p>
                <div class="mt-3 grid grid-cols-2 gap-2">
                    <a href="{{ route('catalog.labels') }}" class="dashboard-mini-link">Étiquettes</a>
                    <a href="{{ route('catalog', ['panel' => 'import']) }}" class="dashboard-mini-link">Import</a>
                    <a href="{{ route('module', ['module' => 'contacts', 'section' => 'customer-add']) }}" class="dashboard-mini-link">Client</a>
                    <a href="{{ route('module', ['module' => 'purchases', 'section' => 'add']) }}" class="dashboard-mini-link">Achat</a>
                </div>
            </div>
        </aside>
    </section>

    <section class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1.2fr)_minmax(360px,.8fr)]">
        <article class="dashboard-panel">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-base font-semibold">Transactions récentes</h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Derniers tickets validés avec client, mode et montant.</p>
                </div>
                <a href="{{ route('module', ['module' => 'sales', 'section' => 'list']) }}" class="text-sm font-semibold text-brand">Liste des ventes</a>
            </div>

            <div class="mt-4 overflow-hidden rounded-xl border border-slate-200 dark:border-white/10">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[720px] text-left text-sm">
                        <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-white/5">
                            <tr>
                                <th class="px-4 py-3">Ticket</th>
                                <th class="px-4 py-3">Client</th>
                                <th class="px-4 py-3">Articles</th>
                                <th class="px-4 py-3">Paiement</th>
                                <th class="px-4 py-3 text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-white/10">
                            @forelse ($recentSales as $sale)
                                <tr class="transition hover:bg-slate-50 dark:hover:bg-white/5">
                                    <td class="px-4 py-3 font-semibold">{{ $sale->number }}</td>
                                    <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $sale->contact?->name ?? 'Client comptoir' }}</td>
                                    <td class="px-4 py-3">{{ $sale->items_count }}</td>
                                    <td class="px-4 py-3"><x-status-pill tone="neutral">{{ $sale->payment_method }}</x-status-pill></td>
                                    <td class="px-4 py-3 text-right font-semibold">{{ $money($sale->total_amount) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-4 py-12 text-center text-sm text-slate-500">Aucune vente récente.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </article>

        <article class="dashboard-panel">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold">Alertes stock</h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Priorité aux ruptures et seuils bas.</p>
                </div>
                <a href="{{ route('catalog', ['stock' => 'low']) }}" class="text-sm font-semibold text-brand">Voir tout</a>
            </div>
            <div class="mt-4 space-y-3">
                @forelse ($lowStockItems as $item)
                    <a href="{{ route('catalog', ['panel' => 'articles', 'edit' => $item->id]) }}" class="dashboard-stock-row">
                        <span>
                            <strong>{{ $item->title }}</strong>
                            <small>{{ $item->category?->name ?? 'Sans catégorie' }} · seuil {{ $item->min_stock_threshold }}</small>
                        </span>
                        <x-status-pill tone="warning">{{ $item->stock_quantity }} restants</x-status-pill>
                    </a>
                @empty
                    <div class="rounded-xl border border-dashed border-slate-200 p-8 text-center text-sm text-slate-500 dark:border-white/10">Aucune alerte stock.</div>
                @endforelse
            </div>
        </article>
    </section>

    <section class="mt-6 grid gap-6 xl:grid-cols-3">
        <article class="dashboard-panel">
            <h2 class="text-base font-semibold">Meilleures ventes du mois</h2>
            <div class="mt-4 space-y-4">
                @forelse ($topItems as $item)
                    <div>
                        <div class="flex justify-between gap-3 text-sm">
                            <span class="min-w-0 truncate font-semibold">{{ $item->name }}</span>
                            <span class="shrink-0 text-slate-500">{{ number_format((float) $item->quantity, 0, ',', ' ') }} u.</span>
                        </div>
                        <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-white/10">
                            <div class="h-full rounded-full bg-brand" style="width: {{ max(8, ((float) $item->quantity / $maxTopItem) * 100) }}%"></div>
                        </div>
                        <p class="mt-1 text-xs text-slate-500">{{ $money($item->revenue) }}</p>
                    </div>
                @empty
                    <p class="rounded-xl border border-dashed border-slate-200 p-8 text-center text-sm text-slate-500 dark:border-white/10">Aucune vente pour le mois.</p>
                @endforelse
            </div>
        </article>

        <article class="dashboard-panel">
            <h2 class="text-base font-semibold">Activité du jour</h2>
            <div class="mt-4 grid gap-3">
                @foreach ($recentActivity as $activity)
                    <a href="{{ $activity['href'] }}" class="dashboard-action-row">
                        <span>
                            <strong>{{ $activity['label'] }}</strong>
                            <small>Aujourd'hui</small>
                        </span>
                        <em>{{ $activity['value'] }}</em>
                    </a>
                @endforeach
            </div>
        </article>

        <article class="dashboard-panel">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-base font-semibold">Emprunts à suivre</h2>
                <a href="{{ route('module', ['module' => 'loans']) }}" class="text-sm font-semibold text-brand">Ouvrir</a>
            </div>
            <div class="mt-4 space-y-3">
                @forelse ($activeLoans as $loan)
                    <div class="rounded-xl border border-slate-200 p-3 dark:border-white/10">
                        <div class="flex items-center justify-between gap-2">
                            <p class="truncate text-sm font-semibold">{{ $loan->item->title }}</p>
                            <x-status-pill :tone="$loan->status === 'overdue' ? 'danger' : 'info'">{{ $loan->status }}</x-status-pill>
                        </div>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $loan->member->name }} · retour {{ $loan->due_at->format('d/m/Y') }}</p>
                    </div>
                @empty
                    <p class="rounded-xl border border-dashed border-slate-200 p-8 text-center text-sm text-slate-500 dark:border-white/10">Aucun emprunt actif.</p>
                @endforelse
            </div>
        </article>
    </section>
</x-layouts.app>
