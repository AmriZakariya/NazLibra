@php
    $money = fn ($amount) => number_format((float) $amount, 2, ',', ' ').' DH';
    $maxTrend = max(1, (float) $salesTrend->max('total'));
@endphp

<x-layouts.app :tenant="$tenant" :active="$active" title="LibrairePro · Tableau de bord">
    <section class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <p class="text-sm font-medium text-indigo-600 dark:text-indigo-300">Tableau de bord opérationnel</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-normal text-slate-950 dark:text-white">Bonjour, voici l'activité de {{ $tenant->name }}</h1>
            <p class="mt-2 max-w-3xl text-sm text-slate-600 dark:text-slate-300">Vue compacte pour suivre ventes, stock, emprunts, équipes et préparation de la rentrée scolaire.</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('catalog') }}" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 dark:border-white/10 dark:bg-white/5 dark:text-slate-200">Importer CSV</a>
            <a href="{{ route('pos') }}" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm">Ouvrir la caisse</a>
        </div>
    </section>

    <section class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        @foreach ($stats as $stat)
            <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                <div class="flex items-start justify-between gap-3">
                    <p class="text-sm font-medium text-slate-500 dark:text-slate-400">{{ $stat['label'] }}</p>
                    <x-status-pill :tone="$stat['tone']">{{ $stat['delta'] }}</x-status-pill>
                </div>
                <p class="mt-4 text-3xl font-semibold tracking-normal text-slate-950 dark:text-white">{{ $stat['value'] }}</p>
                <div class="mt-4 h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-white/10">
                    <div class="h-full rounded-full bg-indigo-600" style="width: {{ 58 + ($loop->index * 9) }}%"></div>
                </div>
            </article>
        @endforeach
    </section>

    <section class="mt-6 grid gap-6 xl:grid-cols-[1.45fr_.85fr]">
        <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold">Revenus des 7 derniers jours</h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Comparaison rapide pour anticiper les heures fortes.</p>
                </div>
                <x-status-pill tone="info">MAD</x-status-pill>
            </div>
            <div class="mt-6 flex h-64 items-end gap-3">
                @forelse ($salesTrend as $point)
                    <div class="flex min-w-0 flex-1 flex-col items-center gap-2">
                        <div class="w-full rounded-t-lg bg-indigo-600/90 transition hover:bg-indigo-500" style="height: {{ max(18, ((float) $point->total / $maxTrend) * 220) }}px"></div>
                        <span class="text-xs text-slate-500 dark:text-slate-400">{{ \Illuminate\Support\Carbon::parse($point->day)->format('d/m') }}</span>
                    </div>
                @empty
                    <div class="grid h-full w-full place-items-center rounded-lg border border-dashed border-slate-200 text-sm text-slate-500 dark:border-white/10">Aucune vente enregistrée.</div>
                @endforelse
            </div>
        </article>

        <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
            <div class="flex items-center justify-between">
                <h2 class="text-base font-semibold">Alertes stock</h2>
                <a href="{{ route('catalog', ['status' => 'active']) }}" class="text-sm font-semibold text-indigo-600 dark:text-indigo-300">Voir tout</a>
            </div>
            <div class="mt-4 space-y-3">
                @forelse ($lowStockItems as $item)
                    <div class="flex items-center justify-between gap-3 rounded-lg border border-slate-200 p-3 dark:border-white/10">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold">{{ $item->title }}</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">{{ $item->category?->name }} · seuil {{ $item->min_stock_threshold }}</p>
                        </div>
                        <x-status-pill tone="warning">{{ $item->stock_quantity }} restants</x-status-pill>
                    </div>
                @empty
                    <div class="rounded-lg border border-dashed border-slate-200 p-6 text-center text-sm text-slate-500 dark:border-white/10">Aucune alerte stock.</div>
                @endforelse
            </div>
        </article>
    </section>

    <section class="mt-6 grid gap-6 xl:grid-cols-3">
        <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03] xl:col-span-2">
            <h2 class="text-base font-semibold">Transactions récentes</h2>
            <div class="mt-4 overflow-hidden rounded-lg border border-slate-200 dark:border-white/10">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-white/5 dark:text-slate-400">
                        <tr>
                            <th class="px-4 py-3">Vente</th>
                            <th class="px-4 py-3">Client</th>
                            <th class="px-4 py-3">Paiement</th>
                            <th class="px-4 py-3 text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-white/10">
                        @foreach ($recentSales as $sale)
                            <tr>
                                <td class="px-4 py-3 font-medium">{{ $sale->number }}</td>
                                <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $sale->contact?->name ?? 'Client comptoir' }}</td>
                                <td class="px-4 py-3"><x-status-pill tone="neutral">{{ $sale->payment_method }}</x-status-pill></td>
                                <td class="px-4 py-3 text-right font-semibold">{{ $money($sale->total_amount) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </article>

        <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
            <h2 class="text-base font-semibold">Emprunts à suivre</h2>
            <div class="mt-4 space-y-3">
                @foreach ($activeLoans as $loan)
                    <div class="rounded-lg border border-slate-200 p-3 dark:border-white/10">
                        <div class="flex items-center justify-between gap-2">
                            <p class="truncate text-sm font-semibold">{{ $loan->item->title }}</p>
                            <x-status-pill :tone="$loan->status === 'overdue' ? 'danger' : 'info'">{{ $loan->status }}</x-status-pill>
                        </div>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $loan->member->name }} · retour {{ $loan->due_at->format('d/m/Y') }}</p>
                    </div>
                @endforeach
            </div>
        </article>
    </section>
</x-layouts.app>
