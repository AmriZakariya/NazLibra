@php
    $paymentMethodLabels = [
        'cash' => 'Espèces',
        'card' => 'Carte',
        'transfer' => 'Virement',
        'advance' => 'Avance client',
        'cheque' => 'Chèque',
        'other' => 'Autre',
    ];
    $scopeLabels = ['cart' => 'Panier', 'item' => 'Article'];
    $typeLabels = ['percentage' => 'Pourcentage', 'fixed' => 'Montant fixe'];
    $selectedIncluded = collect(old('included_item_ids', []))->map(fn ($id) => (int) $id)->all();
    $selectedExcluded = collect(old('excluded_item_ids', []))->map(fn ($id) => (int) $id)->all();
    $selectedPayments = old('payment_methods', []);
@endphp

@if ($financeSection === 'discount-add')
    <section class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
        <form action="{{ route('discounts.store') }}" method="POST" class="space-y-5 rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
            @csrf
            <div class="flex flex-col gap-3 border-b border-slate-200 pb-4 dark:border-white/10 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="text-sm font-semibold text-brand">Remises POS</p>
                    <h2 class="mt-1 text-xl font-semibold">Créer une remise</h2>
                    <p class="mt-1 text-sm text-slate-500">Règle réutilisable en caisse: panier complet, articles ciblés, exclusions et moyens de paiement autorisés.</p>
                </div>
                <button class="rounded-lg bg-brand px-5 py-2.5 text-sm font-semibold text-white">Enregistrer</button>
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Nom *</span><input name="name" value="{{ old('name') }}" required class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm font-semibold dark:border-white/10 dark:bg-slate-900" placeholder="Remise rentrée scolaire"></label>
                <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Code interne</span><input name="code" value="{{ old('code') }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm font-bold uppercase tracking-wide dark:border-white/10 dark:bg-slate-900" placeholder="REMISE10"></label>
                <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Type *</span><select name="type" class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="percentage" @selected(old('type', 'percentage') === 'percentage')>Pourcentage</option><option value="fixed" @selected(old('type') === 'fixed')>Montant fixe DH</option></select></label>
                <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Valeur *</span><input name="value" value="{{ old('value') }}" required min="0.01" step="0.01" type="number" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="10"></label>
                <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Portée *</span><select name="scope" class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="cart" @selected(old('scope', 'cart') === 'cart')>Panier</option><option value="item" @selected(old('scope') === 'item')>Articles ciblés</option></select></label>
                <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Minimum panier / sélection</span><input name="minimum_amount" value="{{ old('minimum_amount', 0) }}" min="0" step="0.01" type="number" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="0"></label>
                <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Début</span><input name="starts_at" value="{{ old('starts_at') }}" type="date" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Fin</span><input name="ends_at" value="{{ old('ends_at') }}" type="date" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>

                <div class="space-y-2 lg:col-span-2">
                    <span class="text-xs font-semibold uppercase text-slate-500">Moyens de paiement autorisés</span>
                    <div class="grid gap-2 sm:grid-cols-3">
                        @foreach ($paymentMethodLabels as $method => $label)
                            <label class="flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-semibold dark:border-white/10 dark:bg-white/5"><input name="payment_methods[]" value="{{ $method }}" type="checkbox" @checked(in_array($method, $selectedPayments, true)) class="size-4 accent-[var(--brand-primary)]"> {{ $label }}</label>
                        @endforeach
                    </div>
                    <p class="text-xs text-slate-500">Laissez vide pour autoriser tous les moyens de paiement.</p>
                </div>

                <label class="space-y-1.5 lg:col-span-2"><span class="text-xs font-semibold uppercase text-slate-500">Articles inclus</span><select name="included_item_ids[]" multiple size="7" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-slate-900">@foreach ($discountItems as $item)<option value="{{ $item->id }}" @selected(in_array($item->id, $selectedIncluded, true))>{{ $item->title }} · {{ $item->item_code ?? $item->barcode ?? $item->isbn ?? 'sans code' }} · {{ $money($item->sale_price) }}</option>@endforeach</select><span class="block text-xs text-slate-500">Vide = tous les articles du panier sont éligibles.</span></label>
                <label class="space-y-1.5 lg:col-span-2"><span class="text-xs font-semibold uppercase text-slate-500">Articles exclus</span><select name="excluded_item_ids[]" multiple size="7" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-slate-900">@foreach ($discountItems as $item)<option value="{{ $item->id }}" @selected(in_array($item->id, $selectedExcluded, true))>{{ $item->title }} · {{ $item->item_code ?? $item->barcode ?? $item->isbn ?? 'sans code' }}</option>@endforeach</select><span class="block text-xs text-slate-500">Utilisé surtout pour une remise panier avec exceptions.</span></label>
                <label class="space-y-1.5 lg:col-span-2"><span class="text-xs font-semibold uppercase text-slate-500">Notes internes</span><textarea name="notes" class="min-h-24 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Conditions de campagne, validation manager, cible...">{{ old('notes') }}</textarea></label>
                <label class="flex items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-3 text-sm font-semibold dark:border-white/10 dark:bg-white/5"><input name="is_active" value="1" type="checkbox" checked class="size-4 accent-[var(--brand-primary)]"> Remise active en caisse</label>
            </div>
        </form>

        <aside class="space-y-4">
            <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                <h3 class="font-semibold">Comportement caisse</h3>
                <p class="mt-2 text-sm text-slate-500">Le caissier peut choisir une remise enregistrée ou garder une remise manuelle. Si une règle est sélectionnée, elle remplace la remise manuelle.</p>
            </article>
            <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                <h3 class="font-semibold">Sécurité</h3>
                <p class="mt-2 text-sm text-slate-500">La remise est recalculée côté serveur avec les articles, exclusions et paiements réels avant encaissement.</p>
            </article>
        </aside>
    </section>
@else
    <section class="mt-6 space-y-5">
        <div class="grid gap-3 md:grid-cols-4">
            <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><span class="text-xs font-semibold uppercase text-slate-500">Actives</span><p class="mt-2 text-2xl font-semibold text-emerald-600">{{ number_format($discountStats['active'], 0, ',', ' ') }}</p></article>
            <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><span class="text-xs font-semibold uppercase text-slate-500">Panier</span><p class="mt-2 text-2xl font-semibold">{{ number_format($discountStats['cart'], 0, ',', ' ') }}</p></article>
            <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><span class="text-xs font-semibold uppercase text-slate-500">Article</span><p class="mt-2 text-2xl font-semibold">{{ number_format($discountStats['item'], 0, ',', ' ') }}</p></article>
            <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]"><span class="text-xs font-semibold uppercase text-slate-500">Paiement limité</span><p class="mt-2 text-2xl font-semibold">{{ number_format($discountStats['payment_limited'], 0, ',', ' ') }}</p></article>
        </div>

        <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div><h2 class="font-semibold">Liste des remises</h2><p class="mt-1 text-sm text-slate-500">Règles disponibles en caisse, avec filtres par portée et statut.</p></div>
                <a href="{{ route('module', ['module' => 'finance', 'section' => 'discount-add']) }}" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Créer une remise</a>
            </div>
            <form class="mt-4 grid gap-3 lg:grid-cols-[1fr_170px_170px_auto]" method="GET" action="{{ route('module', ['module' => 'finance', 'section' => 'discounts']) }}">
                <input type="hidden" name="section" value="discounts">
                <input name="q" value="{{ request('q') }}" class="h-11 rounded-lg border border-slate-200 bg-slate-50 px-3 text-sm dark:border-white/10 dark:bg-white/5" placeholder="Rechercher nom, code, note...">
                <select name="discount_status" class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="all">Tous statuts</option><option value="active" @selected(request('discount_status') === 'active')>Actives</option><option value="expired" @selected(request('discount_status') === 'expired')>Expirées</option><option value="inactive" @selected(request('discount_status') === 'inactive')>Inactives</option></select>
                <select name="discount_scope" class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="all">Toutes portées</option><option value="cart" @selected(request('discount_scope') === 'cart')>Panier</option><option value="item" @selected(request('discount_scope') === 'item')>Article</option></select>
                <div class="flex gap-2"><button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Filtrer</button><a href="{{ route('module', ['module' => 'finance', 'section' => 'discounts']) }}" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold dark:border-white/10">Reset</a></div>
            </form>
        </article>

        <article class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[1120px] text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-white/5"><tr><th class="px-3 py-3">Remise</th><th class="px-3 py-3">Portée</th><th class="px-3 py-3 text-right">Valeur</th><th class="px-3 py-3 text-right">Minimum</th><th class="px-3 py-3">Paiement</th><th class="px-3 py-3">Articles</th><th class="px-3 py-3">Période</th><th class="px-3 py-3">Statut</th><th class="px-3 py-3 text-right">Action</th></tr></thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-white/10">
                        @forelse ($discountRules as $rule)
                            @php
                                $expired = $rule->ends_at && $rule->ends_at->isPast();
                                $includedIds = collect($rule->included_item_ids ?? [])->map(fn ($id) => (int) $id)->all();
                                $excludedIds = collect($rule->excluded_item_ids ?? [])->map(fn ($id) => (int) $id)->all();
                                $paymentMethods = collect($rule->payment_methods ?? [])->all();
                            @endphp
                            <tr>
                                <td class="px-3 py-3"><p class="font-black">{{ $rule->name }}</p><p class="text-xs text-slate-500">{{ $rule->code ?: 'Sans code' }}</p></td>
                                <td class="px-3 py-3">{{ $scopeLabels[$rule->scope] ?? $rule->scope }}</td>
                                <td class="px-3 py-3 text-right font-semibold">{{ $rule->type === 'percentage' ? number_format((float) $rule->value, 2, ',', ' ').'%' : $money($rule->value) }}</td>
                                <td class="px-3 py-3 text-right">{{ $money($rule->minimum_amount) }}</td>
                                <td class="px-3 py-3">{{ empty($paymentMethods) ? 'Tous' : collect($paymentMethods)->map(fn ($method) => $paymentMethodLabels[$method] ?? $method)->join(', ') }}</td>
                                <td class="px-3 py-3"><span class="font-semibold">{{ count($includedIds) ?: 'Tous' }}</span> inclus<p class="text-xs text-slate-500">{{ count($excludedIds) }} exclu(s)</p></td>
                                <td class="px-3 py-3">{{ $rule->starts_at?->format('d/m/Y') ?? 'Maintenant' }} → {{ $rule->ends_at?->format('d/m/Y') ?? 'Illimité' }}</td>
                                <td class="px-3 py-3"><x-status-pill :tone="! $rule->is_active ? 'danger' : ($expired ? 'warning' : 'success')">{{ ! $rule->is_active ? 'Inactive' : ($expired ? 'Expirée' : 'Active') }}</x-status-pill></td>
                                <td class="px-3 py-3 text-right"><button type="button" onclick="document.getElementById('discount-edit-{{ $rule->id }}').showModal()" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold transition hover:border-brand hover:text-brand dark:border-white/10">Modifier</button></td>
                            </tr>
                            <dialog id="discount-edit-{{ $rule->id }}" class="app-dialog w-[min(820px,calc(100vw-2rem))] rounded-2xl border border-slate-200 bg-white p-0 text-slate-950 shadow-2xl backdrop:bg-slate-950/45 dark:border-white/10 dark:bg-slate-950 dark:text-slate-100">
                                <form action="{{ route('discounts.update', $rule) }}" method="POST">
                                    @csrf @method('PUT')
                                    <div class="flex items-start justify-between gap-4 border-b border-slate-200 p-5 dark:border-white/10"><div><p class="text-sm font-semibold text-brand">Remise POS</p><h3 class="mt-1 text-xl font-semibold">Modifier {{ $rule->name }}</h3><p class="mt-1 text-sm text-slate-500">Les changements s’appliquent aux prochains encaissements.</p></div><button class="dialog-close grid size-9 shrink-0 place-items-center rounded-lg border border-slate-200 text-lg font-semibold dark:border-white/10" type="button">×</button></div>
                                    <div class="grid max-h-[65vh] gap-4 overflow-y-auto p-5 lg:grid-cols-2">
                                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Nom *</span><input name="name" value="{{ $rule->name }}" required class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm font-semibold dark:border-white/10 dark:bg-slate-900"></label>
                                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Code</span><input name="code" value="{{ $rule->code }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm font-bold uppercase dark:border-white/10 dark:bg-slate-900"></label>
                                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Type</span><select name="type" class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="percentage" @selected($rule->type === 'percentage')>Pourcentage</option><option value="fixed" @selected($rule->type === 'fixed')>Montant fixe DH</option></select></label>
                                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Valeur</span><input name="value" value="{{ $rule->value }}" required min="0.01" step="0.01" type="number" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Portée</span><select name="scope" class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900"><option value="cart" @selected($rule->scope === 'cart')>Panier</option><option value="item" @selected($rule->scope === 'item')>Articles ciblés</option></select></label>
                                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Minimum</span><input name="minimum_amount" value="{{ $rule->minimum_amount }}" min="0" step="0.01" type="number" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Début</span><input name="starts_at" value="{{ $rule->starts_at?->toDateString() }}" type="date" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                                        <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Fin</span><input name="ends_at" value="{{ $rule->ends_at?->toDateString() }}" type="date" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                                        <div class="space-y-2 lg:col-span-2"><span class="text-xs font-semibold uppercase text-slate-500">Paiements autorisés</span><div class="grid gap-2 sm:grid-cols-3">@foreach ($paymentMethodLabels as $method => $label)<label class="flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-semibold dark:border-white/10 dark:bg-white/5"><input name="payment_methods[]" value="{{ $method }}" type="checkbox" @checked(in_array($method, $paymentMethods, true)) class="size-4 accent-[var(--brand-primary)]"> {{ $label }}</label>@endforeach</div></div>
                                        <label class="space-y-1.5 lg:col-span-2"><span class="text-xs font-semibold uppercase text-slate-500">Articles inclus</span><select name="included_item_ids[]" multiple size="6" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-slate-900">@foreach ($discountItems as $item)<option value="{{ $item->id }}" @selected(in_array($item->id, $includedIds, true))>{{ $item->title }} · {{ $item->item_code ?? $item->barcode ?? $item->isbn ?? 'sans code' }}</option>@endforeach</select></label>
                                        <label class="space-y-1.5 lg:col-span-2"><span class="text-xs font-semibold uppercase text-slate-500">Articles exclus</span><select name="excluded_item_ids[]" multiple size="6" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-slate-900">@foreach ($discountItems as $item)<option value="{{ $item->id }}" @selected(in_array($item->id, $excludedIds, true))>{{ $item->title }} · {{ $item->item_code ?? $item->barcode ?? $item->isbn ?? 'sans code' }}</option>@endforeach</select></label>
                                        <label class="flex items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-3 text-sm font-semibold dark:border-white/10 dark:bg-white/5"><input name="is_active" value="1" type="checkbox" @checked($rule->is_active) class="size-4 accent-[var(--brand-primary)]"> Remise active</label>
                                        <label class="space-y-1.5 lg:col-span-2"><span class="text-xs font-semibold uppercase text-slate-500">Notes</span><textarea name="notes" class="min-h-20 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-white/10 dark:bg-slate-900">{{ $rule->notes }}</textarea></label>
                                    </div>
                                    <div class="flex flex-col-reverse gap-2 border-t border-slate-200 bg-slate-50 p-5 dark:border-white/10 dark:bg-white/5 sm:flex-row sm:items-center sm:justify-between"><button form="discount-delete-{{ $rule->id }}" class="rounded-lg border border-rose-200 px-4 py-2 text-sm font-semibold text-rose-600 dark:border-rose-500/30" type="submit">Supprimer</button><div class="flex justify-end gap-2"><button type="button" class="dialog-close rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold dark:border-white/10 dark:bg-slate-950">Annuler</button><button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Enregistrer</button></div></div>
                                </form>
                                <form id="discount-delete-{{ $rule->id }}" action="{{ route('discounts.destroy', $rule) }}" method="POST" onsubmit="return confirm('Supprimer cette remise ?')">@csrf @method('DELETE')</form>
                            </dialog>
                        @empty
                            <tr><td colspan="9" class="px-4 py-12 text-center text-sm text-slate-500">Aucune remise trouvée.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-200 px-4 py-3 dark:border-white/10">{{ $discountRules->links() }}</div>
        </article>
    </section>
@endif
