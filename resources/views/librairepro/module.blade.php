@php
    $money = fn ($amount) => number_format((float) $amount, 2, ',', ' ').' DH';
    $pageTitle = 'LibrairePro · '.$meta['title'];
    $locale = \App\Support\Locale::current($tenant);
    $tr = fn (string $text): string => \App\Support\Locale::t($text, $locale);
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
            @if ($module === 'sales')
                <a href="{{ route('module', ['module' => 'sales', 'section' => 'add']) }}" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Ajouter vente</a>
                <a href="{{ route('pos') }}" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold dark:border-white/10 dark:bg-white/5">Caisse</a>
            @elseif ($module === 'purchases')
                <a href="{{ route('module', ['module' => 'purchases', 'section' => 'add']) }}" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Nouvel achat</a>
                <a href="{{ route('module', ['module' => 'contacts', 'section' => 'supplier-add']) }}" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold dark:border-white/10 dark:bg-white/5">Fournisseur</a>
                <a href="{{ route('stock', ['panel' => 'stock-adjustments']) }}" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold dark:border-white/10 dark:bg-white/5">Stock</a>
            @elseif ($module === 'contacts')
                <a href="{{ route('module', ['module' => 'contacts', 'section' => 'customer-add']) }}" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Client</a>
                <a href="{{ route('module', ['module' => 'contacts', 'section' => 'supplier-add']) }}" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold dark:border-white/10 dark:bg-white/5">Fournisseur</a>
            @else
                <a href="{{ route('dashboard') }}" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Tableau de bord</a>
            @endif
        </div>
    </section>

    @if ($module === 'sales')
        @php
            $salesSection = request('section', 'list');
            $salesTabs = [
                'add' => ['label' => 'Ajouter vente', 'href' => route('module', ['module' => 'sales', 'section' => 'add'])],
                'list' => ['label' => 'Liste des ventes', 'href' => route('module', 'sales')],
                'quote-add' => ['label' => 'Nouveau devis', 'href' => route('module', ['module' => 'sales', 'section' => 'quote-add'])],
                'quotes' => ['label' => 'Liste de devis', 'href' => route('module', ['module' => 'sales', 'section' => 'quotes'])],
                'invoices' => ['label' => 'Factures', 'href' => route('module', ['module' => 'sales', 'section' => 'invoices'])],
                'payments' => ['label' => 'Paiements', 'href' => route('module', ['module' => 'sales', 'section' => 'payments'])],
                'returns' => ['label' => 'Retours', 'href' => route('module', ['module' => 'sales', 'section' => 'returns'])],
                'delivery' => ['label' => 'Livraison', 'href' => route('module', ['module' => 'sales', 'section' => 'delivery'])],
            ];
        @endphp
        <details class="app-collapsible-menu mt-6" data-collapsible-menu data-menu-key="module-sales-menu">
            <summary class="app-collapsible-menu-summary">
                <span><strong>Menu ventes</strong><small>{{ $salesTabs[$salesSection]['label'] ?? 'Liste des ventes' }}</small></span>
                <em data-collapsible-menu-state>Afficher</em>
            </summary>
            <nav class="app-tab-nav">
                @foreach ($salesTabs as $key => $tab)
                    <a href="{{ $tab['href'] }}" class="app-tab-link {{ $salesSection === $key || ($key === 'list' && ! in_array($salesSection, ['add', 'quote-add', 'quotes', 'invoices', 'payments', 'returns', 'delivery'], true)) ? 'is-active' : '' }}">{{ $tab['label'] }}</a>
                @endforeach
            </nav>
        </details>

        @if ($salesSection === 'add')
            <section class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1fr)_360px]" data-manual-sale-form>
                <form id="manual-sale-form" action="{{ route('sales.store') }}" method="POST" class="space-y-5">
                    @csrf
                    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                        <div class="border-b border-slate-200 p-5 dark:border-white/10">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <p class="text-sm font-semibold text-brand">Ventes · Ajouter/mettre à jour</p>
                                    <h2 class="mt-1 text-xl font-semibold">Ajouter une vente</h2>
                                    <p class="mt-1 max-w-3xl text-sm text-slate-500">Saisie complète hors caisse: client, articles, remises, taxes, paiement, échéance et livraison.</p>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <x-status-pill tone="primary">{{ $nextSaleNumber ?? 'BL' }}</x-status-pill>
                                    <x-status-pill tone="info">MAD · DH</x-status-pill>
                                </div>
                            </div>
                        </div>

                        <div class="grid gap-4 p-5 lg:grid-cols-4">
                            <label class="space-y-1.5 lg:col-span-2"><span class="text-xs font-semibold uppercase text-slate-500">Client existant</span><select name="contact_id" data-searchable-select data-placeholder="Rechercher client..." class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="">Client Grand Public / nouveau</option>@foreach ($salesClients as $client)<option value="{{ $client->id }}" @selected(old('contact_id') == $client->id)>{{ $client->name }}{{ $client->phone ? ' · '.$client->phone : '' }}{{ $client->advance_balance > 0 ? ' · avance '.$money($client->advance_balance) : '' }}</option>@endforeach</select></label>
                            <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Date de vente *</span><input name="sold_at" value="{{ old('sold_at', now()->format('Y-m-d\\TH:i')) }}" type="datetime-local" required class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                            <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Date d'échéance</span><input name="due_date" value="{{ old('due_date') }}" type="date" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                            <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Nouveau client</span><input name="client_name" value="{{ old('client_name') }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Nom client"></label>
                            <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Téléphone</span><input name="client_phone" value="{{ old('client_phone') }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="+212..."></label>
                            <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Référence</span><input name="reference_number" value="{{ old('reference_number') }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Bon, commande, école..."></label>
                            <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Statut paiement *</span><select name="sale_status" data-manual-sale-status class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="paid" @selected(old('sale_status', 'paid') === 'paid')>Payée</option><option value="partial" @selected(old('sale_status') === 'partial')>Partielle</option><option value="unpaid" @selected(old('sale_status') === 'unpaid')>À crédit / impayée</option></select></label>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                        <div class="flex flex-col gap-3 border-b border-slate-200 p-5 dark:border-white/10 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h3 class="font-semibold">Articles de la vente</h3>
                                <p class="mt-1 text-sm text-slate-500">Recherche par nom, ISBN ou code-barres. Les prix et remises restent modifiables ligne par ligne.</p>
                            </div>
                            <span class="text-xs font-semibold uppercase text-slate-500">8 lignes rapides</span>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[1080px] text-left text-sm manual-sale-lines">
                                <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-white/5">
                                    <tr><th class="px-3 py-3">Article *</th><th class="px-3 py-3 w-24">Qté</th><th class="px-3 py-3 w-32">Prix DH</th><th class="px-3 py-3 w-32">Remise</th><th class="px-3 py-3 w-28">Taxe</th><th class="px-3 py-3">Description</th><th class="px-3 py-3 w-32 text-right">Total</th></tr>
                                </thead>
                                <tbody class="divide-y divide-slate-200 dark:divide-white/10">
                                    @for ($i = 0; $i < 8; $i++)
                                        @php $oldItem = old("items.$i.item_id"); @endphp
                                        <tr class="manual-sale-line">
                                            <td class="px-3 py-3"><select name="items[{{ $i }}][item_id]" data-manual-sale-item data-searchable-select data-placeholder="Rechercher article..." class="h-10 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="">Choisir article</option>@foreach ($quoteItems as $item)<option value="{{ $item->id }}" data-price="{{ (float) $item->sale_price }}" data-tax="{{ (float) ($item->tax?->rate ?? 20) }}" data-stock="{{ $item->type === 'service' ? 999999 : (int) $item->stock_quantity }}" @selected((string) $oldItem === (string) $item->id)>{{ $item->title }} · {{ $item->barcode ?: $item->isbn ?: $item->item_code }} · {{ $money($item->sale_price) }} · stock {{ $item->type === 'service' ? '∞' : $item->stock_quantity }}</option>@endforeach</select></td>
                                            <td class="px-3 py-3"><input name="items[{{ $i }}][quantity]" data-manual-sale-qty value="{{ old("items.$i.quantity") }}" type="number" min="1" class="h-10 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="1"></td>
                                            <td class="px-3 py-3"><input name="items[{{ $i }}][unit_price]" data-manual-sale-price value="{{ old("items.$i.unit_price") }}" type="number" min="0" step="0.01" class="h-10 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="0.00"></td>
                                            <td class="px-3 py-3"><input name="items[{{ $i }}][discount_amount]" data-manual-sale-line-discount value="{{ old("items.$i.discount_amount", 0) }}" type="number" min="0" step="0.01" class="h-10 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></td>
                                            <td class="px-3 py-3"><input name="items[{{ $i }}][tax_rate]" data-manual-sale-tax value="{{ old("items.$i.tax_rate", 20) }}" type="number" min="0" max="100" step="0.01" class="h-10 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></td>
                                            <td class="px-3 py-3"><input name="items[{{ $i }}][description]" value="{{ old("items.$i.description") }}" class="h-10 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Description / note ligne"></td>
                                            <td class="px-3 py-3 text-right font-semibold" data-manual-sale-line-total>0,00 DH</td>
                                        </tr>
                                    @endfor
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="grid gap-5 lg:grid-cols-2">
                        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                            <h3 class="font-semibold">Remises, charges & note</h3>
                            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                                <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Remise globale DH</span><input name="discount_amount" data-manual-sale-discount value="{{ old('discount_amount', 0) }}" type="number" min="0" step="0.01" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                                <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Autres charges DH</span><input name="other_charges" data-manual-sale-charges value="{{ old('other_charges', 0) }}" type="number" min="0" step="0.01" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                                <label class="space-y-1.5 sm:col-span-2"><span class="text-xs font-semibold uppercase text-slate-500">Note</span><textarea name="note" class="min-h-24 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Conditions, commentaire, école, classe...">{{ old('note') }}</textarea></label>
                            </div>
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                            <h3 class="font-semibold">Livraison optionnelle</h3>
                            <div class="mt-4 grid gap-3">
                                <textarea name="delivery_address" class="min-h-24 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Adresse de livraison">{{ old('delivery_address') }}</textarea>
                                <input name="delivery_note" value="{{ old('delivery_note') }}" class="h-11 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Note livraison / livreur">
                            </div>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03] xl:hidden">
                        <button class="w-full rounded-lg bg-brand px-4 py-3 text-sm font-semibold text-white">Enregistrer la vente</button>
                    </div>
                </form>

                <aside class="space-y-5 xl:sticky xl:top-24 xl:self-start">
                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                        <h3 class="font-semibold">Paiement</h3>
                        <div class="mt-4 grid gap-3">
                            <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Espèces</span><input form="manual-sale-form" name="cash_amount" data-manual-sale-payment value="{{ old('cash_amount', 0) }}" type="number" min="0" step="0.01" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                            <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Carte</span><input form="manual-sale-form" name="card_amount" data-manual-sale-payment value="{{ old('card_amount', 0) }}" type="number" min="0" step="0.01" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                            <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Virement</span><input form="manual-sale-form" name="transfer_amount" data-manual-sale-payment value="{{ old('transfer_amount', 0) }}" type="number" min="0" step="0.01" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                            <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Avance client</span><input form="manual-sale-form" name="advance_amount" data-manual-sale-payment value="{{ old('advance_amount', 0) }}" type="number" min="0" step="0.01" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                        </div>
                        <p class="mt-3 text-xs text-slate-500">Les champs paiement sont synchronisés dans le formulaire de vente.</p>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                        <h3 class="font-semibold">Résumé vente</h3>
                        <dl class="mt-4 space-y-3 text-sm">
                            <div class="flex justify-between gap-3"><dt class="text-slate-500">Sous-total</dt><dd class="font-semibold" data-manual-sale-subtotal>0,00 DH</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-slate-500">Remises</dt><dd class="font-semibold" data-manual-sale-discount-total>0,00 DH</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-slate-500">TVA incluse</dt><dd class="font-semibold" data-manual-sale-tax-total>0,00 DH</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-slate-500">Autres charges</dt><dd class="font-semibold" data-manual-sale-charges-total>0,00 DH</dd></div>
                            <div class="flex justify-between gap-3 border-t border-slate-200 pt-3 text-lg dark:border-white/10"><dt class="font-semibold">Total</dt><dd class="font-bold" data-manual-sale-total>0,00 DH</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-slate-500">Payé</dt><dd class="font-semibold text-emerald-600" data-manual-sale-paid>0,00 DH</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-slate-500">Reste</dt><dd class="font-semibold text-rose-600" data-manual-sale-due>0,00 DH</dd></div>
                        </dl>
                    </div>
                    <div class="hidden rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03] xl:block">
                        <button form="manual-sale-form" class="w-full rounded-lg bg-brand px-4 py-3 text-sm font-semibold text-white">Enregistrer la vente</button>
                    </div>
                </aside>
            </section>
        @elseif ($salesSection === 'quote-add')
            <section class="mt-6 grid gap-6 xl:grid-cols-[1fr_360px]">
                <form action="{{ route('quotations.store') }}" method="POST" class="space-y-5 rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]" data-quote-form>
                    @csrf
                    <div class="flex flex-col gap-2 border-b border-slate-200 pb-4 dark:border-white/10 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h2 class="text-lg font-semibold">Nouveau devis</h2>
                            <p class="mt-1 text-sm text-slate-500">Préparez une offre client sans impacter le stock avant conversion.</p>
                        </div>
                        <button class="rounded-lg bg-brand px-5 py-2.5 text-sm font-semibold text-white">Enregistrer le devis</button>
                    </div>
                    <div class="grid gap-4 lg:grid-cols-4">
                        <label class="space-y-1.5 lg:col-span-2"><span class="text-xs font-semibold uppercase text-slate-500">Client existant</span><select name="contact_id" data-searchable-select data-placeholder="Client..." class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="">Client comptoir / nouveau</option>@foreach ($salesClients as $client)<option value="{{ $client->id }}" @selected(old('contact_id') == $client->id)>{{ $client->name }} · {{ $client->phone }}</option>@endforeach</select></label>
                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Date devis</span><input name="quoted_at" value="{{ old('quoted_at', now()->format('Y-m-d\TH:i')) }}" type="datetime-local" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Expiration</span><input name="expires_at" value="{{ old('expires_at', now()->addDays(15)->toDateString()) }}" type="date" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Nouveau client</span><input name="client_name" value="{{ old('client_name') }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Nom client"></label>
                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Téléphone</span><input name="client_phone" value="{{ old('client_phone') }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="+212..."></label>
                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Référence</span><input name="reference" value="{{ old('reference') }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Bon, école..."></label>
                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Statut</span><select name="status" class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="draft">Brouillon</option><option value="sent">Envoyé</option><option value="accepted">Accepté</option></select></label>
                    </div>
                    <div>
                        <div class="flex items-center justify-between pb-2">
                            <h3 class="text-sm font-semibold">Lignes devis</h3>
                            <button type="button" class="quote-add-line inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold dark:border-white/10 dark:bg-white/5">
                                <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                                Ajouter une ligne
                            </button>
                        </div>
                        <div class="overflow-hidden rounded-xl border border-slate-200 dark:border-white/10" data-quote-lines>
                            <div class="grid gap-2 bg-slate-50 px-3 py-2 text-xs font-semibold uppercase text-slate-500 dark:bg-white/5 lg:grid-cols-[1fr_90px_120px_120px_40px]"><span>Article</span><span class="text-center">Qté</span><span class="text-right">Prix</span><span class="text-right">Total</span><span></span></div>
                            @for ($i = 0; $i < 5; $i++)
                                <div class="quote-line grid gap-2 border-t border-slate-200 p-2 dark:border-white/10 lg:grid-cols-[1fr_90px_120px_120px_40px]" data-line-index="{{ $i }}">
                                    <select name="items[{{ $i }}][item_id]" data-quote-item data-searchable-select data-placeholder="Rechercher article..." class="h-10 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value=""></option>@foreach ($quoteItems as $item)<option value="{{ $item->id }}" data-price="{{ $item->sale_price }}">{{ $item->title }} · {{ $money($item->sale_price) }} · stock {{ $item->type === 'service' ? '∞' : $item->stock_quantity }}</option>@endforeach</select>
                                    <input name="items[{{ $i }}][quantity]" data-quote-qty type="number" min="1" value="1" class="h-10 rounded-lg border border-slate-200 px-3 text-sm text-center dark:border-white/10 dark:bg-slate-900">
                                    <input name="items[{{ $i }}][unit_price]" data-quote-price type="number" min="0" step="0.01" class="h-10 rounded-lg border border-slate-200 px-3 text-sm text-right dark:border-white/10 dark:bg-slate-900">
                                    <div class="flex h-10 items-center justify-end rounded-lg bg-slate-50 px-3 text-sm font-semibold dark:bg-white/5" data-quote-line-total>0,00 DH</div>
                                    <button type="button" class="quote-remove-line grid size-10 place-items-center rounded-lg text-slate-400 hover:bg-rose-50 hover:text-rose-600" title="Retirer"><svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></button>
                                </div>
                            @endfor
                        </div>
                    </div>
                    <div class="grid gap-4 lg:grid-cols-[1fr_200px]">
                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Note / Conditions</span><textarea name="note" class="min-h-20 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Conditions, délai, commentaire...">{{ old('note') }}</textarea></label>
                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Remise globale (DH)</span><input name="discount_amount" data-quote-discount value="{{ old('discount_amount', 0) }}" type="number" min="0" step="0.01" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"><span class="block text-xs text-slate-500">La TVA incluse (20%) sera calculée automatiquement.</span></label>
                    </div>
                </form>
                <aside class="space-y-4">
                    <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                        <h3 class="font-semibold">Récapitulatif</h3>
                        <dl class="mt-4 space-y-3 text-sm">
                            <div class="flex justify-between"><dt class="text-slate-500">Sous-total</dt><dd class="font-semibold" data-quote-summary-subtotal>0,00 DH</dd></div>
                            <div class="flex justify-between"><dt class="text-slate-500">Remise</dt><dd class="font-semibold text-rose-600" data-quote-summary-discount>0,00 DH</dd></div>
                            <div class="flex justify-between"><dt class="text-slate-500">TVA (20%)</dt><dd class="font-semibold" data-quote-summary-tax>0,00 DH</dd></div>
                            <div class="flex justify-between border-t border-slate-200 pt-3 text-lg dark:border-white/10"><dt class="font-semibold">Total TTC</dt><dd class="font-bold text-brand" data-quote-summary-total>0,00 DH</dd></div>
                        </dl>
                    </article>
                    <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                        <h3 class="font-semibold">Flux recommandé</h3>
                        <div class="mt-3 space-y-2 text-sm text-slate-500">
                            <p><span class="inline-flex size-5 items-center justify-center rounded-full bg-brand/10 text-xs font-bold text-brand">1</span> Créez le devis en brouillon</p>
                            <p><span class="inline-flex size-5 items-center justify-center rounded-full bg-brand/10 text-xs font-bold text-brand">2</span> Envoyez-le au client (marquez "Envoyé")</p>
                            <p><span class="inline-flex size-5 items-center justify-center rounded-full bg-brand/10 text-xs font-bold text-brand">3</span> Quand le client confirme, marquez "Accepté"</p>
                            <p><span class="inline-flex size-5 items-center justify-center rounded-full bg-brand/10 text-xs font-bold text-brand">4</span> Convertissez en vente — le stock est alors décrémenté</p>
                        </div>
                    </article>
                </aside>
            </section>
        @elseif ($salesSection === 'quotes')
            @php
                $quoteStatusLabels = ['draft' => 'Brouillon', 'sent' => 'Envoyé', 'accepted' => 'Accepté', 'rejected' => 'Rejeté', 'expired' => 'Expiré'];
                $quoteStatusTones = ['draft' => 'warning', 'sent' => 'info', 'accepted' => 'success', 'rejected' => 'danger', 'expired' => 'danger'];
            @endphp
            <section class="mt-6 space-y-5">
                <div class="grid gap-3 md:grid-cols-4">
                    <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><span class="text-xs font-semibold uppercase text-slate-500">Devis total</span><p class="mt-2 text-2xl font-semibold">{{ $quotations->total() }}</p></article>
                    <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><span class="text-xs font-semibold uppercase text-slate-500">Montant filtré</span><p class="mt-2 text-2xl font-semibold">{{ $money($quotations->sum('total_amount')) }}</p></article>
                    <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><span class="text-xs font-semibold uppercase text-slate-500">Acceptés</span><p class="mt-2 text-2xl font-semibold text-emerald-600">{{ \App\Models\Quotation::where('tenant_id', $tenant->id)->where('status', 'accepted')->count() }}</p></article>
                    <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><span class="text-xs font-semibold uppercase text-slate-500">À relancer</span><p class="mt-2 text-2xl font-semibold text-amber-600">{{ \App\Models\Quotation::where('tenant_id', $tenant->id)->whereIn('status', ['draft', 'sent'])->where(function($q){$q->whereNull('expires_at')->orWhereDate('expires_at', '>=', now());})->count() }}</p></article>
                </div>
                <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <form class="grid gap-3 lg:grid-cols-[1fr_190px_150px_150px_160px_auto]" method="GET" action="{{ route('module', ['module' => 'sales', 'section' => 'quotes']) }}">
                        <input type="hidden" name="section" value="quotes">
                        <input name="q" value="{{ request('q') }}" class="h-11 rounded-lg border border-slate-200 bg-slate-50 px-3 text-sm dark:border-white/10 dark:bg-white/5" placeholder="Rechercher devis, client, référence...">
                        <select name="client" class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="">Tous clients</option>@foreach ($salesClients as $client)<option value="{{ $client->id }}" @selected((string) request('client') === (string) $client->id)>{{ $client->name }}</option>@endforeach</select>
                        <input name="from" value="{{ request('from') }}" type="date" class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">
                        <input name="to" value="{{ request('to') }}" type="date" class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">
                        <select name="quote_status" class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="">Tous statuts</option>@foreach ($quoteStatusLabels as $key => $label)<option value="{{ $key }}" @selected(request('quote_status') === $key)>{{ $label }}</option>@endforeach</select>
                        <div class="flex gap-2"><button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Filtrer</button><a href="{{ route('module', ['module' => 'sales', 'section' => 'quotes']) }}" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold dark:border-white/10">Reset</a></div>
                    </form>
                </article>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[1120px] text-left text-sm">
                            <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-white/5">
                                <tr>
                                    <th class="px-3 py-3">N° devis</th>
                                    <th class="px-3 py-3">Date</th>
                                    <th class="px-3 py-3">Expiration</th>
                                    <th class="px-3 py-3">Client</th>
                                    <th class="px-3 py-3">Référence</th>
                                    <th class="px-3 py-3">Statut</th>
                                    <th class="px-3 py-3 text-right">Total</th>
                                    <th class="px-3 py-3 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 dark:divide-white/10">
                                @forelse ($quotations as $quote)
                                    @php
                                        $isExpired = $quote->expires_at && $quote->expires_at->isPast();
                                        $isSoon = $quote->expires_at && ! $isExpired && $quote->expires_at->diffInDays(now()) <= 3;
                                        $canConvert = ! $quote->converted_sale_id && $quote->status === 'accepted';
                                    @endphp
                                    <tr class="transition hover:bg-slate-50 dark:hover:bg-white/[0.02]">
                                        <td class="px-3 py-3">
                                            <p class="font-semibold">{{ $quote->number }}</p>
                                            @if ($quote->converted_sale_id)
                                                <p class="mt-0.5 text-xs text-emerald-600">Converti · {{ $quote->convertedSale?->number }}</p>
                                            @endif
                                        </td>
                                        <td class="px-3 py-3">{{ $quote->quoted_at?->format('d/m/Y H:i') }}</td>
                                        <td class="px-3 py-3">
                                            <span class="{{ $isExpired ? 'text-rose-600 font-semibold' : ($isSoon ? 'text-amber-600 font-semibold' : '') }}">
                                                {{ $quote->expires_at?->format('d/m/Y') ?? '—' }}
                                            </span>
                                            @if ($isExpired)<span class="ml-1 text-[10px] font-bold uppercase text-rose-600">Expiré</span>@endif
                                            @if ($isSoon)<span class="ml-1 text-[10px] font-bold uppercase text-amber-600">Bientôt</span>@endif
                                        </td>
                                        <td class="px-3 py-3">{{ $quote->contact?->name ?? data_get($quote->metadata, 'client_name', 'Client comptoir') }}</td>
                                        <td class="px-3 py-3 text-slate-500">{{ data_get($quote->metadata, 'reference', '—') }}</td>
                                        <td class="px-3 py-3"><x-status-pill :tone="$quoteStatusTones[$quote->status] ?? 'warning'">{{ $quoteStatusLabels[$quote->status] ?? $quote->status }}</x-status-pill></td>
                                        <td class="px-3 py-3 text-right font-semibold">{{ $money($quote->total_amount) }}</td>
                                        <td class="px-3 py-3">
                                            <div class="flex justify-end gap-2">
                                                <button type="button" onclick="document.getElementById('quote-detail-{{ $quote->id }}').showModal()" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold dark:border-white/10">Détail</button>
                                                @if ($canConvert)
                                                    <form action="{{ route('quotations.convert', $quote) }}" method="POST" onsubmit="return confirm('Convertir ce devis en vente ? Le stock sera décrémenté.')">@csrf<button class="rounded-lg bg-brand px-3 py-2 text-xs font-semibold text-white">Convertir</button></form>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="8" class="px-4 py-12 text-center text-sm text-slate-500">Aucun devis trouvé.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="border-t border-slate-200 px-4 py-3 dark:border-white/10">{{ $quotations->links() }}</div>
                </article>
                @foreach ($quotations as $quote)
                    <dialog id="quote-detail-{{ $quote->id }}" class="w-full max-w-3xl rounded-2xl border border-slate-200 bg-white p-0 text-slate-950 shadow-2xl backdrop:bg-slate-950/40 dark:border-white/10 dark:bg-slate-950 dark:text-slate-100">
                        <div class="border-b border-slate-200 p-5 dark:border-white/10">
                            <div class="flex justify-between gap-4">
                                <div>
                                    <p class="text-sm font-semibold text-brand">Détail devis</p>
                                    <h3 class="mt-1 text-xl font-semibold">{{ $quote->number }} · {{ $money($quote->total_amount) }}</h3>
                                    <p class="mt-1 text-sm text-slate-500">{{ $quote->contact?->name ?? data_get($quote->metadata, 'client_name', 'Client comptoir') }}</p>
                                </div>
                                <button class="dialog-close grid size-9 place-items-center rounded-lg border border-slate-200 text-lg font-semibold dark:border-white/10" type="button">×</button>
                            </div>
                        </div>
                        <div class="grid gap-4 p-5 lg:grid-cols-[1fr_280px]">
                            <div class="space-y-2">
                                @foreach ($quote->lines as $line)
                                    <div class="grid grid-cols-[1fr_70px_110px] gap-3 rounded-lg bg-slate-50 px-3 py-2 text-sm dark:bg-white/5">
                                        <span>{{ $line['name'] }}</span>
                                        <span class="text-center">x{{ $line['quantity'] }}</span>
                                        <strong class="text-right">{{ $money($line['total_price']) }}</strong>
                                    </div>
                                @endforeach
                            </div>
                            <aside class="space-y-3">
                                <div class="rounded-xl bg-slate-50 p-4 dark:bg-white/5">
                                    <dl class="space-y-2 text-sm">
                                        <div class="flex justify-between"><dt>Sous-total</dt><dd class="font-semibold">{{ $money($quote->subtotal_amount) }}</dd></div>
                                        <div class="flex justify-between"><dt>Remise</dt><dd class="font-semibold">{{ $money($quote->discount_amount) }}</dd></div>
                                        <div class="flex justify-between text-base"><dt class="font-semibold">Total</dt><dd class="font-bold">{{ $money($quote->total_amount) }}</dd></div>
                                    </dl>
                                </div>
                                <div class="space-y-2">
                                    <form action="{{ route('quotations.update', $quote) }}" method="POST">@csrf @method('PATCH')
                                        <select name="status" class="h-10 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">
                                            @foreach ($quoteStatusLabels as $key => $label)
                                                <option value="{{ $key }}" @selected($quote->status === $key)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        <button class="mt-2 w-full rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Mettre à jour le statut</button>
                                    </form>
                                    @if (! $quote->converted_sale_id && $quote->status === 'accepted')
                                        <form action="{{ route('quotations.convert', $quote) }}" method="POST" onsubmit="return confirm('Convertir ce devis en vente ? Le stock sera décrémenté.')">@csrf<button class="w-full rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white">Convertir en vente</button></form>
                                    @elseif ($quote->converted_sale_id)
                                        <a href="{{ route('module', ['module' => 'sales', 'section' => 'list']) }}" class="block w-full rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-center text-sm font-semibold text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300">Vente {{ $quote->convertedSale?->number ?? '' }}</a>
                                    @endif
                                    @if (! $quote->converted_sale_id)
                                        <form action="{{ route('quotations.destroy', $quote) }}" method="POST" onsubmit="return confirm('Supprimer définitivement ce devis ?')">@csrf @method('DELETE')<button type="submit" class="w-full rounded-lg border border-rose-200 px-4 py-2 text-sm font-semibold text-rose-600 dark:border-rose-500/30 dark:text-rose-300">Supprimer le devis</button></form>
                                    @endif
                                </div>
                            </aside>
                        </div>
                    </dialog>
                @endforeach
            </section>
        @elseif ($salesSection === 'invoices')
            <section class="mt-6 space-y-4">
                <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <form method="GET" action="{{ route('module', 'sales') }}" class="flex flex-wrap items-center gap-3">
                        <input type="hidden" name="section" value="invoices">
                        <input name="q" value="{{ request('q') }}" class="h-11 min-w-[260px] flex-1 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Rechercher facture, ticket, client...">
                        <button class="h-11 rounded-lg bg-brand px-4 text-sm font-semibold text-white" type="submit">Filtrer</button>
                        <a href="{{ route('module', ['module' => 'sales', 'section' => 'invoices']) }}" class="grid h-11 place-items-center rounded-lg border border-slate-200 px-4 text-sm font-semibold dark:border-white/10">Reset</a>
                    </form>
                </article>
                <article class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[960px] text-left text-sm">
                            <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-white/5">
                                <tr><th class="px-3 py-3">N° facture</th><th class="px-3 py-3">Ticket</th><th class="px-3 py-3">Date</th><th class="px-3 py-3">Échéance</th><th class="px-3 py-3">Client</th><th class="px-3 py-3 text-right">Total</th><th class="px-3 py-3 text-right">Action</th></tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 dark:divide-white/10">
                                @forelse ($saleInvoices as $invoice)
                                    <tr class="transition hover:bg-slate-50/80 dark:hover:bg-white/5" data-row-url="{{ route('module', ['module' => 'sales', 'section' => 'list', 'invoice' => $invoice->id]) }}">
                                        <td class="px-3 py-3 font-mono text-xs font-semibold">{{ $invoice->number }}</td>
                                        <td class="px-3 py-3 font-semibold">{{ $invoice->sale?->number ?? '—' }}</td>
                                        <td class="px-3 py-3">{{ $invoice->issued_at?->format('d/m/Y H:i') ?? '—' }}</td>
                                        <td class="px-3 py-3">{{ $invoice->due_date?->format('d/m/Y') ?? '—' }}</td>
                                        <td class="px-3 py-3">{{ $invoice->contact?->name ?? $invoice->sale?->contact?->name ?? 'Client Grand Public' }}</td>
                                        <td class="px-3 py-3 text-right font-semibold">{{ $money($invoice->total_amount) }}</td>
                                        <td class="px-3 py-3 text-right">
                                            <div class="flex justify-end gap-2">
                                                <a href="{{ route('module', ['module' => 'sales', 'section' => 'list', 'invoice' => $invoice->id]) }}" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold hover:border-brand hover:text-brand dark:border-white/10">Voir</a>
                                                <a href="{{ route('sales.invoices.pdf', $invoice) }}" class="rounded-lg bg-brand px-3 py-2 text-xs font-semibold text-white">PDF</a>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" class="px-4 py-12 text-center text-sm text-slate-500">Aucune facture créée. Créez une facture depuis la liste des ventes.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="border-t border-slate-200 px-4 py-3 dark:border-white/10">{{ $saleInvoices->links() }}</div>
                </article>
            </section>
        @elseif ($salesSection === 'payments')
            <section class="mt-6 space-y-5">
                <div class="grid gap-3 md:grid-cols-3">
                    <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><span class="text-xs font-semibold uppercase text-slate-500">Paiements enregistrés</span><p class="mt-2 text-2xl font-semibold">{{ $salePayments->total() }}</p></article>
                    <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><span class="text-xs font-semibold uppercase text-slate-500">Montant page</span><p class="mt-2 text-2xl font-semibold text-emerald-600">{{ $money($salePayments->sum('amount')) }}</p></article>
                    <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><span class="text-xs font-semibold uppercase text-slate-500">Ventes disponibles</span><p class="mt-2 text-2xl font-semibold">{{ $paymentSales->count() }}</p></article>
                </div>
                <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <form action="{{ route('sales.payments.store') }}" method="POST" class="grid gap-3 lg:grid-cols-[1fr_150px_140px_170px_1fr_auto]">
                        @csrf
                        <select name="sale_id" class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900" required>
                            <option value="">Vente à payer</option>
                            @foreach ($paymentSales as $sale)
                                <option value="{{ $sale->id }}">{{ $sale->number }} · {{ $sale->contact?->name ?? 'Client comptoir' }} · {{ $money($sale->total_amount) }}</option>
                            @endforeach
                        </select>
                        <select name="method" class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="cash">Espèces</option><option value="card">Carte</option><option value="transfer">Virement</option><option value="advance">Avance</option></select>
                        <input name="amount" inputmode="decimal" class="h-11 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Montant DH" required>
                        <input name="reference" class="h-11 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Référence">
                        <input name="note" class="h-11 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Note">
                        <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Ajouter</button>
                    </form>
                </article>
                <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <form class="app-action-form" method="GET" action="{{ route('module', ['module' => 'sales', 'section' => 'payments']) }}">
                        <input type="hidden" name="section" value="payments">
                        <input name="q" value="{{ request('q') }}" class="h-11 rounded-lg border border-slate-200 bg-slate-50 px-3 text-sm dark:border-white/10 dark:bg-white/5" placeholder="Paiement, vente, client...">
                        <input name="from" value="{{ request('from') }}" type="date" class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">
                        <input name="to" value="{{ request('to') }}" type="date" class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">
                        <select name="payment_method" class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="">Méthode</option><option value="cash" @selected(request('payment_method') === 'cash')>Espèces</option><option value="card" @selected(request('payment_method') === 'card')>Carte</option><option value="transfer" @selected(request('payment_method') === 'transfer')>Virement</option><option value="advance" @selected(request('payment_method') === 'advance')>Avance</option></select>
                        <div class="flex gap-2"><button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Filtrer</button><a href="{{ route('module', ['module' => 'sales', 'section' => 'payments']) }}" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold dark:border-white/10">Reset</a></div>
                    </form>
                </article>
                <article class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <div class="overflow-x-auto"><table class="w-full min-w-[960px] text-left text-sm"><thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-white/5"><tr><th class="px-3 py-3">N° paiement</th><th class="px-3 py-3">Vente</th><th class="px-3 py-3">Client</th><th class="px-3 py-3">Date</th><th class="px-3 py-3">Méthode</th><th class="px-3 py-3 text-right">Montant</th><th class="px-3 py-3">Référence</th></tr></thead><tbody class="divide-y divide-slate-200 dark:divide-white/10">@forelse ($salePayments as $payment)<tr><td class="px-3 py-3 font-semibold">{{ $payment->number }}</td><td class="px-3 py-3">{{ $payment->sale?->number }}</td><td class="px-3 py-3">{{ $payment->contact?->name ?? 'Client comptoir' }}</td><td class="px-3 py-3">{{ $payment->paid_at?->format('d/m/Y H:i') }}</td><td class="px-3 py-3"><x-status-pill tone="info">{{ $payment->method }}</x-status-pill></td><td class="px-3 py-3 text-right font-semibold">{{ $money($payment->amount) }}</td><td class="px-3 py-3 text-slate-500">{{ $payment->reference ?? '—' }}</td></tr>@empty<tr><td colspan="7" class="px-4 py-12 text-center text-sm text-slate-500">Aucun paiement trouvé.</td></tr>@endforelse</tbody></table></div><div class="border-t border-slate-200 px-4 py-3 dark:border-white/10">{{ $salePayments->links() }}</div>
                </article>
            </section>
        @elseif ($salesSection === 'returns')
            <section class="mt-6 space-y-5">
                <div class="grid gap-3 md:grid-cols-3">
                    <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><span class="text-xs font-semibold uppercase text-slate-500">Retours</span><p class="mt-2 text-2xl font-semibold">{{ $saleReturns->total() }}</p></article>
                    <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><span class="text-xs font-semibold uppercase text-slate-500">Montant page</span><p class="mt-2 text-2xl font-semibold text-rose-600">{{ $money($saleReturns->sum('total_amount')) }}</p></article>
                    <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><span class="text-xs font-semibold uppercase text-slate-500">Avec restock</span><p class="mt-2 text-2xl font-semibold">{{ $saleReturns->where('restock', true)->count() }}</p></article>
                </div>
                <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <form class="app-action-form" method="GET" action="{{ route('module', ['module' => 'sales', 'section' => 'returns']) }}">
                        <input type="hidden" name="section" value="returns"><input name="q" value="{{ request('q') }}" class="h-11 rounded-lg border border-slate-200 bg-slate-50 px-3 text-sm dark:border-white/10 dark:bg-white/5" placeholder="Retour, vente, client, motif..."><input name="from" value="{{ request('from') }}" type="date" class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><input name="to" value="{{ request('to') }}" type="date" class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><select name="refund_method" class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="">Remboursement</option><option value="cash" @selected(request('refund_method') === 'cash')>Espèces</option><option value="card" @selected(request('refund_method') === 'card')>Carte</option><option value="transfer" @selected(request('refund_method') === 'transfer')>Virement</option><option value="credit" @selected(request('refund_method') === 'credit')>Avoir</option></select><div class="flex gap-2"><button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Filtrer</button><a href="{{ route('module', ['module' => 'sales', 'section' => 'returns']) }}" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold dark:border-white/10">Reset</a></div>
                    </form>
                </article>
                <article class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <div class="overflow-x-auto"><table class="w-full min-w-[1040px] text-left text-sm"><thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-white/5"><tr><th class="px-3 py-3">N° retour</th><th class="px-3 py-3">Vente</th><th class="px-3 py-3">Client</th><th class="px-3 py-3">Date</th><th class="px-3 py-3">Méthode</th><th class="px-3 py-3">Stock</th><th class="px-3 py-3 text-right">Montant</th><th class="px-3 py-3 text-right">Action</th></tr></thead><tbody class="divide-y divide-slate-200 dark:divide-white/10">@forelse ($saleReturns as $return)<tr><td class="px-3 py-3 font-semibold">{{ $return->number }}</td><td class="px-3 py-3">{{ $return->sale?->number }}</td><td class="px-3 py-3">{{ $return->contact?->name ?? 'Client comptoir' }}</td><td class="px-3 py-3">{{ $return->returned_at?->format('d/m/Y H:i') }}</td><td class="px-3 py-3">{{ $return->refund_method }}</td><td class="px-3 py-3"><x-status-pill :tone="$return->restock ? 'success' : 'warning'">{{ $return->restock ? 'Restocké' : 'Sans restock' }}</x-status-pill></td><td class="px-3 py-3 text-right font-semibold">{{ $money($return->total_amount) }}</td><td class="px-3 py-3 text-right"><button class="rounded-lg bg-brand px-3 py-2 text-xs font-semibold text-white" type="button" onclick="document.getElementById('return-detail-{{ $return->id }}').showModal()">Détail</button></td></tr><dialog id="return-detail-{{ $return->id }}" class="w-full max-w-2xl rounded-2xl border border-slate-200 bg-white p-0 text-slate-950 shadow-2xl backdrop:bg-slate-950/40 dark:border-white/10 dark:bg-slate-950 dark:text-slate-100"><div class="border-b border-slate-200 p-5 dark:border-white/10"><div class="flex justify-between gap-4"><div><p class="text-sm font-semibold text-brand">Détail retour</p><h3 class="mt-1 text-xl font-semibold">{{ $return->number }} · {{ $money($return->total_amount) }}</h3><p class="mt-1 text-sm text-slate-500">{{ $return->reason ?? 'Sans motif' }}</p></div><button class="dialog-close grid size-9 place-items-center rounded-lg border border-slate-200 dark:border-white/10" type="button">×</button></div></div><div class="p-5 space-y-2">@foreach ($return->lines as $line)<div class="flex justify-between rounded-lg bg-slate-50 px-3 py-2 text-sm dark:bg-white/5"><span>{{ $line['quantity'] }} x {{ $line['name'] }}</span><strong>{{ $money($line['total_price']) }}</strong></div>@endforeach</div></dialog>@empty<tr><td colspan="8" class="px-4 py-12 text-center text-sm text-slate-500">Aucun retour trouvé.</td></tr>@endforelse</tbody></table></div><div class="border-t border-slate-200 px-4 py-3 dark:border-white/10">{{ $saleReturns->links() }}</div>
                </article>
            </section>
        @elseif ($salesSection === 'delivery')
            <section class="mt-6 space-y-5">
                <div class="grid gap-3 md:grid-cols-4">
                    @foreach (['pending' => 'En attente', 'preparing' => 'Préparation', 'dispatched' => 'Expédiée', 'delivered' => 'Livrée'] as $key => $label)
                        <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><span class="text-xs font-semibold uppercase text-slate-500">{{ $label }}</span><p class="mt-2 text-2xl font-semibold">{{ $deliveryOrders->where('status', $key)->count() }}</p></article>
                    @endforeach
                </div>
                <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <form action="{{ route('sales.deliveries.store') }}" method="POST" class="app-action-form">@csrf<select name="sale_id" class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900" required><option value="">Créer depuis une vente</option>@foreach ($deliverySales as $sale)<option value="{{ $sale->id }}">{{ $sale->number }} · {{ $sale->contact?->name ?? 'Client comptoir' }}</option>@endforeach</select><input name="assigned_to" class="h-11 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Livreur"><input name="scheduled_at" type="datetime-local" class="h-11 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"><input name="delivery_address" class="h-11 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Adresse de livraison"><button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Créer</button></form>
                </article>
                <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><form class="app-action-form" method="GET" action="{{ route('module', ['module' => 'sales', 'section' => 'delivery']) }}"><input type="hidden" name="section" value="delivery"><input name="q" value="{{ request('q') }}" class="h-11 rounded-lg border border-slate-200 bg-slate-50 px-3 text-sm dark:border-white/10 dark:bg-white/5" placeholder="Livraison, vente, client, livreur..."><input name="from" value="{{ request('from') }}" type="date" class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><input name="to" value="{{ request('to') }}" type="date" class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><select name="delivery_status" class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="">Tous statuts</option><option value="pending" @selected(request('delivery_status') === 'pending')>En attente</option><option value="preparing" @selected(request('delivery_status') === 'preparing')>Préparation</option><option value="dispatched" @selected(request('delivery_status') === 'dispatched')>Expédiée</option><option value="delivered" @selected(request('delivery_status') === 'delivered')>Livrée</option><option value="failed" @selected(request('delivery_status') === 'failed')>Échouée</option></select><div class="flex gap-2"><button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Filtrer</button><a href="{{ route('module', ['module' => 'sales', 'section' => 'delivery']) }}" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold dark:border-white/10">Reset</a></div></form></article>
                <article class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><div class="overflow-x-auto"><table class="w-full min-w-[1080px] text-left text-sm"><thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-white/5"><tr><th class="px-3 py-3">N° livraison</th><th class="px-3 py-3">Vente</th><th class="px-3 py-3">Client</th><th class="px-3 py-3">Livreur</th><th class="px-3 py-3">Planifiée</th><th class="px-3 py-3">Statut</th><th class="px-3 py-3">Adresse</th><th class="px-3 py-3 text-right">Action</th></tr></thead><tbody class="divide-y divide-slate-200 dark:divide-white/10">@forelse ($deliveryOrders as $delivery)<tr><td class="px-3 py-3 font-semibold">{{ $delivery->number }}</td><td class="px-3 py-3">{{ $delivery->sale?->number }}</td><td class="px-3 py-3">{{ $delivery->contact?->name ?? 'Client comptoir' }}</td><td class="px-3 py-3">{{ $delivery->assigned_to ?? '—' }}</td><td class="px-3 py-3">{{ $delivery->scheduled_at?->format('d/m/Y H:i') ?? '—' }}</td><td class="px-3 py-3"><x-status-pill :tone="$delivery->status === 'delivered' ? 'success' : ($delivery->status === 'failed' ? 'danger' : 'warning')">{{ $delivery->status }}</x-status-pill></td><td class="px-3 py-3 max-w-xs truncate">{{ $delivery->delivery_address ?? '—' }}</td><td class="px-3 py-3"><form action="{{ route('sales.deliveries.update', $delivery) }}" method="POST" class="flex justify-end gap-2">@csrf @method('PATCH')<select name="status" class="h-9 rounded-lg border border-slate-200 bg-white px-2 text-xs dark:border-white/10 dark:bg-slate-900"><option value="pending" @selected($delivery->status === 'pending')>En attente</option><option value="preparing" @selected($delivery->status === 'preparing')>Préparation</option><option value="dispatched" @selected($delivery->status === 'dispatched')>Expédiée</option><option value="delivered" @selected($delivery->status === 'delivered')>Livrée</option><option value="failed" @selected($delivery->status === 'failed')>Échouée</option></select><button class="rounded-lg bg-brand px-3 py-2 text-xs font-semibold text-white">OK</button></form></td></tr>@empty<tr><td colspan="8" class="px-4 py-12 text-center text-sm text-slate-500">Aucune livraison trouvée.</td></tr>@endforelse</tbody></table></div><div class="border-t border-slate-200 px-4 py-3 dark:border-white/10">{{ $deliveryOrders->links() }}</div></article>
            </section>
        @else
        @php
            $salesAllowEdit = (bool) data_get($tenant->settings, 'pos.allow_sale_edit', true);
        @endphp
        <section class="mt-6 space-y-5">
            <div class="grid gap-3 md:grid-cols-3">
                <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <span class="text-xs font-semibold uppercase text-slate-500">Total ventes</span>
                    <p class="mt-2 text-2xl font-semibold">{{ $money($salesTotals['total'] ?? 0) }}</p>
                </article>
                <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <span class="text-xs font-semibold uppercase text-slate-500">Paiement payé</span>
                    <p class="mt-2 text-2xl font-semibold text-emerald-600">{{ $money($salesTotals['paid'] ?? 0) }}</p>
                </article>
                <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <span class="text-xs font-semibold uppercase text-slate-500">Reste client</span>
                    <p class="mt-2 text-2xl font-semibold text-rose-600">{{ $money($salesTotals['due'] ?? 0) }}</p>
                </article>
            </div>

            <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                <form class="flex flex-wrap items-end gap-3" method="GET" action="{{ route('module', 'sales') }}">
                    <input name="q" value="{{ request('q') }}" class="h-11 min-w-[220px] flex-[1_1_260px] rounded-lg border border-slate-200 bg-slate-50 px-3 text-sm outline-none focus:border-brand focus:ring-4 focus:ring-brand/10 dark:border-white/10 dark:bg-white/5" placeholder="Rechercher code, client, paiement...">
                    <input name="from" value="{{ request('from') }}" type="date" class="h-11 min-w-[150px] flex-[1_1_150px] rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">
                    <input name="to" value="{{ request('to') }}" type="date" class="h-11 min-w-[150px] flex-[1_1_150px] rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">
                    <select name="client" class="h-11 min-w-[190px] flex-[1_1_210px] rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">
                        <option value="">Tous les clients</option>
                        @foreach ($salesClients as $client)
                            <option value="{{ $client->id }}" @selected((string) request('client') === (string) $client->id)>{{ $client->name }}</option>
                        @endforeach
                    </select>
                    <select name="payment_status" class="h-11 min-w-[150px] flex-[1_1_160px] rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">
                        <option value="">Tous statuts</option>
                        <option value="paid" @selected(request('payment_status') === 'paid')>payé</option>
                        <option value="partial" @selected(request('payment_status') === 'partial')>Partiel</option>
                        <option value="unpaid" @selected(request('payment_status') === 'unpaid')>Impayé</option>
                        <option value="refunded" @selected(request('payment_status') === 'refunded')>Remboursé</option>
                        <option value="cancelled" @selected(request('payment_status') === 'cancelled')>Annulé</option>
                    </select>
                    <select name="payment_method" class="h-11 min-w-[150px] flex-[1_1_160px] rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">
                        <option value="">Tous paiements</option>
                        <option value="cash" @selected(request('payment_method') === 'cash')>Espèces</option>
                        <option value="card" @selected(request('payment_method') === 'card')>Carte</option>
                        <option value="transfer" @selected(request('payment_method') === 'transfer')>Virement</option>
                        <option value="advance" @selected(request('payment_method') === 'advance')>Avance</option>
                        <option value="mixed" @selected(request('payment_method') === 'mixed')>Mixte</option>
                    </select>
                    <input name="min_total" value="{{ request('min_total') }}" inputmode="decimal" class="h-11 min-w-[105px] flex-[1_1_110px] rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Min DH">
                    <input name="max_total" value="{{ request('max_total') }}" inputmode="decimal" class="h-11 min-w-[105px] flex-[1_1_110px] rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Max DH">
                    <div class="grid min-w-[180px] flex-[0_1_220px] grid-cols-2 gap-2 max-sm:flex-1">
                        <button class="h-11 rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white" type="submit">Filtrer</button>
                        <a href="{{ route('module', 'sales') }}" class="grid h-11 place-items-center rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold dark:border-white/10">Reset</a>
                    </div>
                </form>
            </article>

            <article class="overflow-visible rounded-xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[1280px] text-left text-sm">
                        <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-white/5">
                            <tr>
                                <th class="px-3 py-3"><input type="checkbox" class="rounded border-slate-300"></th>
                                <th class="px-3 py-3 whitespace-nowrap">N° facture</th>
                                <th class="px-3 py-3 whitespace-nowrap">Date de vente</th>
                                <th class="px-3 py-3 whitespace-nowrap">Date d'échéance</th>
                                <th class="px-3 py-3 whitespace-nowrap">Code de vente</th>
                                <th class="px-3 py-3 whitespace-nowrap">Numéro de référence</th>
                                <th class="px-3 py-3 whitespace-nowrap">Nom du client</th>
                                <th class="px-3 py-3 text-right whitespace-nowrap">Total</th>
                                <th class="px-3 py-3 text-right whitespace-nowrap">Payé</th>
                                <th class="px-3 py-3 whitespace-nowrap">Statut de paiement</th>
                                <th class="sticky right-0 bg-slate-50 px-3 py-3 text-right whitespace-nowrap dark:bg-slate-900">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-white/10">
                            @forelse ($sales as $sale)
                                @php
                                    $paidFromPayments = (float) $sale->payments->sum('amount');
                                    $metadataPaid = data_get($sale->metadata, 'paid_amount');
                                    $paid = min(max($paidFromPayments, $metadataPaid !== null ? (float) $metadataPaid : 0, $sale->status === 'paid' ? (float) $sale->total_amount : 0), (float) $sale->total_amount);
                                    $due = max(0, (float) $sale->total_amount - (float) $paid);
                                    $paymentStatus = match ($sale->status) {
                                        'refunded' => 'Remboursé',
                                        'cancelled' => 'Annulé',
                                        default => $due <= 0.001 ? 'payé' : ((float) $paid > 0 ? 'Partiel' : 'Impayé'),
                                    };
                                    $statusTone = $paymentStatus === 'payé' ? 'success' : ($paymentStatus === 'Partiel' ? 'warning' : (in_array($paymentStatus, ['Remboursé', 'Annulé'], true) ? 'info' : 'danger'));
                                    $canReceivePayment = $due > 0.001 && ! in_array($sale->status, ['paid', 'refunded', 'cancelled'], true);
                                    $invoice = $sale->invoice;
                                    $invoiceNumber = $invoice?->number;
                                    $invoiceDueDate = $invoice?->due_date?->toDateString() ?? data_get($sale->metadata, 'due_date');
                                    $invoiceGenerated = (bool) $invoice;
                                    $systemNoteDate = $sale->sold_at?->format('d/m/Y H:i') ?? '—';
                                    $systemNote = data_get($sale->metadata, 'system_note')
                                        ?: 'Note système: vente '.$sale->number.' enregistrée le '.$systemNoteDate.' pour '.($sale->contact?->name ?? 'Client comptoir').', '.$sale->items->count().' ligne(s), total '.$money($sale->total_amount).', paiement '.$sale->payment_method.'.';
                                @endphp
                                <tr class="transition hover:bg-slate-50/80 dark:hover:bg-white/5">
                                    <td class="px-3 py-3"><input type="checkbox" class="rounded border-slate-300" value="{{ $sale->id }}"></td>
                                    <td class="px-3 py-3 whitespace-nowrap text-slate-500">{{ $invoiceNumber ?? '—' }}</td>
                                    <td class="px-3 py-3 whitespace-nowrap">{{ $sale->sold_at?->format('d/m/Y H:i') }}</td>
                                    <td class="px-3 py-3 whitespace-nowrap">{{ $invoiceDueDate ? \Illuminate\Support\Carbon::parse($invoiceDueDate)->format('d/m/Y') : '—' }}</td>
                                    <td class="px-3 py-3 whitespace-nowrap font-mono text-xs font-semibold">{{ $sale->number }}</td>
                                    <td class="px-3 py-3 whitespace-nowrap text-slate-500">{{ data_get($sale->metadata, 'reference_number', '—') }}</td>
                                    <td class="px-3 py-3 min-w-48 font-medium">{{ $sale->contact?->name ?? 'Client Grand Public' }}</td>
                                    <td class="px-3 py-3 text-right font-semibold whitespace-nowrap">{{ $money($sale->total_amount) }}</td>
                                    <td class="px-3 py-3 text-right font-semibold whitespace-nowrap">{{ $money($paid) }}</td>
                                    <td class="px-3 py-3 whitespace-nowrap"><x-status-pill :tone="$statusTone">{{ $paymentStatus }}</x-status-pill></td>
                                    <td class="sticky right-0 bg-white px-3 py-3 dark:bg-slate-950">
                                        <details class="sale-action-menu" data-sale-action-menu>
                                            <summary>Action</summary>
                                            <div class="sale-action-panel">
                                                <button type="button" onclick="document.getElementById('sale-detail-{{ $sale->id }}').showModal()"><span>VO</span> Voir détail</button>
                                                @if ($salesAllowEdit)
                                                    <button type="button" onclick="document.getElementById('sale-edit-{{ $sale->id }}').showModal()"><span>ED</span> Modifier vente</button>
                                                @else
                                                    <button type="button" disabled><span>LK</span> Modification verrouillée</button>
                                                @endif
                                                <button type="button" onclick="document.getElementById('sale-payments-{{ $sale->id }}').showModal()"><span>PY</span> Voir paiements</button>
                                                @if ($canReceivePayment)
                                                    <button type="button" onclick="document.getElementById('sale-payment-add-{{ $sale->id }}').showModal()"><span>RC</span> Recevoir paiement</button>
                                                @else
                                                    <button type="button" disabled><span>OK</span> Paiement soldé</button>
                                                @endif
                                                <form action="{{ route('sales.invoice.store', $sale) }}" method="POST">@csrf<button type="submit"><span>FA</span> {{ $invoiceGenerated ? 'Mettre à jour facture' : 'Créer facture' }}</button></form>
                                                <button type="button" onclick="document.getElementById('sale-invoice-{{ $sale->id }}').showModal()"><span>{{ $invoiceGenerated ? 'FA' : 'BL' }}</span> {{ $invoiceGenerated ? 'Voir facture' : 'Préparer facture / BL' }}</button>
                                                <a href="{{ route('sales.pdf', $sale) }}"><span>PDF</span> Télécharger vente</a>
                                                @if ($invoiceGenerated)
                                                    <a href="{{ route('sales.invoices.pdf', $invoice) }}"><span>FA</span> Télécharger facture</a>
                                                @endif
                                                <button type="button" onclick="document.getElementById('sale-detail-{{ $sale->id }}').showModal()"><span>TP</span> Voir ticket POS</button>
                                                @if ($sale->status !== 'refunded' && $sale->status !== 'cancelled')
                                                    <button type="button" onclick="document.getElementById('sale-refund-{{ $sale->id }}').showModal()"><span>RT</span> Retour vente</button>
                                                    <button type="button" onclick="document.getElementById('sale-delivery-{{ $sale->id }}').showModal()"><span>LV</span> Ajouter livraison</button>
                                                    <form action="{{ route('sales.destroy', $sale) }}" method="POST" onsubmit="return confirm('Annuler cette vente ? Cette action garde une trace dans l’historique.')">@csrf @method('DELETE')<button type="submit" class="is-danger"><span>DL</span> Supprimer</button></form>
                                                @endif
                                            </div>
                                        </details>
                                    </td>
                                </tr>
                                <dialog id="sale-detail-{{ $sale->id }}" class="app-dialog w-[min(1080px,calc(100vw-2rem))] rounded-2xl border border-slate-200 bg-white p-0 text-slate-950 shadow-2xl backdrop:bg-slate-950/45 dark:border-white/10 dark:bg-slate-950 dark:text-slate-100">
                                    <div class="flex max-h-[calc(100dvh-2rem)] flex-col overflow-hidden">
                                        <div class="border-b border-slate-200 bg-slate-50/80 p-5 dark:border-white/10 dark:bg-white/[0.04]">
                                            <div class="flex items-start justify-between gap-4">
                                                <div class="min-w-0">
                                                    <p class="text-sm font-semibold text-brand">Détail ticket</p>
                                                    <div class="mt-1 flex flex-wrap items-center gap-2">
                                                        <h3 class="text-2xl font-semibold tracking-tight">{{ $sale->number }}</h3>
                                                        <x-status-pill :tone="$statusTone">{{ $paymentStatus }}</x-status-pill>
                                                        @if ($invoiceGenerated)
                                                            <x-status-pill tone="info">{{ $invoiceNumber }}</x-status-pill>
                                                        @endif
                                                    </div>
                                                    <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">{{ $sale->contact?->name ?? 'Client Grand Public' }} · {{ $sale->sold_at?->format('d/m/Y H:i') }} · {{ $sale->items->count() }} ligne(s)</p>
                                                </div>
                                                <button class="dialog-close grid size-9 shrink-0 place-items-center rounded-lg border border-slate-200 bg-white text-lg font-semibold text-slate-600 transition hover:border-rose-200 hover:text-rose-600 dark:border-white/10 dark:bg-slate-950 dark:text-slate-200" type="button">×</button>
                                            </div>
                                        </div>

                                        <div class="min-h-0 overflow-y-auto p-5">
                                            <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_320px]">
                                                <div class="space-y-4">
                                                    <div class="grid gap-3 md:grid-cols-3">
                                                        <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-white/10 dark:bg-white/[0.03]">
                                                            <span class="text-xs font-semibold uppercase text-slate-500">Client</span>
                                                            <strong class="mt-1 block truncate">{{ $sale->contact?->name ?? 'Client Grand Public' }}</strong>
                                                            <span class="mt-1 block text-xs text-slate-500">{{ $sale->contact?->phone ?? 'Sans téléphone' }}</span>
                                                        </div>
                                                        <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-white/10 dark:bg-white/[0.03]">
                                                            <span class="text-xs font-semibold uppercase text-slate-500">Référence</span>
                                                            <strong class="mt-1 block truncate">{{ data_get($sale->metadata, 'reference_number', '—') }}</strong>
                                                            <span class="mt-1 block text-xs text-slate-500">Échéance {{ $invoiceDueDate ? \Illuminate\Support\Carbon::parse($invoiceDueDate)->format('d/m/Y') : '—' }}</span>
                                                        </div>
                                                        <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-white/10 dark:bg-white/[0.03]">
                                                            <span class="text-xs font-semibold uppercase text-slate-500">Opérations liées</span>
                                                            <strong class="mt-1 block">{{ $sale->payments->count() }} paiement(s)</strong>
                                                            <span class="mt-1 block text-xs text-slate-500">{{ $sale->deliveryOrders->count() }} livraison(s) · {{ $sale->returns->count() }} retour(s)</span>
                                                        </div>
                                                    </div>

                                                    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-white/10 dark:bg-white/[0.03]">
                                                        <div class="flex items-center justify-between gap-3 border-b border-slate-200 bg-slate-50 px-4 py-3 dark:border-white/10 dark:bg-white/[0.04]">
                                                            <div>
                                                                <h4 class="text-sm font-semibold">Articles du ticket</h4>
                                                                <p class="mt-0.5 text-xs text-slate-500">Liste scrollable pour les tickets longs.</p>
                                                            </div>
                                                            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600 dark:bg-white/10 dark:text-slate-300">{{ $sale->items->count() }} ligne(s)</span>
                                                        </div>
                                                        <div class="max-h-[360px] overflow-y-auto">
                                                            <div class="hidden grid-cols-[minmax(0,1fr)_90px_120px_130px] gap-3 border-b border-slate-200 px-4 py-2 text-xs font-semibold uppercase text-slate-500 dark:border-white/10 md:grid">
                                                                <span>Article</span>
                                                                <span class="text-center">Qté</span>
                                                                <span class="text-right">Prix unit.</span>
                                                                <span class="text-right">Total</span>
                                                            </div>
                                                            @foreach ($sale->items as $line)
                                                                <div class="grid gap-2 border-b border-slate-100 px-4 py-3 text-sm last:border-b-0 dark:border-white/10 md:grid-cols-[minmax(0,1fr)_90px_120px_130px] md:items-center md:gap-3">
                                                                    <div class="min-w-0">
                                                                        <strong class="block truncate">{{ $line->name }}</strong>
                                                                        <span class="mt-1 block text-xs text-slate-500">{{ $line->item?->item_code ?? $line->item?->barcode ?? 'Ligne ticket' }}</span>
                                                                    </div>
                                                                    <span class="font-semibold md:text-center">x{{ number_format((float) $line->quantity, 0, ',', ' ') }}</span>
                                                                    <span class="text-slate-500 md:text-right">{{ $money($line->unit_price) }}</span>
                                                                    <strong class="text-right">{{ $money($line->total_price) }}</strong>
                                                                </div>
                                                            @endforeach
                                                        </div>
                                                    </section>

                                                    @if (data_get($sale->metadata, 'note'))
                                                        <div class="rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-900 shadow-sm dark:border-white/10 dark:bg-slate-900 dark:text-slate-100">
                                                            <span class="text-xs font-semibold uppercase text-slate-600 dark:text-slate-300">Note manuelle</span>
                                                            <p class="mt-2 leading-6">{{ data_get($sale->metadata, 'note') }}</p>
                                                        </div>
                                                    @endif
                                                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-900 dark:border-white/10 dark:bg-slate-900 dark:text-slate-100">
                                                        <span class="text-xs font-semibold uppercase text-slate-600 dark:text-slate-300">Note système</span>
                                                        <p class="mt-2 leading-6">{{ $systemNote }}</p>
                                                    </div>
                                                </div>

                                                <aside class="space-y-4">
                                                    <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-white/10 dark:bg-white/[0.03]">
                                                        <h4 class="text-sm font-semibold">Résumé financier</h4>
                                                        <dl class="mt-4 space-y-2 text-sm">
                                                            <div class="flex justify-between gap-3"><dt class="text-slate-500">Sous-total</dt><dd class="font-semibold">{{ $money($sale->subtotal_amount) }}</dd></div>
                                                            <div class="flex justify-between gap-3"><dt class="text-slate-500">Remise</dt><dd class="font-semibold">{{ $money($sale->discount_amount) }}</dd></div>
                                                            <div class="flex justify-between gap-3"><dt class="text-slate-500">TVA incluse</dt><dd class="font-semibold">{{ $money($sale->tax_amount) }}</dd></div>
                                                            <div class="flex justify-between gap-3 border-t border-slate-200 pt-3 text-base dark:border-white/10"><dt class="font-semibold">Total</dt><dd class="font-bold">{{ $money($sale->total_amount) }}</dd></div>
                                                            <div class="flex justify-between gap-3"><dt class="text-slate-500">Payé</dt><dd class="font-semibold text-emerald-600">{{ $money($paid) }}</dd></div>
                                                            <div class="flex justify-between gap-3"><dt class="text-slate-500">Reste</dt><dd class="font-semibold {{ $due > 0.001 ? 'text-rose-600' : 'text-emerald-600' }}">{{ $money($due) }}</dd></div>
                                                            <div class="flex justify-between gap-3"><dt class="text-slate-500">Méthode</dt><dd class="font-semibold">{{ $sale->payment_method }}</dd></div>
                                                        </dl>
                                                    </div>

                                                    <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-white/10 dark:bg-white/[0.03]">
                                                        <h4 class="text-sm font-semibold">Documents</h4>
                                                        <div class="mt-3 grid gap-2 text-sm">
                                                            <div class="flex justify-between gap-3 rounded-lg bg-slate-50 px-3 py-2 dark:bg-white/5"><span class="text-slate-500">Facture</span><strong>{{ $invoiceNumber ?? 'Non créée' }}</strong></div>
                                                            <div class="flex justify-between gap-3 rounded-lg bg-slate-50 px-3 py-2 dark:bg-white/5"><span class="text-slate-500">Date vente</span><strong>{{ $sale->sold_at?->format('d/m/Y') }}</strong></div>
                                                            <div class="flex justify-between gap-3 rounded-lg bg-slate-50 px-3 py-2 dark:bg-white/5"><span class="text-slate-500">Magasin</span><strong>{{ $currentStore['name'] ?? $tenant->name }}</strong></div>
                                                        </div>
                                                    </div>

                                                    @if ($sale->status === 'cancelled' || $sale->status === 'refunded')
                                                        <div class="rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-800 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-100">
                                                            @if ($sale->status === 'cancelled')
                                                                Vente annulée le {{ data_get($sale->metadata, 'cancelled.cancelled_at') ? \Illuminate\Support\Carbon::parse(data_get($sale->metadata, 'cancelled.cancelled_at'))->format('d/m/Y H:i') : '—' }}.
                                                            @else
                                                                Vente remboursée le {{ data_get($sale->metadata, 'refund.refunded_at') ? \Illuminate\Support\Carbon::parse(data_get($sale->metadata, 'refund.refunded_at'))->format('d/m/Y H:i') : '—' }}.
                                                            @endif
                                                        </div>
                                                    @endif
                                                </aside>
                                            </div>
                                        </div>

                                        <div class="flex flex-col gap-2 border-t border-slate-200 bg-white p-4 dark:border-white/10 dark:bg-slate-950 sm:flex-row sm:items-center sm:justify-between">
                                            <div class="flex flex-wrap gap-2">
                                                <button class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold transition hover:border-brand hover:text-brand dark:border-white/10 dark:bg-slate-950" type="button" onclick="window.print()">Imprimer ticket</button>
                                                <a href="{{ route('sales.pdf', $sale) }}" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-center text-sm font-semibold transition hover:border-brand hover:text-brand dark:border-white/10 dark:bg-slate-950">PDF vente</a>
                                                @if ($invoiceGenerated)
                                                    <a href="{{ route('sales.invoices.pdf', $invoice) }}" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-center text-sm font-semibold transition hover:border-brand hover:text-brand dark:border-white/10 dark:bg-slate-950">PDF facture</a>
                                                @else
                                                    <form action="{{ route('sales.invoice.store', $sale) }}" method="POST">@csrf<button class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold transition hover:border-brand hover:text-brand dark:border-white/10 dark:bg-slate-950" type="submit">Créer facture</button></form>
                                                @endif
                                            </div>
                                            <div class="flex flex-wrap justify-end gap-2">
                                                @if ($canReceivePayment)
                                                    <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white" type="button" onclick="document.getElementById('sale-detail-{{ $sale->id }}').close(); document.getElementById('sale-payment-add-{{ $sale->id }}').showModal()">Recevoir paiement</button>
                                                @endif
                                                @if ($sale->status !== 'refunded' && $sale->status !== 'cancelled')
                                                    <button class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold transition hover:border-rose-200 hover:text-rose-600 dark:border-white/10 dark:bg-slate-950" type="button" onclick="document.getElementById('sale-detail-{{ $sale->id }}').close(); document.getElementById('sale-refund-{{ $sale->id }}').showModal()">Retour vente</button>
                                                    <button class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold transition hover:border-brand hover:text-brand dark:border-white/10 dark:bg-slate-950" type="button" onclick="document.getElementById('sale-detail-{{ $sale->id }}').close(); document.getElementById('sale-delivery-{{ $sale->id }}').showModal()">Ajouter livraison</button>
                                                @endif
                                                <button class="dialog-close rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold dark:border-white/10 dark:bg-slate-950" type="button">Fermer</button>
                                            </div>
                                        </div>
                                    </div>
                                </dialog>
                                @if ($salesAllowEdit)
                                <dialog id="sale-edit-{{ $sale->id }}" class="app-dialog w-[min(860px,calc(100vw-2rem))] rounded-2xl border border-slate-200 bg-white p-0 text-slate-950 shadow-2xl backdrop:bg-slate-950/40 dark:border-white/10 dark:bg-slate-950 dark:text-slate-100">
                                    @php
                                        $saleEditHasErrors = (string) old('sale_edit_id') === (string) $sale->id && $errors->any();
                                        $saleEditBorder = fn (string $field) => $saleEditHasErrors && $errors->has($field) ? 'border-rose-400 ring-2 ring-rose-100 dark:border-rose-400 dark:ring-rose-500/20' : 'border-slate-200 dark:border-white/10';
                                    @endphp
                                    <form action="{{ route('sales.update', $sale) }}" method="POST" class="overflow-hidden">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="sale_edit_id" value="{{ $sale->id }}">
                                        <div class="flex items-start justify-between gap-4 border-b border-slate-200 p-5 dark:border-white/10">
                                            <div>
                                                <p class="text-sm font-semibold text-brand">Modifier vente</p>
                                                <h3 class="mt-1 text-xl font-semibold">{{ $sale->number }}</h3>
                                                <p class="mt-1 text-sm text-slate-500">Modifiez les informations administratives. Paiement et statut restent pilotés par les actions dédiées.</p>
                                            </div>
                                            <button class="dialog-close grid size-9 place-items-center rounded-lg border border-slate-200 text-lg font-semibold dark:border-white/10" type="button">×</button>
                                        </div>
                                        @if ($saleEditHasErrors)
                                            <div class="mx-5 mt-5 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-100">
                                                <strong class="block">Le formulaire contient des informations à corriger.</strong>
                                                <ul class="mt-2 list-inside list-disc space-y-1">
                                                    @foreach ($errors->all() as $message)
                                                        <li>{{ $message }}</li>
                                                    @endforeach
                                                </ul>
                                            </div>
                                        @endif
                                        <div class="grid gap-5 p-5 lg:grid-cols-[minmax(0,1fr)_280px]">
                                            <div class="grid gap-3 sm:grid-cols-2">
                                                <label class="space-y-1.5 sm:col-span-2">
                                                    <span class="text-xs font-semibold uppercase text-slate-500">Client</span>
                                                    <select name="contact_id" data-searchable-select data-placeholder="Client..." class="h-11 w-full rounded-lg {{ $saleEditBorder('contact_id') }} bg-white px-3 text-sm dark:bg-slate-900">
                                                        <option value="">Client Grand Public</option>
                                                        @foreach ($salesClients as $client)
                                                            <option value="{{ $client->id }}" @selected((int) old('contact_id', $sale->contact_id) === $client->id)>{{ $client->name }}{{ $client->phone ? ' · '.$client->phone : '' }}</option>
                                                        @endforeach
                                                    </select>
                                                    @if ($saleEditHasErrors && $errors->has('contact_id'))<span class="text-xs font-semibold text-rose-600">{{ $errors->first('contact_id') }}</span>@endif
                                                </label>
                                                <label class="space-y-1.5">
                                                    <span class="text-xs font-semibold uppercase text-slate-500">Référence</span>
                                                    <input name="reference_number" value="{{ old('reference_number', data_get($sale->metadata, 'reference_number')) }}" class="h-11 w-full rounded-lg {{ $saleEditBorder('reference_number') }} px-3 text-sm dark:bg-slate-900" placeholder="Référence client, commande...">
                                                    @if ($saleEditHasErrors && $errors->has('reference_number'))<span class="text-xs font-semibold text-rose-600">{{ $errors->first('reference_number') }}</span>@endif
                                                </label>
                                                <label class="space-y-1.5">
                                                    <span class="text-xs font-semibold uppercase text-slate-500">Date d'échéance</span>
                                                    <input name="due_date" value="{{ old('due_date', data_get($sale->metadata, 'due_date')) }}" type="date" class="h-11 w-full rounded-lg {{ $saleEditBorder('due_date') }} px-3 text-sm dark:bg-slate-900">
                                                    @if ($saleEditHasErrors && $errors->has('due_date'))<span class="text-xs font-semibold text-rose-600">{{ $errors->first('due_date') }}</span>@endif
                                                </label>
                                                <label class="space-y-1.5 sm:col-span-2">
                                                    <span class="text-xs font-semibold uppercase text-slate-500">Note interne</span>
                                                    <textarea name="note" class="min-h-28 w-full rounded-lg {{ $saleEditBorder('note') }} px-3 py-2 text-sm dark:bg-slate-900" placeholder="Note visible uniquement par l'équipe">{{ old('note', data_get($sale->metadata, 'note')) }}</textarea>
                                                    @if ($saleEditHasErrors && $errors->has('note'))<span class="text-xs font-semibold text-rose-600">{{ $errors->first('note') }}</span>@endif
                                                </label>
                                            </div>
                                            <aside class="space-y-3">
                                                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-white/10 dark:bg-white/5">
                                                    <span class="text-xs font-semibold uppercase text-slate-500">Statut calculé</span>
                                                    <div class="mt-2"><x-status-pill :tone="$statusTone">{{ $paymentStatus }}</x-status-pill></div>
                                                    <p class="mt-3 text-xs leading-5 text-slate-500">Le statut change automatiquement via les paiements, retours et annulations.</p>
                                                </div>
                                                <div class="grid gap-2 text-sm">
                                                    <div class="flex justify-between rounded-lg bg-slate-50 px-3 py-2 dark:bg-white/5"><span class="text-slate-500">Total</span><strong>{{ $money($sale->total_amount) }}</strong></div>
                                                    <div class="flex justify-between rounded-lg bg-slate-50 px-3 py-2 dark:bg-white/5"><span class="text-slate-500">Payé</span><strong class="text-emerald-600">{{ $money($paid) }}</strong></div>
                                                    <div class="flex justify-between rounded-lg bg-slate-50 px-3 py-2 dark:bg-white/5"><span class="text-slate-500">Reste</span><strong class="{{ $due > 0.001 ? 'text-rose-600' : 'text-emerald-600' }}">{{ $money($due) }}</strong></div>
                                                    <div class="flex justify-between rounded-lg bg-slate-50 px-3 py-2 dark:bg-white/5"><span class="text-slate-500">Paiements</span><strong>{{ $sale->payments->count() }}</strong></div>
                                                </div>
                                                @if ($sale->status === 'refunded' || $sale->status === 'cancelled')
                                                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs leading-5 text-amber-800 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-100">Vente clôturée: les informations administratives restent modifiables, mais aucune action financière directe n'est disponible.</div>
                                                @endif
                                            </aside>
                                        </div>
                                        <div class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-200 bg-slate-50 p-5 dark:border-white/10 dark:bg-white/5">
                                            <p class="text-xs text-slate-500">Dernière modification: {{ data_get($sale->metadata, 'edited_at') ? \Illuminate\Support\Carbon::parse(data_get($sale->metadata, 'edited_at'))->format('d/m/Y H:i') : '—' }}</p>
                                            <div class="flex flex-wrap justify-end gap-2">
                                            <button class="dialog-close rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold dark:border-white/10" type="button">Annuler</button>
                                            <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white" type="submit">Enregistrer</button>
                                            </div>
                                        </div>
                                    </form>
                                </dialog>
                                @if ((string) old('sale_edit_id') === (string) $sale->id)
                                    <script>window.addEventListener('DOMContentLoaded', () => document.getElementById('sale-edit-{{ $sale->id }}')?.showModal());</script>
                                @endif
                                @endif
                                <dialog id="sale-payments-{{ $sale->id }}" class="app-dialog w-full max-w-2xl rounded-2xl border border-slate-200 bg-white p-0 text-slate-950 shadow-2xl backdrop:bg-slate-950/40 dark:border-white/10 dark:bg-slate-950 dark:text-slate-100">
                                    <div class="border-b border-slate-200 p-5 dark:border-white/10">
                                        <div class="flex items-start justify-between gap-4">
                                            <div><p class="text-sm font-semibold text-brand">Paiements vente</p><h3 class="mt-1 text-xl font-semibold">{{ $sale->number }}</h3></div>
                                            <button class="dialog-close grid size-9 place-items-center rounded-lg border border-slate-200 text-lg font-semibold dark:border-white/10" type="button">×</button>
                                        </div>
                                    </div>
                                    <div class="space-y-3 p-5">
                                        <div class="grid gap-3 sm:grid-cols-3">
                                            <div class="rounded-xl bg-slate-50 p-3 dark:bg-white/5"><span class="text-xs uppercase text-slate-500">Total</span><strong class="mt-1 block">{{ $money($sale->total_amount) }}</strong></div>
                                            <div class="rounded-xl bg-slate-50 p-3 dark:bg-white/5"><span class="text-xs uppercase text-slate-500">Payé</span><strong class="mt-1 block text-emerald-600">{{ $money($paid) }}</strong></div>
                                            <div class="rounded-xl bg-slate-50 p-3 dark:bg-white/5"><span class="text-xs uppercase text-slate-500">Reste</span><strong class="mt-1 block text-rose-600">{{ $money($due) }}</strong></div>
                                        </div>
                                        <div class="overflow-hidden rounded-xl border border-slate-200 dark:border-white/10">
                                            @forelse ($sale->payments as $payment)
                                                <div class="grid grid-cols-[1fr_110px_120px] gap-3 border-b border-slate-200 px-3 py-2 text-sm last:border-b-0 dark:border-white/10">
                                                    <span><strong>{{ $payment->number }}</strong><small class="mt-0.5 block text-slate-500">{{ $payment->method }} · {{ $payment->paid_at?->format('d/m/Y H:i') }}</small></span>
                                                    <span class="text-slate-500">{{ $payment->reference ?? '—' }}</span>
                                                    <strong class="text-right">{{ $money($payment->amount) }}</strong>
                                                </div>
                                            @empty
                                                <div class="px-4 py-8 text-center text-sm text-slate-500">Aucun paiement enregistré.</div>
                                            @endforelse
                                        </div>
                                    </div>
                                </dialog>
                                <dialog id="sale-payment-add-{{ $sale->id }}" class="app-dialog w-full max-w-xl rounded-2xl border border-slate-200 bg-white p-0 text-slate-950 shadow-2xl backdrop:bg-slate-950/40 dark:border-white/10 dark:bg-slate-950 dark:text-slate-100">
                                    @if ($canReceivePayment)
                                        <form action="{{ route('sales.payments.store') }}" method="POST" class="p-5">
                                            @csrf
                                            <input type="hidden" name="sale_id" value="{{ $sale->id }}">
                                            <div class="flex items-start justify-between gap-4">
                                                <div><p class="text-sm font-semibold text-brand">Recevoir paiement</p><h3 class="mt-1 text-xl font-semibold">Reste {{ $money($due) }}</h3><p class="mt-1 text-sm text-slate-500">Le montant reçu ne peut pas dépasser le reste à payer.</p></div>
                                                <button class="dialog-close grid size-9 place-items-center rounded-lg border border-slate-200 text-lg font-semibold dark:border-white/10" type="button">×</button>
                                            </div>
                                            <div class="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-100">
                                                Total {{ $money($sale->total_amount) }} · déjà payé {{ $money($paid) }} · maximum accepté {{ $money($due) }}.
                                            </div>
                                            <div class="mt-5 grid gap-3 sm:grid-cols-2">
                                                <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Méthode</span><select name="method" class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="cash">Espèces</option><option value="card">Carte</option><option value="transfer">Virement</option><option value="advance">Avance</option></select></label>
                                                <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Montant *</span><input name="amount" type="number" min="0.01" max="{{ number_format($due, 2, '.', '') }}" step="0.01" value="{{ number_format($due, 2, '.', '') }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" required></label>
                                                <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Date</span><input name="paid_at" type="datetime-local" value="{{ now()->format('Y-m-d\\TH:i') }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                                                <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Référence</span><input name="reference" value="{{ $sale->number }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                                                <label class="space-y-1.5 sm:col-span-2"><span class="text-xs font-semibold uppercase text-slate-500">Note</span><textarea name="note" class="min-h-20 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-white/10 dark:bg-slate-900"></textarea></label>
                                            </div>
                                            <div class="mt-5 flex flex-wrap justify-end gap-2">
                                                <button class="dialog-close rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold dark:border-white/10" type="button">Annuler</button>
                                                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white" type="submit">Encaisser</button>
                                            </div>
                                        </form>
                                    @else
                                        <div class="p-5">
                                            <div class="flex items-start justify-between gap-4">
                                                <div><p class="text-sm font-semibold text-emerald-600">Paiement clôturé</p><h3 class="mt-1 text-xl font-semibold">{{ $sale->number }}</h3><p class="mt-1 text-sm text-slate-500">Cette vente ne peut pas recevoir de paiement supplémentaire.</p></div>
                                                <button class="dialog-close grid size-9 place-items-center rounded-lg border border-slate-200 text-lg font-semibold dark:border-white/10" type="button">×</button>
                                            </div>
                                            <div class="mt-5 grid gap-3 sm:grid-cols-3">
                                                <div class="rounded-xl bg-slate-50 p-3 dark:bg-white/5"><span class="text-xs uppercase text-slate-500">Total</span><strong class="mt-1 block">{{ $money($sale->total_amount) }}</strong></div>
                                                <div class="rounded-xl bg-slate-50 p-3 dark:bg-white/5"><span class="text-xs uppercase text-slate-500">Payé</span><strong class="mt-1 block text-emerald-600">{{ $money($paid) }}</strong></div>
                                                <div class="rounded-xl bg-slate-50 p-3 dark:bg-white/5"><span class="text-xs uppercase text-slate-500">Reste</span><strong class="mt-1 block">{{ $money($due) }}</strong></div>
                                            </div>
                                            <div class="mt-5 text-right">
                                                <button class="dialog-close rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold dark:border-white/10" type="button">Fermer</button>
                                            </div>
                                        </div>
                                    @endif
                                </dialog>
                                <dialog id="sale-invoice-{{ $sale->id }}" class="app-dialog w-full max-w-4xl rounded-2xl border border-slate-200 bg-white p-0 text-slate-950 shadow-2xl backdrop:bg-slate-950/40 dark:border-white/10 dark:bg-slate-950 dark:text-slate-100">
                                    <div class="border-b border-slate-200 p-5 dark:border-white/10">
                                        <div class="flex items-start justify-between gap-4">
                                            <div><p class="text-sm font-semibold text-brand">{{ $invoiceGenerated ? 'Facture client' : 'Création facture' }}</p><h3 class="mt-1 text-xl font-semibold">{{ $invoiceNumber ?? 'Facture à créer' }}</h3></div>
                                            <button class="dialog-close grid size-9 place-items-center rounded-lg border border-slate-200 text-lg font-semibold dark:border-white/10" type="button">×</button>
                                        </div>
                                    </div>
                                    <div class="p-5">
                                        <div class="sale-invoice-sheet rounded-2xl border border-slate-200 bg-white p-6 text-slate-950 dark:border-white/10">
                                            <div class="flex flex-wrap items-start justify-between gap-6 border-b border-slate-200 pb-5">
                                                <div><strong class="text-lg">{{ $tenant->name }}</strong><p class="mt-1 text-sm text-slate-500">{{ $tenant->phone }} · ICE {{ $tenant->ice }}</p></div>
                                                <div class="text-right"><p class="text-xs font-semibold uppercase text-slate-500">{{ $invoiceGenerated ? 'Facture' : 'Pro forma' }}</p><h4 class="text-2xl font-bold">{{ $invoiceNumber ?? 'Non créée' }}</h4><p class="text-sm text-slate-500">{{ $sale->sold_at?->format('d/m/Y') }}{{ $invoiceDueDate ? ' · échéance '.\Illuminate\Support\Carbon::parse($invoiceDueDate)->format('d/m/Y') : '' }}</p></div>
                                            </div>
                                            <div class="mt-5 grid gap-4 sm:grid-cols-2">
                                                <div class="rounded-xl bg-slate-50 p-4"><span class="text-xs font-semibold uppercase text-slate-500">Client</span><p class="mt-1 font-semibold">{{ $sale->contact?->name ?? 'Client Grand Public' }}</p><p class="text-sm text-slate-500">{{ $sale->contact?->phone ?? '' }}</p></div>
                                                <div class="rounded-xl bg-slate-50 p-4"><span class="text-xs font-semibold uppercase text-slate-500">Vente</span><p class="mt-1 font-semibold">{{ $sale->number }}</p><p class="text-sm text-slate-500">{{ $sale->payment_method }} · {{ $paymentStatus }}</p></div>
                                            </div>
                                            <div class="mt-5 overflow-hidden rounded-xl border border-slate-200">
                                                @foreach ($sale->items as $line)
                                                    <div class="grid grid-cols-[1fr_70px_110px_120px] gap-3 border-b border-slate-200 px-3 py-2 text-sm last:border-b-0">
                                                        <span class="font-medium">{{ $line->name }}</span><span class="text-center">{{ $line->quantity }}</span><span class="text-right">{{ $money($line->unit_price) }}</span><strong class="text-right">{{ $money($line->total_price) }}</strong>
                                                    </div>
                                                @endforeach
                                            </div>
                                            <div class="mt-5 ml-auto max-w-sm space-y-2 text-sm">
                                                <div class="flex justify-between"><span class="text-slate-500">Sous-total</span><strong>{{ $money($sale->subtotal_amount) }}</strong></div>
                                                <div class="flex justify-between"><span class="text-slate-500">Remise</span><strong>{{ $money($sale->discount_amount) }}</strong></div>
                                                <div class="flex justify-between"><span class="text-slate-500">TVA incluse</span><strong>{{ $money($sale->tax_amount) }}</strong></div>
                                                <div class="flex justify-between border-t border-slate-200 pt-2 text-lg"><span>Total</span><strong>{{ $money($sale->total_amount) }}</strong></div>
                                            </div>
                                            @if ($invoice?->note)
                                                <p class="mt-5 rounded-xl bg-slate-50 p-4 text-sm text-slate-600">{{ $invoice->note }}</p>
                                            @endif
                                        </div>
                                        <form action="{{ route('sales.invoice.store', $sale) }}" method="POST" class="mt-4 grid gap-3 sm:grid-cols-[180px_1fr_auto_auto]">
                                            @csrf
                                            <input name="due_date" value="{{ $invoiceDueDate }}" type="date" class="h-11 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900">
                                            <input name="invoice_note" value="{{ $invoice?->note }}" class="h-11 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Note facture">
                                            <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white" type="submit">{{ $invoiceGenerated ? 'Mettre à jour' : 'Créer facture' }}</button>
                                            @if ($invoiceGenerated)
                                                <a href="{{ route('sales.invoices.pdf', $invoice) }}" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold dark:border-white/10">Télécharger PDF</a>
                                            @endif
                                        </form>
                                    </div>
                                </dialog>
                                @if ((string) request('detail_sale') === (string) $sale->id)
                                    <script>window.addEventListener('DOMContentLoaded', () => document.getElementById('sale-detail-{{ $sale->id }}')?.showModal());</script>
                                @endif
                                @if (($invoice && (string) request('invoice') === (string) $invoice->id) || (string) request('ticket') === (string) $sale->id)
                                    <script>window.addEventListener('DOMContentLoaded', () => document.getElementById('sale-invoice-{{ $sale->id }}')?.showModal());</script>
                                @endif
                                @if ($sale->status !== 'refunded' && $sale->status !== 'cancelled')
                                    <dialog id="sale-refund-{{ $sale->id }}" class="app-dialog w-full max-w-xl rounded-2xl border border-slate-200 bg-white p-0 text-slate-950 shadow-2xl backdrop:bg-slate-950/40 dark:border-white/10 dark:bg-slate-950 dark:text-slate-100">
                                        <form action="{{ route('sales.refund', $sale) }}" method="POST" class="p-5">
                                            @csrf
                                            <div class="flex items-start justify-between gap-4">
                                                <div><p class="text-sm font-semibold text-rose-600">Retour de vente</p><h3 class="mt-1 text-xl font-semibold">{{ $sale->number }} · {{ $money($sale->total_amount) }}</h3></div>
                                                <button class="dialog-close grid size-9 place-items-center rounded-lg border border-slate-200 text-lg font-semibold dark:border-white/10" type="button">×</button>
                                            </div>
                                            <div class="mt-5 grid gap-3">
                                                <select name="refund_method" class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="cash">Espèces</option><option value="card">Carte</option><option value="transfer">Virement</option><option value="credit">Avoir client</option></select>
                                                <textarea name="refund_reason" class="min-h-24 rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Motif du retour"></textarea>
                                                <label class="flex items-center gap-2 text-sm font-medium"><input name="restock" value="1" type="checkbox" checked class="rounded border-slate-300"> Remettre les articles en stock</label>
                                            </div>
                                            <div class="mt-5 flex justify-end gap-2"><button class="dialog-close rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold dark:border-white/10" type="button">Annuler</button><button class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white" type="submit">Valider le retour</button></div>
                                        </form>
                                    </dialog>
                                    <dialog id="sale-delivery-{{ $sale->id }}" class="app-dialog w-[min(780px,calc(100vw-2rem))] rounded-2xl border border-slate-200 bg-white p-0 text-slate-950 shadow-2xl backdrop:bg-slate-950/45 dark:border-white/10 dark:bg-slate-950 dark:text-slate-100">
                                        @php
                                            $deliveryHasErrors = (string) old('delivery_sale_id') === (string) $sale->id && $errors->any();
                                            $deliveryBorder = fn (string $field) => $deliveryHasErrors && $errors->has($field) ? 'border-rose-400 ring-2 ring-rose-100 dark:border-rose-400 dark:ring-rose-500/20' : 'border-slate-200 dark:border-white/10';
                                            $deliveryAddressValue = old('delivery_address', $sale->contact?->address ?: data_get($sale->metadata, 'delivery_address'));
                                        @endphp
                                        <form action="{{ route('sales.deliveries.store') }}" method="POST" class="overflow-hidden">
                                            @csrf
                                            <input type="hidden" name="sale_id" value="{{ $sale->id }}">
                                            <input type="hidden" name="delivery_sale_id" value="{{ $sale->id }}">
                                            <div class="flex items-start justify-between gap-4 border-b border-slate-200 bg-slate-50/80 p-5 dark:border-white/10 dark:bg-white/[0.04]">
                                                <div class="min-w-0">
                                                    <p class="text-sm font-semibold text-brand">Ajouter une livraison</p>
                                                    <h3 class="mt-1 text-xl font-semibold">{{ $sale->number }} · {{ $money($sale->total_amount) }}</h3>
                                                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $sale->contact?->name ?? 'Client comptoir' }} · {{ $sale->sold_at?->format('d/m/Y H:i') }}</p>
                                                </div>
                                                <button class="dialog-close grid size-9 place-items-center rounded-lg border border-slate-200 text-lg font-semibold dark:border-white/10" type="button">×</button>
                                            </div>
                                            @if ($deliveryHasErrors)
                                                <div class="mx-5 mt-5 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-100">
                                                    <strong class="block">La livraison contient des informations à corriger.</strong>
                                                    <ul class="mt-2 list-inside list-disc space-y-1">
                                                        @foreach ($errors->all() as $message)
                                                            <li>{{ $message }}</li>
                                                        @endforeach
                                                    </ul>
                                                </div>
                                            @endif
                                            <div class="grid gap-5 p-5 lg:grid-cols-[minmax(0,1fr)_260px]">
                                                <div class="grid gap-3 sm:grid-cols-2">
                                                    <label class="space-y-1.5">
                                                        <span class="text-xs font-semibold uppercase text-slate-500">Livreur / responsable</span>
                                                        <input name="assigned_to" value="{{ old('assigned_to') }}" class="h-11 w-full rounded-lg {{ $deliveryBorder('assigned_to') }} px-3 text-sm dark:bg-slate-900" placeholder="Nom livreur, équipe...">
                                                        @if ($deliveryHasErrors && $errors->has('assigned_to'))<span class="text-xs font-semibold text-rose-600">{{ $errors->first('assigned_to') }}</span>@endif
                                                    </label>
                                                    <label class="space-y-1.5">
                                                        <span class="text-xs font-semibold uppercase text-slate-500">Planifiée le</span>
                                                        <input name="scheduled_at" value="{{ old('scheduled_at') }}" type="datetime-local" class="h-11 w-full rounded-lg {{ $deliveryBorder('scheduled_at') }} px-3 text-sm dark:bg-slate-900">
                                                        @if ($deliveryHasErrors && $errors->has('scheduled_at'))<span class="text-xs font-semibold text-rose-600">{{ $errors->first('scheduled_at') }}</span>@endif
                                                    </label>
                                                    <label class="space-y-1.5 sm:col-span-2">
                                                        <span class="text-xs font-semibold uppercase text-slate-500">Adresse de livraison *</span>
                                                        <textarea name="delivery_address" required class="min-h-28 w-full rounded-lg {{ $deliveryBorder('delivery_address') }} px-3 py-2 text-sm dark:bg-slate-900" placeholder="Adresse complète, quartier, repère...">{{ $deliveryAddressValue }}</textarea>
                                                        @if ($deliveryHasErrors && $errors->has('delivery_address'))<span class="text-xs font-semibold text-rose-600">{{ $errors->first('delivery_address') }}</span>@endif
                                                    </label>
                                                    <label class="space-y-1.5 sm:col-span-2">
                                                        <span class="text-xs font-semibold uppercase text-slate-500">Note livraison</span>
                                                        <textarea name="note" class="min-h-24 w-full rounded-lg {{ $deliveryBorder('note') }} px-3 py-2 text-sm dark:bg-slate-900" placeholder="Créneau, téléphone à appeler, consignes...">{{ old('note', data_get($sale->metadata, 'delivery_note')) }}</textarea>
                                                        @if ($deliveryHasErrors && $errors->has('note'))<span class="text-xs font-semibold text-rose-600">{{ $errors->first('note') }}</span>@endif
                                                    </label>
                                                </div>
                                                <aside class="space-y-3">
                                                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm dark:border-white/10 dark:bg-white/[0.04]">
                                                        <span class="text-xs font-semibold uppercase text-slate-500">Résumé vente</span>
                                                        <div class="mt-3 space-y-2">
                                                            <div class="flex justify-between gap-3"><span class="text-slate-500">Client</span><strong class="text-right">{{ $sale->contact?->name ?? 'Client comptoir' }}</strong></div>
                                                            <div class="flex justify-between gap-3"><span class="text-slate-500">Téléphone</span><strong>{{ $sale->contact?->phone ?? '—' }}</strong></div>
                                                            <div class="flex justify-between gap-3"><span class="text-slate-500">Lignes</span><strong>{{ $sale->items->count() }}</strong></div>
                                                            <div class="flex justify-between gap-3"><span class="text-slate-500">Livraisons</span><strong>{{ $sale->deliveryOrders->count() }}</strong></div>
                                                            <div class="flex justify-between gap-3 border-t border-slate-200 pt-2 dark:border-white/10"><span class="text-slate-500">Statut</span><x-status-pill :tone="$statusTone">{{ $paymentStatus }}</x-status-pill></div>
                                                        </div>
                                                    </div>
                                                    <div class="rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-100">
                                                        <strong class="block">Conseil</strong>
                                                        <p class="mt-1 leading-5">Renseignez une adresse exploitable par le livreur. La livraison démarre en statut “En attente”.</p>
                                                    </div>
                                                </aside>
                                            </div>
                                            <div class="flex flex-col-reverse gap-2 border-t border-slate-200 bg-slate-50 p-5 dark:border-white/10 dark:bg-white/5 sm:flex-row sm:items-center sm:justify-between">
                                                <p class="text-sm text-slate-500 dark:text-slate-400">Une trace sera conservée dans la liste des livraisons.</p>
                                                <div class="flex justify-end gap-2">
                                                    <button class="dialog-close rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold dark:border-white/10 dark:bg-slate-950" type="button">Annuler</button>
                                                    <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white" type="submit">Créer livraison</button>
                                                </div>
                                            </div>
                                        </form>
                                    </dialog>
                                    @if ((string) old('delivery_sale_id') === (string) $sale->id)
                                        <script>window.addEventListener('DOMContentLoaded', () => document.getElementById('sale-delivery-{{ $sale->id }}')?.showModal());</script>
                                    @endif
                                @endif
                            @empty
                                <tr>
                                    <td colspan="11" class="px-4 py-12 text-center text-sm text-slate-500">Aucune vente ne correspond aux filtres.</td>
                                </tr>
                            @endforelse
                        </tbody>
                        <tfoot class="bg-slate-50 text-sm font-semibold dark:bg-white/5">
                            <tr>
                                <td colspan="7" class="px-3 py-3 text-right">Totaux filtrés</td>
                                <td class="px-3 py-3 text-right">{{ $money($salesTotals['total'] ?? 0) }}</td>
                                <td class="px-3 py-3 text-right">{{ $money($salesTotals['paid'] ?? 0) }}</td>
                                <td colspan="2" class="px-3 py-3">{{ $money($salesTotals['due'] ?? 0) }} restant</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div class="border-t border-slate-200 px-4 py-3 dark:border-white/10">
                    {{ $sales->links() }}
                </div>
            </article>
        </section>
        @endif
    @elseif ($module === 'purchases')
        @php
            $purchaseSection = request('section', 'list');
            $purchaseTabs = [
                'add' => ['label' => 'Nouvel achat', 'href' => route('module', ['module' => 'purchases', 'section' => 'add'])],
                'list' => ['label' => "Liste d'achat", 'href' => route('module', ['module' => 'purchases', 'section' => 'list'])],
                'returns' => ['label' => "Liste des retours d'achat", 'href' => route('module', ['module' => 'purchases', 'section' => 'returns'])],
            ];
        @endphp
        <details class="app-collapsible-menu mt-6" data-collapsible-menu data-menu-key="module-purchases-menu">
            <summary class="app-collapsible-menu-summary">
                <span><strong>Menu achats</strong><small>{{ $purchaseTabs[$purchaseSection]['label'] ?? "Liste d'achat" }}</small></span>
                <em data-collapsible-menu-state>Afficher</em>
            </summary>
            <nav class="app-tab-nav">
                @foreach ($purchaseTabs as $key => $tab)
                    <a href="{{ $tab['href'] }}" class="app-tab-link {{ $purchaseSection === $key || ($key === 'list' && ! in_array($purchaseSection, ['add', 'returns'], true)) ? 'is-active' : '' }}">{{ $tab['label'] }}</a>
                @endforeach
            </nav>
        </details>

        @if ($purchaseSection === 'add')
            <section class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1fr)_320px]">
                <form action="{{ route('purchases.store') }}" method="POST" class="space-y-5 rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    @csrf
                    <div class="flex flex-col gap-3 border-b border-slate-200 pb-5 dark:border-white/10 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <p class="text-sm font-semibold text-brand">Achats · commande fournisseur</p>
                            <h2 class="mt-1 text-xl font-semibold">Ajouter un achat</h2>
                            <p class="mt-1 text-sm text-slate-500">Commande fournisseur avec réception immédiate ou différée.</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <a href="{{ route('module', ['module' => 'contacts', 'section' => 'suppliers']) }}" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 transition hover:border-brand hover:text-brand dark:border-white/10 dark:text-slate-200">Voir fournisseurs</a>
                            <a href="{{ route('catalog', ['panel' => 'ajouter']) }}" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 transition hover:border-brand hover:text-brand dark:border-white/10 dark:text-slate-200">Ajouter article</a>
                            <x-status-pill tone="info">Stock synchronisé</x-status-pill>
                        </div>
                    </div>
                    <div class="grid gap-3 lg:grid-cols-4">
                        <label class="block lg:col-span-2">
                            <span class="flex items-center justify-between gap-3 text-xs font-semibold uppercase text-slate-500">
                                <span>Fournisseur *</span>
                                <button class="inline-create-open text-xs font-semibold normal-case text-brand hover:underline" type="button" data-dialog="purchase-supplier-dialog">+ Ajouter</button>
                            </span>
                            <select name="supplier_id" required data-searchable-select data-placeholder="Rechercher fournisseur..." class="mt-1 h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="">Choisir un fournisseur</option>@foreach ($purchaseSuppliers as $supplier)<option value="{{ $supplier->id }}">{{ $supplier->name }}{{ $supplier->phone ? ' · '.$supplier->phone : '' }}</option>@endforeach</select>
                        </label>
                        <label class="block"><span class="text-xs font-semibold uppercase text-slate-500">Date achat</span><input name="ordered_at" type="date" value="{{ now()->toDateString() }}" class="mt-1 h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                        <label class="block"><span class="text-xs font-semibold uppercase text-slate-500">Réception prévue</span><input name="expected_at" type="date" class="mt-1 h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                        <label class="block"><span class="text-xs font-semibold uppercase text-slate-500">N° facture fournisseur</span><input name="supplier_invoice" class="mt-1 h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="FA-0001"></label>
                        <label class="block"><span class="text-xs font-semibold uppercase text-slate-500">Référence</span><input name="reference" class="mt-1 h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Bon, commande..."></label>
                        <label class="block"><span class="text-xs font-semibold uppercase text-slate-500">Dépôt</span><input name="warehouse" class="mt-1 h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Magasin principal"></label>
                        <label class="block"><span class="text-xs font-semibold uppercase text-slate-500">Statut</span><select name="status" class="mt-1 h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="ordered">Commandé</option><option value="received">Réception immédiate</option><option value="draft">Brouillon</option></select></label>
                    </div>

                    @php
                        $purchasePrefillItemId = (int) request('item');
                        $purchaseDefaultLines = $purchasePrefillItemId > 0
                            ? [['item_id' => $purchasePrefillItemId, 'quantity' => 1]]
                            : [];
                        $purchaseOldLines = collect(old('items', $purchaseDefaultLines))
                            ->filter(fn ($line) => filled(data_get($line, 'item_id')))
                            ->values();
                        $purchaseItemOptions = $purchaseItems->map(function ($item) {
                            return [
                                'value' => (string) $item->id,
                                'title' => $item->title,
                                'text' => collect([$item->title, $item->barcode ?: $item->isbn ?: $item->item_code, $item->category?->name, $item->brand?->name])->filter()->join(' · '),
                                'stock' => (int) $item->stock_quantity,
                                'code' => $item->barcode ?: $item->isbn ?: $item->item_code,
                                'category' => $item->category?->name,
                                'brand' => $item->brand?->name,
                                'purchase_price' => (float) $item->purchase_price,
                            ];
                        })->values();
                    @endphp
                    <div class="purchase-item-builder overflow-hidden rounded-xl border border-slate-200 dark:border-white/10" data-purchase-item-builder data-purchase-item-search-url="{{ route('catalog.stock-items.search') }}" data-next-index="{{ max(1, $purchaseOldLines->count()) }}">
                        <div class="flex flex-col gap-3 bg-slate-50 px-3 py-3 dark:bg-white/5 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h3 class="text-sm font-semibold">Articles à commander</h3>
                                <p class="mt-1 text-xs text-slate-500">Recherchez par nom, ISBN, code-barres ou catégorie. Les doublons sont détectés automatiquement.</p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <a href="{{ route('catalog', ['panel' => 'articles']) }}" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold dark:border-white/10 dark:bg-slate-950">Catalogue</a>
                                <a href="{{ route('stock', ['panel' => 'stock-adjustments']) }}" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold dark:border-white/10 dark:bg-slate-950">Voir stock</a>
                            </div>
                        </div>
                        <div class="border-t border-slate-200 bg-white p-3 dark:border-white/10 dark:bg-slate-950/40">
                            <div class="purchase-item-toolbar">
                                <label class="purchase-item-search">
                                    <span>Recherche article</span>
                                    <input type="search" data-purchase-item-search placeholder="Scanner ou rechercher titre, ISBN, code-barres..." autocomplete="off">
                                </label>
                                <button class="purchase-item-tool-button" type="button" data-purchase-item-add-match>Ajouter le premier résultat</button>
                                <button class="purchase-item-tool-button is-muted" type="button" data-purchase-item-clear>Effacer</button>
                                <div class="purchase-item-search-meta"><strong data-purchase-item-count>0</strong><span data-purchase-item-state> résultat(s)</span></div>
                            </div>
                            <div class="purchase-item-suggestions mt-3" data-purchase-item-suggestions hidden></div>
                        </div>
                        <div class="purchase-item-list" data-purchase-item-lines>
                            @forelse ($purchaseOldLines as $lineIndex => $line)
                                @php
                                    $oldPurchaseItem = $purchaseItems->firstWhere('id', (int) data_get($line, 'item_id'));
                                @endphp
                                <div class="purchase-item-row" data-purchase-item-row data-item-id="{{ data_get($line, 'item_id') }}">
                                    <input type="hidden" name="items[{{ $lineIndex }}][item_id]" value="{{ data_get($line, 'item_id') }}" data-purchase-item-id>
                                    <div class="purchase-item-main">
                                        <span data-purchase-item-index>{{ str_pad((string) ($lineIndex + 1), 2, '0', STR_PAD_LEFT) }}</span>
                                        <div>
                                            <strong data-purchase-item-title>{{ $oldPurchaseItem?->title ?? 'Article sélectionné' }}</strong>
                                            <small data-purchase-item-meta>Stock {{ $oldPurchaseItem?->stock_quantity ?? '—' }} · {{ $oldPurchaseItem?->barcode ?? $oldPurchaseItem?->isbn ?? $oldPurchaseItem?->item_code ?? 'Sans code' }}</small>
                                        </div>
                                    </div>
                                    <label><span>Qté</span><input name="items[{{ $lineIndex }}][quantity]" data-purchase-item-quantity type="number" min="1" value="{{ data_get($line, 'quantity', 1) }}"></label>
                                    <label><span>Coût DH</span><input name="items[{{ $lineIndex }}][unit_cost]" data-purchase-item-cost type="number" min="0" step="0.01" value="{{ data_get($line, 'unit_cost', $oldPurchaseItem?->purchase_price ?? 0) }}"></label>
                                    <div class="purchase-item-line-total"><span>Ligne</span><strong data-purchase-item-line-total>0,00 DH</strong></div>
                                    <button type="button" class="purchase-item-remove" data-purchase-item-remove>Retirer</button>
                                </div>
                            @empty
                                <div class="purchase-item-empty" data-purchase-item-empty>
                                    <strong>Aucun article sélectionné</strong>
                                    <span>Utilisez la recherche ci-dessus pour ajouter les articles à commander.</span>
                                </div>
                            @endforelse
                        </div>
                        <div class="purchase-item-footer">
                            <span><strong data-purchase-item-selected>{{ $purchaseOldLines->count() }}</strong> ligne(s)</span>
                            <span>Total estimé <strong data-purchase-item-total>0,00 DH</strong></span>
                        </div>
                        <template data-purchase-item-template>
                            <div class="purchase-item-row" data-purchase-item-row data-item-id="">
                                <input type="hidden" name="items[__INDEX__][item_id]" value="" data-purchase-item-id>
                                <div class="purchase-item-main">
                                    <span data-purchase-item-index>01</span>
                                    <div>
                                        <strong data-purchase-item-title>Article</strong>
                                        <small data-purchase-item-meta>Stock — · Sans code</small>
                                    </div>
                                </div>
                                <label><span>Qté</span><input name="items[__INDEX__][quantity]" data-purchase-item-quantity type="number" min="1" value="1"></label>
                                <label><span>Coût DH</span><input name="items[__INDEX__][unit_cost]" data-purchase-item-cost type="number" min="0" step="0.01" value="0"></label>
                                <div class="purchase-item-line-total"><span>Ligne</span><strong data-purchase-item-line-total>0,00 DH</strong></div>
                                <button type="button" class="purchase-item-remove" data-purchase-item-remove>Retirer</button>
                            </div>
                        </template>
                        <script type="application/json" data-purchase-item-options>
                            @json($purchaseItemOptions)
                        </script>
                    </div>

                    <label class="block"><span class="text-xs font-semibold uppercase text-slate-500">Note</span><textarea name="note" class="mt-1 min-h-20 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Conditions fournisseur, livraison, priorité rentrée..."></textarea></label>
                    <div class="flex flex-wrap justify-end gap-2">
                        <a href="{{ route('module', ['module' => 'purchases', 'section' => 'list']) }}" class="rounded-lg border border-slate-200 px-4 py-2.5 text-sm font-semibold dark:border-white/10">Annuler</a>
                        <button class="rounded-lg bg-brand px-5 py-2.5 text-sm font-semibold text-white">Enregistrer l'achat</button>
                    </div>
                </form>
                <aside class="space-y-4">
                    <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                        <h2 class="font-semibold">Raccourcis achat</h2>
                        <div class="mt-4 grid gap-2 text-sm">
                            <button class="inline-create-open rounded-lg border border-slate-200 px-4 py-3 text-left font-semibold transition hover:border-brand hover:text-brand dark:border-white/10" type="button" data-dialog="purchase-supplier-dialog">+ Ajouter un fournisseur</button>
                            <a href="{{ route('catalog', ['panel' => 'ajouter']) }}" class="rounded-lg border border-slate-200 px-4 py-3 font-semibold transition hover:border-brand hover:text-brand dark:border-white/10">+ Ajouter un article</a>
                            <a href="{{ route('stock', ['panel' => 'stock-adjustments']) }}" class="rounded-lg border border-slate-200 px-4 py-3 font-semibold transition hover:border-brand hover:text-brand dark:border-white/10">Consulter l'inventaire</a>
                        </div>
                    </article>
                    <article class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-100"><strong>Rentrée scolaire</strong><p class="mt-2">Les quantités reçues incrémentent le stock et mettent à jour le dernier coût d'achat de l'article.</p></article>
                </aside>
            </section>
            <dialog id="purchase-supplier-dialog" class="app-dialog w-[min(560px,calc(100vw-2rem))] rounded-2xl border border-slate-200 bg-white p-0 text-slate-950 shadow-2xl backdrop:bg-slate-950/45 dark:border-white/10 dark:bg-slate-950 dark:text-slate-100">
                <div data-inline-create data-endpoint="{{ route('contacts.store') }}" data-target="supplier_id" class="p-0">
                    <input type="hidden" name="kind" value="supplier">
                    <input type="hidden" name="status" value="active">
                    <input type="hidden" name="client_type" value="company">
                    <input type="hidden" name="price_level_type" value="increase">
                    <div class="flex items-start justify-between gap-4 border-b border-slate-200 p-5 dark:border-white/10">
                        <div>
                            <p class="text-sm font-semibold text-brand">Raccourci achat</p>
                            <h3 class="mt-1 text-xl font-semibold">Ajouter un fournisseur</h3>
                            <p class="mt-1 text-sm text-slate-500">Le fournisseur sera ajouté puis sélectionné automatiquement dans cet achat.</p>
                        </div>
                        <button class="dialog-close grid size-9 shrink-0 place-items-center rounded-lg border border-slate-200 text-lg font-semibold dark:border-white/10" type="button">×</button>
                    </div>
                    <div class="grid gap-4 p-5 sm:grid-cols-2">
                        <label class="space-y-1.5 sm:col-span-2"><span class="text-xs font-semibold uppercase text-slate-500">Nom fournisseur *</span><input name="name" required class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Nom société / éditeur"></label>
                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Téléphone</span><input name="phone" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="+212..."></label>
                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Email</span><input name="email" type="email" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="contact@..."></label>
                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">ICE / IF</span><input name="ice" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Identifiant fiscal"></label>
                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Ville</span><input name="city" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Casablanca, Rabat..."></label>
                        <label class="space-y-1.5 sm:col-span-2"><span class="text-xs font-semibold uppercase text-slate-500">Adresse</span><input name="address" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Adresse principale"></label>
                        <p class="inline-create-error hidden rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-200 sm:col-span-2"></p>
                    </div>
                    <div class="flex flex-col-reverse gap-2 border-t border-slate-200 bg-slate-50 p-5 dark:border-white/10 dark:bg-white/5 sm:flex-row sm:justify-end">
                        <button class="dialog-close rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold dark:border-white/10 dark:bg-slate-950" type="button">Annuler</button>
                        <button class="inline-create-submit rounded-lg bg-brand px-4 py-2.5 text-sm font-semibold text-white" type="button">Ajouter</button>
                    </div>
                </div>
            </dialog>
        @elseif ($purchaseSection === 'returns')
            <section class="mt-6 space-y-5">
                <div class="grid gap-3 md:grid-cols-3">
                    <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><span class="text-xs font-semibold uppercase text-slate-500">Retours achat</span><p class="mt-2 text-2xl font-semibold">{{ $purchaseReturns->total() }}</p></article>
                    <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><span class="text-xs font-semibold uppercase text-slate-500">Montant page</span><p class="mt-2 text-2xl font-semibold text-rose-600">{{ $money($purchaseReturns->sum('total_amount')) }}</p></article>
                    <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><span class="text-xs font-semibold uppercase text-slate-500">Sources reçues</span><p class="mt-2 text-2xl font-semibold">{{ $purchaseReturnSources->count() }}</p></article>
                </div>
                <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <h2 class="font-semibold">Créer un retour fournisseur</h2>
                    <form action="{{ route('purchases.returns.store') }}" method="POST" class="app-action-form mt-4">
                        @csrf
                        <select name="purchase_id" required class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="">Achat source</option>@foreach ($purchaseReturnSources as $purchase)<option value="{{ $purchase->id }}">{{ $purchase->number }} · {{ $purchase->supplier?->name }} · {{ $money($purchase->total_amount) }}</option>@endforeach</select>
                        <input name="returned_at" type="date" value="{{ now()->toDateString() }}" class="h-11 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900">
                        <input name="reason" class="h-11 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Motif: erreur, abîmé, surplus...">
                        <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Créer retour</button>
                        <div class="lg:col-span-4 overflow-hidden rounded-xl border border-slate-200 dark:border-white/10">
                            @for ($i = 0; $i < 4; $i++)
                                <div class="grid gap-2 border-b border-slate-200 p-2 last:border-b-0 dark:border-white/10 lg:grid-cols-[1fr_110px_140px]">
                                    <select name="items[{{ $i }}][item_id]" data-searchable-select data-placeholder="Article à retourner..." class="h-10 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="">Article</option>@foreach ($purchaseItems as $item)<option value="{{ $item->id }}">{{ $item->title }} · stock {{ $item->stock_quantity }}</option>@endforeach</select>
                                    <input name="items[{{ $i }}][quantity]" type="number" min="1" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Qté">
                                    <input name="items[{{ $i }}][unit_cost]" type="number" min="0" step="0.01" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Coût">
                                </div>
                            @endfor
                        </div>
                    </form>
                </article>
                <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <form class="app-action-form" method="GET" action="{{ route('module', ['module' => 'purchases', 'section' => 'returns']) }}">
                        <input type="hidden" name="section" value="returns">
                        <input name="q" value="{{ request('q') }}" class="h-11 rounded-lg border border-slate-200 bg-slate-50 px-3 text-sm dark:border-white/10 dark:bg-white/5" placeholder="Afficher/recherche Achat, retour, fournisseur...">
                        <select name="supplier_id" class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="">Tous fournisseurs</option>@foreach ($purchaseSuppliers as $supplier)<option value="{{ $supplier->id }}" @selected((string) request('supplier_id') === (string) $supplier->id)>{{ $supplier->name }}</option>@endforeach</select>
                        <input name="from" value="{{ request('from') }}" type="date" class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">
                        <input name="to" value="{{ request('to') }}" type="date" class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">
                        <div class="flex gap-2"><button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Filtrer</button><a href="{{ route('module', ['module' => 'purchases', 'section' => 'returns']) }}" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold dark:border-white/10">Reset</a></div>
                    </form>
                </article>
                <article class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <div class="overflow-x-auto"><table class="w-full min-w-[980px] text-left text-sm"><thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-white/5"><tr><th class="px-3 py-3">N° retour</th><th class="px-3 py-3">Achat</th><th class="px-3 py-3">Fournisseur</th><th class="px-3 py-3">Date</th><th class="px-3 py-3">Motif</th><th class="px-3 py-3 text-right">Montant</th><th class="px-3 py-3 text-right">Action</th></tr></thead><tbody class="divide-y divide-slate-200 dark:divide-white/10">@forelse ($purchaseReturns as $return)<tr><td class="px-3 py-3 font-semibold">{{ $return->number }}</td><td class="px-3 py-3">{{ $return->purchase?->number ?? '—' }}</td><td class="px-3 py-3">{{ $return->supplier?->name ?? '—' }}</td><td class="px-3 py-3">{{ $return->returned_at?->format('d/m/Y H:i') }}</td><td class="px-3 py-3 max-w-xs truncate">{{ $return->reason ?? '—' }}</td><td class="px-3 py-3 text-right font-semibold">{{ $money($return->total_amount) }}</td><td class="px-3 py-3 text-right"><button class="rounded-lg bg-brand px-3 py-2 text-xs font-semibold text-white" type="button" onclick="document.getElementById('purchase-return-detail-{{ $return->id }}').showModal()">Détail</button></td></tr><dialog id="purchase-return-detail-{{ $return->id }}" class="w-full max-w-2xl rounded-2xl border border-slate-200 bg-white p-0 text-slate-950 shadow-2xl backdrop:bg-slate-950/40 dark:border-white/10 dark:bg-slate-950 dark:text-slate-100"><div class="border-b border-slate-200 p-5 dark:border-white/10"><div class="flex justify-between gap-4"><div><p class="text-sm font-semibold text-brand">Retour achat</p><h3 class="mt-1 text-xl font-semibold">{{ $return->number }} · {{ $money($return->total_amount) }}</h3><p class="mt-1 text-sm text-slate-500">{{ $return->reason ?? 'Sans motif' }}</p></div><button class="dialog-close grid size-9 place-items-center rounded-lg border border-slate-200 dark:border-white/10" type="button">×</button></div></div><div class="space-y-2 p-5">@foreach ($return->lines ?? [] as $line)<div class="grid grid-cols-[1fr_70px_110px] gap-3 rounded-lg bg-slate-50 px-3 py-2 text-sm dark:bg-white/5"><span>{{ $line['name'] }}</span><span>x{{ $line['quantity'] }}</span><strong class="text-right">{{ $money($line['total_price']) }}</strong></div>@endforeach</div></dialog>@empty<tr><td colspan="7" class="px-4 py-12 text-center text-sm text-slate-500">Aucun retour d'achat trouvé.</td></tr>@endforelse</tbody></table></div><div class="border-t border-slate-200 px-4 py-3 dark:border-white/10">{{ $purchaseReturns->links() }}</div>
                </article>
            </section>
        @else
            <section class="mt-6 space-y-5">
                <div class="grid gap-3 md:grid-cols-4">
                    <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><span class="text-xs font-semibold uppercase text-slate-500">Achats</span><p class="mt-2 text-2xl font-semibold">{{ $purchases->total() }}</p></article>
                    <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><span class="text-xs font-semibold uppercase text-slate-500">Montant page</span><p class="mt-2 text-2xl font-semibold">{{ $money($purchases->sum('total_amount')) }}</p></article>
                    <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><span class="text-xs font-semibold uppercase text-slate-500">À recevoir</span><p class="mt-2 text-2xl font-semibold">{{ $purchases->whereNotIn('status', ['received', 'cancelled'])->count() }}</p></article>
                    <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><span class="text-xs font-semibold uppercase text-slate-500">Fournisseurs</span><p class="mt-2 text-2xl font-semibold">{{ $purchaseSuppliers->count() }}</p></article>
                </div>
                <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <form class="app-action-form" method="GET" action="{{ route('module', ['module' => 'purchases', 'section' => 'list']) }}">
                        <input type="hidden" name="section" value="list">
                        <input name="q" value="{{ request('q') }}" class="h-11 rounded-lg border border-slate-200 bg-slate-50 px-3 text-sm dark:border-white/10 dark:bg-white/5" placeholder="Afficher/recherche Achat, facture, fournisseur, article...">
                        <select name="supplier_id" class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="">Tous fournisseurs</option>@foreach ($purchaseSuppliers as $supplier)<option value="{{ $supplier->id }}" @selected((string) request('supplier_id') === (string) $supplier->id)>{{ $supplier->name }}</option>@endforeach</select>
                        <input name="from" value="{{ request('from') }}" type="date" class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">
                        <input name="to" value="{{ request('to') }}" type="date" class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">
                        <select name="purchase_status" class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="">Tous statuts</option>@foreach (['draft' => 'Brouillon', 'ordered' => 'Commandé', 'partially_received' => 'Partiel', 'received' => 'Reçu', 'cancelled' => 'Annulé'] as $key => $label)<option value="{{ $key }}" @selected(request('purchase_status') === $key)>{{ $label }}</option>@endforeach</select>
                        <div class="flex gap-2"><button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Filtrer</button><a href="{{ route('module', ['module' => 'purchases', 'section' => 'list']) }}" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold dark:border-white/10">Reset</a></div>
                    </form>
                </article>
                <article class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <div class="border-b border-slate-200 px-4 py-4 dark:border-white/10">
                        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                            <div>
                                <h2 class="text-base font-semibold">Liste d'achat</h2>
                                <p class="mt-1 text-sm text-slate-500">Suivez les commandes, les réceptions et les factures fournisseur.</p>
                            </div>
                            <a href="{{ route('module', ['module' => 'purchases', 'section' => 'add']) }}" class="inline-flex items-center justify-center rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white shadow-sm">+ Nouvel achat</a>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[1120px] text-left text-sm">
                            <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-white/5">
                                <tr>
                                    <th class="px-4 py-3">N° achat</th>
                                    <th class="px-4 py-3">Date</th>
                                    <th class="px-4 py-3">Fournisseur</th>
                                    <th class="px-4 py-3">Facture / référence</th>
                                    <th class="px-4 py-3">Articles</th>
                                    <th class="px-4 py-3">Réception</th>
                                    <th class="px-4 py-3 text-right">Total</th>
                                    <th class="px-4 py-3 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 dark:divide-white/10">
                                @forelse ($purchases as $purchase)
                                    @php
                                        $orderedQuantity = (int) $purchase->items->sum('quantity_ordered');
                                        $receivedQuantity = (int) $purchase->items->sum('quantity_received');
                                        $remainingQuantity = max(0, $orderedQuantity - $receivedQuantity);
                                        $purchaseStatusTone = $purchase->status === 'received' ? 'success' : ($purchase->status === 'cancelled' ? 'danger' : 'warning');
                                        $purchaseStatusLabel = [
                                            'draft' => 'Brouillon',
                                            'ordered' => 'Commandé',
                                            'partially_received' => 'Partiel',
                                            'received' => 'Reçu',
                                            'cancelled' => 'Annulé',
                                        ][$purchase->status] ?? $purchase->status;
                                    @endphp
                                    <tr class="transition hover:bg-slate-50/80 dark:hover:bg-white/[0.04]">
                                        <td class="px-4 py-3">
                                            <a href="{{ route('module', ['module' => 'purchases', 'section' => 'list', 'detail_purchase' => $purchase->id]) }}" class="font-semibold text-slate-950 hover:text-brand dark:text-white">{{ $purchase->number }}</a>
                                            <p class="mt-1 text-xs text-slate-500">{{ data_get($purchase->metadata, 'warehouse', 'Magasin principal') ?: 'Magasin principal' }}</p>
                                        </td>
                                        <td class="px-4 py-3">
                                            <span class="font-medium">{{ $purchase->ordered_at?->format('d/m/Y') ?? '—' }}</span>
                                            <p class="mt-1 text-xs text-slate-500">Prévu {{ $purchase->expected_at?->format('d/m/Y') ?? '—' }}</p>
                                        </td>
                                        <td class="px-4 py-3">
                                            <span class="font-semibold">{{ $purchase->supplier?->name ?? '—' }}</span>
                                            @if ($purchase->supplier?->phone)
                                                <p class="mt-1 text-xs text-slate-500">{{ $purchase->supplier->phone }}</p>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3">
                                            <span class="font-medium">{{ data_get($purchase->metadata, 'supplier_invoice', '—') }}</span>
                                            <p class="mt-1 text-xs text-slate-500">{{ data_get($purchase->metadata, 'reference', 'Sans référence') }}</p>
                                        </td>
                                        <td class="px-4 py-3">
                                            <span class="font-semibold">{{ $purchase->items->count() }} ligne(s)</span>
                                            <p class="mt-1 text-xs text-slate-500">{{ $orderedQuantity }} commandé(s), {{ $receivedQuantity }} reçu(s)</p>
                                        </td>
                                        <td class="px-4 py-3">
                                            <x-status-pill :tone="$purchaseStatusTone">{{ $purchaseStatusLabel }}</x-status-pill>
                                            @if ($remainingQuantity > 0)
                                                <p class="mt-1 text-xs font-semibold text-amber-600">{{ $remainingQuantity }} restant(s)</p>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-right font-semibold">{{ $money($purchase->total_amount) }}</td>
                                        <td class="px-4 py-3">
                                            <div class="flex flex-wrap justify-end gap-2">
                                                <button class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 transition hover:border-brand hover:text-brand dark:border-white/10 dark:bg-slate-950 dark:text-slate-200" type="button" onclick="document.getElementById('purchase-detail-{{ $purchase->id }}').showModal()">Voir détail</button>
                                                <a href="{{ route('purchases.pdf', $purchase) }}" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 transition hover:border-brand hover:text-brand dark:border-white/10 dark:bg-slate-950 dark:text-slate-200">PDF</a>
                                                @if ($purchase->status !== 'received')
                                                    <form action="{{ route('purchases.receive', $purchase) }}" method="POST">
                                                        @csrf
                                                        <button class="rounded-lg bg-emerald-600 px-3 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-emerald-700">Recevoir</button>
                                                    </form>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                    <dialog id="purchase-detail-{{ $purchase->id }}" class="app-dialog purchase-detail-dialog w-[min(980px,calc(100vw-2rem))] rounded-2xl border border-slate-200 bg-white p-0 text-slate-950 shadow-2xl backdrop:bg-slate-950/45 dark:border-white/10 dark:bg-slate-950 dark:text-slate-100">
                                        <div class="purchase-detail-hero">
                                            <div class="flex items-start gap-3">
                                                <span class="purchase-detail-icon">PO</span>
                                                <div class="min-w-0">
                                                    <p class="text-sm font-semibold text-brand">Détail achat</p>
                                                    <h3 class="mt-1 text-xl font-semibold">{{ $purchase->number }}</h3>
                                                    <p class="mt-1 text-sm text-slate-500">{{ $purchase->supplier?->name ?? 'Sans fournisseur' }} · {{ $purchase->ordered_at?->format('d/m/Y') ?? 'Sans date' }}</p>
                                                </div>
                                            </div>
                                            <div class="flex items-start gap-2">
                                                <x-status-pill :tone="$purchaseStatusTone">{{ $purchaseStatusLabel }}</x-status-pill>
                                                <button class="dialog-close grid size-9 shrink-0 place-items-center rounded-lg border border-slate-200 bg-white text-lg font-semibold text-slate-600 transition hover:border-rose-200 hover:text-rose-600 dark:border-white/10 dark:bg-slate-950 dark:text-slate-200" type="button">×</button>
                                            </div>
                                        </div>
                                        <div class="grid gap-4 p-5 lg:grid-cols-[minmax(0,1fr)_300px]">
                                            <div class="space-y-4">
                                                <div class="grid gap-3 sm:grid-cols-3">
                                                    <div class="purchase-detail-stat"><span>Total</span><strong>{{ $money($purchase->total_amount) }}</strong></div>
                                                    <div class="purchase-detail-stat"><span>Quantité commandée</span><strong>{{ $orderedQuantity }}</strong></div>
                                                    <div class="purchase-detail-stat"><span>Quantité reçue</span><strong>{{ $receivedQuantity }}</strong></div>
                                                </div>
                                                <div class="overflow-hidden rounded-xl border border-slate-200 dark:border-white/10">
                                                    <div class="grid grid-cols-[minmax(220px,1fr)_80px_80px_100px_110px] gap-3 bg-slate-50 px-4 py-3 text-xs font-semibold uppercase text-slate-500 dark:bg-white/5">
                                                        <span>Article</span><span class="text-right">Cmd</span><span class="text-right">Reçu</span><span class="text-right">Coût</span><span class="text-right">Ligne</span>
                                                    </div>
                                                    <div class="divide-y divide-slate-200 dark:divide-white/10">
                                                        @forelse ($purchase->items as $line)
                                                            <div class="grid grid-cols-[minmax(220px,1fr)_80px_80px_100px_110px] gap-3 px-4 py-3 text-sm">
                                                                <div class="min-w-0">
                                                                    <span class="block truncate font-semibold">{{ $line->item?->title ?? 'Article supprimé' }}</span>
                                                                    <small class="mt-1 block truncate text-xs text-slate-500">{{ $line->item?->barcode ?? $line->item?->isbn ?? $line->item?->item_code ?? 'Sans code' }}</small>
                                                                </div>
                                                                <span class="text-right font-medium">{{ $line->quantity_ordered }}</span>
                                                                <span class="text-right font-medium">{{ $line->quantity_received }}</span>
                                                                <span class="text-right">{{ $money($line->unit_cost) }}</span>
                                                                <strong class="text-right">{{ $money($line->quantity_ordered * $line->unit_cost) }}</strong>
                                                            </div>
                                                        @empty
                                                            <div class="px-4 py-10 text-center text-sm text-slate-500">Aucune ligne article.</div>
                                                        @endforelse
                                                    </div>
                                                </div>
                                            </div>
                                            <aside class="space-y-3">
                                                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-white/10 dark:bg-white/5">
                                                    <h4 class="text-sm font-semibold">Informations fournisseur</h4>
                                                    <dl class="mt-3 space-y-2 text-sm">
                                                        <div class="flex justify-between gap-3"><dt class="text-slate-500">Fournisseur</dt><dd class="text-right font-semibold">{{ $purchase->supplier?->name ?? '—' }}</dd></div>
                                                        <div class="flex justify-between gap-3"><dt class="text-slate-500">Téléphone</dt><dd class="text-right">{{ $purchase->supplier?->phone ?? '—' }}</dd></div>
                                                        <div class="flex justify-between gap-3"><dt class="text-slate-500">Facture</dt><dd class="text-right">{{ data_get($purchase->metadata, 'supplier_invoice', '—') }}</dd></div>
                                                        <div class="flex justify-between gap-3"><dt class="text-slate-500">Référence</dt><dd class="text-right">{{ data_get($purchase->metadata, 'reference', '—') }}</dd></div>
                                                        <div class="flex justify-between gap-3"><dt class="text-slate-500">Dépôt</dt><dd class="text-right">{{ data_get($purchase->metadata, 'warehouse', 'Magasin principal') ?: 'Magasin principal' }}</dd></div>
                                                        <div class="flex justify-between gap-3"><dt class="text-slate-500">Réception prévue</dt><dd class="text-right">{{ $purchase->expected_at?->format('d/m/Y') ?? '—' }}</dd></div>
                                                    </dl>
                                                </div>
                                                @if (data_get($purchase->metadata, 'note'))
                                                    <div class="rounded-xl border border-slate-200 bg-white p-4 text-sm dark:border-white/10 dark:bg-slate-950/40">
                                                        <h4 class="font-semibold">Note</h4>
                                                        <p class="mt-2 text-slate-500">{{ data_get($purchase->metadata, 'note') }}</p>
                                                    </div>
                                                @endif
                                            </aside>
                                        </div>
                                        <div class="flex flex-col-reverse gap-2 border-t border-slate-200 bg-slate-50 p-5 dark:border-white/10 dark:bg-white/5 sm:flex-row sm:items-center sm:justify-between">
                                            <a href="{{ route('module', ['module' => 'purchases', 'section' => 'list', 'detail_purchase' => $purchase->id]) }}" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 dark:border-white/10 dark:bg-slate-950 dark:text-slate-200">Lien direct</a>
                                            <div class="flex justify-end gap-2">
                                                <button type="button" class="dialog-close rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold dark:border-white/10 dark:bg-slate-950">Fermer</button>
                                                <a href="{{ route('purchases.pdf', $purchase) }}" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-brand hover:text-brand dark:border-white/10 dark:bg-slate-950 dark:text-slate-200">Télécharger PDF</a>
                                                @if ($purchase->status !== 'received')
                                                    <form action="{{ route('purchases.receive', $purchase) }}" method="POST">
                                                        @csrf
                                                        <button class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-700">Confirmer réception</button>
                                                    </form>
                                                @endif
                                            </div>
                                        </div>
                                    </dialog>
                                @empty
                                    <tr><td colspan="8" class="px-4 py-12 text-center text-sm text-slate-500">Aucun achat trouvé.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="border-t border-slate-200 px-4 py-3 dark:border-white/10">{{ $purchases->links() }}</div>
                </article>
            </section>
        @endif
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
        @php
            $contactSection = request('section', 'customers');
            $isSupplierForm = in_array($contactSection, ['supplier-add', 'suppliers', 'import-suppliers'], true) || ($editContact?->kind === 'supplier');
            $contactKind = $isSupplierForm ? 'supplier' : 'client';
            $contactTabs = [
                'customer-add' => ['label' => 'Ajouter un client', 'href' => route('module', ['module' => 'contacts', 'section' => 'customer-add'])],
                'customers' => ['label' => 'Liste des clients', 'href' => route('module', ['module' => 'contacts', 'section' => 'customers'])],
                'import-customers' => ['label' => 'Importer des clients', 'href' => route('module', ['module' => 'contacts', 'section' => 'import-customers'])],
                'supplier-add' => ['label' => 'Ajouter un fournisseur', 'href' => route('module', ['module' => 'contacts', 'section' => 'supplier-add'])],
                'suppliers' => ['label' => 'Liste des fournisseurs', 'href' => route('module', ['module' => 'contacts', 'section' => 'suppliers'])],
                'import-suppliers' => ['label' => 'Importer des fournisseurs', 'href' => route('module', ['module' => 'contacts', 'section' => 'import-suppliers'])],
            ];
            $clientTypes = ['individual' => 'Particulier', 'school' => 'École', 'company' => 'Entreprise', 'wholesale' => 'Grossiste', 'teacher' => 'Enseignant', 'student' => 'Étudiant'];
            $contactFormAction = $editContact ? route('contacts.update', $editContact) : route('contacts.store');
        @endphp
        <details class="app-collapsible-menu mt-6" data-collapsible-menu data-menu-key="module-contacts-menu">
            <summary class="app-collapsible-menu-summary">
                <span><strong>Menu contacts</strong><small>{{ $contactTabs[$contactSection]['label'] ?? 'Liste des clients' }}</small></span>
                <em data-collapsible-menu-state>Afficher</em>
            </summary>
            <nav class="app-tab-nav">
                @foreach ($contactTabs as $key => $tab)
                    <a href="{{ $tab['href'] }}" class="app-tab-link {{ $contactSection === $key || ($key === 'customers' && ! in_array($contactSection, ['customer-add', 'import-customers', 'supplier-add', 'suppliers', 'import-suppliers'], true)) ? 'is-active' : '' }}">{{ $tab['label'] }}</a>
                @endforeach
            </nav>
        </details>

        @if (in_array($contactSection, ['customer-add', 'supplier-add'], true) || $editContact)
            <section id="contact-form" class="mt-6 grid gap-6 xl:grid-cols-[1fr_340px]">
                <form action="{{ $contactFormAction }}" method="POST" enctype="multipart/form-data" class="space-y-5 rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    @csrf
                    @if ($editContact)
                        @method('PUT')
                    @endif
                    <input type="hidden" name="kind" value="{{ $editContact?->kind ?? $contactKind }}">
                    <div class="flex flex-col gap-3 border-b border-slate-200 pb-4 dark:border-white/10 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h2 class="text-lg font-semibold">{{ $editContact ? 'Modifier le contact' : ($contactKind === 'supplier' ? 'Ajouter un fournisseur' : 'Ajouter un client') }}</h2>
                            <p class="mt-1 text-sm text-slate-500">{{ $contactKind === 'supplier' ? 'Informations fournisseur, fiscalité, solde précédent et adresse principale.' : 'Informations complètes, crédit, adresse de facturation/livraison et niveau de prix.' }}</p>
                        </div>
                        <button class="rounded-lg bg-brand px-4 py-2.5 text-sm font-semibold text-white">{{ $editContact ? 'Mettre à jour' : 'Enregistrer' }}</button>
                    </div>

                    <div class="grid gap-4 lg:grid-cols-4">
                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">{{ $contactKind === 'supplier' ? 'ID fournisseur' : 'Code' }}</span><input name="code" value="{{ old('code', $editContact?->code) }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Auto"></label>
                        <label class="space-y-1.5 lg:col-span-2"><span class="text-xs font-semibold uppercase text-slate-500">Nom requis</span><input name="name" required value="{{ old('name', $editContact?->name) }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="{{ $contactKind === 'supplier' ? 'Nom fournisseur / société' : 'Nom du client' }}"></label>
                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Statut</span><select name="status" class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="active" @selected(old('status', $editContact?->status ?? 'active') === 'active')>Actif</option><option value="archived" @selected(old('status', $editContact?->status) === 'archived')>Archivé</option></select></label>
                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Type</span><select name="client_type" class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">@foreach ($clientTypes as $key => $label)<option value="{{ $key }}" @selected(old('client_type', $editContact?->client_type ?? ($contactKind === 'supplier' ? 'company' : 'individual')) === $key)>{{ $label }}</option>@endforeach</select></label>
                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Mobile</span><input name="phone" value="{{ old('phone', $editContact?->phone) }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="+212..."></label>
                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Email</span><input name="email" type="email" value="{{ old('email', $editContact?->email) }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="contact@email.com"></label>
                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Téléphone fixe</span><input name="secondary_phone" value="{{ old('secondary_phone', $editContact?->secondary_phone) }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Optionnel"></label>
                        @if ($contactKind !== 'supplier')
                            <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">CIN</span><input name="cin" value="{{ old('cin', $editContact?->cin) }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                        @endif
                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">ICE / GSTIN</span><input name="ice" value="{{ old('ice', $editContact?->ice) }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">N° taxe</span><input name="tax_number" value="{{ old('tax_number', $editContact?->tax_number) }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Magasin</span><input name="store_id" value="{{ old('store_id', $editContact?->store_id) }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Atlas, dépôt..."></label>
                    </div>

                    @if ($contactKind === 'supplier')
                        <input type="hidden" name="credit_limit" value="0">
                        <input type="hidden" name="advance_balance" value="{{ old('advance_balance', $editContact?->advance_balance ?? 0) }}">
                        <input type="hidden" name="outstanding_balance" value="{{ old('outstanding_balance', $editContact?->outstanding_balance ?? 0) }}">
                        <input type="hidden" name="fine_balance" value="0">
                        <input type="hidden" name="price_level_type" value="increase">
                        <input type="hidden" name="price_level" value="0">
                        <div class="grid gap-4 rounded-xl bg-slate-50 p-4 dark:bg-white/5 lg:grid-cols-3">
                            <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Solde précédent</span><input name="opening_balance" value="{{ old('opening_balance', $editContact?->opening_balance ?? 0) }}" type="number" step="0.01" class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                            <div class="rounded-lg border border-slate-200 bg-white p-3 text-sm dark:border-white/10 dark:bg-slate-900"><span class="block text-xs font-semibold uppercase text-slate-500">Achat dû</span><strong class="mt-2 block text-rose-600">{{ $money($contactStats['supplier_purchases']) }}</strong></div>
                            <div class="rounded-lg border border-slate-200 bg-white p-3 text-sm dark:border-white/10 dark:bg-slate-900"><span class="block text-xs font-semibold uppercase text-slate-500">Retour achat dû</span><strong class="mt-2 block text-emerald-600">{{ $money($contactStats['supplier_returns']) }}</strong></div>
                        </div>
                    @else
                        <div class="grid gap-4 rounded-xl bg-slate-50 p-4 dark:bg-white/5 lg:grid-cols-4">
                            <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Limite crédit</span><input name="credit_limit" value="{{ old('credit_limit', $editContact?->credit_limit ?? 0) }}" type="number" min="0" step="0.01" class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                            <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Solde initial</span><input name="opening_balance" value="{{ old('opening_balance', $editContact?->opening_balance ?? 0) }}" type="number" step="0.01" class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                            <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Avance</span><input name="advance_balance" value="{{ old('advance_balance', $editContact?->advance_balance ?? 0) }}" type="number" min="0" step="0.01" class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                            <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Solde dû</span><input name="outstanding_balance" value="{{ old('outstanding_balance', $editContact?->outstanding_balance ?? 0) }}" type="number" min="0" step="0.01" class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                            <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Pénalités</span><input name="fine_balance" value="{{ old('fine_balance', $editContact?->fine_balance ?? 0) }}" type="number" min="0" step="0.01" class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                            <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Expiration membre</span><input name="membership_expires_at" value="{{ old('membership_expires_at', $editContact?->membership_expires_at?->toDateString()) }}" type="date" class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                            <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Niveau prix</span><select name="price_level_type" class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="increase" @selected(old('price_level_type', $editContact?->price_level_type ?? 'increase') === 'increase')>Augmenter</option><option value="decrease" @selected(old('price_level_type', $editContact?->price_level_type) === 'decrease')>Réduire</option></select></label>
                            <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Prix %</span><input name="price_level" value="{{ old('price_level', $editContact?->price_level ?? 0) }}" type="number" min="0" step="0.01" class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                        </div>
                    @endif

                    <div class="grid gap-4 {{ $contactKind === 'supplier' ? 'lg:grid-cols-1' : 'lg:grid-cols-2' }}">
                        <div class="space-y-4 rounded-xl border border-slate-200 p-4 dark:border-white/10">
                            <h3 class="font-semibold">Adresse principale</h3>
                            <div class="grid gap-3 sm:grid-cols-2"><input name="country" value="{{ old('country', $editContact?->country) }}" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Pays"><input name="state" value="{{ old('state', $editContact?->state) }}" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Région"><input name="city" value="{{ old('city', $editContact?->city) }}" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Ville"><input name="postcode" value="{{ old('postcode', $editContact?->postcode) }}" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Code postal"></div>
                            <textarea name="address" class="min-h-24 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Adresse">{{ old('address', $editContact?->address) }}</textarea>
                            <input name="location_link" value="{{ old('location_link', $editContact?->location_link) }}" class="h-10 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Lien Google Maps">
                        </div>
                        @if ($contactKind !== 'supplier')
                            <div class="space-y-4 rounded-xl border border-slate-200 p-4 dark:border-white/10">
                                <div class="flex items-center justify-between gap-3"><h3 class="font-semibold">Adresse livraison</h3><label class="flex items-center gap-2 text-sm"><input name="copy_address" value="1" type="checkbox" class="rounded border-slate-300"> Copier</label></div>
                                <div class="grid gap-3 sm:grid-cols-2"><input name="shipping_country" value="{{ old('shipping_country', $editContact?->shipping_country) }}" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Pays"><input name="shipping_state" value="{{ old('shipping_state', $editContact?->shipping_state) }}" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Région"><input name="shipping_city" value="{{ old('shipping_city', $editContact?->shipping_city) }}" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Ville"><input name="shipping_postcode" value="{{ old('shipping_postcode', $editContact?->shipping_postcode) }}" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Code postal"></div>
                                <textarea name="shipping_address" class="min-h-24 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Adresse livraison">{{ old('shipping_address', $editContact?->shipping_address) }}</textarea>
                                <input name="shipping_location_link" value="{{ old('shipping_location_link', $editContact?->shipping_location_link) }}" class="h-10 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Lien localisation livraison">
                            </div>
                        @endif
                    </div>

                    <div class="grid gap-4 lg:grid-cols-3">
                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Tags</span><input name="tags" value="{{ old('tags', collect($editContact?->tags)->implode(', ')) }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="VIP, école, relance..."></label>
                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Pièce jointe</span><input name="attachment" type="file" class="block h-11 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Note interne</span><input name="note" value="{{ old('note', $editContact?->note) }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Note rapide"></label>
                    </div>
                </form>
                <aside class="space-y-4">
                    <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><h3 class="font-semibold">{{ $contactKind === 'supplier' ? 'Résumé fournisseurs' : 'Résumé contacts' }}</h3><dl class="mt-4 space-y-3 text-sm">@if ($contactKind === 'supplier')<div class="flex justify-between"><dt class="text-slate-500">Fournisseurs</dt><dd class="font-semibold">{{ $contactStats['suppliers'] }}</dd></div><div class="flex justify-between"><dt class="text-slate-500">Achat dû</dt><dd class="font-semibold text-rose-600">{{ $money($contactStats['supplier_purchases']) }}</dd></div><div class="flex justify-between"><dt class="text-slate-500">Retours achat</dt><dd class="font-semibold text-emerald-600">{{ $money($contactStats['supplier_returns']) }}</dd></div>@else<div class="flex justify-between"><dt class="text-slate-500">Clients</dt><dd class="font-semibold">{{ $contactStats['clients'] }}</dd></div><div class="flex justify-between"><dt class="text-slate-500">Fournisseurs</dt><dd class="font-semibold">{{ $contactStats['suppliers'] }}</dd></div><div class="flex justify-between"><dt class="text-slate-500">Créances</dt><dd class="font-semibold text-rose-600">{{ $money($contactStats['receivable']) }}</dd></div><div class="flex justify-between"><dt class="text-slate-500">Avances</dt><dd class="font-semibold text-emerald-600">{{ $money($contactStats['advances']) }}</dd></div>@endif</dl></article>
                    <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><h3 class="font-semibold">Champs importés</h3><p class="mt-2 text-sm text-slate-500">{{ $contactKind === 'supplier' ? "La fiche fournisseur reprend les champs de l'ancien écran: ID fournisseur, mobile, email, ICE/GST, taxe, solde précédent et adresse." : "La fiche garde les champs utiles de l'ancien écran: crédit, solde initial, adresse livraison, ICE/GST, niveau de prix et pièce jointe." }}</p></article>
                </aside>
            </section>
        @else
            @php $listKind = in_array($contactSection, ['suppliers', 'import-suppliers'], true) ? 'supplier' : 'client'; @endphp
            <section class="mt-6 space-y-5">
                <div class="grid gap-3 md:grid-cols-4">
                    @if ($listKind === 'supplier')
                        <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><span class="text-xs font-semibold uppercase text-slate-500">Fournisseurs</span><p class="mt-2 text-2xl font-semibold">{{ $contactStats['suppliers'] }}</p></article>
                        <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><span class="text-xs font-semibold uppercase text-slate-500">Solde précédent</span><p class="mt-2 text-2xl font-semibold">{{ $money($contactStats['supplier_previous']) }}</p></article>
                        <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><span class="text-xs font-semibold uppercase text-slate-500">Achat dû</span><p class="mt-2 text-2xl font-semibold text-rose-600">{{ $money($contactStats['supplier_purchases']) }}</p></article>
                        <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><span class="text-xs font-semibold uppercase text-slate-500">Retour achat dû</span><p class="mt-2 text-2xl font-semibold text-emerald-600">{{ $money($contactStats['supplier_returns']) }}</p></article>
                    @else
                        <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><span class="text-xs font-semibold uppercase text-slate-500">Clients</span><p class="mt-2 text-2xl font-semibold">{{ $contactStats['clients'] }}</p></article>
                        <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><span class="text-xs font-semibold uppercase text-slate-500">Fournisseurs</span><p class="mt-2 text-2xl font-semibold">{{ $contactStats['suppliers'] }}</p></article>
                        <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><span class="text-xs font-semibold uppercase text-slate-500">Créances client</span><p class="mt-2 text-2xl font-semibold text-rose-600">{{ $money($contactStats['receivable']) }}</p></article>
                        <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><span class="text-xs font-semibold uppercase text-slate-500">Avances</span><p class="mt-2 text-2xl font-semibold text-emerald-600">{{ $money($contactStats['advances']) }}</p></article>
                    @endif
                </div>
                <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                        <div><h2 class="font-semibold">{{ $listKind === 'supplier' ? 'Liste des fournisseurs' : 'Liste des clients' }}</h2><p class="mt-1 text-sm text-slate-500">Recherche serveur, pagination, tri, export navigateur et actions rapides.</p></div>
                        <div class="app-action-row">
                            <a href="{{ route('module', ['module' => 'contacts', 'section' => $listKind === 'supplier' ? 'supplier-add' : 'customer-add']) }}" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Ajouter</a>
                            <a href="{{ route('contacts.import.example', $listKind) }}" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold dark:border-white/10">Exemple Excel</a>
                            <a href="{{ route('module', ['module' => 'contacts', 'section' => $listKind === 'supplier' ? 'suppliers' : 'customers']) }}" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold dark:border-white/10">Actualiser</a>
                        </div>
                    </div>
                    <form action="{{ route('contacts.import') }}" method="POST" enctype="multipart/form-data" class="app-action-form mt-4 rounded-xl border border-slate-200 bg-slate-50 p-3 dark:border-white/10 dark:bg-white/5">
                        @csrf
                        <input type="hidden" name="kind" value="{{ $listKind }}">
                        <input name="contact_file" required type="file" accept=".csv,.tsv,.xlsx" class="min-h-11 rounded-lg border border-dashed border-slate-300 bg-white p-2 text-sm dark:border-white/10 dark:bg-slate-900">
                        <a href="{{ route('contacts.import.example', $listKind) }}" class="grid h-11 place-items-center rounded-lg border border-slate-200 px-4 text-sm font-semibold dark:border-white/10">Télécharger modèle</a>
                        <button class="h-11 rounded-lg bg-brand px-4 text-sm font-semibold text-white">Importer {{ $listKind === 'supplier' ? 'fournisseurs' : 'clients' }}</button>
                    </form>
                </article>
                <article class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <div class="overflow-x-auto p-4">
                        <table data-contact-table data-kind="{{ $listKind }}" data-ajax-url="{{ route('contacts.data', ['kind' => $listKind]) }}" class="w-full min-w-[1240px] text-left text-sm">
                            <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-white/5">
                                @if ($listKind === 'supplier')
                                    <tr><th><input type="checkbox" class="rounded border-slate-300"></th><th>ID fournisseur</th><th>Nom fournisseur</th><th>Mobile</th><th>Email</th><th>Solde précédent</th><th>Achat dû</th><th>Retour d'achat dû</th><th>Total</th><th>Statut</th><th class="text-right">Action</th></tr>
                                @else
                                    <tr><th><input type="checkbox" class="rounded border-slate-300"></th><th>N° client</th><th>Nom client</th><th>Mobile</th><th>Email</th><th>Emplacement</th><th>Limite crédit</th><th>Solde dû</th><th>Avance</th><th>Statut</th><th class="text-right">Action</th></tr>
                                @endif
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </article>
            </section>
        @endif
    @elseif ($module === 'cash-register')
        @include('librairepro.partials.cash-register-section')
    @elseif ($module === 'finance')
        @php
            $financeSection = request('section', 'expenses');
            $financeTabs = [
                'advances' => ['label' => 'Liste des avances', 'href' => route('module', ['module' => 'finance', 'section' => 'advances'])],
                'advance-add' => ['label' => 'Ajouter avance', 'href' => route('module', ['module' => 'finance', 'section' => 'advance-add'])],
                'account-add' => ['label' => 'Ajouter compte', 'href' => route('module', ['module' => 'finance', 'section' => 'account-add'])],
                'accounts' => ['label' => 'Liste des comptes', 'href' => route('module', ['module' => 'finance', 'section' => 'accounts'])],
                'transfers' => ['label' => "Transferts d'argent", 'href' => route('module', ['module' => 'finance', 'section' => 'transfers'])],
                'deposits' => ['label' => 'Dépôts', 'href' => route('module', ['module' => 'finance', 'section' => 'deposits'])],
                'cash' => ['label' => 'Transactions espèces', 'href' => route('module', ['module' => 'finance', 'section' => 'cash'])],
                'coupon-add' => ['label' => 'Créer coupon', 'href' => route('module', ['module' => 'finance', 'section' => 'coupon-add'])],
                'coupons' => ['label' => 'Liste coupons', 'href' => route('module', ['module' => 'finance', 'section' => 'coupons'])],
                'customer-coupon-add' => ['label' => 'Créer coupon client', 'href' => route('module', ['module' => 'finance', 'section' => 'customer-coupon-add'])],
                'customer-coupons' => ['label' => 'Coupons client', 'href' => route('module', ['module' => 'finance', 'section' => 'customer-coupons'])],
                'discount-add' => ['label' => 'Créer remise', 'href' => route('module', ['module' => 'finance', 'section' => 'discount-add'])],
                'discounts' => ['label' => 'Liste remises', 'href' => route('module', ['module' => 'finance', 'section' => 'discounts'])],
                'expenses' => ['label' => 'Liste des dépenses', 'href' => route('module', ['module' => 'finance', 'section' => 'expenses'])],
                'expense-add' => ['label' => 'Ajouter dépense', 'href' => route('module', ['module' => 'finance', 'section' => 'expense-add'])],
                'expense-categories' => ['label' => 'Catégories', 'href' => route('module', ['module' => 'finance', 'section' => 'expense-categories'])],
            ];
            $accountTypeLabels = ['cash' => 'Caisse', 'bank' => 'Banque', 'card' => 'Carte / TPE', 'mobile' => 'Wallet mobile', 'other' => 'Autre'];
        @endphp
        <details class="app-collapsible-menu mt-6" data-collapsible-menu data-menu-key="module-finance-menu">
            <summary class="app-collapsible-menu-summary">
                <span><strong>Menu finances</strong><small>{{ $financeTabs[$financeSection]['label'] ?? 'Liste des dépenses' }}</small></span>
                <em data-collapsible-menu-state>Afficher</em>
            </summary>
            <nav class="app-tab-nav">
                @foreach ($financeTabs as $key => $tab)
                    <a href="{{ $tab['href'] }}" class="app-tab-link {{ $financeSection === $key || ($key === 'expenses' && ! in_array($financeSection, ['advance-add', 'advances', 'account-add', 'accounts', 'transfers', 'deposits', 'cash', 'coupon-add', 'coupons', 'customer-coupon-add', 'customer-coupons', 'discount-add', 'discounts', 'expense-add', 'expense-categories'], true)) ? 'is-active' : '' }}">{{ $tab['label'] }}</a>
                @endforeach
            </nav>
        </details>

        @if ($financeSection === 'advance-add')
            <section class="mt-6 grid gap-6 xl:grid-cols-[1fr_340px]">
                <form action="{{ route('customer-advances.store') }}" method="POST" class="space-y-5 rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    @csrf
                    <div class="flex flex-col gap-2 border-b border-slate-200 pb-4 dark:border-white/10 sm:flex-row sm:items-center sm:justify-between">
                        <div><h2 class="text-lg font-semibold">Nouvelle avance client</h2><p class="mt-1 text-sm text-slate-500">Enregistrez un paiement anticipé et mettez à jour immédiatement le solde disponible en caisse.</p></div>
                        <button class="rounded-lg bg-brand px-4 py-2.5 text-sm font-semibold text-white">Enregistrer l'avance</button>
                    </div>
                    <div class="grid gap-4 lg:grid-cols-2">
                        <label class="space-y-1.5 lg:col-span-2"><span class="text-xs font-semibold uppercase text-slate-500">Client requis</span><select name="contact_id" required data-searchable-select class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="">Sélectionner un client</option>@foreach ($financeClients as $client)<option value="{{ $client->id }}" @selected(old('contact_id') == $client->id)>{{ $client->code ?? 'CL' }} · {{ $client->name }} · solde avance {{ $money($client->advance_balance) }}</option>@endforeach</select></label>
                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Montant DH requis</span><input name="amount" value="{{ old('amount') }}" required type="number" min="0.01" step="0.01" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="0,00"></label>
                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Date de paiement</span><input name="paid_at" value="{{ old('paid_at', now()->format('Y-m-d\TH:i')) }}" type="datetime-local" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Mode de paiement</span><select name="payment_method" class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="cash" @selected(old('payment_method') === 'cash')>Espèces</option><option value="card" @selected(old('payment_method') === 'card')>Carte</option><option value="transfer" @selected(old('payment_method') === 'transfer')>Virement</option><option value="cheque" @selected(old('payment_method') === 'cheque')>Chèque</option><option value="other" @selected(old('payment_method') === 'other')>Autre</option></select></label>
                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Référence</span><input name="reference" value="{{ old('reference') }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Reçu, virement, chèque..."></label>
                        <label class="space-y-1.5 lg:col-span-2"><span class="text-xs font-semibold uppercase text-slate-500">Note</span><textarea name="note" class="min-h-24 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Détail interne ou condition d'utilisation...">{{ old('note') }}</textarea></label>
                    </div>
                </form>
                <aside class="space-y-4">
                    <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><h3 class="font-semibold">Résumé avances</h3><dl class="mt-4 space-y-3 text-sm"><div class="flex justify-between"><dt class="text-slate-500">Solde disponible</dt><dd class="font-semibold text-emerald-600">{{ $money($advanceStats['balance'] ?? 0) }}</dd></div><div class="flex justify-between"><dt class="text-slate-500">Ce mois</dt><dd class="font-semibold">{{ $money($advanceStats['month'] ?? 0) }}</dd></div><div class="flex justify-between"><dt class="text-slate-500">Reçus actifs</dt><dd class="font-semibold">{{ $advanceStats['active_count'] ?? 0 }}</dd></div></dl></article>
                    <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><h3 class="font-semibold">Utilisation en caisse</h3><p class="mt-2 text-sm text-slate-500">Le solde avance du client apparaît dans le POS et peut être utilisé comme moyen de paiement partiel ou total.</p></article>
                </aside>
            </section>
        @elseif ($financeSection === 'advances')
            <section class="mt-6 space-y-5">
                <div class="grid gap-3 md:grid-cols-4">
                    <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><span class="text-xs font-semibold uppercase text-slate-500">Solde avances client</span><p class="mt-2 text-2xl font-semibold text-emerald-600">{{ $money($advanceStats['balance'] ?? 0) }}</p></article>
                    <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><span class="text-xs font-semibold uppercase text-slate-500">Avances ce mois</span><p class="mt-2 text-2xl font-semibold">{{ $money($advanceStats['month'] ?? 0) }}</p></article>
                    <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><span class="text-xs font-semibold uppercase text-slate-500">Reçus actifs</span><p class="mt-2 text-2xl font-semibold">{{ $advanceStats['active_count'] ?? 0 }}</p></article>
                    <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><span class="text-xs font-semibold uppercase text-slate-500">Montant page</span><p class="mt-2 text-2xl font-semibold">{{ $money($advanceStats['page'] ?? 0) }}</p></article>
                </div>
                <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <div class="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
                        <div><h2 class="font-semibold">Liste des paiements anticipés</h2><p class="mt-1 text-sm text-slate-500">Recherche serveur, reçus imprimables, annulation contrôlée et solde client synchronisé.</p></div>
                        <div class="app-action-row"><a href="{{ route('module', ['module' => 'finance', 'section' => 'advance-add']) }}" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Ajouter une avance</a><a href="{{ route('module', ['module' => 'finance', 'section' => 'advances']) }}" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold dark:border-white/10">Actualiser</a></div>
                    </div>
                    <form class="app-action-form mt-4" method="GET" action="{{ route('module', ['module' => 'finance', 'section' => 'advances']) }}">
                        <input type="hidden" name="section" value="advances">
                        <input name="q" value="{{ request('q') }}" class="h-11 rounded-lg border border-slate-200 bg-slate-50 px-3 text-sm dark:border-white/10 dark:bg-white/5" placeholder="Rechercher client, reçu, mobile, référence...">
                        <select name="client" class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="">Tous les clients</option>@foreach ($financeClients as $client)<option value="{{ $client->id }}" @selected((string) request('client') === (string) $client->id)>{{ $client->name }}</option>@endforeach</select>
                        <select name="payment_method" class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="">Paiement</option><option value="cash" @selected(request('payment_method') === 'cash')>Espèces</option><option value="card" @selected(request('payment_method') === 'card')>Carte</option><option value="transfer" @selected(request('payment_method') === 'transfer')>Virement</option><option value="cheque" @selected(request('payment_method') === 'cheque')>Chèque</option><option value="other" @selected(request('payment_method') === 'other')>Autre</option></select>
                        <input name="from" value="{{ request('from') }}" type="date" class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">
                        <input name="to" value="{{ request('to') }}" type="date" class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">
                        <div class="flex gap-2"><button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Filtrer</button><a href="{{ route('module', ['module' => 'finance', 'section' => 'advances']) }}" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold dark:border-white/10">Reset</a></div>
                    </form>
                </article>
                <article class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <div class="overflow-x-auto p-4">
                        <table data-advance-table data-ajax-url="{{ route('customer-advances.data', request()->only(['q', 'client', 'payment_method', 'advance_status', 'from', 'to'])) }}" class="w-full min-w-[1180px] text-left text-sm">
                            <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-white/5"><tr><th><input type="checkbox" class="rounded border-slate-300"></th><th>Date</th><th>N° avance</th><th>Client</th><th>Mobile</th><th>Paiement</th><th>Référence</th><th class="text-right">Montant</th><th>Statut</th><th class="text-right">Action</th></tr></thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </article>
                <dialog data-advance-receipt-dialog class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-0 text-slate-950 shadow-2xl backdrop:bg-slate-950/40 dark:border-white/10 dark:bg-slate-950 dark:text-slate-100">
                    <div class="border-b border-slate-200 p-5 dark:border-white/10"><div class="flex justify-between gap-4"><div><p class="text-sm font-semibold text-brand">Reçu avance</p><h3 class="mt-1 text-xl font-semibold" data-advance-receipt-value="number">—</h3></div><button class="dialog-close grid size-9 place-items-center rounded-lg border border-slate-200 text-lg font-semibold dark:border-white/10" type="button">×</button></div></div>
                    <div class="space-y-3 p-5 text-sm">
                        <div class="rounded-xl bg-slate-50 p-4 text-center dark:bg-white/5"><span class="block text-xs font-semibold uppercase text-slate-500">Montant reçu</span><strong class="mt-1 block text-3xl text-emerald-600" data-advance-receipt-value="amount">—</strong></div>
                        <div class="grid gap-2 sm:grid-cols-2"><div class="rounded-lg border border-slate-200 p-3 dark:border-white/10"><span class="block text-xs text-slate-500">Client</span><strong data-advance-receipt-value="client">—</strong></div><div class="rounded-lg border border-slate-200 p-3 dark:border-white/10"><span class="block text-xs text-slate-500">Mobile</span><strong data-advance-receipt-value="mobile">—</strong></div><div class="rounded-lg border border-slate-200 p-3 dark:border-white/10"><span class="block text-xs text-slate-500">Paiement</span><strong data-advance-receipt-value="payment">—</strong></div><div class="rounded-lg border border-slate-200 p-3 dark:border-white/10"><span class="block text-xs text-slate-500">Date</span><strong data-advance-receipt-value="date">—</strong></div></div>
                        <div class="rounded-lg border border-slate-200 p-3 dark:border-white/10"><span class="block text-xs text-slate-500">Référence</span><strong data-advance-receipt-value="reference">—</strong></div>
                        <p class="rounded-lg bg-slate-50 p-3 text-slate-500 dark:bg-white/5" data-advance-receipt-value="note">—</p>
                        <button type="button" onclick="window.print()" class="w-full rounded-lg bg-brand px-4 py-2.5 text-sm font-semibold text-white">Imprimer le reçu</button>
                    </div>
                </dialog>
            </section>
        @elseif ($financeSection === 'account-add')
            <section class="mt-6 grid gap-6 xl:grid-cols-[1fr_340px]">
                <form action="{{ route('accounts.store') }}" method="POST" class="space-y-5 rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    @csrf
                    <div class="flex flex-col gap-2 border-b border-slate-200 pb-4 dark:border-white/10 sm:flex-row sm:items-center sm:justify-between">
                        <div><h2 class="text-lg font-semibold">Ajouter un compte</h2><p class="mt-1 text-sm text-slate-500">Créez un compte banque, caisse ou TPE lié à un magasin.</p></div>
                        <button class="rounded-lg bg-brand px-4 py-2.5 text-sm font-semibold text-white">Enregistrer</button>
                    </div>
                    <div class="grid gap-4 lg:grid-cols-2">
                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Nom du compte requis</span><input name="name" required value="{{ old('name') }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Compte BMCE, Caisse principale..."></label>
                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Type</span><select name="type" class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">@foreach($accountTypeLabels as $key => $label)<option value="{{ $key }}" @selected(old('type', 'bank') === $key)>{{ $label }}</option>@endforeach</select></label>
                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Magasin</span><select name="store_key" class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">@foreach($stores as $store)<option value="{{ $store['key'] }}" @selected(old('store_key', $currentStore['key']) === $store['key'])>{{ $store['name'] }}</option>@endforeach</select></label>
                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Solde d'ouverture</span><input name="opening_balance" value="{{ old('opening_balance', 0) }}" type="number" step="0.01" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Banque</span><input name="bank_name" value="{{ old('bank_name') }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Attijariwafa, BMCE..."></label>
                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">N° compte</span><input name="account_number" value="{{ old('account_number') }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Titulaire</span><input name="holder_name" value="{{ old('holder_name', $tenant->name) }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                        <label class="flex h-11 items-center gap-2 rounded-lg border border-slate-200 px-3 text-sm font-semibold dark:border-white/10"><input name="is_active" value="1" checked type="checkbox" class="size-4 accent-[var(--brand-primary)]"> Actif</label>
                        <label class="space-y-1.5 lg:col-span-2"><span class="text-xs font-semibold uppercase text-slate-500">Description</span><textarea name="description" class="min-h-24 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-white/10 dark:bg-slate-900">{{ old('description') }}</textarea></label>
                    </div>
                </form>
                <aside class="space-y-4">
                    <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><h3 class="font-semibold">Résumé comptes</h3><dl class="mt-4 space-y-3 text-sm"><div class="flex justify-between"><dt class="text-slate-500">Solde total</dt><dd class="font-semibold">{{ $money($accountStats['balance'] ?? 0) }}</dd></div><div class="flex justify-between"><dt class="text-slate-500">Comptes actifs</dt><dd class="font-semibold">{{ $accountStats['active'] ?? 0 }}</dd></div><div class="flex justify-between"><dt class="text-slate-500">Caisse</dt><dd class="font-semibold">{{ $money($accountStats['cash'] ?? 0) }}</dd></div></dl></article>
                </aside>
            </section>
        @elseif ($financeSection === 'accounts')
            <section class="mt-6 space-y-5">
                <div class="grid gap-3 md:grid-cols-4">
                    <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><span class="text-xs font-semibold uppercase text-slate-500">Solde total</span><p class="mt-2 text-2xl font-semibold">{{ $money($accountStats['balance'] ?? 0) }}</p></article>
                    <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><span class="text-xs font-semibold uppercase text-slate-500">Banque</span><p class="mt-2 text-2xl font-semibold">{{ $money($accountStats['bank'] ?? 0) }}</p></article>
                    <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><span class="text-xs font-semibold uppercase text-slate-500">Caisse</span><p class="mt-2 text-2xl font-semibold">{{ $money($accountStats['cash'] ?? 0) }}</p></article>
                    <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><span class="text-xs font-semibold uppercase text-slate-500">Actifs</span><p class="mt-2 text-2xl font-semibold">{{ $accountStats['active'] ?? 0 }}</p></article>
                </div>
                <article class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <div class="flex flex-col gap-3 border-b border-slate-200 p-4 dark:border-white/10 lg:flex-row lg:items-center lg:justify-between"><div><h2 class="font-semibold">Liste des comptes</h2><p class="mt-1 text-sm text-slate-500">Comptes bancaires, caisses et TPE par magasin.</p></div><a href="{{ route('module', ['module' => 'finance', 'section' => 'account-add']) }}" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Ajouter un compte</a></div>
                    <div class="overflow-x-auto"><table class="w-full min-w-[1040px] text-left text-sm"><thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-white/5"><tr><th class="px-3 py-3">Compte</th><th class="px-3 py-3">Magasin</th><th class="px-3 py-3">Type</th><th class="px-3 py-3">Banque</th><th class="px-3 py-3">N° compte</th><th class="px-3 py-3 text-right">Solde</th><th class="px-3 py-3">Statut</th><th class="px-3 py-3 text-right">Action</th></tr></thead><tbody class="divide-y divide-slate-200 dark:divide-white/10">@forelse($financialAccounts as $account)<tr><td class="px-3 py-3"><p class="font-semibold">{{ $account->name }}</p><p class="mt-1 text-xs text-slate-500">{{ $account->holder_name ?: $tenant->name }}</p></td><td class="px-3 py-3">{{ collect($stores)->firstWhere('key', $account->store_key)['name'] ?? '—' }}</td><td class="px-3 py-3">{{ $accountTypeLabels[$account->type] ?? $account->type }}</td><td class="px-3 py-3">{{ $account->bank_name ?: '—' }}</td><td class="px-3 py-3 text-slate-500">{{ $account->account_number ?: '—' }}</td><td class="px-3 py-3 text-right font-semibold">{{ $money($account->current_balance) }}</td><td class="px-3 py-3"><x-status-pill :tone="$account->is_active ? 'success' : 'danger'">{{ $account->is_active ? 'Actif' : 'Inactif' }}</x-status-pill></td><td class="px-3 py-3 text-right"><button type="button" onclick="document.getElementById('account-edit-{{ $account->id }}').showModal()" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold dark:border-white/10">Modifier</button></td></tr><dialog id="account-edit-{{ $account->id }}" class="w-full max-w-2xl rounded-2xl border border-slate-200 bg-white p-0 text-slate-950 shadow-2xl backdrop:bg-slate-950/40 dark:border-white/10 dark:bg-slate-950 dark:text-slate-100"><form action="{{ route('accounts.update', $account) }}" method="POST" class="p-5">@csrf @method('PUT')<h3 class="text-lg font-semibold">Modifier compte</h3><div class="mt-4 grid gap-3 md:grid-cols-2"><input name="name" required value="{{ $account->name }}" class="h-11 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"><select name="type" class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">@foreach($accountTypeLabels as $key => $label)<option value="{{ $key }}" @selected($account->type === $key)>{{ $label }}</option>@endforeach</select><select name="store_key" class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">@foreach($stores as $store)<option value="{{ $store['key'] }}" @selected($account->store_key === $store['key'])>{{ $store['name'] }}</option>@endforeach</select><input name="bank_name" value="{{ $account->bank_name }}" class="h-11 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Banque"><input name="account_number" value="{{ $account->account_number }}" class="h-11 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="N° compte"><input name="holder_name" value="{{ $account->holder_name }}" class="h-11 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Titulaire"><label class="flex items-center gap-2 text-sm font-semibold"><input name="is_active" value="1" type="checkbox" @checked($account->is_active) class="size-4 accent-[var(--brand-primary)]"> Actif</label><textarea name="description" class="min-h-20 rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-white/10 dark:bg-slate-900 md:col-span-2">{{ $account->description }}</textarea></div><div class="mt-5 flex justify-end gap-2"><button type="button" class="dialog-close rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold dark:border-white/10">Fermer</button><button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Enregistrer</button></div></form><form action="{{ route('accounts.destroy', $account) }}" method="POST" class="px-5 pb-5 text-right" onsubmit="return confirm('Supprimer ce compte ?')">@csrf @method('DELETE')<button class="text-sm font-semibold text-rose-600">Supprimer</button></form></dialog>@empty<tr><td colspan="8" class="px-4 py-12 text-center text-sm text-slate-500">Aucun compte trouvé.</td></tr>@endforelse</tbody></table></div>
                </article>
            </section>
        @elseif ($financeSection === 'deposits')
            <section class="mt-6 grid gap-6 xl:grid-cols-[380px_1fr]">
                <form action="{{ route('accounts.deposits.store') }}" method="POST" class="space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">@csrf<h2 class="text-lg font-semibold">Nouveau dépôt</h2><select name="financial_account_id" required data-searchable-select class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="">Compte destination</option>@foreach($financialAccounts->where('is_active', true) as $account)<option value="{{ $account->id }}">{{ $account->name }} · {{ $money($account->current_balance) }}</option>@endforeach</select><input name="amount" required type="number" min="0.01" step="0.01" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Montant DH"><select name="payment_method" class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="cash">Espèces</option><option value="card">Carte</option><option value="transfer">Virement</option><option value="cheque">Chèque</option><option value="other">Autre</option></select><input name="transacted_at" type="datetime-local" value="{{ now()->format('Y-m-d\TH:i') }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"><input name="reference" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Référence"><textarea name="note" class="min-h-24 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Note"></textarea><button class="w-full rounded-lg bg-brand px-4 py-2.5 text-sm font-semibold text-white">Enregistrer dépôt</button></form>
                <article class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.03]">@include('librairepro.partials.account-transactions-table', ['transactions' => $accountTransactions, 'money' => $money])</article>
            </section>
        @elseif ($financeSection === 'transfers')
            <section class="mt-6 grid gap-6 xl:grid-cols-[420px_1fr]">
                <form action="{{ route('accounts.transfers.store') }}" method="POST" class="space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">@csrf<h2 class="text-lg font-semibold">Nouveau transfert d'argent</h2><select name="from_account_id" required data-searchable-select class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="">Compte source</option>@foreach($financialAccounts->where('is_active', true) as $account)<option value="{{ $account->id }}">{{ $account->name }} · {{ $money($account->current_balance) }}</option>@endforeach</select><select name="to_account_id" required data-searchable-select class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="">Compte destination</option>@foreach($financialAccounts->where('is_active', true) as $account)<option value="{{ $account->id }}">{{ $account->name }} · {{ $money($account->current_balance) }}</option>@endforeach</select><input name="amount" required type="number" min="0.01" step="0.01" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Montant DH"><input name="transacted_at" type="datetime-local" value="{{ now()->format('Y-m-d\TH:i') }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"><input name="reference" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Référence"><textarea name="note" class="min-h-24 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Note"></textarea><button class="w-full rounded-lg bg-brand px-4 py-2.5 text-sm font-semibold text-white">Transférer</button></form>
                <article class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.03]">@include('librairepro.partials.account-transactions-table', ['transactions' => $accountTransactions, 'money' => $money])</article>
            </section>
        @elseif ($financeSection === 'cash')
            <section class="mt-6 space-y-5">
                <div class="grid gap-3 md:grid-cols-3"><article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><span class="text-xs font-semibold uppercase text-slate-500">Solde caisse</span><p class="mt-2 text-2xl font-semibold">{{ $money($accountStats['cash'] ?? 0) }}</p></article><article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><span class="text-xs font-semibold uppercase text-slate-500">Mouvements caisse</span><p class="mt-2 text-2xl font-semibold">{{ $accountStats['cash_movements'] ?? 0 }}</p></article><article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><span class="text-xs font-semibold uppercase text-slate-500">Dépôts ce mois</span><p class="mt-2 text-2xl font-semibold">{{ $money($accountStats['deposits_month'] ?? 0) }}</p></article></div>
                <article class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.03]">@include('librairepro.partials.account-transactions-table', ['transactions' => $accountTransactions, 'money' => $money])</article>
            </section>
        @elseif (in_array($financeSection, ['coupon-add', 'coupons', 'customer-coupon-add', 'customer-coupons'], true))
            @include('librairepro.partials.coupons-section')
        @elseif (in_array($financeSection, ['discount-add', 'discounts'], true))
            @include('librairepro.partials.discounts-section')
        @elseif ($financeSection === 'expense-add')
            <section class="mt-6 grid gap-6 xl:grid-cols-[1fr_340px]">
                <form action="{{ route('expenses.store') }}" method="POST" class="space-y-5 rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    @csrf
                    <div class="flex flex-col gap-2 border-b border-slate-200 pb-4 dark:border-white/10 sm:flex-row sm:items-center sm:justify-between">
                        <div><h2 class="text-lg font-semibold">Ajouter une dépense</h2><p class="mt-1 text-sm text-slate-500">Enregistrez frais, charges, loyers, transport, achats hors stock et opérations de caisse.</p></div>
                        <button class="rounded-lg bg-brand px-4 py-2.5 text-sm font-semibold text-white">Enregistrer</button>
                    </div>
                    <div class="grid gap-4 lg:grid-cols-2">
                        <label class="space-y-1.5 lg:col-span-2"><span class="text-xs font-semibold uppercase text-slate-500">Libellé requis</span><input name="label" value="{{ old('label') }}" required class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Ex: Transport fournisseur, loyer, internet..."></label>
                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Catégorie requise</span><input name="category" value="{{ old('category') }}" required list="expense-category-options" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Choisir ou saisir une catégorie"><datalist id="expense-category-options">@foreach ($expenseCategories as $category)<option value="{{ $category->name }}">@if($category->icon){{ $category->icon }} @endif{{ $category->name }}</option>@endforeach</datalist></label>
                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Montant DH requis</span><input name="amount" value="{{ old('amount') }}" required type="number" min="0.01" step="0.01" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="0,00"></label>
                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Date</span><input name="spent_at" value="{{ old('spent_at', now()->toDateString()) }}" type="date" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Paiement</span><select name="payment_method" class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="cash">Espèces</option><option value="card">Carte</option><option value="transfer">Virement</option><option value="cheque">Chèque</option><option value="other">Autre</option></select></label>
                        <label class="space-y-1.5 lg:col-span-2"><span class="text-xs font-semibold uppercase text-slate-500">Référence</span><input name="reference" value="{{ old('reference') }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Laisser vide pour auto-génération (DEP-REF-XXXXX)"></label>
                        <label class="space-y-1.5 lg:col-span-2"><span class="text-xs font-semibold uppercase text-slate-500">Note</span><textarea name="note" class="min-h-24 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Détail interne...">{{ old('note') }}</textarea></label>
                    </div>
                </form>
                <aside class="space-y-4">
                    <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><h3 class="font-semibold">Contrôle caisse</h3><p class="mt-2 text-sm text-slate-500">Les dépenses en espèces seront visibles dans la vue finance et pourront alimenter la clôture Z.</p></article>
                    <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><h3 class="font-semibold">Catégories rapides</h3><div class="mt-3 flex flex-wrap gap-2">@forelse ($expenseCategories->take(8) as $category)<span class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600 dark:bg-white/10 dark:text-slate-300">@if($category->icon){{ $category->icon }} @endif<span class="inline-block size-2 rounded-full" style="background: {{ $category->color ?? '#4F46E5' }}"></span>{{ $category->name }}</span>@empty<span class="text-sm text-slate-500">Ajoutez vos premières catégories.</span>@endforelse</div><a href="{{ route('module', ['module' => 'finance', 'section' => 'expense-categories']) }}" class="mt-3 block text-xs font-semibold text-brand">Gérer les catégories →</a></article>
                </aside>
            </section>
        @elseif ($financeSection === 'expense-categories')
            <section class="mt-6 grid gap-6 xl:grid-cols-[380px_1fr]">
                <form action="{{ route('expenses.categories.store') }}" method="POST" class="space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    @csrf
                    <h2 class="text-lg font-semibold">Nouvelle catégorie</h2>
                    <label class="block space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Nom requis</span><input name="name" required class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Transport, Loyer, Marketing..."></label>
                    <label class="block space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Couleur</span><input name="color" value="#4F46E5" type="color" class="h-11 w-full rounded-lg border border-slate-200 px-2 py-1 dark:border-white/10 dark:bg-slate-900"></label>
                    <label class="block space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Icône (emoji)</span><input name="icon" value="{{ old('icon') }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="🚚 📦 💻 🏠"></label>
                    <label class="block space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Description</span><textarea name="description" class="min-h-24 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-white/10 dark:bg-slate-900"></textarea></label>
                    <button class="w-full rounded-lg bg-brand px-4 py-2.5 text-sm font-semibold text-white">Ajouter</button>
                </form>
                <article class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <div class="border-b border-slate-200 p-4 dark:border-white/10"><form method="GET" action="{{ route('module', ['module' => 'finance', 'section' => 'expense-categories']) }}" class="flex gap-2"><input type="hidden" name="section" value="expense-categories"><input name="q" value="{{ request('q') }}" class="h-11 flex-1 rounded-lg border border-slate-200 bg-slate-50 px-3 text-sm dark:border-white/10 dark:bg-white/5" placeholder="Rechercher catégorie..."><button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Chercher</button></form></div>
                    <div class="max-h-[620px] overflow-auto divide-y divide-slate-200 dark:divide-white/10">@forelse ($expenseCategories as $category)<div class="flex items-start justify-between gap-4 p-4"><div class="flex items-start gap-3"><span class="mt-1 size-3 rounded-full" style="background: {{ $category->color ?? '#4F46E5' }}"></span><div><h3 class="font-semibold">@if($category->icon){{ $category->icon }} @endif{{ $category->name }}</h3><p class="mt-1 text-sm text-slate-500">{{ $category->description ?? 'Sans description' }}</p></div></div><span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-500 dark:bg-white/10">{{ $expenses->getCollection()->where('category', $category->name)->count() }} dép.</span></div>@empty<div class="p-10 text-center text-sm text-slate-500">Aucune catégorie trouvée.</div>@endforelse</div>
                </article>
            </section>
        @else
            <section class="mt-6 space-y-5">
                <div class="grid gap-3 md:grid-cols-4">
                    <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><span class="text-xs font-semibold uppercase text-slate-500">Dépenses ce mois</span><p class="mt-2 text-2xl font-semibold text-rose-600">{{ $money($expenseTotals['month'] ?? 0) }}</p></article>
                    <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><span class="text-xs font-semibold uppercase text-slate-500">Montant page</span><p class="mt-2 text-2xl font-semibold">{{ $money($expenseTotals['page'] ?? 0) }}</p></article>
                    <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><span class="text-xs font-semibold uppercase text-slate-500">Catégories</span><p class="mt-2 text-2xl font-semibold">{{ $expenseTotals['categories'] ?? 0 }}</p></article>
                    <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><span class="text-xs font-semibold uppercase text-slate-500">Dépenses totales</span><p class="mt-2 text-2xl font-semibold">{{ $money($expenseTotals['total'] ?? 0) }}</p></article>
                </div>
                <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <form class="grid gap-3 lg:grid-cols-[1fr_180px_150px_150px_150px_auto]" method="GET" action="{{ route('module', ['module' => 'finance', 'section' => 'expenses']) }}">
                        <input type="hidden" name="section" value="expenses">
                        <input name="q" value="{{ request('q') }}" class="h-11 rounded-lg border border-slate-200 bg-slate-50 px-3 text-sm dark:border-white/10 dark:bg-white/5" placeholder="Rechercher dépense, référence, note...">
                        <select name="expense_category" class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="">Toutes catégories</option>@foreach ($expenseCategories as $category)<option value="{{ $category->name }}" @selected(request('expense_category') === $category->name)>{{ $category->name }}</option>@endforeach</select>
                        <input name="from" value="{{ request('from') }}" type="date" class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">
                        <input name="to" value="{{ request('to') }}" type="date" class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">
                        <select name="payment_method" class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="">Paiement</option><option value="cash" @selected(request('payment_method') === 'cash')>Espèces</option><option value="card" @selected(request('payment_method') === 'card')>Carte</option><option value="transfer" @selected(request('payment_method') === 'transfer')>Virement</option><option value="cheque" @selected(request('payment_method') === 'cheque')>Chèque</option><option value="other" @selected(request('payment_method') === 'other')>Autre</option></select>
                        <div class="flex gap-2"><button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Filtrer</button><a href="{{ route('module', ['module' => 'finance', 'section' => 'expenses']) }}" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold dark:border-white/10">Reset</a></div>
                    </form>
                </article>
                <article class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <div class="flex flex-col gap-3 border-b border-slate-200 p-4 dark:border-white/10 lg:flex-row lg:items-center lg:justify-between">
                        <div><h2 class="font-semibold">Liste des dépenses</h2><p class="mt-1 text-sm text-slate-500">Consultez, filtrez et gérez les dépenses enregistrées.</p></div>
                        <a href="{{ route('module', ['module' => 'finance', 'section' => 'expense-add']) }}" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Ajouter une dépense</a>
                    </div>
                    <div class="overflow-x-auto"><table class="w-full min-w-[1080px] text-left text-sm"><thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-white/5"><tr><th class="px-3 py-3">N° dépense</th><th class="px-3 py-3">Dépense</th><th class="px-3 py-3">Catégorie</th><th class="px-3 py-3">Date</th><th class="px-3 py-3">Paiement</th><th class="px-3 py-3">Référence</th><th class="px-3 py-3 text-right">Montant</th><th class="px-3 py-3 text-right">Action</th></tr></thead><tbody class="divide-y divide-slate-200 dark:divide-white/10">@forelse ($expenses as $expense)<tr><td class="px-3 py-3 font-semibold">{{ $expense->number ?? '—' }}</td><td class="px-3 py-3"><p class="font-semibold">{{ $expense->label }}</p><p class="mt-1 max-w-xs truncate text-xs text-slate-500">{{ $expense->note ?? '—' }}</p></td><td class="px-3 py-3">@php $expCat = $expenseCategories->firstWhere('name', $expense->category) @endphp@if($expCat && $expCat->color)<span class="inline-block size-2.5 rounded-full align-middle" style="background: {{ $expCat->color }}"></span> @endif{{ $expense->category }}</td><td class="px-3 py-3">{{ $expense->spent_at?->format('d/m/Y') }}</td><td class="px-3 py-3"><x-status-pill tone="info">{{ $expense->payment_method }}</x-status-pill></td><td class="px-3 py-3 text-slate-500">{{ $expense->reference ?? '—' }}</td><td class="px-3 py-3 text-right font-semibold">{{ $money($expense->amount) }}</td><td class="px-3 py-3 text-right"><button type="button" onclick="document.getElementById('expense-detail-{{ $expense->id }}').showModal()" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold dark:border-white/10">Détail</button></td></tr><dialog id="expense-detail-{{ $expense->id }}" class="w-full max-w-xl rounded-2xl border border-slate-200 bg-white p-0 text-slate-950 shadow-2xl backdrop:bg-slate-950/40 dark:border-white/10 dark:bg-slate-950 dark:text-slate-100"><div class="border-b border-slate-200 p-5 dark:border-white/10"><div class="flex justify-between gap-4"><div><p class="text-sm font-semibold text-brand">Détail dépense</p><h3 class="mt-1 text-xl font-semibold">{{ $expense->number ?? 'Dépense' }} · {{ $money($expense->amount) }}</h3><p class="mt-1 text-sm text-slate-500">{{ $expense->category }} · {{ $expense->spent_at?->format('d/m/Y') }} @if($expense->reference)· {{ $expense->reference }}@endif</p></div><button class="dialog-close grid size-9 place-items-center rounded-lg border border-slate-200 text-lg font-semibold dark:border-white/10" type="button">×</button></div></div><div class="space-y-3 p-5 text-sm"><div class="rounded-xl bg-slate-50 p-4 dark:bg-white/5"><strong class="block">{{ $expense->label }}</strong><p class="mt-2 text-slate-500">{{ $expense->note ?? 'Sans note' }}</p></div><div class="grid gap-2 sm:grid-cols-2"><div class="rounded-lg border border-slate-200 p-3 dark:border-white/10"><span class="block text-xs text-slate-500">Catégorie</span><strong>@php $expCat = $expenseCategories->firstWhere('name', $expense->category) @endphp@if($expCat && $expCat->color)<span class="inline-block size-2.5 rounded-full align-middle" style="background: {{ $expCat->color }}"></span> @endif{{ $expense->category }}</strong></div><div class="rounded-lg border border-slate-200 p-3 dark:border-white/10"><span class="block text-xs text-slate-500">Paiement</span><strong>{{ $expense->payment_method }}</strong></div><div class="rounded-lg border border-slate-200 p-3 dark:border-white/10"><span class="block text-xs text-slate-500">Référence</span><strong>{{ $expense->reference ?? '—' }}</strong></div><div class="rounded-lg border border-slate-200 p-3 dark:border-white/10"><span class="block text-xs text-slate-500">Montant</span><strong class="text-rose-600">{{ $money($expense->amount) }}</strong></div></div></div></dialog>@empty<tr><td colspan="8" class="px-4 py-12 text-center text-sm text-slate-500">Aucune dépense ne correspond aux filtres.</td></tr>@endforelse</tbody></table></div><div class="border-t border-slate-200 px-4 py-3 dark:border-white/10">{{ $expenses->links() }}</div>
                </article>
            </section>
        @endif
    @elseif ($module === 'reports')
        @php
            $reportSection = request('section', 'profit-loss');
            $reportTabs = [
                'profit-loss' => 'Rapport de profits et pertes',
                'sales-payments' => 'Ventes et paiements',
                'customer-orders' => 'Commandes client',
                'sales' => 'Rapport des ventes',
                'sales-summary' => 'Récapitulatif ventes',
                'item-sales' => 'Articles de vente',
                'sales-return' => 'Retours ventes',
                'return-items' => 'Articles retournés',
                'sales-payments-list' => 'Paiements ventes',
                'sales-return-payments' => 'Paiements retours',
                'purchases' => "Rapport d'achat",
                'purchase-return' => "Retours d'achat",
                'purchase-payments' => "Paiements d'achat",
                'supplier-items' => 'Articles fournisseur',
                'expenses' => 'Rapport de dépenses',
                'stock' => 'Rapport de stock',
                'stock-transfer' => 'Transfert de stock',
                'sales-tax' => 'Taxe de vente',
                'purchase-tax' => "Taxe d'achat",
                'gstr-1' => 'GSTR-1',
                'gstr-2' => 'GSTR-2',
                'sales-gst' => 'TPS ventes',
                'purchase-gst' => 'TPS achats',
                'seller-points' => 'Points vendeur',
            ];
            $summary = $reportContext['summary'];
            $reportRows = match ($reportSection) {
                'purchases', 'purchase-payments', 'supplier-items', 'purchase-tax', 'gstr-2', 'purchase-gst' => $reportContext['purchases'],
                'purchase-return' => $reportContext['purchaseReturns'],
                'expenses' => $reportContext['expenses'],
                'stock' => $reportContext['stockItems'],
                'sales-return', 'return-items', 'sales-return-payments' => $reportContext['saleReturns'],
                'sales-payments', 'sales-payments-list' => $reportContext['payments'],
                'item-sales' => $reportContext['topItems'],
                'sales-summary', 'sales-tax', 'gstr-1', 'sales-gst' => $reportContext['categorySales'],
                default => $reportContext['sales'],
            };
        @endphp
        <details class="app-collapsible-menu mt-6" data-collapsible-menu data-menu-key="module-reports-menu">
            <summary class="app-collapsible-menu-summary">
                <span><strong>Menu rapports</strong><small>{{ $reportTabs[$reportSection] ?? 'Rapports' }}</small></span>
                <em data-collapsible-menu-state>Afficher</em>
            </summary>
            <nav class="app-tab-nav">
                @foreach ($reportTabs as $key => $label)
                    <a href="{{ route('module', ['module' => 'reports', 'section' => $key]) }}" class="app-tab-link {{ $reportSection === $key ? 'is-active' : '' }}">{{ $label }}</a>
                @endforeach
            </nav>
        </details>

        <section class="mt-6 space-y-5">
            <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                <div class="flex flex-col gap-3 xl:flex-row xl:items-start xl:justify-between">
                    <div>
                        <p class="text-sm font-semibold text-brand">Rapports</p>
                        <h2 class="mt-1 text-xl font-semibold">{{ $reportTabs[$reportSection] ?? 'Rapport' }}</h2>
                        <p class="mt-1 text-sm text-slate-500">Filtres période, client et recherche. Les exports PDF/Excel pourront s'appuyer sur ce tableau consolidé.</p>
                    </div>
                    <div class="app-action-row">
                        <button type="button" onclick="window.print()" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold dark:border-white/10">Imprimer / PDF</button>
                        <button type="button" data-report-copy="report-data" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Copier tableau</button>
                    </div>
                </div>
                <form method="GET" action="{{ route('module', 'reports') }}" class="mt-4 grid gap-3 lg:grid-cols-[1fr_160px_160px_220px_auto]">
                    <input type="hidden" name="section" value="{{ $reportSection }}">
                    <input name="q" value="{{ request('q') }}" class="h-11 rounded-lg border border-slate-200 bg-slate-50 px-3 text-sm dark:border-white/10 dark:bg-white/5" placeholder="Rechercher n°, client, paiement...">
                    <input name="from" value="{{ $reportContext['from'] }}" type="date" class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">
                    <input name="to" value="{{ $reportContext['to'] }}" type="date" class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">
                    <select name="customer_id" data-searchable-select class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">
                        <option value="">Tous les clients</option>
                        @foreach ($reportContext['clients'] as $client)
                            <option value="{{ $client->id }}" @selected((string) request('customer_id') === (string) $client->id)>{{ $client->name }}</option>
                        @endforeach
                    </select>
                    <div class="flex gap-2"><button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Afficher</button><a href="{{ route('module', ['module' => 'reports', 'section' => $reportSection]) }}" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold dark:border-white/10">Reset</a></div>
                </form>
            </article>

            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><span class="text-xs font-semibold uppercase text-slate-500">Ventes nettes</span><p class="mt-2 text-2xl font-semibold">{{ $money($summary['net_revenue']) }}</p><p class="mt-1 text-xs text-slate-500">Brut {{ $money($summary['gross_revenue']) }} - retours {{ $money($summary['returns']) }}</p></article>
                <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><span class="text-xs font-semibold uppercase text-slate-500">Coût articles</span><p class="mt-2 text-2xl font-semibold">{{ $money($summary['purchase_cost']) }}</p><p class="mt-1 text-xs text-slate-500">Basé sur prix d'achat catalogue</p></article>
                <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><span class="text-xs font-semibold uppercase text-slate-500">Dépenses</span><p class="mt-2 text-2xl font-semibold text-rose-600">{{ $money($summary['expenses']) }}</p><p class="mt-1 text-xs text-slate-500">Période filtrée</p></article>
                <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><span class="text-xs font-semibold uppercase text-slate-500">Profit net</span><p class="mt-2 text-2xl font-semibold {{ $summary['net_profit'] >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">{{ $money($summary['net_profit']) }}</p><p class="mt-1 text-xs text-slate-500">Marge brute {{ $money($summary['gross_profit']) }}</p></article>
            </div>

            @if ($reportSection === 'profit-loss')
                <article class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <div class="border-b border-slate-200 p-4 dark:border-white/10"><h3 class="font-semibold">Rapport de profits et pertes</h3></div>
                    <div class="overflow-x-auto"><table id="report-data" class="w-full min-w-[760px] text-left text-sm"><tbody class="divide-y divide-slate-200 dark:divide-white/10">
                        @foreach ([['Ventes brutes', $summary['gross_revenue']], ['Retours ventes', -$summary['returns']], ['Ventes nettes', $summary['net_revenue']], ['Coût des articles vendus', -$summary['purchase_cost']], ['Profit brut', $summary['gross_profit']], ['Dépenses', -$summary['expenses']], ['Profit / perte net', $summary['net_profit']]] as [$label, $amount])
                            <tr><td class="px-4 py-3 font-semibold">{{ $label }}</td><td class="px-4 py-3 text-right font-bold {{ $amount < 0 ? 'text-rose-600' : 'text-slate-900 dark:text-slate-100' }}">{{ $money($amount) }}</td></tr>
                        @endforeach
                    </tbody></table></div>
                </article>
            @else
                <article class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <div class="border-b border-slate-200 p-4 dark:border-white/10"><h3 class="font-semibold">{{ $reportTabs[$reportSection] ?? 'Données rapport' }}</h3></div>
                    <div class="overflow-x-auto">
                        <table id="report-data" class="w-full min-w-[980px] text-left text-sm">
                            <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-white/5">
                                @if (in_array($reportSection, ['stock'], true))
                                    <tr><th class="px-3 py-3">Article</th><th class="px-3 py-3">Catégorie</th><th class="px-3 py-3">Marque</th><th class="px-3 py-3 text-right">Stock</th><th class="px-3 py-3 text-right">Coût</th><th class="px-3 py-3 text-right">Valeur</th></tr>
                                @elseif (in_array($reportSection, ['item-sales', 'sales-summary', 'sales-tax', 'gstr-1', 'sales-gst'], true))
                                    <tr><th class="px-3 py-3">Nom</th><th class="px-3 py-3 text-right">Quantité</th><th class="px-3 py-3 text-right">Chiffre d'affaires</th><th class="px-3 py-3 text-right">Coût</th><th class="px-3 py-3 text-right">Marge</th></tr>
                                @elseif (in_array($reportSection, ['sales-payments', 'sales-payments-list'], true))
                                    <tr><th class="px-3 py-3">N° paiement</th><th class="px-3 py-3">Date</th><th class="px-3 py-3">Vente</th><th class="px-3 py-3">Client</th><th class="px-3 py-3">Méthode</th><th class="px-3 py-3 text-right">Montant</th></tr>
                                @elseif (str_contains($reportSection, 'purchase'))
                                    <tr><th class="px-3 py-3">N°</th><th class="px-3 py-3">Date</th><th class="px-3 py-3">Fournisseur</th><th class="px-3 py-3">Statut</th><th class="px-3 py-3 text-right">Montant</th></tr>
                                @elseif (in_array($reportSection, ['expenses'], true))
                                    <tr><th class="px-3 py-3">N°</th><th class="px-3 py-3">Date</th><th class="px-3 py-3">Dépense</th><th class="px-3 py-3">Catégorie</th><th class="px-3 py-3">Paiement</th><th class="px-3 py-3 text-right">Montant</th></tr>
                                @else
                                    <tr><th class="px-3 py-3">N°</th><th class="px-3 py-3">Date</th><th class="px-3 py-3">Client</th><th class="px-3 py-3">Paiement</th><th class="px-3 py-3">Statut</th><th class="px-3 py-3 text-right">Total</th></tr>
                                @endif
                            </thead>
                            <tbody class="divide-y divide-slate-200 dark:divide-white/10">
                                @forelse ($reportRows as $row)
                                    @if (in_array($reportSection, ['stock'], true))
                                        <tr><td class="px-3 py-3 font-semibold">{{ $row->title }}</td><td class="px-3 py-3">{{ $row->category?->name ?? '—' }}</td><td class="px-3 py-3">{{ $row->brand?->name ?? '—' }}</td><td class="px-3 py-3 text-right">{{ number_format($row->stock_quantity, 0, ',', ' ') }}</td><td class="px-3 py-3 text-right">{{ $money($row->purchase_price) }}</td><td class="px-3 py-3 text-right font-semibold">{{ $money($row->stock_quantity * $row->purchase_price) }}</td></tr>
                                    @elseif (in_array($reportSection, ['item-sales', 'sales-summary', 'sales-tax', 'gstr-1', 'sales-gst'], true))
                                        <tr><td class="px-3 py-3 font-semibold">{{ $row['name'] }}</td><td class="px-3 py-3 text-right">{{ number_format($row['quantity'], 0, ',', ' ') }}</td><td class="px-3 py-3 text-right">{{ $money($row['revenue']) }}</td><td class="px-3 py-3 text-right">{{ $money($row['cost'] ?? 0) }}</td><td class="px-3 py-3 text-right font-semibold">{{ $money(($row['revenue'] ?? 0) - ($row['cost'] ?? 0)) }}</td></tr>
                                    @elseif (in_array($reportSection, ['sales-payments', 'sales-payments-list'], true))
                                        <tr><td class="px-3 py-3 font-semibold">{{ $row->number }}</td><td class="px-3 py-3">{{ $row->paid_at?->format('d/m/Y H:i') }}</td><td class="px-3 py-3">{{ $row->sale?->number ?? '—' }}</td><td class="px-3 py-3">{{ $row->contact?->name ?? 'Client comptoir' }}</td><td class="px-3 py-3">{{ $row->method }}</td><td class="px-3 py-3 text-right font-semibold">{{ $money($row->amount) }}</td></tr>
                                    @elseif (str_contains($reportSection, 'purchase'))
                                        <tr><td class="px-3 py-3 font-semibold">{{ $row->number }}</td><td class="px-3 py-3">{{ ($row->ordered_at ?? $row->returned_at)?->format('d/m/Y') }}</td><td class="px-3 py-3">{{ $row->supplier?->name ?? '—' }}</td><td class="px-3 py-3"><x-status-pill tone="info">{{ $row->status }}</x-status-pill></td><td class="px-3 py-3 text-right font-semibold">{{ $money($row->total_amount) }}</td></tr>
                                    @elseif (in_array($reportSection, ['expenses'], true))
                                        <tr><td class="px-3 py-3 font-semibold">{{ $row->number }}</td><td class="px-3 py-3">{{ $row->spent_at?->format('d/m/Y') }}</td><td class="px-3 py-3">{{ $row->label }}</td><td class="px-3 py-3">@php $repCat = $expenseCategories->firstWhere('name', $row->category) @endphp@if($repCat && $repCat->color)<span class="inline-block size-2.5 rounded-full align-middle" style="background: {{ $repCat->color }}"></span> @endif{{ $row->category }}</td><td class="px-3 py-3">{{ $row->payment_method }}</td><td class="px-3 py-3 text-right font-semibold">{{ $money($row->amount) }}</td></tr>
                                    @elseif (in_array($reportSection, ['sales-return', 'return-items', 'sales-return-payments'], true))
                                        <tr><td class="px-3 py-3 font-semibold">{{ $row->number }}</td><td class="px-3 py-3">{{ $row->returned_at?->format('d/m/Y H:i') }}</td><td class="px-3 py-3">{{ $row->contact?->name ?? $row->sale?->contact?->name ?? 'Client comptoir' }}</td><td class="px-3 py-3">{{ $row->refund_method }}</td><td class="px-3 py-3"><x-status-pill :tone="$row->restock ? 'success' : 'warning'">{{ $row->restock ? 'Restocké' : 'Sans restock' }}</x-status-pill></td><td class="px-3 py-3 text-right font-semibold">{{ $money($row->total_amount) }}</td></tr>
                                    @else
                                        <tr><td class="px-3 py-3 font-semibold">{{ $row->number }}</td><td class="px-3 py-3">{{ $row->sold_at?->format('d/m/Y H:i') }}</td><td class="px-3 py-3">{{ $row->contact?->name ?? 'Client comptoir' }}</td><td class="px-3 py-3">{{ $row->payment_method }}</td><td class="px-3 py-3"><x-status-pill tone="info">{{ $row->status }}</x-status-pill></td><td class="px-3 py-3 text-right font-semibold">{{ $money($row->total_amount) }}</td></tr>
                                    @endif
                                @empty
                                    <tr><td colspan="8" class="px-4 py-12 text-center text-sm text-slate-500">Aucune donnée pour ce rapport.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </article>
            @endif
        </section>
    @else
        @php
            $themeDefaults = [
                'primary' => '#3157D5',
                'accent' => '#0F9F8A',
                'success' => '#16A34A',
                'warning' => '#D97706',
                'danger' => '#E11D48',
                'info' => '#0284C7',
                'background' => '#F4F7FB',
                'surface_color' => '#FFFFFF',
                'surface_muted' => '#EEF3F8',
                'text' => '#101828',
                'muted' => '#64748B',
                'border' => '#D7DEE9',
                'font_scale' => '1',
                'density' => 'comfortable',
                'radius' => '12',
            ];
            $theme = array_merge($themeDefaults, $tenant->settings['theme'] ?? []);
            $themePreset = $tenant->settings['theme_preset'] ?? 'default';
            $posEditablePrice = (bool) data_get($tenant->settings, 'pos.editable_price', true);
            $posAllowSaleEdit = (bool) data_get($tenant->settings, 'pos.allow_sale_edit', true);
            $posAllowOversell = (bool) data_get($tenant->settings, 'pos.allow_oversell', false);
            $posShowOutOfStock = (bool) data_get($tenant->settings, 'pos.show_out_of_stock', false);
            $posShowCashDrawerNavbar = (bool) data_get($tenant->settings, 'pos.show_cash_drawer_navbar', true);
            $posRequireAdjustmentReason = (bool) data_get($tenant->settings, 'pos.require_adjustment_reason', true);
            $posUpdateCostOnPurchase = (bool) data_get($tenant->settings, 'pos.update_cost_on_purchase', true);
            $posLowStockDashboard = (bool) data_get($tenant->settings, 'pos.low_stock_dashboard', true);
            $posAutoReorderDraft = (bool) data_get($tenant->settings, 'pos.auto_reorder_draft', false);
            $virtualDevicesEnabled = (bool) data_get($tenant->settings, 'features.virtual_devices', false);
            $posInventoryCycleDays = (int) data_get($tenant->settings, 'pos.inventory_cycle_days', 30);
            $posDefaultMinStock = (int) data_get($tenant->settings, 'pos.default_min_stock_threshold', 3);
            $themePresets = [
                'default' => ['name' => 'LibrairePro', 'hint' => 'Bleu moderne, accent vert, recommandé', 'colors' => ['#3157D5', '#0F9F8A', '#FFFFFF', '#F4F7FB']],
                'classic' => ['name' => 'Indigo classic', 'hint' => 'Plus proche du thème initial', 'colors' => ['#4F46E5', '#0EA5E9', '#FFFFFF', '#F8FAFC']],
                'graphite' => ['name' => 'Graphite', 'hint' => 'Compact et sobre', 'colors' => ['#334155', '#0F766E', '#FFFFFF', '#F7F7F5']],
            ];
            $businessModes = \App\Support\BusinessMode::all();
            $currentBusinessMode = \App\Support\BusinessMode::current($tenant);
            $appModuleSettings = \App\Support\AppModules::settings($tenant);
            $storeTypeLabels = ['store' => 'Magasin', 'warehouse' => 'Dépôt', 'area' => 'Rayon', 'branch' => 'Succursale'];
            $settingsSection = request('section', 'warehouses');
            $settingsTabs = [
                'company' => 'Société',
                'modules' => 'Modules',
                'warehouses' => 'Magasins',
                'store' => 'Caisse & stock',
                'documents' => 'PDF',
                'users' => 'Utilisateurs',
                'roles' => 'Rôles',
                'taxes' => 'Taxes',
                'units' => 'Unités',
                'payment-types' => 'Paiement',
                'countries' => 'Pays',
                'states' => 'États',
                'password' => 'Mot de passe',
                'messaging' => 'Messagerie',
                'message-templates' => 'Modèles',
                'sms-api' => 'API messages',
                'theme' => 'Thème',
                'hardware' => 'Matériel',
            ];
            $settingsReferenceSections = ['taxes', 'units', 'payment-types', 'countries', 'states', 'password'];
            $companyProfile = array_merge([
                'store_code' => $tenant->slug,
                'store_name' => $tenant->name,
                'business_mode' => \App\Support\BusinessMode::defaultKey(),
                'mobile' => '',
                'email' => $tenant->email,
                'phone' => $tenant->phone,
                'cnss' => '',
                'rc' => '',
                'gst_no' => $tenant->ice,
                'vat_no' => '',
                'pan_no' => '',
                'store_website' => '',
                'show_signature' => false,
                'signature' => '',
                'bank_details' => '',
                'country' => 'Maroc',
                'state' => '',
                'city' => '',
                'postcode' => '',
                'address' => $tenant->address,
                'store_logo' => '',
                'timezone' => $tenant->timezone,
                'date_format' => 'dd/mm/yyyy',
                'time_format' => '24',
                'currency' => $tenant->currency,
                'currency_placement' => 'Right',
                'decimals' => 2,
                'qty_decimals' => 2,
                'language_id' => 'fr',
                'round_off' => false,
                'default_account_id' => '',
                'sales_discount' => 0,
                'sales_invoice_format_id' => '3',
                'pos_invoice_format_id' => '1',
                'mrp_column' => false,
                'change_return' => true,
                'previous_balance_bit' => true,
                'number_to_words' => 'Default',
                'sales_invoice_footer_text' => '',
                't_and_c_status' => true,
                't_and_c_status_pos' => true,
                'invoice_terms' => '',
                'toggle_header_footer' => true,
                'category_init' => 'CAT',
                'item_init' => 'IT',
                'supplier_init' => 'SUP',
                'purchase_init' => 'PUR',
                'purchase_return_init' => 'PR',
                'customer_init' => 'CUST',
                'sales_init' => 'SAL',
                'sales_return_init' => 'SR',
                'expense_init' => 'EXP',
                'accounts_init' => 'ACC',
                'quotation_init' => 'QUO',
                'money_transfer_init' => 'MT',
                'sales_payment_init' => 'SP',
                'sales_return_payment_init' => 'SRP',
                'purchase_payment_init' => 'PP',
                'purchase_return_payment_init' => 'PRP',
                'expense_payment_init' => 'EP',
                'cust_advance_init' => 'ADV',
            ], $tenant->settings['company_profile'] ?? []);
            $companyProfile['business_mode'] = \App\Support\BusinessMode::get($companyProfile['business_mode'] ?? null)['key'];
        @endphp
        <details class="app-collapsible-menu mt-6" data-collapsible-menu data-menu-key="module-settings-menu">
            <summary class="app-collapsible-menu-summary">
                <span><strong>Menu paramètres</strong><small>{{ $settingsTabs[$settingsSection] ?? 'Général' }}</small></span>
                <em data-collapsible-menu-state>Afficher</em>
            </summary>
            <nav class="app-tab-nav">
                @foreach ($settingsTabs as $key => $label)
                    <a href="{{ route('module', ['module' => 'settings', 'section' => $key]) }}" class="app-tab-link {{ $settingsSection === $key ? 'is-active' : '' }}">{{ $label }}</a>
                @endforeach
            </nav>
        </details>
        <section class="mt-6 grid gap-6 {{ $settingsSection === 'company' ? 'xl:grid-cols-[minmax(0,1fr)_360px]' : '' }}">
            @if (in_array($settingsSection, $settingsReferenceSections, true))
                <div class="xl:col-span-2">
                    @if ($settingsSection === 'taxes')
                        <div class="grid gap-6 2xl:grid-cols-[minmax(0,1fr)_minmax(360px,0.8fr)]">
                            <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div><p class="text-sm font-semibold text-brand">Acceuil · Liste des taxes</p><h2 class="mt-1 text-xl font-semibold">Liste des taxes</h2><p class="mt-1 text-sm text-slate-500">Afficher/recherche Impôt utilisé par le catalogue, la caisse et les documents.</p></div>
                                    <x-status-pill tone="primary">{{ $settingsTaxes->count() }} entrée(s)</x-status-pill>
                                </div>
                                <form action="{{ route('catalog.taxes.store') }}" method="POST" class="app-action-form mt-5 rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-white/10 dark:bg-white/5">
                                    @csrf
                                    <input name="name" required class="h-11 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Nom fiscal">
                                    <input name="rate" required type="number" min="0" max="100" step="0.01" class="h-11 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="%">
                                    <input name="description" class="h-11 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Description">
                                    <label class="flex h-11 items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold dark:border-white/10 dark:bg-slate-900"><input name="is_active" value="1" checked type="checkbox" class="size-4 accent-[var(--brand-primary)]"> Actif</label>
                                    <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white md:col-span-4">Ajouter taxe</button>
                                </form>
                                <div class="mt-5 overflow-hidden rounded-xl border border-slate-200 dark:border-white/10">
                                    <div class="grid gap-3 border-b border-slate-200 bg-slate-50 p-3 dark:border-white/10 dark:bg-white/5 sm:grid-cols-[140px_1fr]"><label class="flex items-center gap-2 text-sm text-slate-500">Afficher <select class="h-9 rounded-lg border border-slate-200 bg-white px-2 text-sm dark:border-white/10 dark:bg-slate-900"><option>10</option><option>25</option></select> entrées</label><input data-table-filter="settings-tax-table" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Rechercher:"></div>
                                    <div class="overflow-x-auto"><table id="settings-tax-table" class="w-full min-w-[760px] text-left text-sm"><thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-white/5"><tr><th class="px-3 py-3">Nom fiscal</th><th class="px-3 py-3">Impôt(%)</th><th class="px-3 py-3">Statut</th><th class="px-3 py-3 text-right">Action</th></tr></thead><tbody class="divide-y divide-slate-200 dark:divide-white/10">@forelse ($settingsTaxes as $tax)<tr><td class="px-3 py-3 font-semibold">{{ $tax->name }}</td><td class="px-3 py-3">{{ number_format((float) $tax->rate, 2, ',', ' ') }}</td><td class="px-3 py-3"><x-status-pill :tone="$tax->is_active ? 'success' : 'danger'">{{ $tax->is_active ? 'Active' : 'Inactive' }}</x-status-pill></td><td class="px-3 py-3 text-right"><button type="button" onclick="document.getElementById('tax-edit-{{ $tax->id }}').showModal()" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold transition hover:border-brand hover:text-brand dark:border-white/10">Modifier</button></td></tr><dialog id="tax-edit-{{ $tax->id }}" class="app-dialog w-[min(560px,calc(100vw-2rem))] rounded-2xl border border-slate-200 bg-white p-0 text-slate-950 shadow-2xl backdrop:bg-slate-950/45 dark:border-white/10 dark:bg-slate-950 dark:text-slate-100"><form action="{{ route('catalog.taxes.update', $tax) }}" method="POST">@csrf @method('PUT')<div class="flex items-start justify-between gap-4 border-b border-slate-200 p-5 dark:border-white/10"><div><p class="text-sm font-semibold text-brand">Paramètres · Taxes</p><h3 class="mt-1 text-xl font-semibold">Modifier {{ $tax->name }}</h3><p class="mt-1 text-sm text-slate-500">Taux utilisé dans le catalogue, la caisse et les documents.</p></div><button class="dialog-close grid size-9 shrink-0 place-items-center rounded-lg border border-slate-200 text-lg font-semibold dark:border-white/10" type="button">×</button></div><div class="grid gap-4 p-5"><label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Nom fiscal *</span><input name="name" required value="{{ $tax->name }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label><label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Impôt (%) *</span><input name="rate" required type="number" min="0" max="100" step="0.01" value="{{ $tax->rate }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label><label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Description</span><input name="description" value="{{ $tax->description }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label><label class="flex items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-3 text-sm font-semibold dark:border-white/10 dark:bg-white/5"><input name="is_active" value="1" type="checkbox" @checked($tax->is_active) class="size-4 accent-[var(--brand-primary)]"> Taxe active</label></div><div class="flex flex-col-reverse gap-2 border-t border-slate-200 bg-slate-50 p-5 dark:border-white/10 dark:bg-white/5 sm:flex-row sm:items-center sm:justify-between"><button form="tax-delete-{{ $tax->id }}" class="rounded-lg border border-rose-200 px-4 py-2 text-sm font-semibold text-rose-600 dark:border-rose-500/30" type="submit">Supprimer</button><div class="flex justify-end gap-2"><button type="button" class="dialog-close rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold dark:border-white/10 dark:bg-slate-950">Annuler</button><button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Enregistrer</button></div></div></form><form id="tax-delete-{{ $tax->id }}" action="{{ route('catalog.taxes.destroy', $tax) }}" method="POST" onsubmit="return confirm('Supprimer cette taxe ?')">@csrf @method('DELETE')</form></dialog>@empty<tr><td colspan="4" class="px-4 py-12 text-center text-slate-500">Aucune taxe trouvée.</td></tr>@endforelse</tbody></table></div>
                                </div>
                            </article>
                            <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                                <div class="flex items-start justify-between gap-3"><div><h2 class="font-semibold">Groupes fiscaux</h2><p class="mt-1 text-sm text-slate-500">Combinez plusieurs taxes secondaires si nécessaire.</p></div><x-status-pill tone="info">{{ count($settingsTaxGroups) }}</x-status-pill></div>
                                <form action="{{ route('settings.tax-groups.store') }}" method="POST" class="mt-4 grid gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-white/10 dark:bg-white/5">@csrf<input name="name" required class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Nom fiscal"><input name="rate" required type="number" min="0" max="100" step="0.01" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Impôt (%)"><input name="secondary_taxes" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Taxes secondaires"><label class="flex items-center gap-2 text-sm font-semibold"><input name="is_active" value="1" checked type="checkbox" class="size-4 accent-[var(--brand-primary)]"> Actif</label><button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Ajouter groupe</button></form>
                                <div class="mt-5 overflow-x-auto rounded-xl border border-slate-200 dark:border-white/10"><table class="w-full min-w-[560px] text-left text-sm"><thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-white/5"><tr><th class="px-3 py-3">Nom fiscal</th><th class="px-3 py-3">Impôt(%)</th><th class="px-3 py-3">Taxes secondaires</th><th class="px-3 py-3">Statut</th><th class="px-3 py-3 text-right">Action</th></tr></thead><tbody class="divide-y divide-slate-200 dark:divide-white/10">@forelse ($settingsTaxGroups as $group)<tr><td class="px-3 py-3 font-semibold">{{ $group['name'] }}</td><td class="px-3 py-3">{{ number_format($group['rate'], 2, ',', ' ') }}</td><td class="px-3 py-3 text-slate-500">{{ $group['secondary_taxes'] ?: '—' }}</td><td class="px-3 py-3"><x-status-pill :tone="$group['is_active'] ? 'success' : 'danger'">{{ $group['is_active'] ? 'Active' : 'Inactive' }}</x-status-pill></td><td class="px-3 py-3 text-right"><form action="{{ route('settings.tax-groups.destroy', $group['key']) }}" method="POST" onsubmit="return confirm('Supprimer ce groupe ?')">@csrf @method('DELETE')<button class="text-xs font-semibold text-rose-600">Supprimer</button></form></td></tr>@empty<tr><td colspan="5" class="px-4 py-12 text-center text-sm text-slate-500">No matching records found</td></tr>@endforelse</tbody></table></div>
                            </article>
                        </div>
                    @elseif ($settingsSection === 'units')
                        <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                            <div class="flex items-start justify-between gap-3"><div><h2 class="text-xl font-semibold">Liste des unités</h2><p class="mt-1 text-sm text-slate-500">Mesures disponibles sur articles, services et achats.</p></div><x-status-pill tone="primary">{{ $settingsUnits->count() }}</x-status-pill></div>
                            <form action="{{ route('catalog.units.store') }}" method="POST" class="app-action-form mt-5 rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-white/10 dark:bg-white/5">@csrf<input name="name" required class="h-11 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Nom unité"><input name="description" class="h-11 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Description"><label class="flex h-11 items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold dark:border-white/10 dark:bg-slate-900"><input name="is_active" value="1" checked type="checkbox" class="size-4 accent-[var(--brand-primary)]"> Actif</label><button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Ajouter</button></form>
                            <div class="mt-5 overflow-hidden rounded-xl border border-slate-200 dark:border-white/10"><div class="border-b border-slate-200 bg-slate-50 p-3 dark:border-white/10 dark:bg-white/5"><input data-table-filter="settings-units-table" class="h-10 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Rechercher:"></div><div class="overflow-x-auto"><table id="settings-units-table" class="w-full min-w-[760px] text-left text-sm"><thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-white/5"><tr><th class="px-3 py-3">Unité</th><th class="px-3 py-3">Description</th><th class="px-3 py-3">Statut</th><th class="px-3 py-3 text-right">Action</th></tr></thead><tbody class="divide-y divide-slate-200 dark:divide-white/10">@forelse ($settingsUnits as $unit)<tr><td class="px-3 py-3 font-semibold">{{ $unit->name }}</td><td class="px-3 py-3 text-slate-500">{{ $unit->description ?: '—' }}</td><td class="px-3 py-3"><x-status-pill :tone="$unit->is_active ? 'success' : 'danger'">{{ $unit->is_active ? 'Active' : 'Inactive' }}</x-status-pill></td><td class="px-3 py-3 text-right"><button type="button" onclick="document.getElementById('unit-edit-{{ $unit->id }}').showModal()" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold transition hover:border-brand hover:text-brand dark:border-white/10">Modifier</button></td></tr><dialog id="unit-edit-{{ $unit->id }}" class="app-dialog w-[min(540px,calc(100vw-2rem))] rounded-2xl border border-slate-200 bg-white p-0 text-slate-950 shadow-2xl backdrop:bg-slate-950/45 dark:border-white/10 dark:bg-slate-950 dark:text-slate-100"><form action="{{ route('catalog.units.update', $unit) }}" method="POST">@csrf @method('PUT')<div class="flex items-start justify-between gap-4 border-b border-slate-200 p-5 dark:border-white/10"><div><p class="text-sm font-semibold text-brand">Paramètres · Unités</p><h3 class="mt-1 text-xl font-semibold">Modifier {{ $unit->name }}</h3><p class="mt-1 text-sm text-slate-500">Mesure utilisée sur les articles, services et achats.</p></div><button class="dialog-close grid size-9 shrink-0 place-items-center rounded-lg border border-slate-200 text-lg font-semibold dark:border-white/10" type="button">×</button></div><div class="grid gap-4 p-5"><label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Nom unité *</span><input name="name" required value="{{ $unit->name }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label><label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Description</span><input name="description" value="{{ $unit->description }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label><label class="flex items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-3 text-sm font-semibold dark:border-white/10 dark:bg-white/5"><input name="is_active" value="1" type="checkbox" @checked($unit->is_active) class="size-4 accent-[var(--brand-primary)]"> Unité active</label></div><div class="flex flex-col-reverse gap-2 border-t border-slate-200 bg-slate-50 p-5 dark:border-white/10 dark:bg-white/5 sm:flex-row sm:items-center sm:justify-between"><button form="unit-delete-{{ $unit->id }}" class="rounded-lg border border-rose-200 px-4 py-2 text-sm font-semibold text-rose-600 dark:border-rose-500/30" type="submit">Supprimer</button><div class="flex justify-end gap-2"><button type="button" class="dialog-close rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold dark:border-white/10 dark:bg-slate-950">Annuler</button><button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Enregistrer</button></div></div></form><form id="unit-delete-{{ $unit->id }}" action="{{ route('catalog.units.destroy', $unit) }}" method="POST" onsubmit="return confirm('Supprimer cette unité ?')">@csrf @method('DELETE')</form></dialog>@empty<tr><td colspan="4" class="px-4 py-12 text-center text-slate-500">Aucune unité trouvée.</td></tr>@endforelse</tbody></table></div></div>
                        </article>
                    @elseif (in_array($settingsSection, ['payment-types', 'countries', 'states'], true))
                        @php
                            $referenceConfig = [
                                'payment-types' => ['title' => 'Types de paiement', 'hint' => 'Méthodes proposées dans la caisse, les avances et les dépenses.', 'records' => $paymentTypes, 'store' => route('settings.payment-types.store'), 'update' => 'settings.payment-types.update', 'destroy' => 'settings.payment-types.destroy'],
                                'countries' => ['title' => 'Liste des pays', 'hint' => 'Pays disponibles sur les fiches contacts et fournisseurs.', 'records' => $countries, 'store' => route('settings.countries.store'), 'update' => 'settings.countries.update', 'destroy' => 'settings.countries.destroy'],
                                'states' => ['title' => 'Liste des états', 'hint' => 'Régions/états rattachés aux pays.', 'records' => $states, 'store' => route('settings.states.store'), 'update' => 'settings.states.update', 'destroy' => 'settings.states.destroy'],
                            ][$settingsSection];
                        @endphp
                        <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                            <div class="flex items-start justify-between gap-3"><div><h2 class="text-xl font-semibold">{{ $referenceConfig['title'] }}</h2><p class="mt-1 text-sm text-slate-500">{{ $referenceConfig['hint'] }}</p></div><x-status-pill tone="primary">{{ count($referenceConfig['records']) }}</x-status-pill></div>
                            <form action="{{ $referenceConfig['store'] }}" method="POST" class="mt-5 grid gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-white/10 dark:bg-white/5 md:grid-cols-5">@csrf<input name="name" required class="h-11 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Nom"><input name="code" class="h-11 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Code">@if($settingsSection === 'states')<input name="country" class="h-11 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Pays">@endif<input name="description" class="h-11 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Description"><label class="flex h-11 items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold dark:border-white/10 dark:bg-slate-900"><input name="is_active" value="1" checked type="checkbox" class="size-4 accent-[var(--brand-primary)]"> Actif</label><button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Ajouter</button></form>
                            <div class="mt-5 overflow-hidden rounded-xl border border-slate-200 dark:border-white/10"><div class="border-b border-slate-200 bg-slate-50 p-3 dark:border-white/10 dark:bg-white/5"><input data-table-filter="settings-reference-table" class="h-10 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Rechercher:"></div><div class="overflow-x-auto"><table id="settings-reference-table" class="w-full min-w-[900px] text-left text-sm"><thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-white/5"><tr><th class="px-3 py-3">Nom</th><th class="px-3 py-3">Code</th>@if($settingsSection === 'states')<th class="px-3 py-3">Pays</th>@endif<th class="px-3 py-3">Description</th><th class="px-3 py-3">Statut</th><th class="px-3 py-3 text-right">Action</th></tr></thead><tbody class="divide-y divide-slate-200 dark:divide-white/10">@forelse ($referenceConfig['records'] as $record)<tr><td class="px-3 py-3 font-semibold">{{ $record['name'] }}</td><td class="px-3 py-3">{{ $record['code'] }}</td>@if($settingsSection === 'states')<td class="px-3 py-3">{{ $record['country'] ?: '—' }}</td>@endif<td class="px-3 py-3 text-slate-500">{{ $record['description'] ?: '—' }}</td><td class="px-3 py-3"><x-status-pill :tone="$record['is_active'] ? 'success' : 'danger'">{{ $record['is_active'] ? 'Active' : 'Inactive' }}</x-status-pill></td><td class="px-3 py-3 text-right"><button type="button" onclick="document.getElementById('settings-ref-{{ $record['key'] }}').showModal()" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold transition hover:border-brand hover:text-brand dark:border-white/10">Modifier</button></td></tr><dialog id="settings-ref-{{ $record['key'] }}" class="app-dialog w-[min(560px,calc(100vw-2rem))] rounded-2xl border border-slate-200 bg-white p-0 text-slate-950 shadow-2xl backdrop:bg-slate-950/45 dark:border-white/10 dark:bg-slate-950 dark:text-slate-100"><form action="{{ route($referenceConfig['update'], $record['key']) }}" method="POST">@csrf @method('PUT')<div class="flex items-start justify-between gap-4 border-b border-slate-200 p-5 dark:border-white/10"><div><p class="text-sm font-semibold text-brand">Paramètres · {{ $referenceConfig['title'] }}</p><h3 class="mt-1 text-xl font-semibold">Modifier {{ $record['name'] }}</h3><p class="mt-1 text-sm text-slate-500">{{ $referenceConfig['hint'] }}</p></div><button class="dialog-close grid size-9 shrink-0 place-items-center rounded-lg border border-slate-200 text-lg font-semibold dark:border-white/10" type="button">×</button></div><div class="grid gap-4 p-5"><label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Nom *</span><input name="name" required value="{{ $record['name'] }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label><label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Code</span><input name="code" value="{{ $record['code'] }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>@if($settingsSection === 'states')<label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Pays</span><input name="country" value="{{ $record['country'] }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>@endif<label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Description</span><input name="description" value="{{ $record['description'] }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label><label class="flex items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-3 text-sm font-semibold dark:border-white/10 dark:bg-white/5"><input name="is_active" value="1" type="checkbox" @checked($record['is_active']) class="size-4 accent-[var(--brand-primary)]"> Entrée active</label></div><div class="flex flex-col-reverse gap-2 border-t border-slate-200 bg-slate-50 p-5 dark:border-white/10 dark:bg-white/5 sm:flex-row sm:items-center sm:justify-between"><button form="settings-ref-delete-{{ $record['key'] }}" class="rounded-lg border border-rose-200 px-4 py-2 text-sm font-semibold text-rose-600 dark:border-rose-500/30" type="submit">Supprimer</button><div class="flex justify-end gap-2"><button type="button" class="dialog-close rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold dark:border-white/10 dark:bg-slate-950">Annuler</button><button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Enregistrer</button></div></div></form><form id="settings-ref-delete-{{ $record['key'] }}" action="{{ route($referenceConfig['destroy'], $record['key']) }}" method="POST" onsubmit="return confirm('Supprimer cette entrée ?')">@csrf @method('DELETE')</form></dialog>@empty<tr><td colspan="{{ $settingsSection === 'states' ? 6 : 5 }}" class="px-4 py-12 text-center text-slate-500">Aucune entrée trouvée.</td></tr>@endforelse</tbody></table></div></div>
                        </article>
                    @elseif ($settingsSection === 'password')
                        <article class="mx-auto max-w-2xl rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                            <h2 class="text-xl font-semibold">Changer le mot de passe</h2>
                            <p class="mt-1 text-sm text-slate-500">Mettez à jour le mot de passe du compte connecté. Utilisez au moins 8 caractères.</p>
                            <form action="{{ route('settings.password.update') }}" method="POST" class="mt-5 grid gap-4">@csrf @method('PUT')<label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Mot de passe actuel</span><input name="current_password" required type="password" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label><label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Nouveau mot de passe</span><input name="password" required type="password" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label><label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Confirmation</span><input name="password_confirmation" required type="password" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label><button class="rounded-lg bg-brand px-5 py-2.5 text-sm font-semibold text-white">Enregistrer le mot de passe</button></form>
                        </article>
                    @endif
                </div>
            @else
            <div class="space-y-6">
                @if ($settingsSection === 'store')
                <article class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <div class="border-b border-slate-200 bg-slate-50/70 p-5 dark:border-white/10 dark:bg-white/[0.04]">
                        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                            <div class="flex items-start gap-3">
                                <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-brand text-lg font-semibold text-white">₧</span>
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-wide text-brand">Paramètres · magasin</p>
                                    <h2 class="mt-1 text-xl font-semibold">Caisse, stock & comportement comptoir</h2>
                                    <p class="mt-1 max-w-2xl text-sm text-slate-500 dark:text-slate-400">Centralisez les règles qui impactent directement la vente: prix, rupture, survente, devise et préférences opérationnelles.</p>
                                </div>
                            </div>
                            <div class="app-action-row">
                                <x-status-pill :tone="$posEditablePrice ? 'warning' : 'info'">{{ $posEditablePrice ? 'Prix modifiables' : 'Prix verrouillés' }}</x-status-pill>
                                <x-status-pill :tone="$posAllowSaleEdit ? 'info' : 'danger'">{{ $posAllowSaleEdit ? 'Ventes modifiables' : 'Ventes verrouillées' }}</x-status-pill>
                                <x-status-pill :tone="$posAllowOversell ? 'warning' : 'success'">{{ $posAllowOversell ? 'Hors stock autorisé' : 'Stock bloquant' }}</x-status-pill>
                                <x-status-pill :tone="$posShowOutOfStock ? 'warning' : 'info'">{{ $posShowOutOfStock ? 'Ruptures visibles' : 'Ruptures masquées' }}</x-status-pill>
                                <x-status-pill :tone="$posShowCashDrawerNavbar ? 'success' : 'neutral'">{{ $posShowCashDrawerNavbar ? 'Tiroir navbar' : 'Tiroir masqué' }}</x-status-pill>
                            </div>
                        </div>
                    </div>

                    <div class="grid gap-5 p-5 xl:grid-cols-[minmax(0,1.4fr)_minmax(320px,0.8fr)]">
                        <form action="{{ route('settings.pos.update') }}" method="POST" class="space-y-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-slate-950/40">
                            @csrf
                            <input type="hidden" name="editable_price" value="0">
                            <input type="hidden" name="allow_sale_edit" value="0">
                            <input type="hidden" name="allow_oversell" value="0">
                            <input type="hidden" name="show_out_of_stock" value="0">
                            <input type="hidden" name="show_cash_drawer_navbar" value="0">
                            <input type="hidden" name="require_adjustment_reason" value="0">
                            <input type="hidden" name="update_cost_on_purchase" value="0">
                            <input type="hidden" name="low_stock_dashboard" value="0">
                            <input type="hidden" name="auto_reorder_draft" value="0">
                            <div>
                                <h3 class="text-sm font-black uppercase tracking-wide text-slate-600 dark:text-slate-300">Règles caisse</h3>
                                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Ces options changent directement le comportement du POS.</p>
                            </div>
                            <div class="grid gap-3">
                                <label class="settings-rule-card rounded-xl border border-slate-200 bg-white p-4 dark:border-white/10 dark:bg-slate-950/40">
                                    <span class="flex items-start gap-3">
                                        <input name="editable_price" value="1" type="checkbox" @checked($posEditablePrice) class="mt-1 size-4 rounded border-slate-300 accent-[var(--brand-primary)]">
                                        <span>
                                            <span class="block text-sm font-semibold">Autoriser la modification du prix en caisse</span>
                                            <span class="mt-1 block text-sm text-slate-500 dark:text-slate-400">Un appui long sur un produit ou le bouton modifier du panier ouvre le détail ligne pour ajuster prix, quantité et note.</span>
                                        </span>
                                    </span>
                                </label>
                                <label class="settings-rule-card rounded-xl border border-slate-200 bg-white p-4 dark:border-white/10 dark:bg-slate-950/40">
                                    <span class="flex items-start gap-3">
                                        <input name="allow_sale_edit" value="1" type="checkbox" @checked($posAllowSaleEdit) class="mt-1 size-4 rounded border-slate-300 accent-[var(--brand-primary)]">
                                        <span>
                                            <span class="block text-sm font-semibold">Autoriser la modification des ventes</span>
                                            <span class="mt-1 block text-sm text-slate-500 dark:text-slate-400">Permet de modifier client, référence, échéance et note depuis l’historique. Les paiements, retours et annulations restent gérés par leurs actions dédiées.</span>
                                        </span>
                                    </span>
                                </label>
                                <label class="settings-rule-card rounded-xl border border-slate-200 bg-white p-4 dark:border-white/10 dark:bg-slate-950/40">
                                    <span class="flex items-start gap-3">
                                        <input name="allow_oversell" value="1" type="checkbox" @checked($posAllowOversell) class="mt-1 size-4 rounded border-slate-300 accent-[var(--brand-primary)]">
                                        <span>
                                            <span class="block text-sm font-semibold">Autoriser la vente hors stock</span>
                                            <span class="mt-1 block text-sm text-slate-500 dark:text-slate-400">Permet d’encaisser une quantité supérieure au stock disponible. Le stock peut devenir négatif et devra être régularisé ensuite.</span>
                                        </span>
                                    </span>
                                </label>
                                <label class="settings-rule-card rounded-xl border border-slate-200 bg-white p-4 dark:border-white/10 dark:bg-slate-950/40">
                                    <span class="flex items-start gap-3">
                                        <input name="show_out_of_stock" value="1" type="checkbox" @checked($posShowOutOfStock) class="mt-1 size-4 rounded border-slate-300 accent-[var(--brand-primary)]">
                                        <span>
                                            <span class="block text-sm font-semibold">Afficher les articles hors stock dans la caisse</span>
                                            <span class="mt-1 block text-sm text-slate-500 dark:text-slate-400">Les articles sans stock restent visibles et grisés. Un clic ouvre le détail stock avec un raccourci vers l’ajustement préfiltré.</span>
                                        </span>
                                    </span>
                                </label>
                                <label class="settings-rule-card rounded-xl border border-slate-200 bg-white p-4 dark:border-white/10 dark:bg-slate-950/40">
                                    <span class="flex items-start gap-3">
                                        <input name="show_cash_drawer_navbar" value="1" type="checkbox" @checked($posShowCashDrawerNavbar) class="mt-1 size-4 rounded border-slate-300 accent-[var(--brand-primary)]">
                                        <span>
                                            <span class="block text-sm font-semibold">Afficher le tiroir caisse dans la barre supérieure</span>
                                            <span class="mt-1 block text-sm text-slate-500 dark:text-slate-400">Ajoute un indicateur rapide du solde attendu et de l’état du tiroir dans la navbar.</span>
                                        </span>
                                    </span>
                                </label>
                            </div>
                            <div class="border-t border-slate-200 pt-4 dark:border-white/10">
                                <h3 class="text-sm font-black uppercase tracking-wide text-slate-600 dark:text-slate-300">Règles stock & inventaire</h3>
                                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Paramètres utiles pour la traçabilité et la préparation des achats.</p>
                                <div class="mt-3 grid gap-3 md:grid-cols-2">
                                    <label class="settings-rule-card rounded-xl border border-slate-200 bg-white p-4 dark:border-white/10 dark:bg-slate-950/40">
                                        <span class="flex items-start gap-3">
                                            <input name="require_adjustment_reason" value="1" type="checkbox" @checked($posRequireAdjustmentReason) class="mt-1 size-4 rounded border-slate-300 accent-[var(--brand-primary)]">
                                            <span><span class="block text-sm font-semibold">Motif obligatoire sur ajustement</span><span class="mt-1 block text-sm text-slate-500 dark:text-slate-400">Chaque correction manuelle doit expliquer la raison.</span></span>
                                        </span>
                                    </label>
                                    <label class="settings-rule-card rounded-xl border border-slate-200 bg-white p-4 dark:border-white/10 dark:bg-slate-950/40">
                                        <span class="flex items-start gap-3">
                                            <input name="update_cost_on_purchase" value="1" type="checkbox" @checked($posUpdateCostOnPurchase) class="mt-1 size-4 rounded border-slate-300 accent-[var(--brand-primary)]">
                                            <span><span class="block text-sm font-semibold">Mettre à jour le coût à réception</span><span class="mt-1 block text-sm text-slate-500 dark:text-slate-400">Prépare la valorisation stock avec le dernier coût d’achat.</span></span>
                                        </span>
                                    </label>
                                    <label class="settings-rule-card rounded-xl border border-slate-200 bg-white p-4 dark:border-white/10 dark:bg-slate-950/40">
                                        <span class="flex items-start gap-3">
                                            <input name="low_stock_dashboard" value="1" type="checkbox" @checked($posLowStockDashboard) class="mt-1 size-4 rounded border-slate-300 accent-[var(--brand-primary)]">
                                            <span><span class="block text-sm font-semibold">Afficher les alertes stock faible</span><span class="mt-1 block text-sm text-slate-500 dark:text-slate-400">Remonte les seuils critiques dans les écrans opérationnels.</span></span>
                                        </span>
                                    </label>
                                    <label class="settings-rule-card rounded-xl border border-slate-200 bg-white p-4 dark:border-white/10 dark:bg-slate-950/40">
                                        <span class="flex items-start gap-3">
                                            <input name="auto_reorder_draft" value="1" type="checkbox" @checked($posAutoReorderDraft) class="mt-1 size-4 rounded border-slate-300 accent-[var(--brand-primary)]">
                                            <span><span class="block text-sm font-semibold">Préparer des brouillons d’achat</span><span class="mt-1 block text-sm text-slate-500 dark:text-slate-400">Option prête pour réapprovisionner depuis les seuils.</span></span>
                                        </span>
                                    </label>
                                </div>
                                <div class="mt-3 grid gap-3 md:grid-cols-2">
                                    <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Seuil stock faible par défaut</span><input name="default_min_stock_threshold" type="number" min="0" max="9999" value="{{ old('default_min_stock_threshold', $posDefaultMinStock) }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                                    <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Cycle inventaire conseillé</span><select name="inventory_cycle_days" class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="7" @selected($posInventoryCycleDays === 7)>Chaque semaine</option><option value="15" @selected($posInventoryCycleDays === 15)>Tous les 15 jours</option><option value="30" @selected($posInventoryCycleDays === 30)>Chaque mois</option><option value="90" @selected($posInventoryCycleDays === 90)>Chaque trimestre</option></select></label>
                                </div>
                            </div>
                            <div class="flex flex-col gap-2 border-t border-slate-200 pt-4 dark:border-white/10 sm:flex-row sm:items-center sm:justify-between">
                                <p class="text-sm text-slate-500 dark:text-slate-400">Les règles sont enregistrées au niveau magasin.</p>
                                <button class="rounded-lg bg-brand px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:brightness-110" type="submit">Enregistrer les règles</button>
                            </div>
                        </form>

                        <div class="grid gap-4">
                            <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-slate-950/40">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <h3 class="font-semibold">Préférences magasin</h3>
                                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Contexte appliqué à la caisse et au stock.</p>
                                    </div>
                                    <x-status-pill tone="neutral">{{ $currentStore['type'] ?? 'store' }}</x-status-pill>
                                </div>
                                <div class="mt-4 grid gap-3 text-sm">
                                    <div class="settings-info-row"><span>Magasin courant</span><strong>{{ $currentStore['name'] ?? $tenant->name }}</strong></div>
                                    <div class="settings-info-row"><span>Devise</span><strong>{{ strtoupper($tenant->currency) }} · DH</strong></div>
                                    <div class="settings-info-row"><span>Fuseau</span><strong>{{ $tenant->timezone }}</strong></div>
                                    <div class="settings-info-row"><span>Langues</span><strong>Français · العربية</strong></div>
                                </div>
                            </article>
                        </div>
                    </div>
                </article>

                @elseif ($settingsSection === 'modules')
                    <article class="rounded-xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-slate-950/40">
                        <div class="border-b border-slate-200 p-5 dark:border-white/10">
                            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                <div class="flex items-start gap-4">
                                    <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-brand text-lg font-semibold text-white">☷</span>
                                    <div>
                                        <p class="text-xs font-semibold uppercase tracking-wide text-brand">Paramètres · modules</p>
                                        <h2 class="mt-1 text-xl font-semibold text-slate-950 dark:text-slate-100">Modules & ordre du menu</h2>
                                        <p class="mt-1 max-w-3xl text-sm text-slate-500 dark:text-slate-400">Activez uniquement les espaces utiles à votre activité et réorganisez le menu latéral pour placer les actions les plus utilisées en premier.</p>
                                    </div>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <x-status-pill tone="primary">{{ collect($appModuleSettings['enabled'])->filter()->count() }} actif(s)</x-status-pill>
                                    <x-status-pill tone="neutral">{{ count($appModuleSettings['definitions']) }} module(s)</x-status-pill>
                                </div>
                            </div>
                        </div>

                        <form action="{{ route('settings.modules.update') }}" method="POST" class="space-y-5 p-5" data-module-settings-form>
                            @csrf
                            <input type="hidden" name="order" value="{{ implode(',', $appModuleSettings['order']) }}" data-module-order-input>
                            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 dark:border-white/10 dark:bg-white/[0.03]">
                                <div class="mb-3 flex flex-col gap-2 px-1 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <h3 class="text-sm font-semibold text-slate-950 dark:text-slate-100">Menu de l’application</h3>
                                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Glissez une ligne pour changer son ordre. Les modules verrouillés restent toujours actifs.</p>
                                    </div>
                                    <span class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Activation / ordre</span>
                                </div>
                                <div class="space-y-3" data-module-sortable>
                                    @foreach ($appModuleSettings['order'] as $moduleKey)
                                        @php
                                            $moduleConfig = $appModuleSettings['definitions'][$moduleKey] ?? null;
                                        @endphp
                                        @continue(! $moduleConfig)
                                        @php
                                            $locked = (bool) ($moduleConfig['locked'] ?? false) || $moduleKey === 'settings';
                                            $enabled = (bool) ($appModuleSettings['enabled'][$moduleKey] ?? false);
                                        @endphp
                                        <div class="module-order-row group grid gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition hover:border-brand/40 hover:shadow-md dark:border-white/10 dark:bg-slate-950/60 sm:grid-cols-[44px_minmax(0,1fr)_auto] sm:items-center" data-module-key="{{ $moduleKey }}" draggable="{{ $locked ? 'false' : 'true' }}">
                                            <button type="button" class="module-drag-handle grid size-10 place-items-center rounded-lg border border-slate-200 bg-slate-50 text-lg font-semibold text-slate-500 transition group-hover:border-brand/40 group-hover:text-brand disabled:cursor-not-allowed disabled:opacity-40 dark:border-white/10 dark:bg-white/5" aria-label="Déplacer {{ $moduleConfig['label'] }}" @disabled($locked)>☰</button>
                                            <div class="min-w-0">
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <h4 class="font-semibold text-slate-950 dark:text-slate-100">{{ $moduleConfig['label'] }}</h4>
                                                    <x-status-pill :tone="$locked ? 'primary' : ($enabled ? 'success' : 'neutral')">{{ $locked ? 'Toujours actif' : ($enabled ? 'Activé' : 'Désactivé') }}</x-status-pill>
                                                </div>
                                                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $moduleConfig['description'] }}</p>
                                            </div>
                                            <div class="flex items-center justify-between gap-3 sm:justify-end">
                                                <span class="rounded-lg bg-slate-100 px-2.5 py-1 text-xs font-semibold uppercase text-slate-500 dark:bg-white/5 dark:text-slate-400">{{ $moduleKey }}</span>
                                                @if ($locked)
                                                    <input type="hidden" name="enabled[]" value="{{ $moduleKey }}">
                                                    <span class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-500 dark:border-white/10 dark:text-slate-400">Verrouillé</span>
                                                @else
                                                    <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:border-brand hover:text-brand dark:border-white/10 dark:bg-slate-950 dark:text-slate-200">
                                                        <input name="enabled[]" value="{{ $moduleKey }}" type="checkbox" @checked($enabled) class="size-4 rounded border-slate-300 accent-[var(--brand-primary)]">
                                                        <span>{{ $enabled ? 'Activé' : 'Activer' }}</span>
                                                    </label>
                                                @endif
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            <div class="flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 dark:border-white/10 dark:bg-slate-950/40 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <p class="text-sm font-semibold text-slate-950 dark:text-slate-100">Application modulaire</p>
                                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Le menu latéral, les raccourcis et les écrans principaux suivent cette configuration.</p>
                                </div>
                                <button class="rounded-lg bg-brand px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:brightness-110" type="submit">Enregistrer les modules</button>
                            </div>
                        </form>
                    </article>

                @elseif ($settingsSection === 'documents')
                    @include('librairepro.partials.document-settings')
                @elseif (in_array($settingsSection, ['messaging', 'message-templates', 'sms-api'], true))
                    @include('librairepro.partials.messaging-section')

                @elseif ($settingsSection === 'company')
                <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <h2 class="font-semibold">Profil société / magasin principal</h2>
                            <p class="mt-1 text-sm text-slate-500">Informations légales, formats, documents et préfixes repris de l’ancien écran Société.</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <x-status-pill tone="primary">{{ $currentBusinessMode['short_label'] }}</x-status-pill>
                            <x-status-pill tone="info">{{ strtoupper($companyProfile['currency']) }}</x-status-pill>
                        </div>
                    </div>

                    <form action="{{ route('settings.company.update') }}" method="POST" enctype="multipart/form-data" class="mt-5 space-y-5">
                        @csrf
                        <section class="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-white/10 dark:bg-white/5">
                            <h3 class="text-sm font-semibold">Identification</h3>
                            <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                                <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Code magasin</span><input name="store_code" value="{{ old('store_code', $companyProfile['store_code']) }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                                <label class="space-y-1.5 xl:col-span-2"><span class="text-xs font-semibold uppercase text-slate-500">Nom société / magasin *</span><input name="store_name" required value="{{ old('store_name', $companyProfile['store_name']) }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                                <label id="business-mode" class="scroll-mt-24 space-y-1.5">
                                    <span class="text-xs font-semibold uppercase text-slate-500">Mode d’activité *</span>
                                    <select name="business_mode" required class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">
                                        @foreach ($businessModes as $modeKey => $mode)
                                            <option value="{{ $modeKey }}" @selected(old('business_mode', $companyProfile['business_mode']) === $modeKey)>{{ $mode['label'] }}</option>
                                        @endforeach
                                    </select>
                                    <span class="block text-xs text-slate-500">Adapte progressivement les libellés, raccourcis, rapports et comportements métier.</span>
                                </label>
                                <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Site web</span><input name="store_website" value="{{ old('store_website', $companyProfile['store_website']) }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="https://..."></label>
                                <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Mobile</span><input name="mobile" value="{{ old('mobile', $companyProfile['mobile']) }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                                <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Téléphone</span><input name="phone" value="{{ old('phone', $companyProfile['phone']) }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                                <label class="space-y-1.5 xl:col-span-2"><span class="text-xs font-semibold uppercase text-slate-500">Email</span><input name="email" type="email" value="{{ old('email', $companyProfile['email']) }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                            </div>
                        </section>

                        <section class="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-white/10 dark:bg-white/5">
                            <h3 class="text-sm font-semibold">Fiscalité & informations légales</h3>
                            <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                                @foreach (['cnss' => 'CNSS', 'rc' => 'RC', 'gst_no' => 'ICE / GST No', 'vat_no' => 'TVA / VAT No', 'pan_no' => 'PAN / identifiant fiscal'] as $field => $label)
                                    <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">{{ $label }}</span><input name="{{ $field }}" value="{{ old($field, $companyProfile[$field]) }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                                @endforeach
                            </div>
                        </section>

                        <section class="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-white/10 dark:bg-white/5">
                            <h3 class="text-sm font-semibold">Adresse</h3>
                            <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                                <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Pays</span><input name="country" value="{{ old('country', $companyProfile['country']) }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                                <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">État / région</span><input name="state" value="{{ old('state', $companyProfile['state']) }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                                <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Ville</span><input name="city" value="{{ old('city', $companyProfile['city']) }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                                <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Code postal</span><input name="postcode" value="{{ old('postcode', $companyProfile['postcode']) }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                                <label class="space-y-1.5 md:col-span-2 xl:col-span-4"><span class="text-xs font-semibold uppercase text-slate-500">Adresse</span><textarea name="address" class="min-h-24 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-white/10 dark:bg-slate-900">{{ old('address', $companyProfile['address']) }}</textarea></label>
                            </div>
                        </section>

                        <section class="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-white/10 dark:bg-white/5">
                            <h3 class="text-sm font-semibold">Branding, signature & banque</h3>
                            <div class="mt-4 grid gap-3 md:grid-cols-2">
                                <div class="space-y-1.5">
                                    <span class="text-xs font-semibold uppercase text-slate-500">Logo magasin</span>
                                    <div class="flex items-center gap-3">
                                        @if (!empty($companyProfile['store_logo']))
                                            <img src="{{ asset($companyProfile['store_logo']) }}" alt="Logo" class="size-14 rounded-lg border border-slate-200 object-contain dark:border-white/10">
                                        @endif
                                        <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg bg-brand px-4 py-2.5 text-sm font-semibold text-white">
                                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                                            Choisir un fichier
                                            <input type="file" name="store_logo_file" accept="image/png,image/jpeg,image/webp,image/gif" class="sr-only" onchange="this.closest('.space-y-1\.5').querySelector('.store-logo-filename').textContent = this.files[0]?.name || ''">
                                        </label>
                                        <span class="store-logo-filename text-sm text-slate-600 dark:text-slate-300"></span>
                                    </div>
                                    @if (!empty($companyProfile['store_logo']))
                                        <label class="flex items-center gap-2 text-sm font-semibold text-rose-600">
                                            <input type="checkbox" name="remove_store_logo" value="1" class="size-4 accent-rose-500">
                                            Supprimer le logo actuel
                                        </label>
                                    @endif
                                </div>
                                <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Signature (URL ou chemin fichier)</span><input name="signature" value="{{ old('signature', $companyProfile['signature']) }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                                <label class="flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold dark:border-white/10 dark:bg-slate-950/40"><input name="show_signature" value="1" type="checkbox" @checked(old('show_signature', $companyProfile['show_signature'])) class="size-4 accent-[var(--brand-primary)]"> Afficher la signature</label>
                                <label class="space-y-1.5 md:col-span-2"><span class="text-xs font-semibold uppercase text-slate-500">Coordonnées bancaires</span><textarea name="bank_details" class="min-h-24 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-white/10 dark:bg-slate-900">{{ old('bank_details', $companyProfile['bank_details']) }}</textarea></label>
                            </div>
                            <div class="mt-4 rounded-xl border border-slate-200 bg-white p-4 dark:border-white/10 dark:bg-slate-950/40">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <h4 class="text-sm font-semibold">Icône de l'application</h4>
                                        <p class="text-xs text-slate-500">Utilisée pour l'installation PWA (192×192 et 512×512 px). Formats : PNG, JPG, WEBP.</p>
                                    </div>
                                    @if (!empty($companyProfile['app_icon']))
                                            <img src="{{ route('app.icon', 192) }}" alt="App icon" class="size-12 rounded-xl object-cover">
                                    @else
                                        <span class="grid size-12 place-items-center rounded-xl bg-slate-100 text-lg text-slate-400 dark:bg-slate-800">🖼</span>
                                    @endif
                                </div>
                                <div class="mt-3 flex flex-wrap items-center gap-3">
                                    <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg bg-brand px-4 py-2.5 text-sm font-semibold text-white">
                                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                                        Choisir une image
                                        <input type="file" name="app_icon" accept="image/png,image/jpeg,image/webp,image/gif" class="sr-only" onchange="this.closest('form').querySelector('.app-icon-filename').textContent = this.files[0]?.name || ''">
                                    </label>
                                    <span class="app-icon-filename text-sm text-slate-600 dark:text-slate-300"></span>
                                    @if (!empty($companyProfile['app_icon']))
                                        <label class="flex items-center gap-2 text-sm font-semibold text-rose-600">
                                            <input type="checkbox" name="remove_app_icon" value="1" class="size-4 accent-rose-500">
                                            Réinitialiser l'icône par défaut
                                        </label>
                                    @endif
                                </div>
                            </div>
                        </section>

                        <section class="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-white/10 dark:bg-white/5">
                            <h3 class="text-sm font-semibold">Formats, devise & caisse</h3>
                            <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                                <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Fuseau horaire</span><input name="timezone" required value="{{ old('timezone', $companyProfile['timezone']) }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                                <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Format date</span><select name="date_format" class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">@foreach(['dd/mm/yyyy','dd-mm-yyyy','mm-dd-yyyy','yyyy-mm-dd'] as $format)<option value="{{ $format }}" @selected(old('date_format', $companyProfile['date_format']) === $format)>{{ $format }}</option>@endforeach</select></label>
                                <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Format heure</span><select name="time_format" class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="24" @selected(old('time_format', $companyProfile['time_format']) === '24')>24 heures</option><option value="12" @selected(old('time_format', $companyProfile['time_format']) === '12')>12 heures</option></select></label>
                                <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Langue</span><select name="language_id" class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="fr" @selected(old('language_id', $companyProfile['language_id']) === 'fr')>Français</option><option value="ar" @selected(old('language_id', $companyProfile['language_id']) === 'ar')>العربية</option><option value="en" @selected(old('language_id', $companyProfile['language_id']) === 'en')>English</option></select></label>
                                <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Devise</span><input name="currency" required maxlength="3" value="{{ old('currency', strtoupper($companyProfile['currency'])) }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm uppercase dark:border-white/10 dark:bg-slate-900"></label>
                                <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Placement devise</span><select name="currency_placement" class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="Right" @selected(old('currency_placement', $companyProfile['currency_placement']) === 'Right')>Droite</option><option value="Left" @selected(old('currency_placement', $companyProfile['currency_placement']) === 'Left')>Gauche</option></select></label>
                                <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Décimales prix</span><input name="decimals" type="number" min="0" max="4" value="{{ old('decimals', $companyProfile['decimals']) }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                                <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Décimales quantité</span><input name="qty_decimals" type="number" min="0" max="4" value="{{ old('qty_decimals', $companyProfile['qty_decimals']) }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                                <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Compte par défaut</span><input name="default_account_id" value="{{ old('default_account_id', $companyProfile['default_account_id']) }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                                <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Remise vente par défaut (%)</span><input name="sales_discount" type="number" min="0" max="100" step="0.01" value="{{ old('sales_discount', $companyProfile['sales_discount']) }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                                <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Montant en lettres</span><select name="number_to_words" class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">@foreach(['Default','Indian','Western','Off'] as $format)<option value="{{ $format }}" @selected(old('number_to_words', $companyProfile['number_to_words']) === $format)>{{ $format }}</option>@endforeach</select></label>
                            </div>
                            <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                                @foreach (['round_off' => 'Arrondir les montants', 'mrp_column' => 'Afficher colonne MRP', 'change_return' => 'Gérer monnaie rendue', 'previous_balance_bit' => 'Afficher ancien solde'] as $field => $label)
                                    <label class="flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold dark:border-white/10 dark:bg-slate-950/40"><input name="{{ $field }}" value="1" type="checkbox" @checked(old($field, $companyProfile[$field])) class="size-4 accent-[var(--brand-primary)]"> {{ $label }}</label>
                                @endforeach
                            </div>
                        </section>

                        <section class="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-white/10 dark:bg-white/5">
                            <h3 class="text-sm font-semibold">Factures, tickets & conditions</h3>
                            <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                                <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Format facture vente</span><input name="sales_invoice_format_id" value="{{ old('sales_invoice_format_id', $companyProfile['sales_invoice_format_id']) }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                                <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Format ticket POS</span><input name="pos_invoice_format_id" value="{{ old('pos_invoice_format_id', $companyProfile['pos_invoice_format_id']) }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                                <label class="flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold dark:border-white/10 dark:bg-slate-950/40"><input name="t_and_c_status" value="1" type="checkbox" @checked(old('t_and_c_status', $companyProfile['t_and_c_status'])) class="size-4 accent-[var(--brand-primary)]"> Conditions facture</label>
                                <label class="flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold dark:border-white/10 dark:bg-slate-950/40"><input name="t_and_c_status_pos" value="1" type="checkbox" @checked(old('t_and_c_status_pos', $companyProfile['t_and_c_status_pos'])) class="size-4 accent-[var(--brand-primary)]"> Conditions POS</label>
                                <label class="flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold dark:border-white/10 dark:bg-slate-950/40"><input name="toggle_header_footer" value="1" type="checkbox" @checked(old('toggle_header_footer', $companyProfile['toggle_header_footer'])) class="size-4 accent-[var(--brand-primary)]"> En-tête / pied de page</label>
                                <label class="space-y-1.5 md:col-span-2 xl:col-span-4"><span class="text-xs font-semibold uppercase text-slate-500">Texte pied de facture</span><textarea name="sales_invoice_footer_text" class="min-h-20 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-white/10 dark:bg-slate-900">{{ old('sales_invoice_footer_text', $companyProfile['sales_invoice_footer_text']) }}</textarea></label>
                                <label class="space-y-1.5 md:col-span-2 xl:col-span-4"><span class="text-xs font-semibold uppercase text-slate-500">Conditions générales</span><textarea name="invoice_terms" class="min-h-28 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-white/10 dark:bg-slate-900">{{ old('invoice_terms', $companyProfile['invoice_terms']) }}</textarea></label>
                            </div>
                        </section>

                        <section class="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-white/10 dark:bg-white/5">
                            <h3 class="text-sm font-semibold">Préfixes de numérotation</h3>
                            <div class="mt-4 grid gap-3 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-6">
                                @foreach ([
                                    'category_init' => 'Catégories', 'item_init' => 'Articles', 'supplier_init' => 'Fournisseurs', 'purchase_init' => 'Achats', 'purchase_return_init' => 'Retour achat', 'customer_init' => 'Clients',
                                    'sales_init' => 'Ventes', 'sales_return_init' => 'Retour vente', 'expense_init' => 'Dépenses', 'accounts_init' => 'Comptes', 'quotation_init' => 'Devis', 'money_transfer_init' => 'Transfert argent',
                                    'sales_payment_init' => 'Paiement vente', 'sales_return_payment_init' => 'Paiement retour vente', 'purchase_payment_init' => 'Paiement achat', 'purchase_return_payment_init' => 'Paiement retour achat', 'expense_payment_init' => 'Paiement dépense', 'cust_advance_init' => 'Avance client',
                                ] as $field => $label)
                                    <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">{{ $label }}</span><input name="{{ $field }}" value="{{ old($field, $companyProfile[$field]) }}" class="h-10 w-full rounded-lg border border-slate-200 px-3 text-sm uppercase dark:border-white/10 dark:bg-slate-900"></label>
                                @endforeach
                            </div>
                        </section>

                        <div class="flex justify-end">
                            <button class="rounded-lg bg-brand px-5 py-2.5 text-sm font-semibold text-white">Enregistrer la société</button>
                        </div>
                    </form>
                </article>

                @elseif ($settingsSection === 'warehouses')
                <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <h2 class="font-semibold">Magasins & dépôts</h2>
                            <p class="mt-1 text-sm text-slate-500">Gérez les points de vente, dépôts et rayons. Le magasin courant apparaît dans la barre supérieure.</p>
                        </div>
                        <div class="app-action-row">
                            <x-status-pill tone="primary">{{ $currentStore['name'] }}</x-status-pill>
                            <x-status-pill tone="info">{{ count($stores) }} emplacement(s)</x-status-pill>
                        </div>
                    </div>

                    <form action="{{ route('settings.current-store.update') }}" method="POST" class="app-action-form mt-5 rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-white/10 dark:bg-white/5">
                        @csrf
                        <select name="current_store" class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">
                            @foreach ($stores as $store)
                                @if ($store['is_active'])
                                    <option value="{{ $store['key'] }}" @selected($currentStore['key'] === $store['key'])>{{ $store['name'] }} · {{ $storeTypeLabels[$store['type']] ?? $store['type'] }}</option>
                                @endif
                            @endforeach
                        </select>
                        <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Définir courant</button>
                    </form>

                    <form action="{{ route('settings.stores.store') }}" method="POST" class="mt-4 grid gap-3 rounded-xl border border-slate-200 bg-white p-4 dark:border-white/10 dark:bg-slate-950/40 lg:grid-cols-6">
                        @csrf
                        <input name="name" required class="h-11 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900 lg:col-span-2" placeholder="Nom magasin / dépôt">
                        <select name="type" required class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">
                            @foreach ($storeTypeLabels as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <input name="phone" class="h-11 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Téléphone">
                        <input name="manager" class="h-11 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Responsable">
                        <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Ajouter</button>
                        <input name="address" class="h-11 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900 lg:col-span-5" placeholder="Adresse">
                        <label class="flex h-11 items-center gap-2 rounded-lg border border-slate-200 px-3 text-sm font-semibold dark:border-white/10"><input name="is_active" value="1" type="checkbox" checked class="size-4 accent-[var(--brand-primary)]"> Actif</label>
                    </form>

                    <div class="mt-5 grid gap-3 md:grid-cols-2">
                        @foreach ($stores as $store)
                            <details class="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-white/10 dark:bg-white/5" @if($currentStore['key'] === $store['key']) open @endif>
                                <summary class="flex cursor-pointer list-none items-center justify-between gap-3">
                                    <span class="min-w-0"><strong class="block truncate">{{ $store['name'] }}</strong><small class="mt-1 block truncate text-slate-500">{{ $storeTypeLabels[$store['type']] ?? $store['type'] }} · {{ $store['is_active'] ? 'Actif' : 'Désactivé' }}{{ $currentStore['key'] === $store['key'] ? ' · courant' : '' }}</small></span>
                                    <span class="text-xs font-semibold text-brand">Modifier</span>
                                </summary>
                                <form action="{{ route('settings.stores.update', $store['key']) }}" method="POST" class="mt-4 grid gap-3 border-t border-slate-200 pt-4 dark:border-white/10">
                                    @csrf
                                    @method('PUT')
                                    <input name="name" required value="{{ $store['name'] }}" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900">
                                    <select name="type" required class="h-10 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">@foreach ($storeTypeLabels as $key => $label)<option value="{{ $key }}" @selected($store['type'] === $key)>{{ $label }}</option>@endforeach</select>
                                    <input name="phone" value="{{ $store['phone'] }}" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Téléphone">
                                    <input name="manager" value="{{ $store['manager'] }}" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Responsable">
                                    <input name="address" value="{{ $store['address'] }}" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Adresse">
                                    <label class="flex h-10 items-center gap-2 rounded-lg border border-slate-200 px-3 text-sm font-semibold dark:border-white/10"><input name="is_active" value="1" type="checkbox" @checked($store['is_active']) class="size-4 accent-[var(--brand-primary)]"> Actif</label>
                                    <div class="flex justify-end gap-2"><button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Enregistrer</button></div>
                                </form>
                                <form action="{{ route('settings.stores.destroy', $store['key']) }}" method="POST" class="mt-2 flex justify-end" onsubmit="return confirm('Supprimer ce magasin ?')">@csrf @method('DELETE')<button class="rounded-lg border border-rose-200 px-3 py-2 text-xs font-semibold text-rose-600 dark:border-rose-500/30">Supprimer</button></form>
                            </details>
                        @endforeach
                    </div>
                </article>

                @elseif ($settingsSection === 'users')
                <article class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    @php
                        $activeUsersCount = $settingsUsers->where('is_active', true)->count();
                        $inactiveUsersCount = $settingsUsers->count() - $activeUsersCount;
                        $canManageUserPins = (string) ($tenant->users()->whereKey(auth()->id())->first()?->pivot?->role ?? '') === 'owner';
                    @endphp
                    <div class="border-b border-slate-200 bg-white p-5 text-slate-950 dark:border-white/10 dark:bg-slate-950 dark:text-white">
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                            <div>
                                <p class="text-sm font-semibold text-brand">Paramètres · Équipe</p>
                                <h2 class="mt-1 text-2xl font-semibold tracking-tight text-slate-950 dark:text-white">Utilisateurs & accès magasin</h2>
                                <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-600 dark:text-slate-300">Gérez les comptes de l'équipe, les rôles, les magasins autorisés et les permissions exceptionnelles sans perdre le contexte.</p>
                            </div>
                            <div class="app-action-row">
                                <x-status-pill tone="primary">{{ $settingsUsers->count() }} utilisateur(s)</x-status-pill>
                                <x-status-pill tone="success">{{ $activeUsersCount }} actif(s)</x-status-pill>
                                @if ($inactiveUsersCount > 0)
                                    <x-status-pill tone="warning">{{ $inactiveUsersCount }} désactivé(s)</x-status-pill>
                                @endif
                            </div>
                        </div>
                        <div class="mt-5 grid gap-3 sm:grid-cols-3">
                            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.06]">
                                <span class="text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">Rôles disponibles</span>
                                <strong class="mt-2 block text-2xl text-slate-950 dark:text-white">{{ $settingsRoles->count() }}</strong>
                            </div>
                            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.06]">
                                <span class="text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">Magasins / dépôts</span>
                                <strong class="mt-2 block text-2xl text-slate-950 dark:text-white">{{ count($storeAccessOptions) }}</strong>
                            </div>
                            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.06]">
                                <span class="text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">Permissions suivies</span>
                                <strong class="mt-2 block text-2xl text-slate-950 dark:text-white">{{ count($permissionCatalog) }}</strong>
                            </div>
                        </div>
                    </div>

                    <div class="p-5">
                        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-slate-950/40">
                            <div class="grid gap-4 border-b border-slate-200 bg-slate-50/80 p-4 dark:border-white/10 dark:bg-white/5 xl:grid-cols-[minmax(280px,1fr)_auto] xl:items-center">
                                <div>
                                    <h3 class="text-base font-semibold text-slate-950 dark:text-white">Liste des utilisateurs</h3>
                                    <p class="mt-1 text-sm text-slate-500">Recherchez, modifiez les rôles, les accès magasin et les permissions directes.</p>
                                    @error('pin')
                                        <p class="mt-2 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300">{{ $message }}</p>
                                    @enderror
                                </div>
                                <div class="grid gap-2 sm:grid-cols-[minmax(260px,420px)_auto]">
                                    <input data-table-filter="settings-users-table" class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm shadow-sm transition focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900" placeholder="Rechercher nom, email, rôle, magasin...">
                                    <button type="button" onclick="document.getElementById('user-create-dialog').showModal()" class="h-11 rounded-lg bg-brand px-4 text-sm font-semibold text-white shadow-sm shadow-indigo-500/20 transition hover:brightness-110">Nouvel utilisateur</button>
                                </div>
                            </div>
                            <div class="overflow-x-auto">
                                <table id="settings-users-table" class="w-full min-w-[1120px] text-left text-sm">
                                    <thead class="bg-white text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-950/40">
                                        <tr>
                                            <th class="px-4 py-3">Utilisateur</th>
                                            <th class="px-4 py-3">Rôle</th>
                                            <th class="px-4 py-3">Magasins</th>
                                            <th class="px-4 py-3">Permissions directes</th>
                                            <th class="px-4 py-3">PIN</th>
                                            <th class="px-4 py-3">Statut</th>
                                            <th class="px-4 py-3 text-right">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-200 dark:divide-white/10">
                                        @forelse ($settingsUsers as $user)
                                            @php
                                                $userPermissions = json_decode($user->pivot->permissions ?? '[]', true) ?: [];
                                                $userStores = json_decode($user->pivot->store_access ?? '[]', true) ?: [];
                                                $userRole = $settingsRoles->firstWhere('key', $user->pivot->role);
                                                $visibleStores = collect($userStores)->take(2)->implode(', ');
                                                $extraStoresCount = max(count($userStores) - 2, 0);
                                            @endphp
                                            <tr class="bg-white align-middle transition hover:bg-slate-50 dark:bg-transparent dark:hover:bg-white/5">
                                                <td class="px-4 py-4">
                                                    <div class="flex min-w-0 items-center gap-3">
                                                        <x-user-avatar :user="$user" size="md" rounded="rounded-xl" />
                                                        <span class="min-w-0">
                                                            <strong class="block truncate">{{ $user->name }}</strong>
                                                            <small class="mt-1 block truncate text-slate-500">{{ $user->email }}{{ $user->phone ? ' · '.$user->phone : '' }}</small>
                                                        </span>
                                                    </div>
                                                </td>
                                                <td class="px-4 py-4">
                                                    <a href="{{ route('module', ['module' => 'settings', 'section' => 'roles', 'edit_role' => $user->pivot->role]) }}" class="inline-block transition hover:brightness-110"><x-status-pill tone="info">{{ $userRole?->name ?? $user->pivot->role }}</x-status-pill></a>
                                                    <p class="mt-1 text-xs text-slate-500">Clé: {{ $user->pivot->role }}</p>
                                                </td>
                                                <td class="px-4 py-4">
                                                    <span class="font-medium text-slate-700 dark:text-slate-200">{{ $visibleStores ?: 'Aucun accès' }}</span>
                                                    @if ($extraStoresCount > 0)
                                                        <span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-600 dark:bg-white/10 dark:text-slate-300">+{{ $extraStoresCount }}</span>
                                                    @endif
                                                </td>
                                                <td class="px-4 py-4">
                                                    <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700 dark:bg-white/10 dark:text-slate-200">{{ count($userPermissions) }} permission(s)</span>
                                                </td>
                                                <td class="px-4 py-4">
                                                    @if ($canManageUserPins)
                                                        <button type="button" onclick="document.getElementById('user-pin-{{ $user->id }}').showModal()" class="inline-block transition hover:brightness-110">
                                                            <x-status-pill :tone="$user->pin_hash ? 'success' : 'warning'">{{ $user->pin_hash ? 'Configuré' : 'À définir' }}</x-status-pill>
                                                        </button>
                                                    @else
                                                        <x-status-pill :tone="$user->pin_hash ? 'success' : 'warning'">{{ $user->pin_hash ? 'Configuré' : 'À définir' }}</x-status-pill>
                                                    @endif
                                                </td>
                                                <td class="px-4 py-4">
                                                    <x-status-pill :tone="$user->is_active ? 'success' : 'danger'">{{ $user->is_active ? 'Actif' : 'Désactivé' }}</x-status-pill>
                                                </td>
                                                <td class="px-4 py-4 text-right">
                                                    <button type="button" onclick="document.getElementById('user-edit-{{ $user->id }}').showModal()" class="inline-flex items-center justify-center rounded-lg bg-brand px-3 py-2 text-xs font-semibold text-white shadow-sm shadow-indigo-500/20 transition hover:bg-brand-600">Modifier accès</button>
                                                </td>
                                            </tr>
                                            <dialog id="user-edit-{{ $user->id }}" class="app-dialog app-user-dialog w-[min(980px,calc(100vw-2rem))] rounded-2xl border border-slate-200 bg-white p-0 text-slate-950 shadow-2xl backdrop:bg-slate-950/40 dark:border-white/10 dark:bg-slate-950 dark:text-slate-100">
                                                <form action="{{ route('settings.users.update', $user) }}" method="POST" enctype="multipart/form-data" class="flex max-h-[calc(100dvh-2rem)] flex-col">
                                                    @csrf
                                                    @method('PUT')
                                                    <div class="shrink-0 bg-white flex items-start justify-between gap-4 border-b border-slate-200 p-5 dark:border-white/10 dark:bg-slate-950">
                                                        <div class="flex min-w-0 items-center gap-3">
                                                            <x-user-avatar :user="$user" size="lg" rounded="rounded-xl" />
                                                            <div class="min-w-0">
                                                                <p class="text-sm font-semibold text-brand">Utilisateur · rôle · permissions</p>
                                                                <h3 class="truncate text-xl font-semibold">{{ $user->name }}</h3>
                                                                <p class="mt-1 text-sm text-slate-500">Modifiez ses informations, son rôle, ses magasins autorisés et ses permissions directes.</p>
                                                            </div>
                                                        </div>
                                                        <button class="dialog-close grid size-9 place-items-center rounded-lg border border-slate-200 text-lg font-semibold dark:border-white/10" type="button">×</button>
                                                    </div>
                                                    <div class="app-user-dialog-scroll min-h-0 flex-1 overflow-y-auto p-5">
                                                        <div class="mb-4 flex items-center justify-between gap-3 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-medium text-slate-600 dark:border-white/10 dark:bg-white/5 dark:text-slate-300">
                                                            <span>Informations, rôle, magasins et permissions</span>
                                                            <span class="hidden sm:inline">Défilement disponible</span>
                                                        </div>
                                                        <div class="grid gap-5 lg:grid-cols-[1fr_0.9fr]">
                                                        <section class="space-y-4">
                                                            <div class="grid gap-3 md:grid-cols-2">
                                                                <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Nom complet *</span><input name="name" required value="{{ $user->name }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                                                                <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Email *</span><input name="email" required type="email" value="{{ $user->email }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                                                                <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Téléphone</span><input name="phone" value="{{ $user->phone }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                                                                <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Nouveau mot de passe</span><input name="password" type="password" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Laisser vide si inchangé"></label>
                                                                @if ($canManageUserPins)
                                                                    <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">PIN caisse</span><input name="pin" type="password" inputmode="numeric" pattern="[0-9]*" minlength="4" maxlength="8" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="4 à 8 chiffres"></label>
                                                                @endif
                                                                <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Rôle *</span><select name="role" required class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">@foreach ($settingsRoles as $role)<option value="{{ $role->key }}" @selected($user->pivot->role === $role->key)>{{ $role->name }}</option>@endforeach</select></label>
                                                                <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Couleur avatar</span><input name="avatar_color" type="color" value="{{ $user->avatar_color }}" class="h-11 w-full rounded-lg border border-slate-200 p-1 dark:border-white/10 dark:bg-slate-900"></label>
                                                                <label class="space-y-1.5 md:col-span-2"><span class="text-xs font-semibold uppercase text-slate-500">Photo de profil</span><input name="profile_photo" type="file" accept="image/*" class="block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm file:mr-3 file:rounded-md file:border-0 file:bg-brand file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-white dark:border-white/10 dark:bg-slate-900"></label>
                                                                @if ($user->profile_photo_path)
                                                                    <label class="flex h-11 items-center gap-2 rounded-lg border border-rose-200 px-3 text-sm font-semibold text-rose-600 dark:border-rose-500/30"><input name="remove_profile_photo" value="1" type="checkbox" class="size-4 accent-rose-500"> Retirer la photo</label>
                                                                @endif
                                                                @if ($canManageUserPins && $user->pin_hash)
                                                                    <label class="flex h-11 items-center gap-2 rounded-lg border border-amber-200 px-3 text-sm font-semibold text-amber-700 dark:border-amber-500/30 dark:text-amber-200"><input name="clear_pin" value="1" type="checkbox" class="size-4 accent-amber-500"> Réinitialiser le PIN</label>
                                                                @endif
                                                                <label class="flex h-11 items-center gap-2 rounded-lg border border-slate-200 px-3 text-sm font-semibold dark:border-white/10"><input name="is_active" value="1" type="checkbox" @checked($user->is_active) class="size-4 accent-[var(--brand-primary)]"> Compte actif</label>
                                                            </div>
                                                        </section>
                                                        <section class="grid gap-4">
                                                            <fieldset class="rounded-xl border border-slate-200 p-4 dark:border-white/10">
                                                                <legend class="px-1 text-xs font-semibold uppercase text-slate-500">Accès magasin</legend>
                                                                <div class="mt-2 grid max-h-40 gap-2 overflow-y-auto sm:grid-cols-2">
                                                                    @foreach ($storeAccessOptions as $store)
                                                                        <label class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-slate-50 dark:hover:bg-white/5"><input name="store_access[]" value="{{ $store }}" type="checkbox" @checked(in_array($store, $userStores, true)) class="size-4 accent-[var(--brand-primary)]"> {{ $store }}</label>
                                                                    @endforeach
                                                                </div>
                                                            </fieldset>
                                                            <fieldset class="rounded-xl border border-slate-200 p-4 dark:border-white/10">
                                                                <legend class="px-1 text-xs font-semibold uppercase text-slate-500">Permissions directes</legend>
                                                                <p class="mt-1 text-xs text-slate-500">À utiliser seulement pour les exceptions au rôle.</p>
                                                                <div class="mt-3 grid max-h-64 gap-2 overflow-y-auto sm:grid-cols-2">
                                                                    @foreach ($permissionCatalog as $key => $label)
                                                                        <label class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-slate-50 dark:hover:bg-white/5"><input name="permissions[]" value="{{ $key }}" type="checkbox" @checked(in_array($key, $userPermissions, true)) class="size-4 accent-[var(--brand-primary)]"> {{ $label }}</label>
                                                                    @endforeach
                                                                </div>
                                                            </fieldset>
                                                        </section>
                                                        </div>
                                                    </div>
                                                    <div class="shrink-0 bg-white flex flex-col gap-3 border-t border-slate-200 p-5 shadow-[0_-12px_30px_rgba(15,23,42,0.08)] dark:border-white/10 dark:bg-slate-950 sm:flex-row sm:items-center sm:justify-between">
                                                        <span class="text-xs text-slate-500">Les changements sont appliqués à la prochaine requête de l'utilisateur.</span>
                                                        <div class="flex justify-end gap-2">
                                                            <button type="button" class="dialog-close rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold dark:border-white/10">Annuler</button>
                                                            <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Enregistrer</button>
                                                        </div>
                                                    </div>
                                                </form>
                                                <form action="{{ route('settings.users.destroy', $user) }}" method="POST" class="px-5 pb-5 text-right" onsubmit="return confirm('Retirer l’accès de cet utilisateur ?')">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button class="text-sm font-semibold text-rose-600">Retirer accès</button>
                                                </form>
                                            </dialog>
                                            @if ($canManageUserPins)
                                            <dialog id="user-pin-{{ $user->id }}" class="app-dialog w-full max-w-[420px] rounded-2xl border border-slate-200 bg-white p-0 text-slate-950 shadow-2xl backdrop:bg-slate-950/40 dark:border-white/10 dark:bg-slate-950 dark:text-slate-100">
                                                <form action="{{ route('settings.users.pin', $user) }}" method="POST">
                                                    @csrf
                                                    @method('PUT')
                                                    <div class="flex items-center gap-4 border-b border-slate-200 px-6 py-5 dark:border-white/10">
                                                        <x-user-avatar :user="$user" size="lg" rounded="rounded-xl" />
                                                        <div class="min-w-0 flex-1">
                                                            <h3 class="text-lg font-semibold leading-tight">{{ $user->name }}</h3>
                                                            <p class="mt-0.5 text-sm text-slate-500">{{ $tr('PIN caisse') }}</p>
                                                        </div>
                                                        <button class="dialog-close grid size-9 shrink-0 place-items-center rounded-xl border border-slate-200 text-lg font-semibold text-slate-500 transition hover:bg-slate-50 hover:text-slate-700 dark:border-white/10 dark:hover:bg-white/5 dark:hover:text-white" type="button">×</button>
                                                    </div>
                                                    <div class="px-6 py-5">
                                                        <div class="mb-5 flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 dark:border-white/10 dark:bg-white/5">
                                                            <div class="grid size-10 shrink-0 place-items-center rounded-xl {{ $user->pin_hash ? 'bg-emerald-100 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-400' : 'bg-amber-100 text-amber-600 dark:bg-amber-500/15 dark:text-amber-400' }}">
                                                                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                                                                    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                                                                    @if ($user->pin_hash)
                                                                    <path d="M12 14.5L10 17l-2-1"/>
                                                                    @endif
                                                                </svg>
                                                            </div>
                                                            <div class="min-w-0">
                                                                <strong class="block text-sm">{{ $user->pin_hash ? $tr('PIN configuré') : $tr('Aucun PIN') }}</strong>
                                                                <span class="block text-xs text-slate-500">{{ $user->pin_hash ? $tr('Modifier ou supprimer le PIN existant.') : $tr('Définir un code PIN pour cet utilisateur.') }}</span>
                                                            </div>
                                                        </div>
                                                        <div class="space-y-4">
                                                            <label class="block space-y-2">
                                                                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $tr('Code PIN (4-8 chiffres)') }}</span>
                                                                <div class="relative">
                                                                    <svg class="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                                                    <input name="pin" type="password" inputmode="numeric" pattern="[0-9]*" minlength="4" maxlength="8" autocomplete="off" class="h-12 w-full rounded-xl border border-slate-200 bg-white pl-10 pr-4 text-center text-lg font-bold tracking-[0.3em] outline-none transition placeholder:font-normal placeholder:tracking-normal focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900" placeholder="••••">
                                                                </div>
                                                            </label>
                                                            <label class="block space-y-2">
                                                                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $tr('Confirmer le PIN') }}</span>
                                                                <div class="relative">
                                                                    <svg class="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/><path d="m9 16 2 2 4-4"/></svg>
                                                                    <input name="pin_confirmation" type="password" inputmode="numeric" pattern="[0-9]*" minlength="4" maxlength="8" autocomplete="off" class="h-12 w-full rounded-xl border border-slate-200 bg-white pl-10 pr-4 text-center text-lg font-bold tracking-[0.3em] outline-none transition placeholder:font-normal placeholder:tracking-normal focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900" placeholder="••••">
                                                                </div>
                                                            </label>
                                                            @if ($user->pin_hash)
                                                            <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 transition hover:bg-rose-100 dark:border-rose-500/20 dark:bg-rose-500/10 dark:hover:bg-rose-500/20">
                                                                <input name="clear_pin" value="1" type="checkbox" class="size-4 accent-rose-500">
                                                                <span class="text-sm font-semibold text-rose-700 dark:text-rose-300">{{ $tr('Supprimer le PIN') }}</span>
                                                            </label>
                                                            @endif
                                                        </div>
                                                        @error('pin')
                                                            <p class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-2.5 text-sm font-semibold text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300">{{ $message }}</p>
                                                        @enderror
                                                    </div>
                                                    <div class="flex justify-end gap-3 border-t border-slate-200 px-6 py-4 dark:border-white/10">
                                                        <button type="button" class="dialog-close rounded-xl border border-slate-200 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-white/10 dark:bg-transparent dark:text-slate-300 dark:hover:bg-white/5">{{ $tr('Annuler') }}</button>
                                                        <button class="rounded-xl bg-brand px-5 py-2.5 text-sm font-semibold text-white shadow-sm shadow-indigo-500/20 transition hover:brightness-110">{{ $tr('Enregistrer') }}</button>
                                                    </div>
                                                </form>
                                            </dialog>
                                            @endif
                                        @empty
                                            <tr><td colspan="7" class="px-4 py-12 text-center text-slate-500">Aucun utilisateur configuré.</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <dialog id="user-create-dialog" class="app-dialog app-user-dialog w-[min(980px,calc(100vw-2rem))] rounded-2xl border border-slate-200 bg-white p-0 text-slate-950 shadow-2xl backdrop:bg-slate-950/40 dark:border-white/10 dark:bg-slate-950 dark:text-slate-100">
                        <form action="{{ route('settings.users.store') }}" method="POST" enctype="multipart/form-data" class="flex max-h-[calc(100dvh-2rem)] flex-col">
                            @csrf
                            <div class="shrink-0 bg-white flex items-start justify-between gap-4 border-b border-slate-200 p-5 dark:border-white/10 dark:bg-slate-950">
                                <div>
                                    <p class="text-sm font-semibold text-brand">Nouvel accès</p>
                                    <h3 class="mt-1 text-xl font-semibold">Ajouter un utilisateur</h3>
                                    <p class="mt-1 text-sm text-slate-500">Créez le compte avec un rôle, puis ajustez uniquement les exceptions nécessaires.</p>
                                </div>
                                <button class="dialog-close grid size-9 place-items-center rounded-lg border border-slate-200 text-lg font-semibold dark:border-white/10" type="button">×</button>
                            </div>
                            <div class="app-user-dialog-scroll min-h-0 flex-1 overflow-y-auto p-5">
                                <div class="mb-4 flex items-center justify-between gap-3 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-medium text-slate-600 dark:border-white/10 dark:bg-white/5 dark:text-slate-300">
                                    <span>Compte, rôle, magasins et permissions</span>
                                    <span class="hidden sm:inline">Défilement disponible</span>
                                </div>
                                <div class="grid gap-5 lg:grid-cols-[1fr_0.9fr]">
                                <section class="space-y-4">
                                    <div class="grid gap-3 md:grid-cols-2">
                                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Nom complet *</span><input name="name" required class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Ex: Amina El Fassi"></label>
                                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Email *</span><input name="email" required type="email" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="nom@librairie.ma"></label>
                                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Téléphone</span><input name="phone" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="+212 ..."></label>
                                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Mot de passe temporaire *</span><input name="password" required type="password" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Minimum 8 caractères"></label>
                                        @if ($canManageUserPins)
                                            <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">PIN caisse</span><input name="pin" type="password" inputmode="numeric" pattern="[0-9]*" minlength="4" maxlength="8" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="4 à 8 chiffres"></label>
                                        @endif
                                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Rôle *</span><select name="role" required class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">@foreach ($settingsRoles as $role)<option value="{{ $role->key }}">{{ $role->name }}</option>@endforeach</select></label>
                                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Couleur avatar</span><input name="avatar_color" type="color" value="#3157D5" class="h-11 w-full rounded-lg border border-slate-200 p-1 dark:border-white/10 dark:bg-slate-900"></label>
                                        <label class="space-y-1.5 md:col-span-2"><span class="text-xs font-semibold uppercase text-slate-500">Photo de profil</span><input name="profile_photo" type="file" accept="image/*" class="block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm file:mr-3 file:rounded-md file:border-0 file:bg-brand file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-white dark:border-white/10 dark:bg-slate-900"></label>
                                        <label class="flex h-11 items-center gap-2 rounded-lg border border-slate-200 px-3 text-sm font-semibold dark:border-white/10"><input name="is_active" value="1" checked type="checkbox" class="size-4 accent-[var(--brand-primary)]"> Compte actif</label>
                                    </div>
                                </section>
                                <section class="grid gap-4">
                                    <fieldset class="rounded-xl border border-slate-200 p-4 dark:border-white/10">
                                        <legend class="px-1 text-xs font-semibold uppercase text-slate-500">Accès magasin / dépôt</legend>
                                        <div class="mt-2 grid max-h-40 gap-2 overflow-y-auto sm:grid-cols-2">
                                            @foreach ($storeAccessOptions as $store)
                                                <label class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-slate-50 dark:hover:bg-white/5"><input name="store_access[]" value="{{ $store }}" type="checkbox" checked class="size-4 accent-[var(--brand-primary)]"> {{ $store }}</label>
                                            @endforeach
                                        </div>
                                    </fieldset>
                                    <fieldset class="rounded-xl border border-slate-200 p-4 dark:border-white/10">
                                        <legend class="px-1 text-xs font-semibold uppercase text-slate-500">Permissions directes</legend>
                                        <p class="mt-1 text-xs text-slate-500">Laissez vide si le rôle couvre déjà l'accès.</p>
                                        <div class="mt-3 grid max-h-64 gap-2 overflow-y-auto sm:grid-cols-2">
                                            @foreach ($permissionCatalog as $key => $label)
                                                <label class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-slate-50 dark:hover:bg-white/5"><input name="permissions[]" value="{{ $key }}" type="checkbox" class="size-4 accent-[var(--brand-primary)]"> {{ $label }}</label>
                                            @endforeach
                                        </div>
                                    </fieldset>
                                </section>
                                </div>
                            </div>
                            <div class="shrink-0 bg-white flex justify-end gap-2 border-t border-slate-200 p-5 shadow-[0_-12px_30px_rgba(15,23,42,0.08)] dark:border-white/10 dark:bg-slate-950">
                                <button type="button" class="dialog-close rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold dark:border-white/10">Annuler</button>
                                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Créer utilisateur</button>
                            </div>
                        </form>
                    </dialog>
                </article>

                @elseif ($settingsSection === 'roles')
                <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <div class="flex items-center justify-between gap-3"><div><h2 class="font-semibold">Rôles & permissions</h2><p class="mt-1 text-sm text-slate-500">Créez des profils métier réutilisables pour la caisse, le stock et la gestion.</p></div><x-status-pill tone="info">{{ $settingsRoles->count() }}</x-status-pill></div>
                    <form action="{{ route('settings.roles.store') }}" method="POST" class="mt-5 rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-white/10 dark:bg-white/5">@csrf<div class="grid gap-3 lg:grid-cols-[1fr_1fr_auto]"><input name="name" required class="h-11 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Nom du rôle"><input name="key" required class="h-11 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="clé: manager_stock"><button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Créer rôle</button></div><div class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">@foreach ($permissionCatalog as $key => $label)<label class="flex items-center gap-2 text-sm"><input name="permissions[]" value="{{ $key }}" type="checkbox" class="size-4 accent-[var(--brand-primary)]"> {{ $label }}</label>@endforeach</div></form>
                    <div class="mt-5 grid gap-3">
                        @foreach ($settingsRoles as $role)
                            <details id="role-detail-{{ $role->key }}" class="rounded-xl border border-slate-200 bg-white p-4 dark:border-white/10 dark:bg-slate-950/40"><summary class="flex cursor-pointer list-none items-center justify-between"><span><strong>{{ $role->name }}</strong><small class="mt-1 block text-slate-500">{{ $role->key }} · {{ count($role->permissions ?? []) }} permission(s)</small></span><span class="text-xs font-semibold text-brand">Modifier</span></summary><form action="{{ route('settings.roles.update', $role) }}" method="POST" class="mt-4 border-t border-slate-200 pt-4 dark:border-white/10">@csrf @method('PUT')<div class="grid gap-3 lg:grid-cols-2"><input name="name" required value="{{ $role->name }}" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"><input name="key" required value="{{ $role->key }}" class="h-10 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></div><div class="mt-3 grid max-h-48 gap-2 overflow-y-auto sm:grid-cols-2 lg:grid-cols-3">@foreach ($permissionCatalog as $key => $label)<label class="flex items-center gap-2 text-sm"><input name="permissions[]" value="{{ $key }}" type="checkbox" @checked(in_array($key, $role->permissions ?? [], true)) class="size-4 accent-[var(--brand-primary)]"> {{ $label }}</label>@endforeach</div><div class="mt-3 flex justify-end gap-2"><button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Enregistrer rôle</button></div></form><form action="{{ route('settings.roles.destroy', $role) }}" method="POST" class="mt-2 flex justify-end" onsubmit="return confirm('Supprimer ce rôle ?')">@csrf @method('DELETE')<button class="rounded-lg border border-rose-200 px-3 py-2 text-xs font-semibold text-rose-600 dark:border-rose-500/30">Supprimer</button></form></details>
                        @endforeach
                    </div>
                </article>

                @elseif ($settingsSection === 'theme')
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
                            <input name="primary" type="color" value="{{ $theme['primary'] ?? '#3157D5' }}" class="mt-1 h-11 w-full rounded-lg border border-slate-200 p-1 dark:border-white/10 dark:bg-slate-900">
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase text-slate-500">Accent</span>
                            <input name="accent" type="color" value="{{ $theme['accent'] ?? '#0F9F8A' }}" class="mt-1 h-11 w-full rounded-lg border border-slate-200 p-1 dark:border-white/10 dark:bg-slate-900">
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase text-slate-500">Succès</span>
                            <input name="success" type="color" value="{{ $theme['success'] ?? '#16A34A' }}" class="mt-1 h-11 w-full rounded-lg border border-slate-200 p-1 dark:border-white/10 dark:bg-slate-900">
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase text-slate-500">Fond</span>
                            <input name="background" type="color" value="{{ $theme['background'] ?? '#F4F7FB' }}" class="mt-1 h-11 w-full rounded-lg border border-slate-200 p-1 dark:border-white/10 dark:bg-slate-900">
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase text-slate-500">Surface</span>
                            <input name="surface_color" type="color" value="{{ $theme['surface_color'] ?? '#FFFFFF' }}" class="mt-1 h-11 w-full rounded-lg border border-slate-200 p-1 dark:border-white/10 dark:bg-slate-900">
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase text-slate-500">Surface légère</span>
                            <input name="surface_muted" type="color" value="{{ $theme['surface_muted'] ?? '#EEF3F8' }}" class="mt-1 h-11 w-full rounded-lg border border-slate-200 p-1 dark:border-white/10 dark:bg-slate-900">
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase text-slate-500">Texte</span>
                            <input name="text" type="color" value="{{ $theme['text'] ?? '#101828' }}" class="mt-1 h-11 w-full rounded-lg border border-slate-200 p-1 dark:border-white/10 dark:bg-slate-900">
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase text-slate-500">Texte secondaire</span>
                            <input name="muted" type="color" value="{{ $theme['muted'] ?? '#64748B' }}" class="mt-1 h-11 w-full rounded-lg border border-slate-200 p-1 dark:border-white/10 dark:bg-slate-900">
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase text-slate-500">Bordure</span>
                            <input name="border" type="color" value="{{ $theme['border'] ?? '#D7DEE9' }}" class="mt-1 h-11 w-full rounded-lg border border-slate-200 p-1 dark:border-white/10 dark:bg-slate-900">
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
                                @foreach (['#3157D5', '#0F9F8A', '#16A34A', '#D97706', '#101828'] as $color)
                                    <button class="theme-swatch size-9 rounded-lg border border-slate-200 dark:border-white/10" style="background: {{ $color }}" data-color="{{ $color }}" type="button" aria-label="Couleur {{ $color }}"></button>
                                @endforeach
                            </div>
                            <button class="rounded-lg bg-brand px-5 py-2.5 text-sm font-semibold text-white">Enregistrer le thème</button>
                        </div>
                    </form>
                </article>

                @elseif ($settingsSection === 'hardware')
                <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-sm font-semibold text-brand">{{ $tr('Périphériques POS') }}</p>
                            <h2 class="mt-1 text-xl font-semibold">{{ $tr('Matériel connecté') }}</h2>
                            <p class="mt-1 text-sm text-slate-500">{{ $tr('Testez et configurez votre imprimante thermique, tiroir-caisse et lecteur de code-barres.') }}</p>
                        </div>
                    </div>

                    <div class="mt-6 grid gap-5 xl:grid-cols-[1fr_320px]">
                        <div class="space-y-5">
                            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-white/10 dark:bg-white/5">
                                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                                    <div class="flex items-start gap-3">
                                        <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-slate-900 text-lg text-white dark:bg-white dark:text-slate-950">🖥</span>
                                        <div>
                                            <div class="flex flex-wrap items-center gap-2">
                                                <h3 class="font-semibold">{{ $tr('Appareils virtuels') }}</h3>
                                                <x-status-pill :tone="$virtualDevicesEnabled ? 'success' : 'neutral'">{{ $virtualDevicesEnabled ? $tr('Activé') : $tr('Désactivé') }}</x-status-pill>
                                            </div>
                                            <p class="mt-1 max-w-2xl text-sm text-slate-600 dark:text-slate-300">
                                                {{ $tr('Associez chaque session à une caisse, tablette ou poste précis. Utile pour tracer les actions par terminal, mais optionnel pour les petites équipes.') }}
                                            </p>
                                        </div>
                                    </div>
                                    <div class="flex flex-wrap gap-2">
                                        @if ($virtualDevicesEnabled)
                                            <a href="{{ route('devices.index') }}" class="inline-flex items-center justify-center rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-brand hover:text-brand dark:border-white/10 dark:bg-slate-950 dark:text-slate-200">{{ $tr('Gérer') }}</a>
                                        @endif
                                        <form action="{{ route('settings.virtual-devices.update') }}" method="POST">
                                            @csrf
                                            <input type="hidden" name="virtual_devices_enabled" value="{{ $virtualDevicesEnabled ? '0' : '1' }}">
                                            <button class="inline-flex items-center justify-center rounded-lg {{ $virtualDevicesEnabled ? 'border border-amber-200 bg-amber-50 text-amber-700 hover:bg-amber-100 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300' : 'bg-brand text-white shadow-sm shadow-indigo-500/20 hover:brightness-110' }} px-4 py-2.5 text-sm font-semibold transition" type="submit">
                                                {{ $virtualDevicesEnabled ? $tr('Désactiver le module') : $tr('Activer le module') }}
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>

                            {{-- Printer --}}
                            <div class="rounded-xl border border-slate-200 p-4 dark:border-white/10">
                                <div class="flex items-center gap-3">
                                    <span class="grid size-10 place-items-center rounded-xl bg-brand/10 text-lg text-brand">🖨️</span>
                                    <div class="min-w-0 flex-1">
                                        <h3 class="font-semibold">{{ $tr('Imprimante thermique') }}</h3>
                                        <p class="text-sm text-slate-500">{{ $tr('USB-Serial via Web Serial API (Chrome/Edge Windows)') }}</p>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <span class="pos-hw-printer-dot inline-block size-2.5 rounded-full bg-slate-300 dark:bg-slate-600"></span>
                                        <span class="pos-hw-printer-status inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-500 dark:bg-white/10">{{ $tr('Déconnecté') }}</span>
                                    </div>
                                </div>
                                <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                    <label class="block space-y-1"><span class="text-xs font-semibold uppercase text-slate-500">{{ $tr('Port') }}</span>
                                        <select class="pos-hw-printer-port h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">
                                            <option value="auto">{{ $tr('Auto-détection') }}</option>
                                        </select>
                                    </label>
                                    <label class="block space-y-1"><span class="text-xs font-semibold uppercase text-slate-500">{{ $tr('Baud rate') }}</span>
                                        <select class="pos-hw-printer-baud h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">
                                            <option value="9600" selected>9600</option>
                                            <option value="115200">115200</option>
                                            <option value="19200">19200</option>
                                        </select>
                                    </label>
                                    <label class="block space-y-1"><span class="text-xs font-semibold uppercase text-slate-500">{{ $tr('Largeur ticket') }}</span>
                                        <select class="pos-hw-printer-width h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">
                                            <option value="58">58 mm</option>
                                            <option value="80" selected>80 mm</option>
                                        </select>
                                    </label>
                                    <label class="block space-y-1"><span class="text-xs font-semibold uppercase text-slate-500">{{ $tr('Commandes') }}</span>
                                        <select class="pos-hw-printer-cmd h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">
                                            <option value="escpos" selected>ESC/POS</option>
                                            <option value="star">Star</option>
                                        </select>
                                    </label>
                                </div>
                                <div class="mt-4 flex flex-wrap gap-2">
                                    <button class="pos-hw-connect-printer inline-flex items-center gap-2 rounded-lg bg-brand px-4 py-2.5 text-sm font-semibold text-white" type="button">
                                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                                        {{ $tr('Connecter') }}
                                    </button>
                                    <button class="pos-hw-test-print inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold dark:border-white/10 dark:bg-white/5" type="button">
                                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v8H6z"/></svg>
                                        {{ $tr('Test imprimante') }}
                                    </button>
                                    <button class="pos-hw-disconnect-printer hidden inline-flex items-center gap-2 rounded-lg border border-rose-200 bg-rose-50 px-4 py-2.5 text-sm font-semibold text-rose-600 dark:border-rose-500/30 dark:bg-rose-500/10" type="button">
                                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                                        {{ $tr('Déconnecter') }}
                                    </button>
                                </div>
                                <p class="pos-hw-print-feedback mt-3 hidden text-sm font-medium text-emerald-600 dark:text-emerald-400"></p>
                            </div>

                            {{-- Drawer --}}
                            <div class="rounded-xl border border-slate-200 p-4 dark:border-white/10">
                                <div class="flex items-center gap-3">
                                    <span class="grid size-10 place-items-center rounded-xl bg-emerald-500/10 text-lg text-emerald-600">🗄️</span>
                                    <div class="min-w-0 flex-1">
                                        <h3 class="font-semibold">{{ $tr('Tiroir-caisse') }}</h3>
                                        <p class="text-sm text-slate-500">{{ $tr('Connecté via l\'imprimante (RJ11/RJ12)') }}</p>
                                    </div>
                                </div>
                                <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                    <label class="block space-y-1"><span class="text-xs font-semibold uppercase text-slate-500">{{ $tr('Pin') }}</span>
                                        <select class="pos-hw-drawer-pin h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">
                                            <option value="0" selected>Pin 2</option>
                                            <option value="1">Pin 5</option>
                                        </select>
                                    </label>
                                    <label class="block space-y-1"><span class="text-xs font-semibold uppercase text-slate-500">{{ $tr('Impulsion (ms)') }}</span>
                                        <input type="number" value="25" min="10" max="100" class="pos-hw-drawer-pulse h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">
                                    </label>
                                </div>
                                <div class="mt-4 flex flex-wrap gap-2">
                                    <button class="pos-hw-test-drawer inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold dark:border-white/10 dark:bg-white/5" type="button">
                                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                        {{ $tr('Test tiroir') }}
                                    </button>
                                </div>
                                <p class="pos-hw-drawer-feedback mt-3 hidden text-sm font-medium text-emerald-600 dark:text-emerald-400"></p>
                            </div>

                            {{-- Barcode Scanner --}}
                            <div class="rounded-xl border border-slate-200 p-4 dark:border-white/10">
                                <div class="flex items-center gap-3">
                                    <span class="grid size-10 place-items-center rounded-xl bg-amber-500/10 text-lg text-amber-600">📷</span>
                                    <div class="min-w-0 flex-1">
                                        <h3 class="font-semibold">{{ $tr('Lecteur code-barres') }}</h3>
                                        <p class="text-sm text-slate-500">{{ $tr('Mode clavier USB HID. Aucune configuration requise.') }}</p>
                                    </div>
                                </div>
                                <div class="mt-4 rounded-lg bg-slate-50 p-4 dark:bg-white/5">
                                    <p class="text-sm text-slate-600 dark:text-slate-300">{{ $tr('Branchez le scanner USB. Il fonctionne automatiquement en mode clavier. Scannez un code ci-dessous pour tester :') }}</p>
                                    <div class="mt-3 flex gap-2">
                                        <input type="text" class="pos-hw-barcode-test h-11 flex-1 rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold tracking-wide dark:border-white/10 dark:bg-slate-900" placeholder="{{ $tr('Scannez ici...') }}">
                                        <span class="pos-hw-barcode-result hidden inline-flex items-center gap-1 rounded-lg bg-emerald-50 px-3 text-xs font-bold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">
                                            <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                                            {{ $tr('Code reçu !') }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Sidebar help --}}
                        <aside class="space-y-4">
                            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-white/10 dark:bg-white/5">
                                <h3 class="flex items-center gap-2 text-sm font-semibold">
                                    <svg class="size-4 text-brand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                                    {{ $tr('Aide connexion') }}
                                </h3>
                                <ul class="mt-3 list-inside list-disc space-y-2 text-sm text-slate-600 dark:text-slate-300">
                                    <li>{{ $tr('Utilisez Chrome ou Edge sur Windows 10/11.') }}</li>
                                    <li>{{ $tr('L\'imprimante doit apparaître comme un port COM dans le Gestionnaire de périphériques.') }}</li>
                                    <li>{{ $tr('Si le port n\'apparaît pas, installez le driver du fabricant (FTDI/Prolific/CH340).') }}</li>
                                    <li>{{ $tr('Le tiroir s\'ouvre via l\'imprimante : branchez-le sur le port RJ11 de l\'imprimante.') }}</li>
                                </ul>
                            </div>
                            <div class="rounded-xl border border-slate-200 p-4 dark:border-white/10">
                                <h3 class="text-sm font-semibold">{{ $tr('Compatibilité') }}</h3>
                                <div class="mt-3 space-y-2 text-sm">
                                    <div class="flex items-center gap-2 text-slate-600 dark:text-slate-300">
                                        <svg class="size-4 text-emerald-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                                        <span>ESC/POS (Epson, Bixolon, Xprinter)</span>
                                    </div>
                                    <div class="flex items-center gap-2 text-slate-600 dark:text-slate-300">
                                        <svg class="size-4 text-emerald-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                                        <span>USB HID Scanner (Honeywell, Zebra, Datalogic)</span>
                                    </div>
                                    <div class="flex items-center gap-2 text-slate-600 dark:text-slate-300">
                                        <svg class="size-4 text-emerald-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                                        <span>Tiroir RJ11/RJ12 standard</span>
                                    </div>
                                </div>
                            </div>
                        </aside>
                    </div>
                </article>

                @endif
            </div>

            @if ($settingsSection === 'company')
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
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h2 class="font-semibold">Audit log</h2>
                            <p class="mt-1 text-sm text-slate-500">Dernières actions enregistrées automatiquement.</p>
                        </div>
                        <x-status-pill tone="info">{{ $auditLogs->count() }}</x-status-pill>
                    </div>
                    <div class="mt-4 max-h-[520px] space-y-2 overflow-y-auto pr-1">
                        @forelse ($auditLogs as $log)
                            <div class="rounded-lg bg-slate-50 px-3 py-2 text-sm dark:bg-white/5">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="truncate font-semibold">{{ $log->action }}</p>
                                        <p class="mt-1 text-xs text-slate-500">
                                            {{ $log->user?->name ?? 'Système / invité' }} · {{ data_get($log->properties, 'method') }} · {{ data_get($log->properties, 'path') }}
                                        </p>
                                    </div>
                                    <span class="shrink-0 text-xs font-semibold text-slate-500">{{ $log->created_at->format('d/m H:i') }}</span>
                                </div>
                            </div>
                        @empty
                            <div class="rounded-lg border border-dashed border-slate-200 p-6 text-center text-sm text-slate-500 dark:border-white/10">Aucune action enregistrée.</div>
                        @endforelse
                    </div>
                </article>
            </aside>
            @endif
            @endif
        </section>
    @endif
    @php
        $autoOpenDialogId = null;
        if ($module === 'sales' && request('detail_return')) {
            $autoOpenDialogId = 'return-detail-'.request('detail_return');
        } elseif ($module === 'purchases' && request('detail_purchase')) {
            $autoOpenDialogId = 'purchase-detail-'.request('detail_purchase');
        } elseif ($module === 'purchases' && request('detail_purchase_return')) {
            $autoOpenDialogId = 'purchase-return-detail-'.request('detail_purchase_return');
        }
    @endphp
    @if ($autoOpenDialogId)
        <script>
            window.addEventListener('DOMContentLoaded', () => document.getElementById(@json($autoOpenDialogId))?.showModal());
        </script>
    @endif
    @if (($settingsSection ?? null) === 'roles' && request('edit_role'))
        <script>
            window.addEventListener('DOMContentLoaded', () => {
                const roleDetail = document.getElementById('role-detail-' + @json(request('edit_role')));
                if (roleDetail) {
                    roleDetail.open = true;
                    roleDetail.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            });
        </script>
    @endif
</x-layouts.app>
