@php
    $input = 'h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900';
    $names = $team->pluck('name', 'id');
    $statusLabels = ['waiting' => ['En attente', 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300'], 'called' => ['Appelé', 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300'], 'seated' => ['Installé', 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300']];
    $waitingCount = $board->where('entry.status', 'waiting')->count();
@endphp
<x-layouts.app :tenant="$tenant" active="queue" title="File d’attente">
    <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
        <p class="text-sm font-semibold text-brand">Agenda</p>
        <h1 class="mt-1 text-[1.75rem] font-bold tracking-tight text-slate-950 dark:text-white">File d’attente</h1>
        <p class="mt-2 max-w-2xl text-sm text-slate-500 dark:text-slate-400">Les clients sans rendez-vous, dans l’ordre d’arrivée. Un client appelé réserve le temps du barbier : personne ne peut réserver par-dessus.</p>
        @if (session('status'))<p class="mt-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">{{ session('status') }}</p>@endif
        @if ($errors->any())<p class="mt-4 rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700 dark:bg-rose-500/10 dark:text-rose-300" data-queue-error>{{ $errors->first() }}</p>@endif
    </section>

    <section class="mt-6 grid gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
        <div class="space-y-3" data-queue-board>
            <div class="flex flex-wrap gap-2" data-call-next>
                @foreach ($team as $member)
                    <form method="POST" action="{{ route('queue.call') }}">@csrf
                        <input type="hidden" name="staff_user_id" value="{{ $member->id }}">
                        <button class="rounded-xl bg-brand px-4 py-2 text-sm font-semibold text-white" data-call-staff="{{ $member->id }}">Appeler le suivant · {{ $member->name }}</button>
                    </form>
                @endforeach
            </div>
            @forelse ($board as $row)
                @php $e = $row['entry']; [$label, $tone] = $statusLabels[$e->status]; @endphp
                <article class="rounded-xl border border-slate-200 bg-white p-4 dark:border-white/10 dark:bg-white/[0.03]" data-queue-entry="{{ $e->id }}" data-status="{{ $e->status }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-semibold">N° {{ $e->ticket_number }} · {{ $e->customer_name ?: 'Sans nom' }}</p>
                            <p class="truncate text-sm text-slate-500">{{ $e->service_name ?: 'Prestation à définir' }} · {{ $e->duration_minutes }} min
                                @if ($e->preferredStaff) · souhaite {{ $e->preferredStaff->name }}@else · premier libre @endif</p>
                            @if ($e->status === 'waiting')
                                <p class="text-sm font-semibold text-amber-700 dark:text-amber-300" data-wait>@if ($row['wait_minutes'] === null) Attente non estimable @elseif ($row['wait_minutes'] === 0) C’est son tour @else Attente estimée : {{ $row['wait_minutes'] }} min @endif</p>
                            @else
                                <p class="text-sm text-slate-500">{{ $e->staff?->name }}</p>
                            @endif
                        </div>
                        <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold {{ $tone }}">{{ $label }}</span>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @if ($e->status === 'called')
                            <form method="POST" action="{{ route('queue.seat', $e) }}">@csrf<button class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold dark:border-white/10">Installé</button></form>
                        @endif
                        @if ($e->status === 'seated' && $e->appointment_id)
                            <a href="{{ route('pos', ['appointment' => $e->appointment_id]) }}" class="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white">Encaisser</a>
                        @endif
                        @if (in_array($e->status, ['waiting', 'called'], true))
                            <form method="POST" action="{{ route('queue.leave', $e) }}">@csrf<button class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold dark:border-white/10">Parti</button></form>
                        @endif
                    </div>
                </article>
            @empty
                <p class="rounded-xl border border-dashed border-slate-200 p-6 text-center text-sm text-slate-500 dark:border-white/10">Personne n’attend.</p>
            @endforelse
        </div>

        <div class="space-y-4">
            <form method="POST" action="{{ route('queue.store') }}" class="space-y-3 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]" data-queue-add>
                @csrf
                <h2 class="font-semibold">Ajouter un client</h2>
                <input name="customer_name" maxlength="160" placeholder="Nom (facultatif)" class="{{ $input }}">
                <input name="customer_phone" type="tel" placeholder="Téléphone (facultatif)" class="{{ $input }}">
                <select name="item_id" class="{{ $input }}"><option value="">Prestation : à définir</option>@foreach ($services as $s)<option value="{{ $s->id }}">{{ $s->title }}</option>@endforeach</select>
                <select name="staff_user_id" class="{{ $input }}"><option value="">Premier libre</option>@foreach ($team as $m)<option value="{{ $m->id }}">{{ $m->name }}</option>@endforeach</select>
                <button class="w-full rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">Ajouter à la file</button>
            </form>
            <div class="space-y-2 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]" data-queue-tv>
                <h2 class="font-semibold">Écran public</h2>
                <p class="text-sm text-slate-500">À afficher en salle d’attente : numéro, prénom et attente estimée seulement. Se met à jour tout seul.</p>
                <input readonly value="{{ $tvUrl }}" onclick="this.select()" class="{{ $input }}" data-tv-url>
                <form method="POST" action="{{ route('queue.tv-token') }}" onsubmit="return confirm('L’ancienne adresse cessera de fonctionner. Continuer ?')">@csrf<button class="text-xs font-semibold text-rose-600">Changer l’adresse</button></form>
            </div>
        </div>
    </section>
</x-layouts.app>
