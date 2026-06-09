@php
    $money = fn ($amount) => number_format((float) $amount, 2, ',', ' ').' DH';
    $tr = fn ($text) => \App\Support\Locale::t($text);
    $productTypes = ['all' => 'Tous', 'book' => 'Livres', 'supply' => 'Papeterie', 'service' => 'Services'];
    $resumeDiscountType = old('discount_type', $resumeTicket?->discount_type ?: 'fixed');
    $resumeDiscountValue = old('discount_value', $resumeTicket?->discount_value ?? $resumeTicket?->discount_amount ?? 0);
    $resumeCouponCode = old('coupon_code', $resumeTicket?->coupon_code ?? '');
    $resumeNote = old('note', $resumeTicket?->note ?? '');
@endphp

<x-layouts.app :tenant="$tenant" :active="$active" title="LibrairePro · Caisse">
    <section class="pos-screen grid gap-4 2xl:grid-cols-[minmax(0,1fr)_520px] xl:grid-cols-[minmax(0,1fr)_500px]" data-resume-cart='@json($resumeTicket?->cart ?? [])' data-pos-search-url="{{ route('pos.search') }}" data-coupon-preview-url="{{ route('pos.coupons.preview') }}" data-price-editable="{{ $priceEditable ? '1' : '0' }}" data-allow-oversell="{{ $allowOversell ? '1' : '0' }}" data-show-out-of-stock="{{ $showOutOfStock ? '1' : '0' }}">
        <div class="min-w-0 space-y-5">
            @if ($lastSale)
                @php
                    $receiptText = "Ticket ".$lastSale->number." - ".$money($lastSale->total_amount);
                    $shareText = rawurlencode($receiptText."\n".$tenant->name."\nMerci pour votre achat.");
                    $paidAmount = (float) data_get($lastSale->metadata, 'paid_amount', $lastSale->total_amount);
                    $changeAmount = (float) data_get($lastSale->metadata, 'change_amount', 0);
                    $lastDiscount = data_get($lastSale->metadata, 'discount');
                    $lastManualDiscount = data_get($lastDiscount, 'manual', $lastDiscount);
                    $lastCouponDiscount = data_get($lastDiscount, 'coupon');
                    $lastDiscountLabel = data_get($lastManualDiscount, 'type') === 'percentage'
                        ? number_format((float) data_get($lastManualDiscount, 'value', 0), 2, ',', ' ').'%'
                        : $money(data_get($lastManualDiscount, 'value', $lastSale->discount_amount));
                    $lastSystemNoteDate = $lastSale->sold_at?->format('d/m/Y H:i') ?? '—';
                    $lastSystemNote = data_get($lastSale->metadata, 'system_note')
                        ?: 'Note système: vente '.$lastSale->number.' enregistrée le '.$lastSystemNoteDate.' pour '.($lastSale->contact?->name ?? 'Client comptoir').', '.$lastSale->items->count().' ligne(s), total '.$money($lastSale->total_amount).', paiement '.$lastSale->payment_method.'.';
                    $lastManualNote = trim((string) data_get($lastSale->metadata, 'note', ''));
                @endphp
                <div class="fixed inset-0 z-50 grid place-items-center bg-slate-950/45 px-4 py-6 backdrop-blur-sm">
                    <article class="receipt-success flex max-h-[92vh] w-full max-w-xl flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl dark:border-white/10 dark:bg-slate-950">
                        <div class="shrink-0 border-b border-slate-200 p-5 dark:border-white/10">
                            <div class="flex items-start justify-between gap-4">
                                <div class="flex min-w-0 items-start gap-3">
                                    <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-emerald-50 text-xl font-black text-emerald-700 ring-1 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20">✓</span>
                                    <span class="min-w-0">
                                        <p class="text-sm font-semibold text-emerald-700 dark:text-emerald-300">{{ $tr('Paiement validé') }}</p>
                                        <h2 class="mt-1 truncate text-2xl font-black tracking-normal">{{ $lastSale->number }} · {{ $money($lastSale->total_amount) }}</h2>
                                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $lastSale->sold_at->format('d/m/Y H:i') }}</p>
                                    </span>
                                </div>
                                <a href="{{ route('pos') }}" data-pos-close-success class="grid size-10 shrink-0 place-items-center rounded-xl border border-slate-200 bg-white text-xl font-black text-slate-700 shadow-sm transition hover:border-rose-200 hover:text-rose-600 dark:border-white/10 dark:bg-white/5 dark:text-slate-200">×</a>
                            </div>
                        </div>

                        <div class="min-h-0 flex-1 overflow-y-auto p-5 pb-6">
                        <div class="grid gap-3 sm:grid-cols-3">
                            <div class="rounded-xl border border-emerald-100 bg-emerald-50 p-3 dark:border-emerald-500/20 dark:bg-emerald-500/10">
                                <span class="text-[11px] font-bold uppercase text-emerald-700 dark:text-emerald-300">{{ $tr('Client') }}</span>
                                <p class="mt-1 truncate font-semibold">{{ $lastSale->contact?->name ?? $tr('Client comptoir') }}</p>
                            </div>
                            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 dark:border-white/10 dark:bg-white/5">
                                <span class="text-[11px] font-bold uppercase text-slate-500">{{ $tr('Payé') }}</span>
                                <p class="mt-1 font-semibold">{{ $money($paidAmount) }}</p>
                            </div>
                            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 dark:border-white/10 dark:bg-white/5">
                                <span class="text-[11px] font-bold uppercase text-slate-500">{{ $tr('Monnaie') }}</span>
                                <p class="mt-1 font-semibold">{{ $money($changeAmount) }}</p>
                            </div>
                        </div>

                        @if ($lastManualNote !== '')
                            <div class="mt-4 rounded-xl border border-slate-200 bg-white p-3 text-sm text-slate-900 shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-slate-100">
                                <span class="text-[11px] font-bold uppercase text-brand">{{ $tr('Note ticket') }}</span>
                                <p class="mt-1 leading-6">{{ $lastManualNote }}</p>
                            </div>
                        @else
                            <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-600 dark:border-white/10 dark:bg-white/5 dark:text-slate-300">
                                <span class="text-[11px] font-bold uppercase text-slate-500">{{ $tr('Note ticket') }}</span>
                                <p class="mt-1">{{ $tr('Aucune note manuelle ajoutée.') }}</p>
                            </div>
                        @endif

                        <details class="mt-3 rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700 dark:border-white/10 dark:bg-white/5 dark:text-slate-300">
                            <summary class="cursor-pointer list-none text-[11px] font-bold uppercase text-slate-500">{{ $tr('Info système') }}</summary>
                            <p class="mt-2 leading-6">{{ $lastSystemNote }}</p>
                        </details>

                        <div class="mt-4 flex items-center justify-between gap-3">
                            <h3 class="text-sm font-black">{{ $tr('Aperçu ticket') }}</h3>
                            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-bold text-slate-500 dark:bg-white/10">{{ $tr('Thermique') }}</span>
                        </div>
                        <div class="receipt-print-area thermal-receipt mt-2 max-h-72 overflow-y-auto rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm dark:border-white/10 dark:bg-white/5" data-thermal-receipt>
                            <div class="text-center">
                                <strong class="block text-base">{{ $tenant->name }}</strong>
                                <span class="text-xs text-slate-500">{{ $tenant->address }} · {{ $tenant->phone }}</span>
                                <p class="mt-2 font-semibold">Ticket {{ $lastSale->number }}</p>
                                <p class="text-xs text-slate-500">{{ $lastSale->sold_at->format('d/m/Y H:i') }}</p>
                            </div>
                            <div class="mt-4 space-y-2">
                                @foreach ($lastSale->items as $line)
                                    <div class="flex justify-between gap-3">
                                        <span>{{ $line->quantity }} x {{ $line->name }}</span>
                                        <strong>{{ $money($line->total_price) }}</strong>
                                    </div>
                                @endforeach
                            </div>
                            <div class="mt-4 border-t border-slate-200 pt-3 dark:border-white/10">
                                <div class="flex justify-between"><span>Sous-total</span><span>{{ $money($lastSale->subtotal_amount) }}</span></div>
                                @if ((float) data_get($lastCouponDiscount, 'amount', 0) > 0)
                                    <div class="flex justify-between"><span>Coupon {{ data_get($lastCouponDiscount, 'code') }}</span><span>{{ $money(data_get($lastCouponDiscount, 'amount')) }}</span></div>
                                @endif
                                <div class="flex justify-between"><span>Remise {{ $lastSale->discount_amount > 0 ? '('.$lastDiscountLabel.')' : '' }}</span><span>{{ $money($lastSale->discount_amount) }}</span></div>
                                <div class="flex justify-between text-base font-bold"><span>Total</span><span>{{ $money($lastSale->total_amount) }}</span></div>
                                <div class="mt-2 text-xs text-slate-500">Paiement: {{ $lastSale->payment_method }}</div>
                                <div class="text-xs text-slate-500">Payé: {{ $money($paidAmount) }} · Monnaie: {{ $money($changeAmount) }}</div>
                                @if ($lastManualNote !== '')
                                    <div class="mt-2 rounded-lg bg-white px-2 py-1 text-xs text-slate-600 dark:bg-slate-950/40 dark:text-slate-300">Note: {{ $lastManualNote }}</div>
                                @endif
                            </div>
                            <div class="mt-4 border-t border-slate-200 pt-3 text-center text-xs text-slate-500 dark:border-white/10">
                                Merci pour votre achat
                            </div>
                        </div>
                        </div>
                        <div class="shrink-0 border-t border-slate-200 bg-white p-4 shadow-[0_-14px_30px_rgba(15,23,42,0.08)] dark:border-white/10 dark:bg-slate-950">
                            <div class="grid gap-2 sm:grid-cols-4">
                                <a href="{{ route('pos') }}" data-pos-close-success class="rounded-xl border border-slate-200 bg-white px-3 py-3 text-center text-sm font-bold text-slate-700 transition hover:border-brand/40 hover:text-brand dark:border-white/10 dark:bg-white/5 dark:text-slate-200">{{ $tr('Nouveau') }}</a>
                                <button class="pos-print-ticket rounded-xl bg-brand px-3 py-3 text-sm font-bold text-white shadow-sm shadow-indigo-500/20 transition hover:brightness-110" type="button">{{ $tr('Imprimer') }}</button>
                                <a href="{{ route('sales.pdf', $lastSale) }}" target="_blank" rel="noreferrer" class="rounded-xl border border-slate-200 bg-white px-3 py-3 text-center text-sm font-bold text-slate-700 transition hover:border-brand/40 hover:text-brand dark:border-white/10 dark:bg-white/5 dark:text-slate-200">PDF vente</a>
                                <a href="https://wa.me/?text={{ $shareText }}" target="_blank" rel="noreferrer" class="rounded-xl border border-slate-200 bg-white px-3 py-3 text-center text-sm font-bold text-slate-700 transition hover:border-brand/40 hover:text-brand dark:border-white/10 dark:bg-white/5 dark:text-slate-200">{{ $tr('Partager') }}</a>
                            </div>
                        </div>
                    </article>
                </div>
            @endif

            <details open class="pos-command-center pos-collapsible overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                <summary class="pos-collapsible-summary flex cursor-pointer list-none items-center justify-between gap-3 p-4">
                    <span class="flex min-w-0 items-center gap-3">
                        <span class="grid size-12 shrink-0 place-items-center rounded-2xl bg-brand text-xl font-black text-white shadow-sm shadow-brand/20">⌕</span>
                        <span class="min-w-0">
                            <span class="block text-sm font-semibold text-brand">{{ $tr('Caisse librairie') }}</span>
                            <span class="mt-1 block truncate text-xl font-semibold">{{ $tr('Scanner, trouver, encaisser') }}</span>
                            <span class="mt-1 block truncate text-xs text-slate-500">{{ $tr('Recherche rapide, filtres utiles et création d’article sans quitter la caisse.') }}</span>
                        </span>
                    </span>
                    <span class="flex min-w-0 shrink items-center justify-end gap-2">
                        <span class="hidden flex-wrap justify-end gap-2 lg:flex">
                            <x-status-pill tone="primary">{{ $tr('Prochaine vente') }} {{ $nextSaleNumber }}</x-status-pill>
                            <x-status-pill :tone="$resumeTicket ? 'warning' : 'info'">{{ $resumeTicket ? $tr('Reprise').' '.$resumeTicket->number : $tr('Attente').' '.$nextTicketNumber }}</x-status-pill>
                            <x-status-pill tone="success">{{ $tr('Stock temps réel') }}</x-status-pill>
                            <x-status-pill :tone="$priceEditable ? 'warning' : 'info'">{{ $priceEditable ? $tr('Prix modifiable') : $tr('Prix verrouillés') }}</x-status-pill>
                            <x-status-pill :tone="$allowOversell ? 'warning' : 'success'">{{ $allowOversell ? $tr('Hors stock autorisé') : $tr('Stock bloquant') }}</x-status-pill>
                            <button class="pos-hardware-connect hidden rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-bold text-slate-600 transition hover:border-brand hover:text-brand dark:border-white/10 dark:bg-white/5 dark:text-slate-200" type="button" title="Connecter imprimante / tiroir">
                                <span class="pos-hardware-status">🔌 {{ $tr('Matériel') }}</span>
                            </button>
                        </span>
                        <span class="pos-collapsible-chevron grid size-8 shrink-0 place-items-center rounded-lg border border-slate-200 bg-slate-50 text-sm font-bold text-slate-600 dark:border-white/10 dark:bg-white/5">⌄</span>
                    </span>
                </summary>

                <div class="pos-command-body border-t border-slate-200 bg-slate-50/60 p-4 dark:border-white/10 dark:bg-white/[0.02]">
                    <div class="mb-3 flex flex-wrap gap-2 lg:hidden">
                        <x-status-pill tone="primary">{{ $tr('Prochaine vente') }} {{ $nextSaleNumber }}</x-status-pill>
                        <x-status-pill :tone="$resumeTicket ? 'warning' : 'info'">{{ $resumeTicket ? $tr('Reprise').' '.$resumeTicket->number : $tr('Attente').' '.$nextTicketNumber }}</x-status-pill>
                        <x-status-pill tone="success">{{ $tr('Stock temps réel') }}</x-status-pill>
                        <x-status-pill :tone="$priceEditable ? 'warning' : 'info'">{{ $priceEditable ? $tr('Prix modifiable') : $tr('Prix verrouillés') }}</x-status-pill>
                        <x-status-pill :tone="$allowOversell ? 'warning' : 'success'">{{ $allowOversell ? $tr('Hors stock autorisé') : $tr('Stock bloquant') }}</x-status-pill>
                    </div>

                    <!-- Main Search Section -->
                    <div class="pos-search-row mb-4">
                        <label class="pos-control pos-search-control block">
                            <span class="pos-control-label text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $tr('Recherche rapide') }}</span>
                            <span class="relative mt-2 block group">
                                <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-lg text-slate-400">🔍</span>
                                <input class="pos-search barcode-input h-[52px] w-full rounded-xl border border-slate-200 bg-white px-12 pe-32 text-base font-semibold outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/20 group-hover:border-slate-300 dark:border-white/10 dark:bg-slate-950 dark:group-hover:border-white/20" value="{{ $query }}" placeholder="Code-barres, ISBN, titre, SKU...">
                                <button class="barcode-scan-btn absolute right-2 top-1/2 h-9 -translate-y-1/2 rounded-lg border border-slate-200 bg-white px-3 text-xs font-bold text-slate-600 shadow-sm transition hover:border-brand hover:text-brand hover:bg-brand/5 active:scale-95 dark:border-white/10 dark:bg-slate-900 dark:hover:bg-brand/10" type="button" title="{{ $tr('Scanner avec caméra') }}">📷</button>
                            </span>
                        </label>
                    </div>

                    <!-- Filters Section -->
                    <details class="pos-advanced-filters group rounded-lg border border-slate-200 bg-white p-3 dark:border-white/10 dark:bg-white/5">
                        <summary class="flex cursor-pointer select-none items-center justify-between text-sm font-semibold text-slate-700 dark:text-slate-200">
                            <span class="flex items-center gap-2">
                                ⚙️ {{ $tr('Filtres') }}
                                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-bold text-slate-600 dark:bg-white/10 dark:text-slate-300">5</span>
                            </span>
                            <span class="hidden truncate text-xs font-medium text-slate-500 sm:block">{{ $tr('Famille, stock, catégorie, marque et unité') }}</span>
                            <span class="transition group-open:rotate-180">▼</span>
                        </summary>

                        <div class="mt-3 grid gap-2 border-t border-slate-200 pt-3 dark:border-white/10 sm:grid-cols-2 xl:grid-cols-3">
                            <label class="pos-control block">
                                <span class="pos-control-label text-xs font-semibold uppercase tracking-wide text-slate-500">📚 {{ $tr('Famille') }}</span>
                                <select class="pos-type-filter mt-2 h-[44px] w-full rounded-lg border border-slate-200 bg-white px-3 text-sm font-medium transition hover:border-slate-300 focus:border-brand focus:ring-2 focus:ring-brand/20 dark:border-white/10 dark:bg-slate-950 dark:hover:border-white/20" title="Filtrer par famille de produit">
                                    @foreach ($productTypes as $value => $label)
                                        <option value="{{ $value }}" @selected($type === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>

                            <label class="pos-control block">
                                <span class="pos-control-label text-xs font-semibold uppercase tracking-wide text-slate-500">📦 {{ $tr('Stock') }}</span>
                                <select class="pos-stock-filter mt-2 h-[44px] w-full rounded-lg border border-slate-200 bg-white px-3 text-sm font-medium transition hover:border-slate-300 focus:border-brand focus:ring-2 focus:ring-brand/20 dark:border-white/10 dark:bg-slate-950 dark:hover:border-white/20" title="Filtrer par statut stock">
                                    <option value="available" @selected($stock === 'available')>{{ $tr('Disponible') }}</option>
                                    <option value="all" @selected($stock === 'all')>{{ $tr('Tout stock') }}</option>
                                    <option value="low" @selected($stock === 'low')>{{ $tr('Stock bas') }}</option>
                                </select>
                            </label>

                            <label class="pos-control block">
                                <span class="pos-control-label text-xs font-semibold uppercase tracking-wide text-slate-500">📂 {{ $tr('Catégorie') }}</span>
                                <select class="pos-category-filter mt-2 h-[44px] w-full rounded-lg border border-slate-200 bg-white px-3 text-sm font-medium transition hover:border-slate-300 focus:border-brand focus:ring-2 focus:ring-brand/20 dark:border-white/10 dark:bg-slate-950 dark:hover:border-white/20" title="Filtrer par catégorie">
                                    <option value="all">{{ $tr('Toutes les catégories') }}</option>
                                    @foreach ($categories as $category)
                                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                                    @endforeach
                                </select>
                            </label>

                            <label class="pos-control block">
                                <span class="pos-control-label text-xs font-semibold uppercase tracking-wide text-slate-500">🏢 {{ $tr('Marque / Éditeur') }}</span>
                                <select class="pos-brand-filter mt-2 h-[44px] w-full rounded-lg border border-slate-200 bg-white px-3 text-sm font-medium transition hover:border-slate-300 focus:border-brand focus:ring-2 focus:ring-brand/20 dark:border-white/10 dark:bg-slate-950 dark:hover:border-white/20" title="Filtrer par marque ou éditeur">
                                    <option value="all">{{ $tr('Toutes les marques / éditeurs') }}</option>
                                    @foreach ($brands as $brand)
                                        <option value="{{ $brand->id }}">{{ $brand->name }}</option>
                                    @endforeach
                                </select>
                            </label>

                            <label class="pos-control block">
                                <span class="pos-control-label text-xs font-semibold uppercase tracking-wide text-slate-500">📏 {{ $tr('Unité') }}</span>
                                <select class="pos-unit-filter mt-2 h-[44px] w-full rounded-lg border border-slate-200 bg-white px-3 text-sm font-medium transition hover:border-slate-300 focus:border-brand focus:ring-2 focus:ring-brand/20 dark:border-white/10 dark:bg-slate-950 dark:hover:border-white/20" title="Filtrer par unité de mesure">
                                    <option value="all">{{ $tr('Tous les types') }}</option>
                                    @foreach ($units as $unit)
                                        <option value="{{ $unit->id }}">{{ $unit->name }}</option>
                                    @endforeach
                                </select>
                            </label>

                            <div class="flex items-end">
                                <button class="pos-clear-filters w-full h-[44px] rounded-lg border border-slate-300 bg-white px-3 text-sm font-bold text-slate-600 shadow-sm transition hover:border-rose-300 hover:text-rose-600 hover:bg-rose-50 active:scale-95 dark:border-white/10 dark:bg-slate-950 dark:text-slate-300 dark:hover:bg-rose-500/10" type="button" title="{{ $tr('Effacer tous les filtres') }}">
                                    ✕ {{ $tr('Effacer les filtres') }}
                                </button>
                            </div>
                        </div>
                    </details>
                </div>
            </details>

            @if ($heldTickets->isNotEmpty())
                <details id="tickets-en-attente" class="pos-held-tickets rounded-xl border border-amber-200 bg-amber-50/70 shadow-sm dark:border-amber-500/20 dark:bg-amber-500/10">
                    <summary class="pos-held-summary flex cursor-pointer list-none items-center justify-between gap-3 p-4">
                        <span class="min-w-0">
                            <span class="block font-semibold">{{ $tr('Tickets en attente') }}</span>
                            <span class="mt-0.5 block truncate text-sm text-slate-600 dark:text-slate-300">{{ $tr('Reprendre un panier gardé pour un client qui revient plus tard.') }}</span>
                        </span>
                        <span class="flex shrink-0 items-center gap-2">
                            <span class="pos-held-count rounded-full px-2.5 py-1 text-xs font-bold">{{ $heldTickets->count() }} {{ $tr('ouvert(s)') }}</span>
                            <span class="pos-held-chevron grid size-8 place-items-center rounded-lg border border-amber-200 bg-white text-sm font-bold text-amber-700 dark:border-amber-500/20 dark:bg-slate-950/60">⌄</span>
                        </span>
                    </summary>
                    <div class="pos-held-scroll grid gap-2 border-t border-amber-200 p-3 dark:border-amber-500/20">
                        @foreach ($heldTickets as $ticket)
                            <div class="pos-held-card rounded-lg border border-amber-200 bg-white p-3 dark:border-amber-500/20 dark:bg-slate-950/60">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <p class="font-semibold">{{ $ticket->number }}</p>
                                        <p class="mt-0.5 truncate text-xs text-slate-500">{{ $ticket->contact?->name ?? $tr('Client comptoir') }} · {{ $ticket->held_at?->format('H:i') }}</p>
                                    </div>
                                    <strong class="shrink-0 text-sm">{{ $money($ticket->total_amount) }}</strong>
                                </div>
                                <div class="pos-held-actions mt-3 grid grid-cols-3 gap-1.5">
                                    <a href="{{ route('pos', ['ticket' => $ticket->id]) }}" class="rounded-lg bg-brand px-2 py-2 text-center text-xs font-semibold text-white">{{ $tr('Reprendre') }}</a>
                                    <button class="rounded-lg border border-slate-200 px-2 py-2 text-xs font-semibold dark:border-white/10" type="button" onclick="document.getElementById('held-ticket-{{ $ticket->id }}').showModal()">{{ $tr('Détail') }}</button>
                                    <form action="{{ route('pos.tickets.destroy', $ticket) }}" method="POST" class="min-w-0">
                                        @csrf
                                        @method('DELETE')
                                        <button class="w-full rounded-lg border border-slate-200 px-2 py-2 text-xs font-semibold dark:border-white/10" type="submit">{{ $tr('Annuler') }}</button>
                                    </form>
                                </div>
                            </div>
                            <dialog id="held-ticket-{{ $ticket->id }}" class="w-full max-w-xl rounded-2xl border border-slate-200 bg-white p-0 text-slate-950 shadow-2xl backdrop:bg-slate-950/40 dark:border-white/10 dark:bg-slate-950 dark:text-slate-100">
                                <div class="border-b border-slate-200 p-5 dark:border-white/10">
                                    <div class="flex items-start justify-between gap-4">
                                        <div>
                                            <p class="text-sm font-semibold text-amber-700">{{ $tr('Ticket en attente') }}</p>
                                            <h3 class="mt-1 text-xl font-semibold">{{ $ticket->number }} · {{ $money($ticket->total_amount) }}</h3>
                                            <p class="mt-1 text-sm text-slate-500">{{ $ticket->contact?->name ?? $tr('Client comptoir') }} · {{ $ticket->held_at?->format('d/m/Y H:i') }}</p>
                                        </div>
                                        <button class="dialog-close grid size-9 place-items-center rounded-lg border border-slate-200 text-lg font-semibold dark:border-white/10" type="button">×</button>
                                    </div>
                                </div>
                                <div class="space-y-3 p-5">
                                    <div class="overflow-hidden rounded-xl border border-slate-200 dark:border-white/10">
                                        @foreach ($ticket->cart as $line)
                                            @php($ticketItem = $heldTicketItems->get($line['item_id'] ?? null))
                                            @php($ticketLinePrice = (float) ($line['unit_price'] ?? $ticketItem?->sale_price ?? 0))
                                            <div class="grid grid-cols-[1fr_70px_100px] gap-3 border-b border-slate-200 px-3 py-2 text-sm last:border-b-0 dark:border-white/10">
                                                <span class="font-medium">{{ $ticketItem?->title ?? 'Article supprimé' }}</span>
                                                <span class="text-center">x{{ $line['quantity'] ?? 1 }}</span>
                                                <strong class="text-right">{{ $money($ticketLinePrice * ($line['quantity'] ?? 1)) }}</strong>
                                            </div>
                                        @endforeach
                                    </div>
                                    <div class="grid gap-2 sm:grid-cols-3">
                                        <a href="{{ route('pos', ['ticket' => $ticket->id]) }}" class="rounded-lg bg-brand px-4 py-2 text-center text-sm font-semibold text-white">{{ $tr('Reprendre') }}</a>
                                        <form action="{{ route('pos.tickets.destroy', $ticket) }}" method="POST">
                                            @csrf
                                            @method('DELETE')
                                            <button class="w-full rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold dark:border-white/10" type="submit">{{ $tr('Annuler') }}</button>
                                        </form>
                                        <button class="dialog-close rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold dark:border-white/10" type="button">{{ $tr('Fermer') }}</button>
                                    </div>
                                </div>
                            </dialog>
                        @endforeach
                    </div>
                </details>
            @endif

            <article class="pos-catalog-panel rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                <details class="pos-suggestions-menu mb-3 rounded-xl border border-slate-200 bg-slate-50 dark:border-white/10 dark:bg-white/5" data-collapsible-menu data-menu-key="pos-suggestions">
                    <summary class="pos-suggestions-summary flex cursor-pointer list-none items-center justify-between gap-3 p-3">
                        <span class="min-w-0">
                            <span class="block font-semibold">{{ $tr('Suggestions caisse') }}</span>
                            <span class="mt-1 block truncate text-sm text-slate-500"><span class="pos-visible-count">{{ $items->count() }}</span> {{ $tr('résultat(s).') }} <span class="pos-search-state">{{ $tr('Favoris et plus vendus.') }}</span></span>
                        </span>
                        <span class="flex shrink-0 items-center gap-2">
                            <em data-collapsible-menu-state class="rounded-lg bg-brand/10 px-2.5 py-1 text-xs font-bold not-italic text-brand">{{ $tr('Afficher') }}</em>
                            <span class="pos-suggestions-chevron grid size-8 place-items-center rounded-lg border border-slate-200 bg-white text-sm font-bold text-slate-600 dark:border-white/10 dark:bg-slate-950">⌄</span>
                        </span>
                    </summary>
                    <div class="flex flex-wrap items-center gap-2 border-t border-slate-200 p-3 text-xs font-semibold text-slate-500 dark:border-white/10">
                            <div class="pos-suggestion-toggle inline-flex rounded-lg border border-slate-200 bg-white p-1 dark:border-white/10">
                                <button class="pos-suggestion-btn px-2.5 py-1 is-active" data-suggest="all" type="button">{{ $tr('Tous') }}</button>
                                <button class="pos-suggestion-btn px-2.5 py-1" data-suggest="favorites" type="button">{{ $tr('Favoris') }}</button>
                                <button class="pos-suggestion-btn px-2.5 py-1" data-suggest="top" type="button">{{ $tr('Plus vendus') }}</button>
                            </div>
                            <div class="pos-view-toggle inline-flex rounded-lg border border-slate-200 bg-white p-1 dark:border-white/10">
                                <button class="pos-view-btn px-2.5 py-1" data-view="list" type="button">{{ $tr('Liste') }}</button>
                                <button class="pos-view-btn px-2.5 py-1" data-view="compact" type="button">{{ $tr('Petit') }}</button>
                                <button class="pos-view-btn px-2.5 py-1" data-view="medium" type="button">{{ $tr('Moyen') }}</button>
                            </div>
                            <label class="flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-2.5 py-1 dark:border-white/10">Colonnes <input class="pos-grid-columns w-20 accent-[var(--brand-primary)]" type="range" min="2" max="6" value="4"></label>
                    </div>
                </details>

                <div class="pos-products grid max-h-[calc(100vh-300px)] min-h-[420px] gap-2 overflow-y-auto pr-1" data-view="compact" style="--pos-columns: 4;">
                    @foreach ($items as $item)
                        @php($image = collect($item->images)->first())
                        @php($isOutOfStock = $item->type !== 'service' && $item->stock_quantity <= 0)
                        @php($isSellable = $allowOversell || $item->type === 'service' || ! $isOutOfStock)
                            <article class="pos-product pos-item pos-product-card rounded-xl border border-slate-200 bg-white p-4 text-left shadow-sm transition hover:-translate-y-0.5 hover:border-brand hover:shadow-md dark:border-white/10 dark:bg-white/[0.03] {{ $isSellable ? '' : 'opacity-60 grayscale hover:translate-y-0 hover:border-rose-200 hover:shadow-sm dark:hover:border-rose-500/30' }}"
                            role="button"
                            tabindex="0"
                            aria-disabled="{{ $isSellable ? 'false' : 'true' }}"
                            data-id="{{ $item->id }}"
                            data-name="{{ $item->title }}"
                            data-price="{{ $item->sale_price }}"
                            data-stock="{{ $item->type === 'service' ? 999999 : $item->stock_quantity }}"
                            data-sellable="{{ $isSellable ? '1' : '0' }}"
                            data-stock-url="{{ route('catalog', ['panel' => 'stock-adjustment-add', 'stock_q' => $item->barcode ?? $item->isbn ?? $item->item_code ?? $item->title]) }}"
                            data-low-threshold="{{ $item->min_stock_threshold }}"
                            data-type="{{ $item->type }}"
                            data-category-id="{{ $item->category_id ?? '' }}"
                            data-brand-id="{{ $item->brand_id ?? '' }}"
                            data-unit-id="{{ $item->unit_id ?? '' }}"
                            data-sold="{{ (int) ($topSold[$item->id] ?? 0) }}"
                            data-barcode="{{ $item->barcode ?? $item->isbn ?? $item->sku ?? $item->custom_barcode1 }}"
                            data-search="{{ Str::lower($item->title.' '.$item->barcode.' '.$item->isbn.' '.$item->sku.' '.$item->custom_barcode1.' '.$item->item_code.' '.$item->category?->name.' '.$item->brand?->name.' '.$item->unit?->name) }}">
                            <div class="pos-product-top flex items-start justify-between gap-3">
                                @if ($image)
                                    <img src="{{ asset('storage/'.$image) }}" alt="" class="size-12 rounded-lg object-cover">
                                @else
                                    <div class="grid size-12 place-items-center rounded-lg bg-slate-100 text-sm font-bold text-slate-500 dark:bg-white/10">{{ mb_substr($item->title, 0, 2) }}</div>
                                @endif
                                <x-status-pill :tone="$isOutOfStock ? 'danger' : ($item->type === 'service' ? 'info' : ($item->is_low_stock ? 'warning' : 'success'))">
                                    {{ $isOutOfStock ? $tr('Rupture') : ($item->type === 'service' ? $tr('Service') : $item->stock_quantity) }}
                                </x-status-pill>
                            </div>
                            <div class="pos-product-name mt-3 flex items-start gap-2">
                                <button class="pos-favorite-star text-base text-slate-300" data-product-id="{{ $item->id }}" type="button" aria-label="{{ $tr('Basculer favori') }}" title="{{ $tr('Basculer favori') }}">★</button>
                                <p class="line-clamp-2 min-h-10 text-sm font-semibold">{{ $item->title }}</p>
                            </div>
                            <p class="pos-product-meta mt-2 truncate text-xs text-slate-500">{{ $item->category?->name ?? $tr('Sans catégorie') }} · {{ $item->barcode ?? $item->isbn ?? $item->sku ?? $tr('Sans code') }}</p>
                            @unless ($isSellable)
                                <p class="mt-2 rounded-lg bg-rose-50 px-2 py-1 text-xs font-semibold text-rose-700 ring-1 ring-rose-100 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/20">{{ $tr('Non vendable · cliquer pour gérer le stock') }}</p>
                            @endunless
                            <div class="pos-product-footer mt-3 flex items-center justify-between gap-2">
                                <p class="text-lg font-semibold">{{ $money($item->sale_price) }}</p>
                                @if (($topSold[$item->id] ?? 0) > 0)
                                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-500 dark:bg-white/10">{{ (int) $topSold[$item->id] }} {{ $tr('vendus') }}</span>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
                <div class="pos-empty-products hidden rounded-xl border border-dashed border-slate-200 p-10 text-center text-sm text-slate-500 dark:border-white/10">{{ $tr('Aucun produit ne correspond à cette recherche.') }}</div>
            </article>
        </div>

        <aside class="pos-checkout-shell xl:sticky xl:top-20 xl:h-[calc(100vh-6rem)]">
            <form id="pos-checkout-form" action="{{ route('pos.store') }}" method="POST" class="grid h-full min-h-0 gap-3 xl:grid-rows-[minmax(320px,1fr)_auto]" data-pos-checkout>
                @csrf
                <input class="pos-cart-json" name="cart" type="hidden" value="[]">
                <input name="ticket_id" type="hidden" value="{{ $resumeTicket?->id }}">
                <input class="pos-receipt-data" type="hidden" value="{{ json_encode(['storeName' => $tenant?->name, 'ticketNumber' => $nextSaleNumber]) }}">

                <section class="pos-checkout-panel grid min-h-0 grid-rows-[auto_minmax(0,1fr)] rounded-xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                    <div class="pos-cart-header relative border-b border-slate-200 p-4 dark:border-white/10">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h2 class="text-xl font-black leading-tight">{{ $tr('Panier') }}</h2>
                                    <span class="pos-client-summary hidden max-w-full rounded-full bg-brand/10 px-2.5 py-1 text-[11px] font-bold text-brand"></span>
                                </div>
                                <p class="mt-1 text-sm text-slate-500"><span class="pos-cart-count">0</span> ligne(s), panier en cours</p>
                            </div>
                            <div class="pos-checkout-actions flex shrink-0 items-start gap-2">
                                <details class="pos-actions-menu relative">
                                    <summary class="pos-actions-trigger">
                                        <span class="text-base leading-none">⚡</span>
                                        <span>{{ $tr('Actions') }}</span>
                                        <span class="pos-actions-chevron text-xs">⌄</span>
                                    </summary>
                                    <div class="pos-actions-dropdown">
                                        <button class="pos-action-item pos-client-toggle" data-pos-panel-toggle="client" type="button" aria-expanded="false">
                                            <span class="pos-action-icon">👤</span>
                                            <span class="min-w-0 flex-1">
                                                <strong>{{ $tr('Client') }}</strong>
                                                <small class="pos-action-client-label">{{ $tr('Client comptoir') }}</small>
                                            </span>
                                        </button>
                                        <button class="pos-action-item pos-discount-toggle" data-pos-panel-toggle="discount" type="button" aria-expanded="false">
                                            <span class="pos-action-icon">−</span>
                                            <span class="min-w-0 flex-1">
                                                <strong>{{ $tr('Remise') }}</strong>
                                                <small><span class="pos-discount-summary-value hidden"></span><span class="pos-discount-empty">{{ $tr('Fixe ou %') }}</span></small>
                                            </span>
                                        </button>
                                        <button class="pos-action-item pos-coupon-toggle" data-pos-panel-toggle="coupon" type="button" aria-expanded="false">
                                            <span class="pos-action-icon">%</span>
                                            <span class="min-w-0 flex-1">
                                                <strong>{{ $tr('Coupon') }}</strong>
                                                <small><span class="pos-coupon-summary-code hidden uppercase"></span><span class="pos-coupon-empty">{{ $tr('Code promo') }}</span></small>
                                            </span>
                                        </button>
                                        <button class="pos-action-item pos-note-toggle" data-pos-panel-toggle="note" type="button" aria-expanded="false">
                                            <span class="pos-action-icon">✎</span>
                                            <span class="min-w-0 flex-1">
                                                <strong>{{ $tr('Note') }}</strong>
                                                <small><span class="pos-note-summary-value hidden"></span><span class="pos-note-empty">{{ $tr('Observation ticket') }}</span></small>
                                            </span>
                                        </button>
                                    </div>
                                </details>
                                <button class="pos-clear pos-icon-action text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-500/10" type="button">
                                    <span aria-hidden="true">🗑</span>
                                    <span class="hidden sm:inline">{{ $tr('Vider') }}</span>
                                </button>
                            </div>
                        </div>
                        <div class="pos-adjustment-popovers pointer-events-none absolute inset-x-4 top-[calc(100%-0.25rem)] z-40">
                            <div class="pos-adjustment-popover pos-client-panel pointer-events-auto ml-auto hidden w-full max-w-[430px] rounded-xl border border-slate-200 bg-white p-3 text-slate-950 shadow-2xl dark:border-white/10 dark:bg-slate-950 dark:text-slate-100" data-pos-panel="client">
                                <div class="mb-3 flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <p class="text-sm font-black">{{ $tr('Client du ticket') }}</p>
                                        <p class="pos-client-current mt-0.5 truncate text-xs text-slate-500">{{ $tr('Client comptoir') }}</p>
                                    </div>
                                    <button class="pos-panel-close grid size-8 place-items-center rounded-lg border border-slate-200 text-sm font-black dark:border-white/10" data-pos-panel-close type="button">×</button>
                                </div>
                                <label class="block">
                                    <span class="text-[11px] font-bold uppercase text-slate-500">{{ $tr('Sélectionner un client') }}</span>
                                    <select form="pos-checkout-form" name="contact_id" class="pos-client mt-1 h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-900 outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/20 dark:border-white/10 dark:bg-slate-900 dark:text-slate-100">
                                        <option value="" data-advance="0">{{ $tr('Client comptoir') }}</option>
                                        @foreach ($clients as $client)
                                            <option value="{{ $client->id }}" data-advance="{{ $client->advance_balance }}" @selected($resumeTicket?->contact_id === $client->id)>{{ $client->name }} · avance {{ $money($client->advance_balance) }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <div class="pos-client-info mt-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600 dark:border-white/10 dark:bg-white/5 dark:text-slate-300">
                                    <span class="font-semibold">{{ $tr('Client comptoir') }}</span>
                                    <span class="mt-0.5 block">{{ $tr('Ticket rapide sans compte client.') }}</span>
                                </div>
                                <details class="pos-quick-client mt-3 rounded-lg border border-slate-200 bg-white px-3 py-2 dark:border-white/10 dark:bg-slate-900/60">
                                    <summary class="flex cursor-pointer list-none items-center justify-between gap-2 text-xs font-bold uppercase text-slate-500">
                                        <span>{{ $tr('Créer un client rapide') }}</span>
                                        <span class="text-base leading-none">+</span>
                                    </summary>
                                    <div class="mt-3 grid gap-2 sm:grid-cols-2">
                                        <input form="pos-checkout-form" name="client_name" class="h-10 rounded-lg border border-slate-200 bg-white px-3 text-sm text-slate-900 outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/20 dark:border-white/10 dark:bg-slate-950 dark:text-slate-100" placeholder="Nouveau client">
                                        <input form="pos-checkout-form" name="client_phone" class="h-10 rounded-lg border border-slate-200 bg-white px-3 text-sm text-slate-900 outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/20 dark:border-white/10 dark:bg-slate-950 dark:text-slate-100" placeholder="Téléphone">
                                    </div>
                                </details>
                            </div>
                            <div class="pos-adjustment-popover pos-discount-panel pointer-events-auto ml-auto hidden w-full max-w-[400px] rounded-xl border border-slate-200 bg-white p-3 text-slate-950 shadow-2xl dark:border-white/10 dark:bg-slate-950 dark:text-slate-100" data-pos-panel="discount">
                                <div class="mb-2 flex items-center justify-between gap-2">
                                    <div><p class="text-sm font-black">{{ $tr('Remise') }}</p><p class="text-xs text-slate-500">{{ $tr('Montant fixe ou pourcentage.') }}</p></div>
                                    <button class="pos-panel-close grid size-8 place-items-center rounded-lg border border-slate-200 text-sm font-black dark:border-white/10" data-pos-panel-close type="button">×</button>
                                </div>
                                <div class="grid grid-cols-[86px_minmax(0,1fr)] gap-2">
                                    <select class="pos-discount-type-draft h-11 rounded-lg border border-slate-200 bg-white px-2 text-sm font-bold text-slate-700 dark:border-white/10 dark:bg-slate-900 dark:text-slate-100">
                                        <option value="fixed" @selected($resumeDiscountType === 'fixed')>DH</option>
                                        <option value="percentage" @selected(in_array($resumeDiscountType, ['percentage', 'percent'], true))>%</option>
                                    </select>
                                    <input value="{{ $resumeDiscountValue }}" min="0" step="0.01" type="number" class="pos-discount-draft h-11 w-full rounded-lg border border-slate-200 px-3 text-base font-semibold dark:border-white/10 dark:bg-slate-900" placeholder="0">
                                    <input name="discount_type" value="{{ in_array($resumeDiscountType, ['percentage', 'percent'], true) ? 'percentage' : 'fixed' }}" type="hidden" class="pos-discount-type-value">
                                    <input name="discount_value" value="{{ $resumeDiscountValue }}" type="hidden" class="pos-discount-value">
                                    <input name="discount_amount" value="0" type="hidden" class="pos-discount-amount">
                                </div>
                                <span class="pos-discount-helper mt-2 block text-xs font-semibold text-slate-500">{{ $tr('Fixe en DH') }}</span>
                                <div class="mt-3 grid grid-cols-[1fr_1.35fr] gap-2">
                                    <button class="pos-discount-reset rounded-lg border border-slate-200 px-3 py-2 text-xs font-bold dark:border-white/10" type="button">{{ $tr('Réinitialiser') }}</button>
                                    <button class="pos-discount-confirm rounded-lg bg-brand px-3 py-2 text-xs font-bold text-white" type="button">{{ $tr('Confirmer remise') }}</button>
                                </div>
                            </div>
                            <div class="pos-adjustment-popover pos-coupon-panel pos-coupon-body pointer-events-auto ml-auto hidden w-full max-w-[400px] rounded-xl border border-slate-200 bg-white p-3 text-slate-950 shadow-2xl dark:border-white/10 dark:bg-slate-950 dark:text-slate-100" data-pos-panel="coupon">
                                <div class="mb-2 flex items-center justify-between gap-2">
                                    <div><p class="text-sm font-black">{{ $tr('Coupon') }}</p><p class="text-xs text-slate-500">{{ $tr('Vérifiez le code avant d’encaisser.') }}</p></div>
                                    <button class="pos-panel-close grid size-8 place-items-center rounded-lg border border-slate-200 text-sm font-black dark:border-white/10" data-pos-panel-close type="button">×</button>
                                </div>
                                <div class="grid gap-2 sm:grid-cols-[minmax(0,1fr)_auto]">
                                    <label class="block"><span class="text-[11px] font-bold uppercase text-slate-500">{{ $tr('Code coupon') }}</span><input name="coupon_code" value="{{ $resumeCouponCode }}" class="pos-coupon-code mt-1 h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm font-bold uppercase tracking-wide dark:border-white/10 dark:bg-slate-900" placeholder="RENTREE10"></label>
                                    <button class="pos-apply-coupon h-11 rounded-lg border border-slate-200 bg-white px-4 text-xs font-bold text-slate-700 transition hover:border-brand hover:text-brand disabled:cursor-wait disabled:opacity-60 dark:border-white/10 dark:bg-slate-950 dark:text-slate-200 sm:mt-5" type="button">{{ $tr('Appliquer') }}</button>
                                </div>
                                <p class="pos-coupon-message mt-2 text-xs font-semibold text-slate-500">{{ $tr('Saisissez un code coupon si le client en possède un.') }}</p>
                            </div>
                            <div class="pos-adjustment-popover pos-note-panel pointer-events-auto ml-auto hidden w-full max-w-[420px] rounded-xl border border-slate-200 bg-white p-3 text-slate-950 shadow-2xl dark:border-white/10 dark:bg-slate-950 dark:text-slate-100" data-pos-panel="note">
                                <div class="mb-2 flex items-center justify-between gap-2">
                                    <div><p class="text-sm font-black">{{ $tr('Note ticket') }}</p><p class="text-xs text-slate-500">{{ $tr('Note manuelle visible dans le détail de vente.') }}</p></div>
                                    <button class="pos-panel-close grid size-8 place-items-center rounded-lg border border-slate-200 text-sm font-black dark:border-white/10" data-pos-panel-close type="button">×</button>
                                </div>
                                <label class="block">
                                    <span class="text-[11px] font-bold uppercase text-slate-500">{{ $tr('Note manuelle') }}</span>
                                    <textarea maxlength="500" class="pos-note-draft mt-1 min-h-24 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-900 outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/20 dark:border-white/10 dark:bg-slate-900 dark:text-slate-100" placeholder="Ex: client demande livraison après 18h, emballage cadeau, observation comptoir...">{{ $resumeNote }}</textarea>
                                    <input name="note" value="{{ $resumeNote }}" type="hidden" class="pos-note-value">
                                </label>
                                <div class="mt-3 grid grid-cols-[1fr_1.35fr] gap-2">
                                    <button class="pos-note-reset rounded-lg border border-slate-200 px-3 py-2 text-xs font-bold dark:border-white/10" type="button">{{ $tr('Effacer') }}</button>
                                    <button class="pos-note-confirm rounded-lg bg-brand px-3 py-2 text-xs font-bold text-white" type="button">{{ $tr('Confirmer note') }}</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="pos-cart-area min-h-0 overflow-y-auto bg-slate-50/70 p-3 dark:bg-slate-900/40">
                        <div class="pos-cart space-y-2"></div>
                        <div class="pos-empty rounded-lg border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-500 dark:border-white/10 dark:bg-white/[0.03]">{{ $tr('Scannez un article pour commencer.') }}</div>
                    </div>
                </section>

                <section class="pos-payment-footer rounded-xl border border-slate-200 bg-white p-2.5 shadow-sm dark:border-white/10 dark:bg-slate-950">
                    <div class="grid grid-cols-2 gap-x-3 gap-y-0.5 text-xs">
                        <div class="flex justify-between text-slate-500"><span>{{ $tr('Sous-total') }}</span><span class="pos-subtotal">0,00 DH</span></div>
                        <div class="flex justify-between text-slate-500"><span>TVA</span><span class="pos-tax">0,00 DH</span></div>
                        <div class="flex justify-between text-slate-500"><span>{{ $tr('Coupon') }}</span><span class="pos-coupon-label">0,00 DH</span></div>
                        <div class="flex justify-between text-slate-500"><span>{{ $tr('Remise') }}</span><span class="pos-discount-label">0,00 DH</span></div>
                        <div class="flex justify-between text-slate-500"><span>{{ $tr('Monnaie') }}</span><span class="pos-change">0,00 DH</span></div>
                        <div class="col-span-2 flex justify-between text-base font-semibold"><span>{{ $tr('Total') }}</span><span class="pos-total">0,00 DH</span></div>
                        <div class="col-span-2 flex justify-between text-xs font-semibold"><span>{{ $tr('Reste à payer') }}</span><span class="pos-remaining text-rose-600">0,00 DH</span></div>
                    </div>

                    <div class="mt-2 grid grid-cols-2 gap-1.5 sm:grid-cols-4">
                        <label class="pos-money-field block rounded-xl border border-slate-200 px-3 py-2 dark:border-white/10"><span class="text-[11px] font-bold uppercase text-slate-500">{{ $tr('Espèces') }}</span><input name="cash_amount" min="0" step="0.01" type="number" class="pos-payment h-8 w-full border-0 px-0 text-base font-bold" placeholder="0"></label>
                        <label class="pos-money-field block rounded-xl border border-slate-200 px-3 py-2 dark:border-white/10"><span class="text-[11px] font-bold uppercase text-slate-500">{{ $tr('Carte') }}</span><input name="card_amount" min="0" step="0.01" type="number" class="pos-payment h-8 w-full border-0 px-0 text-base font-bold" placeholder="0"></label>
                        <label class="pos-money-field block rounded-xl border border-slate-200 px-3 py-2 dark:border-white/10"><span class="text-[11px] font-bold uppercase text-slate-500">{{ $tr('Virement') }}</span><input name="transfer_amount" min="0" step="0.01" type="number" class="pos-payment h-8 w-full border-0 px-0 text-base font-bold" placeholder="0"></label>
                        <label class="pos-money-field block rounded-xl border border-slate-200 px-3 py-2 dark:border-white/10"><span class="text-[11px] font-bold uppercase text-slate-500">{{ $tr('Avance') }}</span><input name="advance_amount" min="0" step="0.01" type="number" class="pos-payment h-8 w-full border-0 px-0 text-base font-bold" placeholder="0"></label>
                    </div>

                    <div class="mt-2 grid grid-cols-3 gap-1.5">
                        <button class="pos-fill-cash rounded-lg border border-slate-200 px-2 py-1.5 text-xs font-semibold dark:border-white/10" type="button">{{ $tr('Espèces') }}</button>
                        <button class="pos-fill-card rounded-lg border border-slate-200 px-2 py-1.5 text-xs font-semibold dark:border-white/10" type="button">{{ $tr('Carte') }}</button>
                        <button class="pos-exact-split rounded-lg border border-slate-200 px-2 py-1.5 text-xs font-semibold dark:border-white/10" type="button">50/50</button>
                    </div>

                    <div class="mt-2 grid grid-cols-[1fr_1.5fr] gap-2">
                        <button class="pos-hold-submit rounded-lg border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm font-bold text-amber-700 transition hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-50" type="submit" formaction="{{ route('pos.tickets.store') }}">
                            {{ $tr('Mettre en attente') }}
                        </button>
                        <button class="pos-submit rounded-lg bg-brand px-4 py-2.5 text-base font-bold text-white shadow-sm transition hover:brightness-110 disabled:cursor-not-allowed disabled:opacity-50" type="submit" disabled>
                            <span class="pos-submit-label">{{ $tr('Ajouter un paiement') }}</span>
                        </button>
                    </div>
                    <div class="mt-2 flex gap-2">
                        <button class="pos-drawer-kick hidden flex-1 rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-600 transition hover:border-brand hover:text-brand dark:border-white/10 dark:bg-white/5 dark:text-slate-200" type="button">
                            🗄️ {{ $tr('Ouvrir tiroir') }}
                        </button>
                    </div>
                </section>
            </form>
        </aside>

        <dialog class="app-dialog pos-item-dialog w-[min(540px,calc(100vw-2rem))] rounded-2xl border border-slate-200 bg-white p-0 text-slate-950 shadow-2xl backdrop:bg-slate-950/45 dark:border-white/10 dark:bg-slate-950 dark:text-slate-100">
            <div class="border-b border-slate-200 p-5 dark:border-white/10">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-brand">Détail ligne caisse</p>
                        <h2 class="pos-dialog-title mt-1 truncate text-xl font-semibold">Article</h2>
                        <p class="pos-dialog-meta mt-1 text-sm text-slate-500">Code · stock</p>
                    </div>
                    <button class="dialog-close grid size-9 place-items-center rounded-lg border border-slate-200 text-lg font-semibold dark:border-white/10" type="button">×</button>
                </div>
            </div>
            <div class="grid gap-4 p-5">
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block">
                        <span class="text-xs font-semibold uppercase text-slate-500">Quantité</span>
                        <input class="pos-dialog-quantity mt-1 h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" type="number" min="1" step="1" value="1">
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold uppercase text-slate-500">Prix de vente</span>
                        <input class="pos-dialog-price mt-1 h-11 w-full rounded-lg border border-slate-200 px-3 text-sm font-semibold dark:border-white/10 dark:bg-slate-900" type="number" min="0" step="0.01" @disabled(! $priceEditable)>
                    </label>
                </div>
                @unless ($priceEditable)
                    <p class="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500 dark:bg-white/5">Le prix est verrouillé par les paramètres de caisse. Activez “Prix modifiable” dans Paramètres pour autoriser les changements au comptoir.</p>
                @endunless
                <label class="block">
                    <span class="text-xs font-semibold uppercase text-slate-500">Note ligne</span>
                    <textarea class="pos-dialog-note mt-1 min-h-20 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-white/10 dark:bg-slate-900" maxlength="160" placeholder="Ex: remise manuelle, édition spéciale..."></textarea>
                </label>
                <div class="grid gap-2 sm:grid-cols-[1fr_1.4fr]">
                    <button class="dialog-close rounded-lg border border-slate-200 px-4 py-3 text-sm font-semibold dark:border-white/10" type="button">Annuler</button>
                    <button class="pos-dialog-save rounded-lg bg-brand px-4 py-3 text-sm font-bold text-white" type="button">Appliquer à la caisse</button>
                </div>
            </div>
        </dialog>

        <dialog class="app-dialog pos-stock-dialog w-[min(520px,calc(100vw-2rem))] rounded-2xl border border-slate-200 bg-white p-0 text-slate-950 shadow-2xl backdrop:bg-slate-950/45 dark:border-white/10 dark:bg-slate-950 dark:text-slate-100">
            <div class="border-b border-slate-200 p-5 dark:border-white/10">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-rose-600 dark:text-rose-300">Article non disponible</p>
                        <h2 class="pos-stock-dialog-title mt-1 truncate text-xl font-semibold">Article</h2>
                        <p class="pos-stock-dialog-meta mt-1 text-sm text-slate-500">Stock à vérifier</p>
                    </div>
                    <button class="dialog-close grid size-9 place-items-center rounded-lg border border-slate-200 text-lg font-semibold dark:border-white/10" type="button">×</button>
                </div>
            </div>
            <div class="grid gap-4 p-5">
                <div class="rounded-xl bg-rose-50 p-4 text-sm text-black ring-1 dark:ring-rose-500/20">
                    Cet article est visible dans la caisse, mais le stock disponible est à zéro. Il ne peut pas être ajouté au panier tant que la vente hors stock est désactivée.
                </div>
                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="rounded-lg bg-slate-50 p-3 dark:bg-white/5"><span class="text-xs font-semibold uppercase text-slate-500">Stock</span><p class="pos-stock-dialog-stock mt-1 text-lg font-bold">0</p></div>
                    <div class="rounded-lg bg-slate-50 p-3 dark:bg-white/5"><span class="text-xs font-semibold uppercase text-slate-500">Code</span><p class="pos-stock-dialog-code mt-1 truncate text-sm font-semibold">—</p></div>
                    <div class="rounded-lg bg-slate-50 p-3 dark:bg-white/5"><span class="text-xs font-semibold uppercase text-slate-500">Prix</span><p class="pos-stock-dialog-price mt-1 text-sm font-semibold">—</p></div>
                </div>
                <div class="grid gap-2 sm:grid-cols-[1fr_1.4fr]">
                    <button class="dialog-close rounded-lg border border-slate-200 px-4 py-3 text-sm font-semibold dark:border-white/10" type="button">Fermer</button>
                    <a class="pos-stock-dialog-link rounded-lg bg-brand px-4 py-3 text-center text-sm font-bold text-white" href="{{ route('catalog', ['panel' => 'stock-adjustment-add']) }}">Ajuster le stock</a>
                </div>
            </div>
        </dialog>
    </section>
</x-layouts.app>
