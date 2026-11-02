@php
    $money = fn ($v) => \App\Support\Money::for($tenant)->format($v);
    $statusLabels = [
        'requested' => ['À confirmer', 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300'],
        'booked' => ['Réservé', 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300'],
        'arrived' => ['Arrivé', 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300'],
        'done' => ['Terminé', 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300'],
        'no_show' => ['Absent', 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300'],
        'cancelled' => ['Annulé', 'bg-slate-100 text-slate-500 dark:bg-white/10 dark:text-slate-400'],
    ];
    $columns = $staff->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values()->all();
    if ($appointments->contains(fn ($a) => $a->staff_user_id === null)) {
        $columns[] = ['id' => null, 'name' => 'Sans barbier'];
    }
    $upcoming = $appointments->whereIn('status', \App\Models\Appointment::ACTIVE)->count();
    $input = 'h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900';
@endphp
<x-layouts.app :tenant="$tenant" active="appointments" title="Rendez-vous">
    <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-brand">Agenda</p>
                <h1 class="mt-1 text-[1.75rem] font-bold tracking-tight text-slate-950 dark:text-white">Rendez-vous</h1>
                <p class="mt-2 max-w-2xl text-sm text-slate-500 dark:text-slate-400">
                    {{ ucfirst($day->locale('fr')->isoFormat('dddd D MMMM YYYY')) }} · {{ $appointments->count() }} rendez-vous, dont {{ $upcoming }} à venir ou en cours.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('appointments', ['date' => $day->subDay()->toDateString()]) }}" class="inline-flex h-11 items-center rounded-xl border border-slate-200 px-3 text-sm font-semibold dark:border-white/10" aria-label="Jour précédent">‹</a>
                <form method="GET" action="{{ route('appointments') }}">
                    <input type="date" name="date" value="{{ $day->toDateString() }}" onchange="this.form.submit()" class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">
                </form>
                <a href="{{ route('appointments', ['date' => $day->addDay()->toDateString()]) }}" class="inline-flex h-11 items-center rounded-xl border border-slate-200 px-3 text-sm font-semibold dark:border-white/10" aria-label="Jour suivant">›</a>
                <a href="{{ route('appointments') }}" class="inline-flex h-11 items-center rounded-xl border border-slate-200 px-3 text-sm font-semibold dark:border-white/10">Aujourd’hui</a>
                <button type="button" onclick="document.getElementById('booking-settings').showModal()" class="inline-flex h-11 items-center gap-2 rounded-xl border border-slate-200 px-3 text-sm font-semibold dark:border-white/10" data-booking-settings-open>
                    Réservation en ligne
                    <span class="rounded-full px-2 py-0.5 text-xs {{ $booking['enabled'] ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' : 'bg-slate-100 text-slate-500 dark:bg-white/10 dark:text-slate-400' }}">{{ $booking['enabled'] ? 'Activée' : 'Désactivée' }}</span>
                </button>
                <button type="button" onclick="document.getElementById('appointment-create').showModal()" class="inline-flex h-11 items-center gap-2 rounded-xl bg-brand px-4 text-sm font-semibold text-white">+ Nouveau rendez-vous</button>
            </div>
        </div>

        @if (session('status'))
            <p class="mt-4 flex flex-wrap items-center gap-3 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">
                {{ session('status') }}
                @if (session('confirmation_url'))
                    <a href="{{ session('confirmation_url') }}" target="_blank" rel="noopener" class="rounded-lg bg-emerald-600 px-3 py-1 text-xs font-semibold text-white" data-confirmation-whatsapp>Envoyer la confirmation WhatsApp</a>
                @endif
            </p>
        @endif
        @if ($errors->any())
            <p class="mt-4 rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700 dark:bg-rose-500/10 dark:text-rose-300" data-appointment-error>{{ $errors->first() }}</p>
        @endif
    </section>

    @if ($requests->isNotEmpty())
        <section class="mt-6 rounded-2xl border border-amber-200 bg-amber-50/60 p-5 dark:border-amber-500/20 dark:bg-amber-500/[0.06]" data-booking-requests>
            <h2 class="font-semibold text-amber-900 dark:text-amber-200">{{ $requests->count() }} demande{{ $requests->count() > 1 ? 's' : '' }} à confirmer</h2>
            <p class="mt-1 text-sm text-amber-800/80 dark:text-amber-200/70">Envoyées depuis la page de réservation en ligne. Confirmez pour réserver le créneau, puis prévenez le client sur WhatsApp.</p>
            <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($requests as $r)
                    <article class="rounded-xl border border-amber-200 bg-white p-3 dark:border-white/10 dark:bg-slate-900" data-request="{{ $r->id }}">
                        <p class="font-semibold tabular-nums">{{ ucfirst($r->starts_at->setTimezone($tz)->locale('fr')->isoFormat('ddd D MMM')) }} · {{ $r->starts_at->setTimezone($tz)->format('H:i') }}</p>
                        <p class="truncate text-sm">{{ $r->customer_name }} @if ($r->customer_phone)<span class="text-slate-500">· {{ $r->customer_phone }}</span>@endif</p>
                        <p class="truncate text-xs text-slate-500">{{ $r->service_name }}@if ($r->staff) · {{ $r->staff->name }}@endif</p>
                        @if ($r->note)<p class="mt-1 text-xs italic text-slate-500">{{ $r->note }}</p>@endif
                        <div class="mt-3 flex gap-2">
                            @foreach (['booked' => ['Confirmer', 'bg-brand text-white'], 'cancelled' => ['Refuser', 'border border-slate-200 dark:border-white/10']] as $next => [$nextLabel, $tone])
                                <form method="POST" action="{{ route('appointments.status', $r) }}">
                                    @csrf @method('PATCH')
                                    <input type="hidden" name="status" value="{{ $next }}">
                                    <button class="rounded-lg px-3 py-1.5 text-xs font-semibold {{ $tone }}">{{ $nextLabel }}</button>
                                </form>
                            @endforeach
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    @endif

    <section class="mt-6 overflow-x-auto">
        <div class="grid min-w-full gap-4" style="grid-template-columns: repeat({{ max(1, count($columns)) }}, minmax(260px, 1fr));">
            @forelse ($columns as $column)
                @php $mine = $appointments->where('staff_user_id', $column['id']); @endphp
                <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]" data-staff-column="{{ $column['id'] ?? 0 }}">
                    <div class="flex items-center justify-between">
                        <h2 class="font-semibold">{{ $column['name'] }}</h2>
                        <span class="text-xs text-slate-500">{{ $mine->count() }}</span>
                    </div>
                    <div class="mt-3 space-y-3">
                        @forelse ($mine as $a)
                            @php [$label, $tone] = $statusLabels[$a->status] ?? [$a->status, '']; $wa = $whatsapp($a); @endphp
                            <article class="rounded-xl border border-slate-200 p-3 dark:border-white/10 {{ in_array($a->status, ['cancelled', 'no_show'], true) ? 'opacity-60' : '' }}" data-appointment="{{ $a->id }}">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <p class="font-semibold tabular-nums">{{ $a->starts_at->setTimezone($tz)->format('H:i') }}–{{ $a->endsAt()->setTimezone($tz)->format('H:i') }}</p>
                                        <p class="truncate text-sm">{{ $a->customer_name ?? $a->contact?->name }}</p>
                                        @if ($a->service_name)<p class="truncate text-xs text-slate-500">{{ $a->service_name }}</p>@endif
                                    </div>
                                    <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold {{ $tone }}">{{ $label }}</span>
                                </div>
                                @if ($a->contact?->notes)
                                    <p class="mt-2 rounded-lg bg-amber-50 px-2 py-1 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">{{ $a->contact->notes }}</p>
                                @endif
                                @if ($a->note)<p class="mt-1 text-xs italic text-slate-500">{{ $a->note }}</p>@endif
                                <div class="mt-3 flex flex-wrap gap-1.5">
                                    @foreach ($a->status === 'requested' ? ['booked' => 'Confirmer', 'cancelled' => 'Refuser'] : ['arrived' => 'Arrivé', 'done' => 'Terminé', 'no_show' => 'Absent', 'cancelled' => 'Annuler'] as $next => $nextLabel)
                                        @continue($a->status === $next)
                                        @continue($a->status === 'done' && $next !== 'cancelled')
                                        <form method="POST" action="{{ route('appointments.status', $a) }}">
                                            @csrf @method('PATCH')
                                            <input type="hidden" name="status" value="{{ $next }}">
                                            <button class="rounded-lg border border-slate-200 px-2 py-1 text-xs font-semibold dark:border-white/10">{{ $nextLabel }}</button>
                                        </form>
                                    @endforeach
                                    @if ($wa && in_array($a->status, \App\Models\Appointment::ACTIVE, true))
                                        <a href="{{ $wa }}" target="_blank" rel="noopener" class="rounded-lg bg-emerald-600 px-2 py-1 text-xs font-semibold text-white">Rappel WhatsApp</a>
                                    @endif
                                </div>
                            </article>
                        @empty
                            <p class="rounded-xl border border-dashed border-slate-200 p-4 text-center text-sm text-slate-500 dark:border-white/10">Aucun rendez-vous.</p>
                        @endforelse
                    </div>
                </div>
            @empty
                <p class="text-sm text-slate-500">Ajoutez des utilisateurs pour répartir les rendez-vous.</p>
            @endforelse
        </div>
    </section>

    <dialog id="appointment-create" class="w-full max-w-xl rounded-2xl border border-slate-200 bg-white p-0 text-slate-950 shadow-2xl backdrop:bg-slate-950/40 dark:border-white/10 dark:bg-slate-950 dark:text-slate-100">
        <form method="POST" action="{{ route('appointments.store') }}" class="space-y-4 p-5">
            @csrf
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-semibold">Nouveau rendez-vous</h3>
                <button type="button" onclick="this.closest('dialog').close()" class="grid size-9 place-items-center rounded-lg border border-slate-200 dark:border-white/10" aria-label="Fermer">×</button>
            </div>
            <div class="grid gap-3 sm:grid-cols-3">
                <label class="space-y-1.5 sm:col-span-1"><span class="text-xs font-semibold uppercase text-slate-500">Date *</span><input name="starts_at" type="date" required value="{{ old('starts_at', $day->toDateString()) }}" class="{{ $input }}"></label>
                <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Heure *</span><input name="time" type="time" required step="300" value="{{ old('time', '10:00') }}" class="{{ $input }}"></label>
                <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Durée (min)</span><input name="duration_minutes" type="number" min="5" max="600" step="5" value="{{ old('duration_minutes', 30) }}" class="{{ $input }}"></label>
            </div>
            <label class="block space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Barbier</span>
                <select name="staff_user_id" class="{{ $input }}">
                    <option value="">— Sans barbier précis —</option>
                    @foreach ($staff as $s)<option value="{{ $s->id }}" @selected((string) old('staff_user_id') === (string) $s->id)>{{ $s->name }}</option>@endforeach
                </select>
            </label>
            <label class="block space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Prestation</span>
                <select name="item_id" class="{{ $input }}">
                    <option value="">—</option>
                    @foreach ($services as $service)<option value="{{ $service->id }}">{{ $service->title }} · {{ $money($service->sale_price) }}</option>@endforeach
                </select>
            </label>
            <label class="block space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Client existant</span>
                <select name="contact_id" data-searchable-select class="{{ $input }}">
                    <option value="">— Nouveau client (nom ci-dessous) —</option>
                    @foreach ($customers as $c)<option value="{{ $c->id }}">{{ $c->name }}@if($c->phone) · {{ $c->phone }}@endif</option>@endforeach
                </select>
            </label>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Nom</span><input name="customer_name" value="{{ old('customer_name') }}" class="{{ $input }}" placeholder="Si pas de fiche client"></label>
                <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Téléphone</span><input name="customer_phone" type="tel" value="{{ old('customer_phone') }}" class="{{ $input }}"></label>
            </div>
            <label class="block space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Note</span><input name="note" value="{{ old('note') }}" class="{{ $input }}"></label>
            <div class="flex justify-end gap-2">
                <button type="button" onclick="this.closest('dialog').close()" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold dark:border-white/10">Annuler</button>
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Enregistrer</button>
            </div>
        </form>
    </dialog>
    @php
        $days = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche'];
        $bookingUrl = route('booking.show');
        $bookable = $booking['staff_ids'];
    @endphp
    <dialog id="booking-settings" class="w-full max-w-xl rounded-2xl border border-slate-200 bg-white p-0 text-slate-950 shadow-2xl backdrop:bg-slate-950/40 dark:border-white/10 dark:bg-slate-950 dark:text-slate-100">
        <form method="POST" action="{{ route('appointments.booking') }}" class="space-y-5 p-5">
            @csrf
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-semibold">Réservation en ligne</h3>
                <button type="button" onclick="this.closest('dialog').close()" class="grid size-9 place-items-center rounded-lg border border-slate-200 dark:border-white/10" aria-label="Fermer">×</button>
            </div>
            <label class="flex items-start gap-3">
                <input type="hidden" name="enabled" value="0">
                <input type="checkbox" name="enabled" value="1" @checked($booking['enabled']) class="mt-1 size-4 rounded border-slate-300 accent-[var(--brand-primary)]">
                <span><span class="font-semibold">Les clients peuvent demander un rendez-vous en ligne</span>
                    <span class="block text-sm text-slate-500">Chaque demande arrive « À confirmer » : rien n’est réservé tant que vous ne l’avez pas confirmée.</span></span>
            </label>
            <div class="space-y-1.5">
                <span class="text-xs font-semibold uppercase text-slate-500">Lien à partager</span>
                <div class="flex flex-wrap gap-2">
                    <input readonly value="{{ $bookingUrl }}" class="{{ $input }} min-w-0 flex-1" data-booking-url onclick="this.select()">
                    <button type="button" class="rounded-lg border border-slate-200 px-3 text-sm font-semibold dark:border-white/10" onclick="navigator.clipboard?.writeText('{{ $bookingUrl }}'); this.textContent = 'Copié'">Copier</button>
                    <a href="https://wa.me/?text={{ rawurlencode('Prenez rendez-vous chez '.$tenant->name.' : '.$bookingUrl) }}" target="_blank" rel="noopener" class="inline-flex items-center rounded-lg bg-emerald-600 px-3 text-sm font-semibold text-white">WhatsApp</a>
                </div>
                <p class="text-xs text-slate-500">À mettre sur Instagram, Google Maps ou dans votre statut WhatsApp.</p>
            </div>
            <div class="space-y-2">
                <span class="text-xs font-semibold uppercase text-slate-500">Horaires d’ouverture</span>
                @foreach ($days as $n => $label)
                    @php $h = $booking['hours'][$n]; @endphp
                    <div class="grid grid-cols-[6rem_1fr_1fr_auto] items-center gap-2 text-sm">
                        <span>{{ $label }}</span>
                        <input type="time" step="300" name="hours[{{ $n }}][open]" value="{{ $h[0] ?? '09:00' }}" class="{{ $input }}" aria-label="{{ $label }} ouverture">
                        <input type="time" step="300" name="hours[{{ $n }}][close]" value="{{ $h[1] ?? '19:00' }}" class="{{ $input }}" aria-label="{{ $label }} fermeture">
                        <label class="flex items-center gap-1.5 text-xs text-slate-500"><input type="checkbox" name="hours[{{ $n }}][closed]" value="1" @checked($h === null)> Fermé</label>
                    </div>
                @endforeach
            </div>
            <label class="block space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Durée d’un créneau (min)</span>
                <input type="number" name="duration" min="5" max="240" step="5" value="{{ $booking['duration'] }}" class="{{ $input }} max-w-[8rem]"></label>
            <div class="space-y-1.5">
                <input type="hidden" name="staff_ids_sent" value="1">
                <span class="text-xs font-semibold uppercase text-slate-500">Qui peut être réservé</span>
                <div class="flex flex-wrap gap-3">
                    @foreach ($team as $member)
                        <label class="flex items-center gap-1.5 text-sm"><input type="checkbox" name="staff_ids[]" value="{{ $member->id }}" @checked($bookable === null || in_array($member->id, $bookable, true))> {{ $member->name }}</label>
                    @endforeach
                </div>
            </div>
            <div class="flex justify-end gap-2">
                <button type="button" onclick="this.closest('dialog').close()" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold dark:border-white/10">Annuler</button>
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Enregistrer</button>
            </div>
        </form>
    </dialog>
    @if ($errors->any())
        <script>document.getElementById('appointment-create')?.showModal();</script>
    @endif
</x-layouts.app>
