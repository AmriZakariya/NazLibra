@php
    $tr = fn (string $text) => \App\Support\Locale::t($text);
    $money = fn ($amount) => number_format((float) $amount, 2, ',', ' ').' DH';
    $maxTrend = max(1, (float) $salesTrend->max('total'));
    $maxPayment = max(1, (float) $paymentBreakdown->max('total'));
    $maxTopItem = max(1, (float) $topItems->max('quantity'));
    $maxCategory = max(1, (float) $categoryBreakdown->max('revenue'));
    $maxExpense = max(1, (float) $expenseBreakdown->max('total'));
    $maxHourly = max(1, (float) $hourlyHeatmap->max('tickets'));
    $dateQuery = ['period' => 'custom', 'from' => $filters['from']->toDateString(), 'to' => $filters['to']->toDateString()];
    $operationCards = [
        ['label' => 'Tickets en attente', 'value' => $operations['pending_tickets'], 'href' => route('pos'), 'hint' => 'Reprendre une caisse', 'icon' => 'AT', 'tone' => 'warning'],
        ['label' => 'Livraisons à suivre', 'value' => $operations['pending_deliveries'], 'href' => route('module', ['module' => 'sales', 'section' => 'delivery']), 'hint' => 'Préparation / dispatch', 'icon' => 'BL', 'tone' => 'info'],
        ['label' => 'Devis ouverts', 'value' => $operations['open_quotes'], 'href' => route('module', ['module' => 'sales', 'section' => 'quotes']), 'hint' => 'À relancer', 'icon' => 'DV', 'tone' => 'primary'],
        ['label' => 'Achats ouverts', 'value' => $operations['draft_purchases'], 'href' => route('module', ['module' => 'purchases', 'section' => 'list']), 'hint' => 'Commandes fournisseur', 'icon' => 'PO', 'tone' => 'success'],
        ['label' => 'Tiroir caisse', 'value' => $operations['open_cash_register'] ? 'Ouvert' : 'Fermé', 'href' => route('module', 'cash-register'), 'hint' => $operations['open_cash_register'] ? 'Clôture à vérifier' : 'Ouvrir avant encaissement', 'icon' => 'TC', 'tone' => $operations['open_cash_register'] ? 'success' : 'danger'],
    ];
    $periodPresets = [
        'today' => 'Aujourd’hui',
        'yesterday' => 'Hier',
        'week' => '7 jours',
        'month' => 'Mois',
        'year' => 'Année',
    ];
    $trendPoints = $salesTrend->values();
    $chartWidth = 900;
    $chartHeight = 260;
    $chartPaddingX = 36;
    $chartPaddingTop = 18;
    $chartPaddingBottom = 42;
    $plotHeight = $chartHeight - $chartPaddingTop - $chartPaddingBottom;
    $plotWidth = $chartWidth - ($chartPaddingX * 2);
    $pointCount = max(1, $trendPoints->count());
    $step = $pointCount > 1 ? $plotWidth / ($pointCount - 1) : 0;
    $linePoints = $trendPoints->map(function ($point, $index) use ($maxTrend, $chartPaddingX, $chartPaddingTop, $plotHeight, $step, $chartWidth) {
        $x = $chartPaddingX + ($step * $index);
        if ($step === 0) {
            $x = $chartWidth / 2;
        }
        $y = $chartPaddingTop + ($plotHeight - (((float) $point->total / $maxTrend) * $plotHeight));

        return round($x, 2).','.round($y, 2);
    })->implode(' ');
@endphp

<x-layouts.app :tenant="$tenant" :active="$active" title="LibrairePro · Tableau de bord">
    <section class="dashboard-hero">
        <div class="min-w-0">
            <p class="text-sm font-semibold text-brand">{{ $tr('Tableau de bord opérationnel') }}</p>
            <h1 class="mt-2 text-2xl font-semibold tracking-normal text-slate-950 dark:text-white">{{ $tr('Bonjour') }}, {{ $tenant->name }}</h1>
            <p class="mt-2 max-w-3xl text-sm text-slate-600 dark:text-slate-300">{{ $tr('Une vue rapide pour piloter ventes, stock, caisse, clients et préparation rentrée sans perdre le fil.') }} {{ $tr('Période') }}: {{ $filters['from']->format('d/m/Y') }} - {{ $filters['to']->format('d/m/Y') }}.</p>
        </div>
        <div class="dashboard-hero-actions">
            <a href="{{ route('catalog', ['panel' => 'ajouter']) }}" class="dashboard-secondary-action">{{ $tr('Nouvel article') }}</a>
            <a href="{{ route('stock', ['panel' => 'stock-adjustment-add']) }}" class="dashboard-secondary-action">{{ $tr('Ajuster stock') }}</a>
            <a href="{{ route('pos') }}" class="dashboard-primary-action">{{ $tr('Ouvrir la caisse') }}</a>
        </div>
    </section>

    <section class="dashboard-filter-panel mt-5">
        <div class="dashboard-period-tabs">
            @foreach ($periodPresets as $key => $label)
                <a href="{{ route('dashboard', ['period' => $key]) }}" class="dashboard-period-tab {{ $filters['period'] === $key ? 'is-active' : '' }}">{{ $tr($label) }}</a>
            @endforeach
        </div>
        <form action="{{ route('dashboard') }}" method="GET" class="dashboard-date-form">
            <input type="hidden" name="period" value="custom">
            <label>
                <span>{{ $tr('Du') }}</span>
                <input type="date" name="from" value="{{ $filters['from']->toDateString() }}">
            </label>
            <label>
                <span>{{ $tr('Au') }}</span>
                <input type="date" name="to" value="{{ $filters['to']->toDateString() }}">
            </label>
            <button>{{ $tr('Filtrer') }}</button>
            <a href="{{ route('dashboard') }}">{{ $tr('Réinitialiser') }}</a>
        </form>
    </section>

    <section class="mt-6 grid gap-4 md:grid-cols-2 2xl:grid-cols-4">
        @foreach ($stats as $stat)
            <article class="dashboard-kpi">
                <div class="flex items-start justify-between gap-3">
                    <p class="text-sm font-semibold text-slate-500 dark:text-slate-400">{{ $tr($stat['label']) }}</p>
                    <x-status-pill :tone="$stat['tone']">{{ isset($stat['delta_value']) ? $stat['delta_value'].' '.$tr($stat['delta_label']) : $tr($stat['delta']) }}</x-status-pill>
                </div>
                <p class="mt-4 text-3xl font-semibold tracking-normal text-slate-950 dark:text-white">{{ $stat['value'] }}</p>
                <div class="mt-5 h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-white/10">
                    <div class="h-full rounded-full bg-brand" style="width: {{ min(96, 54 + ($loop->index * 11)) }}%"></div>
                </div>
            </article>
        @endforeach
    </section>

    <section class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
        @foreach ($reportCards as $report)
            <a href="{{ $report['href'] }}" class="dashboard-report-card is-{{ $report['tone'] }}">
                <span>
                    <small>{{ $tr($report['hint']) }}</small>
                    <strong>{{ $tr($report['label']) }}</strong>
                </span>
                <em>{{ $report['value'] }}</em>
            </a>
        @endforeach
    </section>

    <section class="mt-6 grid gap-6 2xl:grid-cols-[minmax(0,1.55fr)_minmax(340px,.75fr)]">
        <article class="dashboard-panel">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 class="text-base font-semibold">{{ $tr('Revenus par jour') }}</h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $tr('Affiche jusqu’à 31 jours et garde les journées sans vente visibles.') }}</p>
                </div>
                <x-status-pill tone="info">MAD</x-status-pill>
            </div>

            <div class="mt-6 grid min-h-72 gap-4 lg:grid-cols-[minmax(0,1fr)_260px]">
                <div class="dashboard-revenue-chart">
                    <svg viewBox="0 0 {{ $chartWidth }} {{ $chartHeight }}" role="img" aria-label="{{ $tr('Revenus par jour') }}" preserveAspectRatio="none">
                        <defs>
                            <linearGradient id="dashboardRevenueFill" x1="0" x2="0" y1="0" y2="1">
                                <stop offset="0%" stop-color="var(--brand-primary)" stop-opacity="0.20" />
                                <stop offset="100%" stop-color="var(--brand-primary)" stop-opacity="0.02" />
                            </linearGradient>
                        </defs>
                        @for ($grid = 0; $grid <= 4; $grid++)
                            @php
                                $gridY = $chartPaddingTop + (($plotHeight / 4) * $grid);
                            @endphp
                            <line x1="{{ $chartPaddingX }}" y1="{{ $gridY }}" x2="{{ $chartWidth - $chartPaddingX }}" y2="{{ $gridY }}" class="dashboard-chart-grid" />
                        @endfor
                        @foreach ($trendPoints as $pointIndex => $point)
                            @php
                                $x = $step === 0 ? $chartWidth / 2 : $chartPaddingX + ($step * $pointIndex);
                                $barHeight = max(5, ((float) $point->total / $maxTrend) * $plotHeight);
                                $barWidth = max(10, min(28, ($plotWidth / $pointCount) * 0.54));
                                $barY = $chartPaddingTop + ($plotHeight - $barHeight);
                            @endphp
                            <rect x="{{ $x - ($barWidth / 2) }}" y="{{ $barY }}" width="{{ $barWidth }}" height="{{ $barHeight }}" rx="6" class="dashboard-chart-bar">
                                <title>{{ \Illuminate\Support\Carbon::parse($point->day)->format('d/m/Y') }} · {{ $money($point->total) }}</title>
                            </rect>
                            @if ($pointIndex === 0 || $pointIndex === $trendPoints->count() - 1 || $trendPoints->count() <= 10 || $pointIndex % 5 === 0)
                                <text x="{{ $x }}" y="{{ $chartHeight - 16 }}" text-anchor="middle" class="dashboard-chart-label">{{ \Illuminate\Support\Carbon::parse($point->day)->format('d/m') }}</text>
                            @endif
                        @endforeach
                        @if ($linePoints !== '')
                            <polyline points="{{ $linePoints }}" class="dashboard-chart-line" />
                            @foreach ($trendPoints as $pointIndex => $point)
                                @php
                                    $x = $step === 0 ? $chartWidth / 2 : $chartPaddingX + ($step * $pointIndex);
                                    $y = $chartPaddingTop + ($plotHeight - (((float) $point->total / $maxTrend) * $plotHeight));
                                @endphp
                                <circle cx="{{ $x }}" cy="{{ $y }}" r="4.5" class="dashboard-chart-dot">
                                    <title>{{ \Illuminate\Support\Carbon::parse($point->day)->format('d/m/Y') }} · {{ $money($point->total) }}</title>
                                </circle>
                            @endforeach
                        @endif
                    </svg>
                    <div class="dashboard-chart-summary">
                        <span>{{ $tr('Total période') }} <strong>{{ $money($salesTrend->sum('total')) }}</strong></span>
                        <span>{{ $tr('Pic') }} <strong>{{ $money($salesTrend->max('total')) }}</strong></span>
                    </div>
                    @if ($salesTrend->sum('total') <= 0)
                        <div class="dashboard-chart-empty">{{ $tr('Aucune vente sur la période sélectionnée.') }}</div>
                    @endif
                </div>

                <div class="grid content-start gap-3">
                    <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-white/10 dark:bg-slate-950/40">
                        <p class="text-xs font-semibold uppercase text-slate-500">{{ $tr('Santé stock') }}</p>
                        <div class="mt-3 flex items-end justify-between gap-3">
                            <strong class="text-3xl font-semibold">{{ $stockSummary['health'] }}%</strong>
                            <span class="text-sm text-slate-500">{{ $money($stockSummary['value']) }}</span>
                        </div>
                        <div class="mt-4 h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-white/10">
                            <div class="h-full rounded-full bg-brand" style="width: {{ $stockSummary['health'] }}%"></div>
                        </div>
                        <p class="mt-3 text-xs text-slate-500">{{ $stockSummary['low'] }} {{ $tr('alerte(s)') }}, {{ $stockSummary['out'] }} {{ $tr('rupture(s)') }}</p>
                    </div>

                    <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-white/10 dark:bg-slate-950/40">
                        <p class="text-xs font-semibold uppercase text-slate-500">{{ $tr('Caisse espèces') }}</p>
                        <div class="mt-3 grid gap-2 text-sm">
                            <div class="flex justify-between gap-3"><span class="text-slate-500">{{ $tr('Reçu') }}</span><strong>{{ $money($cashSummary['received']) }}</strong></div>
                            <div class="flex justify-between gap-3"><span class="text-slate-500">{{ $tr('Monnaie') }}</span><strong>{{ $money($cashSummary['change']) }}</strong></div>
                            <div class="flex justify-between gap-3 rounded-lg bg-slate-50 px-3 py-2 dark:bg-white/5"><span class="text-slate-500">{{ $tr('Entrée tiroir') }}</span><strong>{{ $money($cashSummary['drawer_in']) }}</strong></div>
                        </div>
                    </div>

                    <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-white/10 dark:bg-slate-950/40">
                        <div class="flex items-center justify-between">
                            <p class="text-xs font-semibold uppercase text-slate-500">{{ $tr('Paiements période') }}</p>
                            <a href="{{ route('module', ['module' => 'sales', 'section' => 'payments']) }}" class="text-xs font-semibold text-brand">{{ $tr('Voir') }}</a>
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
                                <p class="rounded-lg border border-dashed border-slate-200 p-4 text-center text-sm text-slate-500 dark:border-white/10">{{ $tr("Aucun paiement aujourd'hui.") }}</p>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>
        </article>

        <aside class="dashboard-panel">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold">{{ $tr("Centre d'action") }}</h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $tr('Les éléments à traiter maintenant.') }}</p>
                </div>
                <span class="grid size-10 place-items-center rounded-xl bg-brand/10 text-sm font-black text-brand">GO</span>
            </div>

            <div class="dashboard-action-list mt-5">
                @foreach ($operationCards as $card)
                    <a href="{{ $card['href'] }}" class="dashboard-action-row is-{{ $card['tone'] }}">
                        <span class="dashboard-action-icon">{{ $card['icon'] }}</span>
                        <span class="min-w-0 flex-1">
                            <strong>{{ $tr($card['label']) }}</strong>
                            <small>{{ $tr($card['hint']) }}</small>
                        </span>
                        <em>{{ is_string($card['value']) ? $tr($card['value']) : $card['value'] }}</em>
                    </a>
                @endforeach
            </div>

            <div class="mt-5 rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-white/10 dark:bg-white/5">
                <p class="text-xs font-semibold uppercase text-slate-500">{{ $tr('Raccourcis') }}</p>
                <div class="mt-3 grid grid-cols-2 gap-2">
                    <a href="{{ route('catalog.labels') }}" class="dashboard-mini-link">{{ $tr('Étiquettes') }}</a>
                    <a href="{{ route('catalog', ['panel' => 'import']) }}" class="dashboard-mini-link">{{ $tr('Import') }}</a>
                    <a href="{{ route('module', ['module' => 'contacts', 'section' => 'customer-add']) }}" class="dashboard-mini-link">{{ $tr('Client') }}</a>
                    <a href="{{ route('module', ['module' => 'purchases', 'section' => 'add']) }}" class="dashboard-mini-link">{{ $tr('Achat') }}</a>
                </div>
            </div>
        </aside>
    </section>

    <section class="mt-6 grid gap-6 xl:grid-cols-3">
        <article class="dashboard-panel xl:col-span-2">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-base font-semibold">{{ $tr('Heures de pointe') }}</h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $tr('Nombre de tickets par heure pour mieux préparer l’équipe en période chargée.') }}</p>
                </div>
                <a href="{{ route('module', ['module' => 'reports', 'section' => 'sales-summary'] + $dateQuery) }}" class="text-sm font-semibold text-brand">{{ $tr('Rapport ventes') }}</a>
            </div>
            <div class="dashboard-hour-grid mt-5">
                @foreach ($hourlyHeatmap as $hour)
                    <div class="dashboard-hour-cell" style="--hour-strength: {{ min(1, (float) $hour->tickets / $maxHourly) }}">
                        <strong>{{ str_pad((string) $hour->hour, 2, '0', STR_PAD_LEFT) }}h</strong>
                        <span>{{ $hour->tickets }} {{ $tr('ticket(s)') }}</span>
                        <small>{{ $money($hour->total) }}</small>
                    </div>
                @endforeach
            </div>
        </article>

        <article class="dashboard-panel">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold">{{ $tr('Clients') }}</h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $tr('Activité CRM sur la période.') }}</p>
                </div>
                <a href="{{ route('module', ['module' => 'contacts', 'section' => 'customers']) }}" class="text-sm font-semibold text-brand">CRM</a>
            </div>
            <div class="mt-5 grid gap-3">
                <div class="dashboard-client-stat">
                    <span>{{ $tr('Nouveaux clients') }}</span>
                    <strong>{{ number_format($clientSummary['new'], 0, ',', ' ') }}</strong>
                </div>
                <div class="dashboard-client-stat">
                    <span>{{ $tr('Clients actifs') }}</span>
                    <strong>{{ number_format($clientSummary['active'], 0, ',', ' ') }}</strong>
                </div>
                <div class="dashboard-client-stat">
                    <span>{{ $tr('Base totale') }}</span>
                    <strong>{{ number_format($clientSummary['total'], 0, ',', ' ') }}</strong>
                </div>
            </div>
        </article>
    </section>

    <section class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1.2fr)_minmax(360px,.8fr)]">
        <article class="dashboard-panel">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-base font-semibold">{{ $tr('Transactions récentes') }}</h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $tr('Derniers tickets validés avec client, mode et montant.') }}</p>
                </div>
                <a href="{{ route('module', ['module' => 'sales', 'section' => 'list']) }}" class="text-sm font-semibold text-brand">{{ $tr('Liste des ventes') }}</a>
            </div>

            <div class="mt-4 overflow-hidden rounded-xl border border-slate-200 dark:border-white/10">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[720px] text-left text-sm">
                        <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-white/5">
                            <tr>
                                <th class="px-4 py-3">{{ $tr('Ticket') }}</th>
                                <th class="px-4 py-3">{{ $tr('Client') }}</th>
                                <th class="px-4 py-3">{{ $tr('Articles') }}</th>
                                <th class="px-4 py-3">{{ $tr('Paiement') }}</th>
                                <th class="px-4 py-3 text-right">{{ $tr('Total') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-white/10">
                            @forelse ($recentSales as $sale)
                                <tr class="transition hover:bg-slate-50 dark:hover:bg-white/5">
                                    <td class="px-4 py-3 font-semibold">{{ $sale->number }}</td>
                                    <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $sale->contact?->name ?? $tr('Client comptoir') }}</td>
                                    <td class="px-4 py-3">{{ $sale->items_count }}</td>
                                    <td class="px-4 py-3"><x-status-pill tone="neutral">{{ $sale->payment_method }}</x-status-pill></td>
                                    <td class="px-4 py-3 text-right font-semibold">{{ $money($sale->total_amount) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-4 py-12 text-center text-sm text-slate-500">{{ $tr('Aucune vente récente.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </article>

        <article class="dashboard-panel">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold">{{ $tr('Alertes stock') }}</h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $tr('Priorité aux ruptures et seuils bas.') }}</p>
                </div>
                <a href="{{ route('catalog', ['stock' => 'low']) }}" class="text-sm font-semibold text-brand">{{ $tr('Voir tout') }}</a>
            </div>
            <div class="mt-4 space-y-3">
                @forelse ($lowStockItems as $item)
                    <a href="{{ route('catalog', ['panel' => 'articles', 'edit' => $item->id]) }}" class="dashboard-stock-row">
                        <span>
                            <strong>{{ $item->title }}</strong>
                            <small>{{ $item->category?->name ?? $tr('Sans catégorie') }} · {{ $tr('seuil') }} {{ $item->min_stock_threshold }}</small>
                        </span>
                        <x-status-pill tone="warning">{{ $item->stock_quantity }} {{ $tr('restants') }}</x-status-pill>
                    </a>
                @empty
                    <div class="rounded-xl border border-dashed border-slate-200 p-8 text-center text-sm text-slate-500 dark:border-white/10">{{ $tr('Aucune alerte stock.') }}</div>
                @endforelse
            </div>
        </article>
    </section>

    <section class="mt-6 grid gap-6 xl:grid-cols-2">
        <article class="dashboard-panel">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold">{{ $tr('Ventes par catégorie') }}</h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $tr('Répartition du chiffre d’affaires par famille d’articles.') }}</p>
                </div>
                <a href="{{ route('module', ['module' => 'reports', 'section' => 'sales'] + $dateQuery) }}" class="text-sm font-semibold text-brand">{{ $tr('Détail') }}</a>
            </div>
            <div class="mt-5 space-y-4">
                @forelse ($categoryBreakdown as $category)
                    <div class="dashboard-breakdown-row">
                        <div class="flex justify-between gap-3 text-sm">
                            <span class="min-w-0 truncate font-semibold">{{ $category->category_name }}</span>
                            <span class="shrink-0 text-slate-500">{{ $money($category->revenue) }}</span>
                        </div>
                        <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-white/10">
                            <div class="h-full rounded-full bg-brand" style="width: {{ max(7, ((float) $category->revenue / $maxCategory) * 100) }}%"></div>
                        </div>
                        <p class="mt-1 text-xs text-slate-500">{{ number_format((float) $category->quantity, 0, ',', ' ') }} {{ $tr('unité(s)') }}</p>
                    </div>
                @empty
                    <p class="rounded-xl border border-dashed border-slate-200 p-8 text-center text-sm text-slate-500 dark:border-white/10">{{ $tr('Aucune vente catégorisée pour cette période.') }}</p>
                @endforelse
            </div>
        </article>

        <article class="dashboard-panel">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold">{{ $tr('Dépenses par catégorie') }}</h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $tr('Suivi rapide des charges qui impactent la marge.') }}</p>
                </div>
                <a href="{{ route('module', ['module' => 'finance', 'section' => 'expenses']) }}" class="text-sm font-semibold text-brand">{{ $tr('Dépenses') }}</a>
            </div>
            <div class="mt-5 space-y-4">
                @forelse ($expenseBreakdown as $expense)
                    <div class="dashboard-breakdown-row">
                        <div class="flex justify-between gap-3 text-sm">
                            <span class="min-w-0 truncate font-semibold">{{ $expense->category }}</span>
                            <span class="shrink-0 text-slate-500">{{ $money($expense->total) }}</span>
                        </div>
                        <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-white/10">
                            <div class="h-full rounded-full bg-rose-500" style="width: {{ max(7, ((float) $expense->total / $maxExpense) * 100) }}%"></div>
                        </div>
                    </div>
                @empty
                    <p class="rounded-xl border border-dashed border-slate-200 p-8 text-center text-sm text-slate-500 dark:border-white/10">{{ $tr('Aucune dépense sur cette période.') }}</p>
                @endforelse
            </div>
        </article>
    </section>

    <section class="mt-6 grid gap-6 xl:grid-cols-3">
        <article class="dashboard-panel">
            <h2 class="text-base font-semibold">{{ $tr('Meilleures ventes période') }}</h2>
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
                    <p class="rounded-xl border border-dashed border-slate-200 p-8 text-center text-sm text-slate-500 dark:border-white/10">{{ $tr('Aucune vente pour cette période.') }}</p>
                @endforelse
            </div>
        </article>

        <article class="dashboard-panel">
            <h2 class="text-base font-semibold">{{ $tr('Activité du jour') }}</h2>
            <div class="mt-4 grid gap-3">
                @foreach ($recentActivity as $activity)
                    <a href="{{ $activity['href'] }}" class="dashboard-action-row">
                        <span>
                            <strong>{{ $tr($activity['label']) }}</strong>
                            <small>{{ $tr("Aujourd'hui") }}</small>
                        </span>
                        <em>{{ $activity['value'] }}</em>
                    </a>
                @endforeach
            </div>
        </article>

        <article class="dashboard-panel">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-base font-semibold">{{ $tr('Emprunts à suivre') }}</h2>
                <a href="{{ route('module', ['module' => 'loans']) }}" class="text-sm font-semibold text-brand">{{ $tr('Ouvrir') }}</a>
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
                    <p class="rounded-xl border border-dashed border-slate-200 p-8 text-center text-sm text-slate-500 dark:border-white/10">{{ $tr('Aucun emprunt actif.') }}</p>
                @endforelse
            </div>
        </article>
    </section>
</x-layouts.app>
