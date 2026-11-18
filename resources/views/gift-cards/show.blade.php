@php
    $money = fn ($v) => \App\Support\Money::for($tenant)->format($v);
    $statusLabels = ['active' => 'Active', 'used' => 'Épuisée', 'expired' => 'Expirée', 'cancelled' => 'Annulée'];
    $typeLabels = ['sale' => 'Vente', 'redeem' => 'Paiement', 'refund' => 'Remboursement', 'adjust' => 'Ajustement', 'cancel' => 'Annulation'];
    $input = 'h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900';
    $canManage = \App\Support\Permissions::userCan($tenant, auth()->user(), 'gift_cards.manage');
@endphp
<x-layouts.app :tenant="$tenant" active="gift_cards" title="Carte cadeau">
    <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
        <a href="{{ route('gift-cards') }}" class="text-sm font-semibold text-brand">← Cartes cadeaux</a>
        <h1 class="mt-2 font-mono text-[1.75rem] font-bold tracking-tight text-slate-950 dark:text-white" data-card-code>{{ $card->displayCode() }}</h1>
        <p class="mt-2 text-sm text-slate-500">
            {{ $statusLabels[$card->status] ?? $card->status }} · solde <strong data-card-balance>{{ $money($card->balance) }}</strong> sur {{ $money($card->initial_amount) }}
            · expire le {{ $card->expires_at?->format('d/m/Y') ?? 'jamais' }}
            @if ($card->sale) · vente {{ $card->sale->number }} @endif
        </p>
        <p class="mt-1 text-sm text-slate-500">
            Acheteur : {{ $card->buyer?->name ?? '—' }} · Bénéficiaire : {{ $card->recipient_name ?? '—' }} {{ $card->recipient_phone }}
        </p>

        @if (session('status'))
            <p class="mt-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">{{ session('status') }}</p>
        @endif
        @if ($errors->any())
            <p class="mt-4 rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">{{ $errors->first() }}</p>
        @endif

        @if ($canManage && $card->status !== 'cancelled')
            <div class="mt-5 grid gap-4 lg:grid-cols-2">
                <form method="POST" action="{{ route('gift-cards.adjust', $card) }}" class="grid gap-2 rounded-xl border border-slate-200 p-4 dark:border-white/10" data-adjust-form>
                    @csrf
                    <h2 class="font-semibold">Ajuster le solde</h2>
                    <input type="number" step="0.01" name="amount" placeholder="Montant (négatif pour retirer)" class="{{ $input }}" required>
                    <input name="reason" placeholder="Motif (obligatoire)" class="{{ $input }}" required maxlength="255">
                    <label class="text-xs font-semibold uppercase text-slate-500">Nouvelle date d’expiration (facultatif)
                        <input type="date" name="expires_at" value="{{ $card->expires_at?->toDateString() }}" class="{{ $input }} mt-1">
                    </label>
                    <button class="h-11 rounded-lg bg-brand px-4 text-sm font-semibold text-white">Ajuster</button>
                </form>
                <form method="POST" action="{{ route('gift-cards.cancel', $card) }}" class="grid gap-2 rounded-xl border border-rose-200 p-4 dark:border-rose-500/20" data-cancel-form onsubmit="return confirm('Annuler cette carte ? Le solde restant sera perdu.')">
                    @csrf
                    <h2 class="font-semibold text-rose-700 dark:text-rose-300">Annuler la carte</h2>
                    <p class="text-sm text-slate-500">Le solde restant ({{ $money($card->balance) }}) est annulé et la carte ne pourra plus servir.</p>
                    <input name="reason" placeholder="Motif (obligatoire)" class="{{ $input }}" required maxlength="255">
                    <button class="h-11 rounded-lg bg-rose-600 px-4 text-sm font-semibold text-white">Annuler la carte</button>
                </form>
            </div>
        @endif
    </section>

    <section class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
        <h2 class="border-b border-slate-200 p-4 font-semibold dark:border-white/10">Historique</h2>
        <table class="w-full text-left text-sm">
            <thead class="border-b border-slate-200 text-xs uppercase text-slate-500 dark:border-white/10"><tr><th class="p-3">Date</th><th class="p-3">Opération</th><th class="p-3 text-right">Montant</th><th class="p-3 text-right">Solde après</th><th class="p-3">Vente</th><th class="p-3">Par</th><th class="p-3">Motif</th></tr></thead>
            <tbody>
                @foreach ($transactions as $t)
                    <tr class="border-b border-slate-100 last:border-0 dark:border-white/5" data-card-transaction>
                        <td class="p-3">{{ \App\Support\TenantClock::format($t->created_at, $tenant) }}</td>
                        <td class="p-3">{{ $typeLabels[$t->type] ?? $t->type }}</td>
                        <td class="p-3 text-right {{ $t->amount < 0 ? 'text-rose-600' : 'text-emerald-600' }}">{{ $t->amount > 0 ? '+' : '' }}{{ $money($t->amount) }}</td>
                        <td class="p-3 text-right">{{ $money($t->balance_after) }}</td>
                        <td class="p-3">{{ $t->sale?->number ?? '—' }}</td>
                        <td class="p-3">{{ $t->user?->name ?? '—' }}</td>
                        <td class="p-3 text-slate-500">{{ $t->reason }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
</x-layouts.app>
