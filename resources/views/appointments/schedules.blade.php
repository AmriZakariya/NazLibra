@php
    $days = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche'];
    $input = 'h-10 w-full rounded-lg border border-slate-200 bg-white px-2 text-sm dark:border-white/10 dark:bg-slate-900';
    $names = $team->pluck('name', 'id');
@endphp
<x-layouts.app :tenant="$tenant" active="appointments" title="Horaires">
    <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
        <p class="text-sm font-semibold text-brand">Agenda</p>
        <h1 class="mt-1 text-[1.75rem] font-bold tracking-tight text-slate-950 dark:text-white">Horaires</h1>
        <p class="mt-2 max-w-2xl text-sm text-slate-500 dark:text-slate-400">Les jours et heures de travail de chaque personne. Sans horaires propres, une personne suit ceux de la boutique (réglés dans « Réservation en ligne »). Les clients ne peuvent jamais réserver en dehors.</p>
        <p class="mt-3 flex gap-3 text-sm"><a href="{{ route('appointments') }}" class="font-semibold text-brand">‹ Retour à l’agenda</a></p>
        @if (session('status'))<p class="mt-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">{{ session('status') }}</p>@endif
        @if ($errors->any())<p class="mt-4 rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">{{ $errors->first() }}</p>@endif
    </section>

    <section class="mt-6 grid gap-4 lg:grid-cols-2">
        @foreach ($team as $member)
            @php $w = $weeks[$member->id]; @endphp
            <form method="POST" action="{{ route('appointments.schedules.save', $member->id) }}" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]" data-schedule-form="{{ $member->id }}">
                @csrf
                <div class="flex items-center justify-between gap-3">
                    <h2 class="font-semibold">{{ $member->name }}</h2>
                    <span class="rounded-full px-2 py-0.5 text-xs {{ $w['custom'] ? 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300' : 'bg-slate-100 text-slate-500 dark:bg-white/10 dark:text-slate-400' }}">{{ $w['custom'] ? 'Horaires propres' : 'Horaires de la boutique' }}</span>
                </div>
                <div class="mt-3 space-y-2">
                    @foreach ($days as $n => $label)
                        @php $slots = $w['week'][$n]; @endphp
                        <div class="grid grid-cols-[5.5rem_1fr_1fr_1fr_1fr_auto] items-center gap-1.5 text-sm">
                            <span>{{ $label }}</span>
                            @for ($i = 0; $i < 2; $i++)
                                <input type="time" step="300" name="week[{{ $n }}][{{ $i }}][open]" value="{{ $slots[$i][0] ?? '' }}" class="{{ $input }}" aria-label="{{ $label }} début {{ $i + 1 }}">
                                <input type="time" step="300" name="week[{{ $n }}][{{ $i }}][close]" value="{{ $slots[$i][1] ?? '' }}" class="{{ $input }}" aria-label="{{ $label }} fin {{ $i + 1 }}">
                            @endfor
                            <label class="flex items-center gap-1 text-xs text-slate-500"><input type="checkbox" name="week[{{ $n }}][off]" value="1" @checked($slots === [])> Repos</label>
                        </div>
                    @endforeach
                </div>
                <p class="mt-2 text-xs text-slate-500">Deux plages par jour pour une pause déjeuner (ex. 09:00–13:00 puis 14:00–19:00).</p>
                <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
                    <label class="flex items-center gap-1.5 text-xs text-slate-500"><input type="checkbox" name="follow_shop" value="1" @checked(! $w['custom'])> Suivre les horaires de la boutique</label>
                    <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Enregistrer</button>
                </div>
            </form>
        @endforeach
    </section>

    <section class="mt-6 grid gap-4 lg:grid-cols-2">
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]" data-exceptions>
            <h2 class="font-semibold">Congés et horaires exceptionnels</h2>
            <p class="mt-1 text-sm text-slate-500">Un jour précis pour une personne : absente, ou avec d’autres horaires.</p>
            <form method="POST" action="{{ route('appointments.exceptions.store') }}" class="mt-3 grid gap-2 sm:grid-cols-2">
                @csrf
                <select name="user_id" required class="{{ $input }}">@foreach ($team as $m)<option value="{{ $m->id }}">{{ $m->name }}</option>@endforeach</select>
                <input type="date" name="date" required class="{{ $input }}">
                <select name="kind" class="{{ $input }}"><option value="off">Absent(e) toute la journée</option><option value="custom">Autres horaires</option></select>
                <div class="grid grid-cols-2 gap-2"><input type="time" step="300" name="starts_at" class="{{ $input }}" aria-label="Début"><input type="time" step="300" name="ends_at" class="{{ $input }}" aria-label="Fin"></div>
                <input name="note" maxlength="190" placeholder="Note (facultatif)" class="{{ $input }} sm:col-span-2">
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white sm:col-span-2">Ajouter</button>
            </form>
            <ul class="mt-4 divide-y divide-slate-100 text-sm dark:divide-white/10">
                @forelse ($exceptions as $e)
                    <li class="flex items-center justify-between gap-2 py-2" data-exception="{{ $e->id }}">
                        <span>{{ $e->date->format('d/m/Y') }} · {{ $names[$e->user_id] ?? '—' }} · {{ $e->kind === 'off' ? 'Absent(e)' : $e->starts_at.'–'.$e->ends_at }}@if ($e->note) · {{ $e->note }}@endif</span>
                        <form method="POST" action="{{ route('appointments.exceptions.destroy', $e) }}">@csrf @method('DELETE')<button class="text-xs font-semibold text-rose-600">Supprimer</button></form>
                    </li>
                @empty
                    <li class="py-2 text-slate-500">Aucune exception à venir.</li>
                @endforelse
            </ul>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]" data-closures>
            <h2 class="font-semibold">Fermetures de la boutique</h2>
            <p class="mt-1 text-sm text-slate-500">Jours fériés, vacances : personne n’est réservable ces jours-là.</p>
            <form method="POST" action="{{ route('appointments.closures.store') }}" class="mt-3 grid gap-2 sm:grid-cols-2">
                @csrf
                <input type="date" name="starts_on" required class="{{ $input }}" aria-label="Du">
                <input type="date" name="ends_on" required class="{{ $input }}" aria-label="Au">
                <input name="label" maxlength="190" placeholder="Motif (ex. Aïd)" class="{{ $input }} sm:col-span-2">
                <button class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white sm:col-span-2">Ajouter</button>
            </form>
            <ul class="mt-4 divide-y divide-slate-100 text-sm dark:divide-white/10">
                @forelse ($closures as $c)
                    <li class="flex items-center justify-between gap-2 py-2" data-closure="{{ $c->id }}">
                        <span>{{ $c->starts_on->format('d/m/Y') }}@if ($c->ends_on->ne($c->starts_on)) → {{ $c->ends_on->format('d/m/Y') }}@endif @if ($c->label) · {{ $c->label }}@endif</span>
                        <form method="POST" action="{{ route('appointments.closures.destroy', $c) }}">@csrf @method('DELETE')<button class="text-xs font-semibold text-rose-600">Supprimer</button></form>
                    </li>
                @empty
                    <li class="py-2 text-slate-500">Aucune fermeture à venir.</li>
                @endforelse
            </ul>
        </div>
    </section>
</x-layouts.app>
