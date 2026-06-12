@php
    $openSession = $cashRegister['openSession'];
    $movementLabels = [
        'opening' => 'Ouverture',
        'sale_cash' => 'Vente cash',
        'cash_in' => 'Entrée',
        'cash_out' => 'Sortie',
        'correction' => 'Correction',
        'closing' => 'Clôture',
    ];
    $movementTones = [
        'opening' => 'info',
        'sale_cash' => 'success',
        'cash_in' => 'success',
        'cash_out' => 'danger',
        'correction' => 'warning',
        'closing' => 'primary',
    ];
@endphp

<section class="mt-6 space-y-6">
    <div class="cash-register-shell">
        <div class="cash-register-header">
            <div class="flex min-w-0 items-start gap-4">
                <span class="cash-register-hero-icon">TC</span>
                <div class="min-w-0">
                    <p class="text-xs font-black uppercase tracking-wide text-brand">Caisse · tiroir</p>
                    <h2 class="mt-1 text-2xl font-semibold text-slate-950 dark:text-white">Suivi du tiroir espèces</h2>
                    <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600 dark:text-slate-300">Pilotez l’ouverture, les entrées/sorties, les encaissements POS et la clôture de caisse.</p>
                    <div class="cash-register-header-meta">
                        <span class="cash-register-badge {{ $openSession ? 'is-open' : 'is-closed' }}">{{ $openSession ? 'Ouvert' : 'Fermé' }}</span>
                        <span class="cash-register-badge">{{ $currentStore['name'] ?? 'Magasin' }}</span>
                        <span class="cash-register-badge">{{ $cashRegister['todayCount'] }} mouvement(s) aujourd’hui</span>
                    </div>
                </div>
            </div>
            <div class="cash-register-actions">
                <a href="{{ route('pos') }}" class="cash-register-button is-secondary"><span>PV</span> Caisse POS</a>
                @if ($openSession)
                    <a href="#cash-register-manual-movement" class="cash-register-button is-primary"><span>+</span> Mouvement</a>
                    <a href="#cash-register-close" class="cash-register-button is-danger"><span>CL</span> Clôturer</a>
                @else
                    <a href="#cash-register-open" class="cash-register-button is-primary"><span>+</span> Ouvrir tiroir</a>
                @endif
            </div>
        </div>

        <div class="grid gap-4 p-5 md:grid-cols-2 xl:grid-cols-4">
            <article class="cash-register-metric is-primary">
                <span>Solde attendu tiroir</span>
                <strong>{{ $money($openSession?->expected_cash_amount ?? 0) }}</strong>
                <p>{{ $openSession ? 'Session '.$openSession->number.' ouverte depuis '.$openSession->opened_at?->format('H:i') : 'Aucun tiroir ouvert actuellement.' }}</p>
            </article>
            <article class="cash-register-metric">
                <span><em class="bg-emerald-500"></em>Entrées espèces</span>
                <strong>{{ $money($cashRegister['todayIn']) }}</strong>
                <p>Ventes cash incluses: <b class="text-emerald-700 dark:text-emerald-300">{{ $money($cashRegister['todaySalesCash']) }}</b></p>
            </article>
            <article class="cash-register-metric">
                <span><em class="bg-rose-500"></em>Sorties espèces</span>
                <strong>{{ $money($cashRegister['todayOut']) }}</strong>
                <p>Sorties manuelles et corrections négatives.</p>
            </article>
            <article class="cash-register-metric">
                <span><em class="bg-slate-400"></em>Dernière clôture</span>
                <strong class="{{ ($cashRegister['lastClosed']?->difference_amount ?? 0) == 0 ? '' : 'text-amber-700 dark:text-amber-300' }}">{{ $money($cashRegister['lastClosed']?->difference_amount ?? 0) }}</strong>
                <p>{{ $cashRegister['lastClosed'] ? $cashRegister['lastClosed']->number.' · '.$cashRegister['lastClosed']->closed_at?->format('d/m/Y H:i') : 'Pas encore de clôture.' }}</p>
            </article>
        </div>
    </div>

    <div class="grid gap-6 xl:grid-cols-[420px_minmax(0,1fr)]">
        <aside class="space-y-5">
            @if ($openSession)
                <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-sm font-semibold text-brand">Session ouverte</p>
                            <h3 class="mt-1 text-xl font-semibold text-slate-950 dark:text-white">{{ $openSession->number }}</h3>
                            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $openSession->openedBy?->name ?? 'Utilisateur' }} · {{ $openSession->opened_at?->format('d/m/Y H:i') }}</p>
                        </div>
                        <x-status-pill tone="success">Ouvert</x-status-pill>
                    </div>
                    <dl class="mt-5 grid gap-3 text-sm">
                        <div class="flex items-center justify-between rounded-xl bg-slate-50 px-4 py-3 dark:bg-slate-900/70"><dt class="text-slate-500 dark:text-slate-400">Fond initial</dt><dd class="font-semibold">{{ $money($openSession->opening_amount) }}</dd></div>
                        <div class="flex items-center justify-between rounded-xl bg-slate-50 px-4 py-3 dark:bg-slate-900/70"><dt class="text-slate-500 dark:text-slate-400">Mouvements</dt><dd class="font-semibold">{{ $openSession->movements()->count() }}</dd></div>
                        <div class="flex items-center justify-between rounded-xl bg-slate-50 px-4 py-3 dark:bg-slate-900/70"><dt class="text-slate-500 dark:text-slate-400">Compte lié</dt><dd class="font-semibold">{{ $openSession->account?->name ?? 'Non lié' }}</dd></div>
                    </dl>
                </article>

                <article id="cash-register-manual-movement" class="scroll-mt-24 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <h3 class="text-lg font-semibold text-slate-950 dark:text-white">Entrée / sortie manuelle</h3>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Exemples: retrait dépôt banque, correction monnaie, appoint manuel.</p>
                    <form action="{{ route('cash-register.movements.store') }}" method="POST" class="mt-4 space-y-3">
                        @csrf
                        <div class="grid grid-cols-3 gap-2">
                            <label class="cash-register-choice is-in"><input class="sr-only" type="radio" name="type" value="cash_in" checked><span>Entrée<small>Ajouter du cash</small></span></label>
                            <label class="cash-register-choice is-out"><input class="sr-only" type="radio" name="type" value="cash_out"><span>Sortie<small>Retrait ou dépense</small></span></label>
                            <label class="cash-register-choice"><input class="sr-only" type="radio" name="type" value="correction"><span>Correction<small>Écart contrôlé</small></span></label>
                        </div>
                        <input name="amount" required type="number" step="0.01" min="0.01" class="h-12 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Montant DH">
                        <input name="reference" class="h-12 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Référence optionnelle">
                        <textarea name="note" required class="min-h-24 w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Motif obligatoire"></textarea>
                        <button class="w-full rounded-xl bg-brand px-4 py-3 text-sm font-semibold text-white">Enregistrer mouvement</button>
                    </form>
                </article>

                <article id="cash-register-close" class="scroll-mt-24 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <h3 class="text-lg font-semibold text-slate-950 dark:text-white">Clôturer le tiroir</h3>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Comptez le cash physique. L’écart sera calculé automatiquement.</p>
                    <form action="{{ route('cash-register.close', $openSession) }}" method="POST" class="mt-4 space-y-3">
                        @csrf
                        <input name="counted_cash_amount" required type="number" step="0.01" min="0" class="h-12 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Cash compté">
                        <textarea name="closing_note" class="min-h-24 w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Note de clôture"></textarea>
                        <button class="w-full rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-black-200">Clôturer la session</button>
                    </form>
                </article>
            @else
                <article id="cash-register-open" class="scroll-mt-24 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <h3 class="text-xl font-semibold text-slate-950 dark:text-white">Ouvrir le tiroir</h3>
                    <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Démarrez la journée ou le shift avec le fond de caisse réel.</p>
                    <form action="{{ route('cash-register.open') }}" method="POST" class="mt-5 space-y-3">
                        @csrf
                        <input type="hidden" name="store_key" value="{{ $currentStore['key'] ?? '' }}">
                        <select name="financial_account_id" class="h-12 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm dark:border-white/10 dark:bg-slate-900">
                            <option value="">Compte caisse non lié</option>
                            @foreach ($cashRegister['cashAccounts'] as $account)
                                <option value="{{ $account->id }}">{{ $account->name }} · {{ $money($account->current_balance) }}</option>
                            @endforeach
                        </select>
                        <input name="opening_amount" required type="number" step="0.01" min="0" value="{{ old('opening_amount', '0') }}" class="h-12 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Fond de caisse initial">
                        <textarea name="note" class="min-h-24 w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Note d’ouverture"></textarea>
                        <button class="w-full rounded-xl bg-brand px-4 py-3 text-sm font-semibold text-white">Ouvrir le tiroir</button>
                    </form>
                </article>
            @endif
        </aside>

        <div class="space-y-5">
            <article class="rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                <div class="border-b border-slate-200 p-4 dark:border-white/10 sm:p-5">
                    <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="text-lg font-semibold text-slate-950 dark:text-white">Historique du tiroir</h3>
                                <span class="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs font-semibold text-slate-600 dark:border-white/10 dark:bg-slate-900 dark:text-slate-300">{{ $cashRegister['movements']->total() }} ligne(s)</span>
                            </div>
                            <p class="mt-1 max-w-3xl text-sm text-slate-500 dark:text-slate-400">Mouvements horodatés, ventes POS liées et solde après opération.</p>
                        </div>
                    </div>

                    <form class="mt-4 overflow-hidden rounded-2xl border border-slate-200 bg-slate-50 p-3 dark:border-white/10 dark:bg-slate-950/45" method="GET" action="{{ route('module', 'cash-register') }}">
                        <div class="grid min-w-0 gap-3 sm:grid-cols-2 xl:grid-cols-8">
                            <label class="min-w-0 space-y-1.5 xl:col-span-3">
                                <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Recherche</span>
                                <input name="q" value="{{ request('q') }}" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-950 outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/10 dark:border-white/10 dark:bg-slate-900 dark:text-white" placeholder="Session, vente, note...">
                            </label>
                            <label class="min-w-0 space-y-1.5 xl:col-span-2">
                                <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Type</span>
                                <select name="movement_type" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-950 outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/10 dark:border-white/10 dark:bg-slate-900 dark:text-white">
                                    <option value="">Tous types</option>
                                    @foreach ($movementLabels as $key => $label)
                                        <option value="{{ $key }}" @selected(request('movement_type') === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="min-w-0 space-y-1.5 xl:col-span-2">
                                <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Du</span>
                                <input name="from" value="{{ request('from') }}" type="date" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-950 outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/10 dark:border-white/10 dark:bg-slate-900 dark:text-white">
                            </label>
                            <label class="min-w-0 space-y-1.5 xl:col-span-2">
                                <span class="text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Au</span>
                                <input name="to" value="{{ request('to') }}" type="date" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-950 outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/10 dark:border-white/10 dark:bg-slate-900 dark:text-white">
                            </label>
                        </div>
                        <div class="mt-3 flex flex-col-reverse gap-2 border-t border-slate-200 pt-3 dark:border-white/10 sm:flex-row sm:items-center sm:justify-between">
                            <p class="text-xs text-slate-500 dark:text-slate-400">Affinez par session, vente, type ou période.</p>
                            <div class="grid grid-cols-2 gap-2 sm:flex sm:justify-end">
                                <a href="{{ route('module', 'cash-register') }}" class="inline-flex h-10 min-w-0 items-center justify-center rounded-xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:border-brand hover:text-brand dark:border-white/10 dark:bg-slate-900 dark:text-slate-200">Effacer</a>
                                <button class="inline-flex h-10 min-w-0 items-center justify-center rounded-xl bg-brand px-5 text-sm font-semibold text-white shadow-sm transition hover:brightness-110">Filtrer</button>
                            </div>
                        </div>
                    </form>
                </div>

                <div class="max-h-[620px] overflow-auto p-2 sm:p-3">
                    <table class="w-full min-w-[980px] text-left text-sm">
                        <thead class="sticky top-0 z-10 bg-slate-50 text-xs uppercase text-slate-500 dark:bg-slate-900 dark:text-slate-400">
                            <tr><th class="px-3 py-3">Date</th><th class="px-3 py-3">Mouvement</th><th class="px-3 py-3">Session</th><th class="px-3 py-3">Référence</th><th class="px-3 py-3 text-right">Montant</th><th class="px-3 py-3 text-right">Solde après</th><th class="px-3 py-3">Utilisateur</th><th class="px-3 py-3 text-right">Action</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-white/10">
                            @forelse ($cashRegister['movements'] as $movement)
                                <tr class="hover:bg-slate-50 dark:hover:bg-white/5">
                                    <td class="px-3 py-3 text-slate-600 dark:text-slate-300">{{ $movement->moved_at?->format('d/m/Y H:i') }}</td>
                                    <td class="px-3 py-3"><x-status-pill :tone="$movementTones[$movement->type] ?? 'primary'">{{ $movementLabels[$movement->type] ?? $movement->type }}</x-status-pill><p class="mt-1 max-w-xs truncate text-xs text-slate-500 dark:text-slate-400">{{ $movement->note }}</p></td>
                                    <td class="px-3 py-3 font-semibold">{{ $movement->session?->number }}</td>
                                    <td class="px-3 py-3 text-slate-600 dark:text-slate-300">{{ $movement->reference ?: '—' }}</td>
                                    <td class="px-3 py-3 text-right font-semibold {{ $movement->direction === 'out' ? 'text-rose-600 dark:text-rose-300' : ($movement->direction === 'neutral' ? 'text-slate-700 dark:text-slate-200' : 'text-emerald-600 dark:text-emerald-300') }}">{{ $movement->direction === 'out' ? '-' : ($movement->direction === 'in' ? '+' : '') }}{{ $money($movement->amount) }}</td>
                                    <td class="px-3 py-3 text-right font-semibold">{{ $money($movement->balance_after) }}</td>
                                    <td class="px-3 py-3 text-slate-600 dark:text-slate-300">{{ $movement->user?->name ?? '—' }}</td>
                                    <td class="px-3 py-3 text-right">
                                        @if ($movement->sale)
                                            <a href="{{ route('module', ['module' => 'sales', 'section' => 'list', 'ticket' => $movement->sale_id]) }}" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold dark:border-white/10">Voir vente</a>
                                        @else
                                            <button type="button" onclick="document.getElementById('cash-movement-{{ $movement->id }}').showModal()" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold dark:border-white/10">Détail</button>
                                        @endif
                                    </td>
                                </tr>
                                <dialog id="cash-movement-{{ $movement->id }}" class="m-auto w-full max-w-lg rounded-2xl border border-slate-200 bg-white p-0 text-slate-950 shadow-2xl backdrop:bg-slate-950/50 dark:border-white/10 dark:bg-slate-950 dark:text-white">
                                    <div class="border-b border-slate-200 p-5 dark:border-white/10"><div class="flex items-start justify-between gap-3"><div><p class="text-sm font-semibold text-brand">{{ $movement->number }}</p><h3 class="mt-1 text-xl font-semibold">{{ $movementLabels[$movement->type] ?? $movement->type }}</h3></div><button type="button" class="dialog-close grid size-10 place-items-center rounded-xl border border-slate-200 text-xl font-semibold dark:border-white/10">×</button></div></div>
                                    <div class="grid gap-3 p-5 text-sm">
                                        <div class="rounded-xl bg-slate-50 p-4 dark:bg-white/5"><span class="block text-xs uppercase text-slate-500 dark:text-slate-400">Note</span><strong class="mt-1 block">{{ $movement->note ?: '—' }}</strong></div>
                                        <div class="grid gap-3 sm:grid-cols-2"><div class="rounded-xl border border-slate-200 p-3 dark:border-white/10"><span class="text-xs text-slate-500 dark:text-slate-400">Montant</span><strong class="mt-1 block">{{ $money($movement->amount) }}</strong></div><div class="rounded-xl border border-slate-200 p-3 dark:border-white/10"><span class="text-xs text-slate-500 dark:text-slate-400">Solde après</span><strong class="mt-1 block">{{ $money($movement->balance_after) }}</strong></div><div class="rounded-xl border border-slate-200 p-3 dark:border-white/10"><span class="text-xs text-slate-500 dark:text-slate-400">Référence</span><strong class="mt-1 block">{{ $movement->reference ?: '—' }}</strong></div><div class="rounded-xl border border-slate-200 p-3 dark:border-white/10"><span class="text-xs text-slate-500 dark:text-slate-400">Utilisateur</span><strong class="mt-1 block">{{ $movement->user?->name ?? '—' }}</strong></div></div>
                                    </div>
                                </dialog>
                            @empty
                                <tr><td colspan="8" class="px-4 py-12 text-center text-sm text-slate-500 dark:text-slate-400">Aucun mouvement trouvé.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="border-t border-slate-200 p-4 dark:border-white/10">{{ $cashRegister['movements']->links() }}</div>
            </article>

            <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div><h3 class="text-lg font-semibold text-slate-950 dark:text-white">Sessions récentes</h3><p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Ouvertures, clôtures, cash compté et écarts.</p></div>
                </div>
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full min-w-[820px] text-left text-sm">
                        <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-white/5 dark:text-slate-400"><tr><th class="px-3 py-3">Session</th><th class="px-3 py-3">Ouverture</th><th class="px-3 py-3">Clôture</th><th class="px-3 py-3 text-right">Attendu</th><th class="px-3 py-3 text-right">Compté</th><th class="px-3 py-3 text-right">Écart</th><th class="px-3 py-3">Statut</th></tr></thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-white/10">
                            @forelse ($cashRegister['sessions'] as $session)
                                <tr><td class="px-3 py-3 font-semibold">{{ $session->number }}<p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $session->openedBy?->name ?? '—' }}</p></td><td class="px-3 py-3">{{ $session->opened_at?->format('d/m/Y H:i') }}</td><td class="px-3 py-3">{{ $session->closed_at?->format('d/m/Y H:i') ?? '—' }}</td><td class="px-3 py-3 text-right font-semibold">{{ $money($session->expected_cash_amount) }}</td><td class="px-3 py-3 text-right">{{ $session->counted_cash_amount !== null ? $money($session->counted_cash_amount) : '—' }}</td><td class="px-3 py-3 text-right font-semibold {{ abs((float) $session->difference_amount) > 0.001 ? 'text-amber-600 dark:text-amber-300' : '' }}">{{ $money($session->difference_amount) }}</td><td class="px-3 py-3"><x-status-pill :tone="$session->status === 'open' ? 'success' : 'primary'">{{ $session->status === 'open' ? 'Ouvert' : 'Clôturé' }}</x-status-pill></td></tr>
                            @empty
                                <tr><td colspan="7" class="px-4 py-10 text-center text-sm text-slate-500 dark:text-slate-400">Aucune session de caisse.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </article>
        </div>
    </div>
</section>
