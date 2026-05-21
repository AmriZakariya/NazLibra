@php
    $money = fn ($amount) => number_format((float) $amount, 2, ',', ' ').' DH';
    $pageTitle = 'LibrairePro · '.$meta['title'];
@endphp

<x-layouts.app :tenant="$tenant" :active="$active" :title="$pageTitle">
    <section class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <p class="text-sm font-medium text-indigo-600 dark:text-indigo-300">Module</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-normal">{{ $meta['title'] }}</h1>
            <p class="mt-2 max-w-3xl text-sm text-slate-600 dark:text-slate-300">{{ $meta['subtitle'] }}</p>
        </div>
        <div class="flex gap-2">
            <button class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold dark:border-white/10 dark:bg-white/5">Exporter</button>
            <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Nouvelle action</button>
        </div>
    </section>

    @if ($module === 'sales')
        <section class="mt-6 grid gap-6 xl:grid-cols-[1fr_320px]">
            <article class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-white/5"><tr><th class="px-4 py-3">Vente</th><th class="px-4 py-3">Client</th><th class="px-4 py-3">Statut</th><th class="px-4 py-3 text-right">Total</th></tr></thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-white/10">
                        @foreach ($sales as $sale)
                            <tr><td class="px-4 py-3 font-semibold">{{ $sale->number }}</td><td class="px-4 py-3">{{ $sale->contact?->name ?? 'Client comptoir' }}</td><td class="px-4 py-3"><x-status-pill tone="success">{{ $sale->status }}</x-status-pill></td><td class="px-4 py-3 text-right font-semibold">{{ $money($sale->total_amount) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </article>
            <div class="space-y-4">
                <article class="rounded-xl border border-slate-200 bg-white p-5 dark:border-white/10 dark:bg-white/[0.03]"><h2 class="font-semibold">Retours & devis</h2><p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Workflow prévu: lier à une vente, remboursement, avoir, échange, approbation manager.</p></article>
                <article class="rounded-xl border border-slate-200 bg-white p-5 dark:border-white/10 dark:bg-white/[0.03]"><h2 class="font-semibold">Livraison</h2><p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Statuts: préparation, expédiée, livrée, échouée avec preuve photo.</p></article>
            </div>
        </section>
    @elseif ($module === 'purchases')
        <section class="mt-6 grid gap-6 lg:grid-cols-3">
            @foreach ($purchases as $purchase)
                <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <div class="flex items-center justify-between"><h2 class="font-semibold">{{ $purchase->number }}</h2><x-status-pill tone="warning">{{ $purchase->status }}</x-status-pill></div>
                    <p class="mt-3 text-sm text-slate-600 dark:text-slate-300">{{ $purchase->supplier?->name }}</p>
                    <p class="mt-4 text-2xl font-semibold">{{ $money($purchase->total_amount) }}</p>
                    <p class="mt-2 text-xs text-slate-500">Réception prévue {{ $purchase->expected_at?->format('d/m/Y') }}</p>
                </article>
            @endforeach
            <article class="rounded-xl border border-dashed border-slate-300 bg-white p-5 dark:border-white/10 dark:bg-white/[0.03]">
                <h2 class="font-semibold">Réassort automatique</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Les articles sous seuil peuvent générer un brouillon de commande fournisseur en un clic.</p>
            </article>
        </section>
    @elseif ($module === 'loans')
        <section class="mt-6 grid gap-4 lg:grid-cols-2">
            @foreach ($loans as $loan)
                <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <div class="flex items-start justify-between gap-4">
                        <div><h2 class="font-semibold">{{ $loan->item->title }}</h2><p class="mt-1 text-sm text-slate-600 dark:text-slate-300">{{ $loan->member->name }} · échéance {{ $loan->due_at->format('d/m/Y') }}</p></div>
                        <x-status-pill :tone="$loan->status === 'overdue' ? 'danger' : 'info'">{{ $loan->status }}</x-status-pill>
                    </div>
                    <div class="mt-4 flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2 text-sm dark:bg-white/5"><span>Pénalité</span><strong>{{ $money($loan->fine_amount) }}</strong></div>
                </article>
            @endforeach
        </section>
    @elseif ($module === 'contacts')
        <section class="mt-6 grid gap-4 lg:grid-cols-3">
            @foreach ($contacts as $contact)
                <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <div class="flex items-center justify-between"><h2 class="font-semibold">{{ $contact->name }}</h2><x-status-pill :tone="$contact->kind === 'supplier' ? 'warning' : 'primary'">{{ $contact->kind }}</x-status-pill></div>
                    <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">{{ $contact->phone ?? $contact->email }}</p>
                    <div class="mt-4 grid grid-cols-2 gap-2 text-sm"><div class="rounded-lg bg-slate-50 p-3 dark:bg-white/5"><span class="block text-xs text-slate-500">Avance</span><strong>{{ $money($contact->advance_balance) }}</strong></div><div class="rounded-lg bg-slate-50 p-3 dark:bg-white/5"><span class="block text-xs text-slate-500">Solde</span><strong>{{ $money($contact->outstanding_balance) }}</strong></div></div>
                </article>
            @endforeach
        </section>
    @elseif ($module === 'finance')
        <section class="mt-6 grid gap-6 xl:grid-cols-[1fr_340px]">
            <article class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                <table class="w-full text-left text-sm"><thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-white/5"><tr><th class="px-4 py-3">Dépense</th><th class="px-4 py-3">Catégorie</th><th class="px-4 py-3">Date</th><th class="px-4 py-3 text-right">Montant</th></tr></thead><tbody class="divide-y divide-slate-200 dark:divide-white/10">@foreach ($expenses as $expense)<tr><td class="px-4 py-3 font-semibold">{{ $expense->label }}</td><td class="px-4 py-3">{{ $expense->category }}</td><td class="px-4 py-3">{{ \Illuminate\Support\Carbon::parse($expense->spent_at)->format('d/m/Y') }}</td><td class="px-4 py-3 text-right font-semibold">{{ $money($expense->amount) }}</td></tr>@endforeach</tbody></table>
            </article>
            <article class="rounded-xl border border-slate-200 bg-white p-5 dark:border-white/10 dark:bg-white/[0.03]"><h2 class="font-semibold">Z report</h2><p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Clôture journalière de caisse, espèces attendues, écarts, coupons et avances utilisés.</p></article>
        </section>
    @elseif ($module === 'reports')
        <section class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            @foreach (['Ventes par heure', 'Valorisation stock', 'Pertes & profits', 'Livres les plus empruntés'] as $report)
                <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <h2 class="font-semibold">{{ $report }}</h2>
                    <div class="mt-5 h-24 rounded-lg bg-gradient-to-r from-indigo-600 via-sky-500 to-emerald-500 opacity-80"></div>
                    <button class="mt-4 rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold dark:border-white/10">Générer PDF</button>
                </article>
            @endforeach
        </section>
    @else
        @php
            $themeDefaults = [
                'primary' => '#2563EB',
                'accent' => '#0D9488',
                'success' => '#16A34A',
                'background' => '#F6F8FB',
                'surface_color' => '#FFFFFF',
                'surface_muted' => '#EEF4FF',
                'text' => '#111827',
                'muted' => '#667085',
                'border' => '#D8E1EE',
                'font_scale' => '1',
                'density' => 'comfortable',
                'radius' => '12',
            ];
            $theme = array_merge($themeDefaults, $tenant->settings['theme'] ?? []);
            $themePreset = $tenant->settings['theme_preset'] ?? 'default';
            $themePresets = [
                'default' => ['name' => 'LibrairePro', 'hint' => 'Sapphire moderne, recommandé', 'colors' => ['#2563EB', '#0D9488', '#FFFFFF', '#F6F8FB']],
                'classic' => ['name' => 'Indigo classic', 'hint' => 'Plus proche du thème initial', 'colors' => ['#4F46E5', '#0EA5E9', '#FFFFFF', '#F8FAFC']],
                'graphite' => ['name' => 'Graphite', 'hint' => 'Compact et sobre', 'colors' => ['#334155', '#0F766E', '#FFFFFF', '#F7F7F5']],
            ];
        @endphp
        <section class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
            <div class="space-y-6">
                <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 class="font-semibold">Personnalisation</h2>
                            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Choisissez un thème fiable, puis ajustez les couleurs seulement si nécessaire.</p>
                        </div>
                        <x-status-pill tone="success">Tenant theme</x-status-pill>
                    </div>

                    <div class="mt-5 grid gap-3 md:grid-cols-3">
                        @foreach ($themePresets as $key => $preset)
                            <form action="{{ route('settings.theme.update') }}" method="POST" class="rounded-xl border border-slate-200 bg-slate-50 p-4 transition hover:border-indigo-200 hover:bg-white dark:border-white/10 dark:bg-white/5">
                                @csrf
                                <input type="hidden" name="preset" value="{{ $key }}">
                                <div class="flex items-center justify-between gap-3">
                                    <div class="flex gap-1">
                                        @foreach ($preset['colors'] as $color)
                                            <span class="size-5 rounded-full border border-white shadow-sm" style="background: {{ $color }}"></span>
                                        @endforeach
                                    </div>
                                    @if ($themePreset === $key)
                                        <x-status-pill tone="primary">Actif</x-status-pill>
                                    @endif
                                </div>
                                <h3 class="mt-4 text-sm font-semibold">{{ $preset['name'] }}</h3>
                                <p class="mt-1 text-xs text-slate-500">{{ $preset['hint'] }}</p>
                                <button class="mt-4 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:border-brand hover:text-brand dark:border-white/10 dark:bg-slate-950 dark:text-slate-200" type="submit">
                                    {{ $key === 'default' ? 'Réinitialiser' : 'Appliquer' }}
                                </button>
                            </form>
                        @endforeach
                    </div>

                    <form action="{{ route('settings.theme.update') }}" method="POST" class="mt-5 grid gap-4 lg:grid-cols-6">
                        @csrf
                        <label class="block">
                            <span class="text-xs font-semibold uppercase text-slate-500">Primaire</span>
                            <input name="primary" type="color" value="{{ $theme['primary'] ?? '#2563EB' }}" class="mt-1 h-11 w-full rounded-lg border border-slate-200 p-1 dark:border-white/10 dark:bg-slate-900">
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase text-slate-500">Accent</span>
                            <input name="accent" type="color" value="{{ $theme['accent'] ?? '#0D9488' }}" class="mt-1 h-11 w-full rounded-lg border border-slate-200 p-1 dark:border-white/10 dark:bg-slate-900">
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase text-slate-500">Succès</span>
                            <input name="success" type="color" value="{{ $theme['success'] ?? '#16A34A' }}" class="mt-1 h-11 w-full rounded-lg border border-slate-200 p-1 dark:border-white/10 dark:bg-slate-900">
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase text-slate-500">Fond</span>
                            <input name="background" type="color" value="{{ $theme['background'] ?? '#F6F8FB' }}" class="mt-1 h-11 w-full rounded-lg border border-slate-200 p-1 dark:border-white/10 dark:bg-slate-900">
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase text-slate-500">Surface</span>
                            <input name="surface_color" type="color" value="{{ $theme['surface_color'] ?? '#FFFFFF' }}" class="mt-1 h-11 w-full rounded-lg border border-slate-200 p-1 dark:border-white/10 dark:bg-slate-900">
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase text-slate-500">Surface légère</span>
                            <input name="surface_muted" type="color" value="{{ $theme['surface_muted'] ?? '#EEF4FF' }}" class="mt-1 h-11 w-full rounded-lg border border-slate-200 p-1 dark:border-white/10 dark:bg-slate-900">
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase text-slate-500">Texte</span>
                            <input name="text" type="color" value="{{ $theme['text'] ?? '#111827' }}" class="mt-1 h-11 w-full rounded-lg border border-slate-200 p-1 dark:border-white/10 dark:bg-slate-900">
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase text-slate-500">Texte secondaire</span>
                            <input name="muted" type="color" value="{{ $theme['muted'] ?? '#667085' }}" class="mt-1 h-11 w-full rounded-lg border border-slate-200 p-1 dark:border-white/10 dark:bg-slate-900">
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase text-slate-500">Bordure</span>
                            <input name="border" type="color" value="{{ $theme['border'] ?? '#D8E1EE' }}" class="mt-1 h-11 w-full rounded-lg border border-slate-200 p-1 dark:border-white/10 dark:bg-slate-900">
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase text-slate-500">Échelle texte</span>
                            <input name="font_scale" type="number" min="0.88" max="1.15" step="0.01" value="{{ $theme['font_scale'] ?? '1' }}" class="mt-1 h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900">
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase text-slate-500">Densité</span>
                            <select name="density" class="mt-1 h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">
                                <option value="comfortable" @selected(($theme['density'] ?? 'comfortable') === 'comfortable')>Confortable</option>
                                <option value="compact" @selected(($theme['density'] ?? '') === 'compact')>Compact</option>
                                <option value="soft" @selected(($theme['density'] ?? '') === 'soft')>Aéré</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase text-slate-500">Rayon</span>
                            <select name="radius" class="mt-1 h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">
                                <option value="8" @selected(($theme['radius'] ?? '12') === '8')>8 px</option>
                                <option value="12" @selected(($theme['radius'] ?? '12') === '12')>12 px</option>
                                <option value="16" @selected(($theme['radius'] ?? '12') === '16')>16 px</option>
                            </select>
                        </label>
                        <div class="lg:col-span-6 flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 pt-4 dark:border-white/10">
                            <div class="flex gap-2">
                                @foreach (['#4F46E5', '#0EA5E9', '#16A34A', '#DB2777', '#111827'] as $color)
                                    <button class="theme-swatch size-9 rounded-lg border border-slate-200 dark:border-white/10" style="background: {{ $color }}" data-color="{{ $color }}" type="button" aria-label="Couleur {{ $color }}"></button>
                                @endforeach
                            </div>
                            <button class="rounded-lg bg-brand px-5 py-2.5 text-sm font-semibold text-white">Enregistrer le thème</button>
                        </div>
                    </form>
                </article>

                <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <h2 class="font-semibold">Préférences système</h2>
                    <div class="mt-5 grid gap-3 md:grid-cols-3">
                        <div class="rounded-lg bg-slate-50 p-4 dark:bg-white/5"><span class="text-xs font-semibold uppercase text-slate-500">Langues</span><p class="mt-2 text-sm font-semibold">Français · العربية</p></div>
                        <div class="rounded-lg bg-slate-50 p-4 dark:bg-white/5"><span class="text-xs font-semibold uppercase text-slate-500">Devise</span><p class="mt-2 text-sm font-semibold">{{ strtoupper($tenant->currency) }} · DH</p></div>
                        <div class="rounded-lg bg-slate-50 p-4 dark:bg-white/5"><span class="text-xs font-semibold uppercase text-slate-500">Fuseau</span><p class="mt-2 text-sm font-semibold">{{ $tenant->timezone }}</p></div>
                    </div>
                </article>
            </div>

            <aside class="space-y-6">
                <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <h2 class="font-semibold">Profil librairie</h2>
                    <dl class="mt-4 space-y-3 text-sm">
                        <div class="flex justify-between gap-4"><dt class="text-slate-500">Nom</dt><dd class="font-semibold text-right">{{ $tenant->name }}</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-slate-500">Mode</dt><dd class="font-semibold text-right">{{ $tenant->mode }}</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-slate-500">Plan</dt><dd class="font-semibold text-right">{{ $tenant->plan }}</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-slate-500">ICE</dt><dd class="font-semibold text-right">{{ $tenant->ice }}</dd></div>
                    </dl>
                </article>
                <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <h2 class="font-semibold">Audit log</h2>
                    <div class="mt-4 space-y-2">@foreach ($auditLogs as $log)<div class="rounded-lg bg-slate-50 px-3 py-2 text-sm dark:bg-white/5">{{ $log->action }} · {{ \Illuminate\Support\Carbon::parse($log->created_at)->format('d/m/Y H:i') }}</div>@endforeach</div>
                </article>
            </aside>
        </section>
    @endif
</x-layouts.app>
