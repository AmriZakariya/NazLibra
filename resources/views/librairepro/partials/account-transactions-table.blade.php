<div class="border-b border-slate-200 p-4 dark:border-white/10">
    <form method="GET" action="{{ url()->current() }}" class="grid gap-3 lg:grid-cols-[1fr_180px_150px_150px_auto]">
        <input type="hidden" name="section" value="{{ request('section') }}">
        <input name="q" value="{{ request('q') }}" class="h-11 rounded-lg border border-slate-200 bg-slate-50 px-3 text-sm dark:border-white/10 dark:bg-white/5" placeholder="Rechercher n°, compte, référence...">
        <select name="transaction_type" class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">
            <option value="">Tous mouvements</option>
            <option value="deposit" @selected(request('transaction_type') === 'deposit')>Dépôt</option>
            <option value="transfer" @selected(request('transaction_type') === 'transfer')>Transfert</option>
            <option value="opening" @selected(request('transaction_type') === 'opening')>Solde initial</option>
        </select>
        <input name="from" value="{{ request('from') }}" type="date" class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">
        <input name="to" value="{{ request('to') }}" type="date" class="h-11 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">
        <div class="flex gap-2"><button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Filtrer</button><a href="{{ url()->current().'?section='.request('section') }}" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold dark:border-white/10">Reset</a></div>
    </form>
</div>
<div class="overflow-x-auto">
    <table class="w-full min-w-[980px] text-left text-sm">
        <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-white/5">
            <tr><th class="px-3 py-3">N°</th><th class="px-3 py-3">Date</th><th class="px-3 py-3">Compte</th><th class="px-3 py-3">Type</th><th class="px-3 py-3">Sens</th><th class="px-3 py-3">Référence</th><th class="px-3 py-3 text-right">Montant</th><th class="px-3 py-3 text-right">Solde après</th></tr>
        </thead>
        <tbody class="divide-y divide-slate-200 dark:divide-white/10">
            @forelse ($transactions as $transaction)
                <tr>
                    <td class="px-3 py-3 font-semibold">{{ $transaction->number }}</td>
                    <td class="px-3 py-3">{{ $transaction->transacted_at?->format('d/m/Y H:i') }}</td>
                    <td class="px-3 py-3"><p class="font-semibold">{{ $transaction->account?->name ?? 'Compte supprimé' }}</p><p class="mt-1 text-xs text-slate-500">{{ $transaction->relatedAccount?->name ? 'Lié: '.$transaction->relatedAccount->name : ($transaction->note ?: '—') }}</p></td>
                    <td class="px-3 py-3"><x-status-pill tone="info">{{ ['deposit' => 'Dépôt', 'transfer' => 'Transfert', 'opening' => 'Solde initial'][$transaction->type] ?? $transaction->type }}</x-status-pill></td>
                    <td class="px-3 py-3"><x-status-pill :tone="$transaction->direction === 'in' ? 'success' : 'danger'">{{ $transaction->direction === 'in' ? 'Entrée' : 'Sortie' }}</x-status-pill></td>
                    <td class="px-3 py-3 text-slate-500">{{ $transaction->reference ?: '—' }}</td>
                    <td class="px-3 py-3 text-right font-semibold {{ $transaction->direction === 'in' ? 'text-emerald-600' : 'text-rose-600' }}">{{ $transaction->direction === 'in' ? '+' : '-' }} {{ $money($transaction->amount) }}</td>
                    <td class="px-3 py-3 text-right font-semibold">{{ $money($transaction->balance_after) }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-4 py-12 text-center text-sm text-slate-500">Aucun mouvement trouvé.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="border-t border-slate-200 px-4 py-3 dark:border-white/10">{{ $transactions->links() }}</div>
