@php
    $money = fn ($v) => \App\Support\Money::for($tenant)->format($v);
    $statusLabels = [
        'active' => ['Active', 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300'],
        'used' => ['Épuisée', 'bg-slate-100 text-slate-600 dark:bg-white/10 dark:text-slate-300'],
        'expired' => ['Expirée', 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300'],
        'cancelled' => ['Annulée', 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300'],
    ];
    $input = 'h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900';
@endphp
<x-layouts.app :tenant="$tenant" active="gift_cards" title="Cartes cadeaux">
    <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
        <p class="text-sm font-semibold text-brand">Ventes</p>
        <h1 class="mt-1 text-[1.75rem] font-bold tracking-tight text-slate-950 dark:text-white">Cartes cadeaux</h1>
        <p class="mt-2 max-w-2xl text-sm text-slate-500 dark:text-slate-400">Les cartes se vendent en caisse. Une carte vendue est de l’argent dû au client : elle ne compte dans le chiffre d’affaires que lorsqu’elle est utilisée.</p>
        <p class="mt-3 text-sm" data-outstanding>Solde dû sur les cartes actives : <strong>{{ $money($outstanding) }}</strong></p>

        @if (session('status'))
            <p class="mt-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">{{ session('status') }}</p>
        @endif
        @if ($errors->any())
            <p class="mt-4 rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">{{ $errors->first() }}</p>
        @endif

        <div class="mt-5 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
            <form method="GET" action="{{ route('gift-cards') }}" class="flex flex-wrap gap-2">
                <input name="q" value="{{ $q }}" placeholder="Code, bénéficiaire, acheteur" class="{{ $input }} w-64">
                <select name="status" class="{{ $input }} w-44">
                    <option value="">Tous les statuts</option>
                    @foreach ($statusLabels as $key => [$label])
                        <option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>
                    @endforeach
                </select>
                <button class="h-11 rounded-lg bg-brand px-4 text-sm font-semibold text-white">Filtrer</button>
            </form>
            <form method="POST" action="{{ route('gift-cards.settings') }}" class="flex items-end gap-2" data-gift-card-settings>
                @csrf
                <label class="block text-xs font-semibold uppercase text-slate-500">Validité d’une nouvelle carte (mois, 0 = illimitée)
                    <input type="number" name="validity_months" min="0" max="120" value="{{ $validityMonths }}" class="{{ $input }} mt-1 w-40">
                </label>
                <button class="h-11 rounded-lg border border-slate-200 px-4 text-sm font-semibold dark:border-white/10">Enregistrer</button>
            </form>
        </div>
    </section>

    <section class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-slate-200 text-xs uppercase text-slate-500 dark:border-white/10">
                    <tr><th class="p-3">Code</th><th class="p-3">Statut</th><th class="p-3 text-right">Montant</th><th class="p-3 text-right">Solde</th><th class="p-3">Expire le</th><th class="p-3">Acheteur / bénéficiaire</th><th class="p-3">Vendue le</th></tr>
                </thead>
                <tbody>
                    @forelse ($cards as $card)
                        @php [$label, $tone] = $statusLabels[$card->status] ?? [$card->status, '']; @endphp
                        <tr class="border-b border-slate-100 last:border-0 dark:border-white/5" data-gift-card-row>
                            <td class="p-3 font-mono font-semibold"><a href="{{ route('gift-cards.show', $card) }}" class="text-brand">{{ $card->displayCode() }}</a></td>
                            <td class="p-3"><span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $tone }}">{{ $label }}</span></td>
                            <td class="p-3 text-right">{{ $money($card->initial_amount) }}</td>
                            <td class="p-3 text-right font-semibold">{{ $money($card->balance) }}</td>
                            <td class="p-3">{{ $card->expires_at?->format('d/m/Y') ?? '—' }}</td>
                            <td class="p-3">{{ $card->buyer?->name ?? '—' }}@if ($card->recipient_name) <span class="text-slate-500">→ {{ $card->recipient_name }}</span>@endif</td>
                            <td class="p-3">{{ $card->sold_at ? \App\Support\TenantClock::format($card->sold_at, $tenant, 'd/m/Y') : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="p-8 text-center text-slate-500">Aucune carte cadeau.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $cards->links() }}</div>
    </section>
</x-layouts.app>
