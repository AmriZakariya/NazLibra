@php($money = fn ($amount) => number_format((float) $amount, 2, ',', ' ').' DH')

<x-layouts.app :tenant="$tenant" :active="$active" title="LibrairePro · Caisse">
    <section class="grid gap-6 xl:grid-cols-[1fr_380px]">
        <div class="space-y-5">
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <p class="text-sm font-medium text-indigo-600 dark:text-indigo-300">Caisse rapide</p>
                        <h1 class="mt-1 text-2xl font-semibold">Scanner, chercher, encaisser</h1>
                    </div>
                    <div class="flex gap-2">
                        <x-status-pill tone="success">Mode hors-ligne prêt</x-status-pill>
                        <x-status-pill tone="info">Raccourcis clavier</x-status-pill>
                    </div>
                </div>
                <form action="{{ route('pos') }}" class="mt-5 flex gap-2">
                    <input name="q" value="{{ $query }}" autofocus class="h-12 flex-1 rounded-lg border border-slate-200 bg-slate-50 px-4 text-sm outline-none focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10 dark:border-white/10 dark:bg-white/5" placeholder="Scanner code-barres ou saisir titre / ISBN">
                    <button class="rounded-lg bg-indigo-600 px-5 text-sm font-semibold text-white">Chercher</button>
                </form>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($items as $item)
                    <button class="pos-item rounded-xl border border-slate-200 bg-white p-4 text-left shadow-sm transition hover:-translate-y-0.5 hover:border-indigo-300 hover:shadow-md dark:border-white/10 dark:bg-white/[0.03]" type="button" data-name="{{ $item->title }}" data-price="{{ $item->sale_price }}">
                        <div class="flex items-start justify-between gap-3">
                            <div class="grid size-11 place-items-center rounded-lg bg-indigo-50 text-sm font-bold text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300">{{ mb_substr($item->title, 0, 2) }}</div>
                            <x-status-pill :tone="$item->is_low_stock ? 'warning' : 'success'">{{ $item->stock_quantity }}</x-status-pill>
                        </div>
                        <p class="mt-4 line-clamp-2 min-h-10 text-sm font-semibold">{{ $item->title }}</p>
                        <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">{{ $item->barcode ?? $item->isbn }}</p>
                        <p class="mt-3 text-lg font-semibold">{{ $money($item->sale_price) }}</p>
                    </button>
                @endforeach
            </div>
        </div>

        <aside class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03] xl:sticky xl:top-24 xl:h-[calc(100vh-7rem)]">
            <div class="flex items-center justify-between">
                <h2 class="text-base font-semibold">Panier</h2>
                <button class="pos-clear text-sm font-semibold text-rose-600" type="button">Vider</button>
            </div>
            <div class="pos-cart mt-4 space-y-3"></div>
            <div class="pos-empty mt-8 rounded-lg border border-dashed border-slate-200 p-8 text-center text-sm text-slate-500 dark:border-white/10">Scannez un article pour commencer.</div>

            <div class="mt-6 border-t border-slate-200 pt-4 dark:border-white/10">
                <label class="text-xs font-semibold uppercase text-slate-500">Client</label>
                <select class="mt-2 h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">
                    <option>Client comptoir</option>
                    @foreach ($clients as $client)
                        <option>{{ $client->name }} · avance {{ $money($client->advance_balance) }}</option>
                    @endforeach
                </select>
            </div>

            <div class="mt-5 space-y-2 text-sm">
                <div class="flex justify-between text-slate-500"><span>Sous-total</span><span class="pos-subtotal">0,00 DH</span></div>
                <div class="flex justify-between text-slate-500"><span>TVA incluse</span><span class="pos-tax">0,00 DH</span></div>
                <div class="flex justify-between text-lg font-semibold"><span>Total</span><span class="pos-total">0,00 DH</span></div>
            </div>

            <div class="mt-5 grid grid-cols-2 gap-2">
                <button class="rounded-lg border border-slate-200 px-4 py-3 text-sm font-semibold dark:border-white/10">Carte</button>
                <button class="rounded-lg border border-slate-200 px-4 py-3 text-sm font-semibold dark:border-white/10">Mixte</button>
                <button class="rounded-lg border border-slate-200 px-4 py-3 text-sm font-semibold dark:border-white/10">Avoir</button>
                <button class="rounded-lg bg-indigo-600 px-4 py-3 text-sm font-semibold text-white">Espèces</button>
            </div>

            <div class="mt-6">
                <h3 class="text-sm font-semibold">Ventes récentes</h3>
                <div class="mt-3 space-y-2">
                    @foreach ($recentSales as $sale)
                        <div class="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2 text-xs dark:bg-white/5">
                            <span>{{ $sale->number }}</span>
                            <strong>{{ $money($sale->total_amount) }}</strong>
                        </div>
                    @endforeach
                </div>
            </div>
        </aside>
    </section>
</x-layouts.app>
