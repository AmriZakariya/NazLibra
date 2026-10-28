@php
    $money = fn ($v) => \App\Support\Money::for($tenant)->format($v);
    $statusLabels = [
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
                <button type="button" onclick="document.getElementById('appointment-create').showModal()" class="inline-flex h-11 items-center gap-2 rounded-xl bg-brand px-4 text-sm font-semibold text-white">+ Nouveau rendez-vous</button>
            </div>
        </div>

        @if (session('status'))
            <p class="mt-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">{{ session('status') }}</p>
        @endif
        @if ($errors->any())
            <p class="mt-4 rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700 dark:bg-rose-500/10 dark:text-rose-300" data-appointment-error>{{ $errors->first() }}</p>
        @endif
    </section>

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
                                    @foreach (['arrived' => 'Arrivé', 'done' => 'Terminé', 'no_show' => 'Absent', 'cancelled' => 'Annuler'] as $next => $nextLabel)
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
    @if ($errors->any())
        <script>document.getElementById('appointment-create')?.showModal();</script>
    @endif
</x-layouts.app>
