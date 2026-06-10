@php
    $locale = \App\Support\Locale::current($tenant);
    $tr = fn (string $text): string => \App\Support\Locale::t($text, $locale);
    $methodTones = ['POST' => 'success', 'PUT' => 'info', 'PATCH' => 'warning', 'DELETE' => 'danger'];
    $activeFilters = collect($filters)->filter(fn ($v) => $v !== '' && $v !== null)->except(['_token', 'page']);
@endphp

<x-layouts.app :tenant="$tenant" :active="$active" title="LibrairePro · {{ $tr('Journal d’activité') }}">
    <header class="mb-8 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
        <div class="flex flex-col gap-6 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-brand">{{ $tr('Profil · Traçabilité') }}</p>
                <h1 class="mt-1 text-[1.75rem] font-bold tracking-tight text-slate-950 dark:text-white">{{ $tr('Journal d’activité') }}</h1>
                <p class="mt-2 max-w-2xl text-sm leading-relaxed text-slate-500 dark:text-slate-400">{{ $tr('Vue propriétaire des actions enregistrées dans l\'application: utilisateur, date, module, IP et données utiles nettoyées.') }}</p>
            </div>
            <div class="flex gap-3">
                <a href="{{ route('profile') }}" class="inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:border-brand/40 hover:text-brand dark:border-white/10 dark:bg-transparent dark:text-slate-200">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 19-7-7 7-7"/><path d="M19 12H5"/></svg>
                    {{ $tr('Retour profil') }}
                </a>
                <a href="{{ route('module', ['module' => 'settings', 'section' => 'users']) }}" class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-brand px-4 text-sm font-semibold text-white shadow-sm shadow-indigo-500/20 transition hover:brightness-110">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    {{ $tr('Utilisateurs') }}
                </a>
            </div>
        </div>

        {{-- Stats mini-cards --}}
        <div class="mt-6 grid gap-3 sm:grid-cols-3">
            <div class="flex items-center gap-4 rounded-xl border border-slate-200 bg-slate-50/80 p-4 dark:border-white/10 dark:bg-white/5">
                <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-brand/10 text-brand">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                </span>
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $tr('Total enregistré') }}</p>
                    <strong class="block text-2xl font-black text-slate-950 dark:text-white">{{ number_format($totals['all'], 0, ',', ' ') }}</strong>
                </div>
            </div>
            <div class="flex items-center gap-4 rounded-xl border border-slate-200 bg-slate-50/80 p-4 dark:border-white/10 dark:bg-white/5">
                <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-emerald-100 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-400">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                </span>
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $tr('Aujourd\'hui') }}</p>
                    <strong class="block text-2xl font-black text-slate-950 dark:text-white">{{ number_format($totals['today'], 0, ',', ' ') }}</strong>
                </div>
            </div>
            <div class="flex items-center gap-4 rounded-xl border border-slate-200 bg-slate-50/80 p-4 dark:border-white/10 dark:bg-white/5">
                <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-amber-100 text-amber-600 dark:bg-amber-500/15 dark:text-amber-400">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                </span>
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $tr('Utilisateurs tracés') }}</p>
                    <strong class="block text-2xl font-black text-slate-950 dark:text-white">{{ number_format($totals['users'], 0, ',', ' ') }}</strong>
                </div>
            </div>
        </div>
    </header>

    {{-- Filter bar --}}
    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
        <form action="{{ route('profile.activity') }}" method="GET" class="space-y-4">
            {{-- Main search --}}
            <div class="relative">
                <svg class="pointer-events-none absolute left-4 top-1/2 size-5 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                <input name="q" value="{{ $filters['q'] }}" placeholder="{{ $tr('Rechercher par route, action, écran ou URL...') }}" class="h-12 w-full rounded-xl border border-slate-200 bg-white py-3 pl-12 pr-4 text-sm text-slate-950 outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900 dark:text-white" autofocus>
            </div>

            {{-- Advanced filters row --}}
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-5">
                <label class="space-y-2">
                    <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $tr('Utilisateur') }}</span>
                    <select name="user_id" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-950 outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900 dark:text-white">
                        <option value="">{{ $tr('Toutes les utilisateurs') }}</option>
                        @foreach ($users as $filterUser)
                            <option value="{{ $filterUser->id }}" @selected((string) $filters['user_id'] === (string) $filterUser->id)>{{ $filterUser->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="space-y-2">
                    <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $tr('Méthode') }}</span>
                    <select name="method" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-950 outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900 dark:text-white">
                        <option value="">{{ $tr('Toutes') }}</option>
                        @foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method)
                            <option value="{{ $method }}" @selected($filters['method'] === $method)>{{ $method }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="space-y-2">
                    <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $tr('Du') }}</span>
                    <input name="from" type="date" value="{{ $filters['from'] }}" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-950 outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900 dark:text-white">
                </label>
                <label class="space-y-2">
                    <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $tr('Au') }}</span>
                    <input name="to" type="date" value="{{ $filters['to'] }}" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-950 outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900 dark:text-white">
                </label>
                <div class="flex items-end gap-2">
                    <button type="submit" class="h-11 flex-1 rounded-xl bg-brand px-4 text-sm font-semibold text-white shadow-sm shadow-indigo-500/20 transition hover:brightness-110">
                        {{ $tr('Filtrer') }}
                    </button>
                    <a href="{{ route('profile.activity') }}" class="inline-flex h-11 shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:border-brand/40 hover:text-brand dark:border-white/10 dark:bg-transparent dark:text-slate-200">
                        {{ $tr('Effacer') }}
                    </a>
                </div>
            </div>

            {{-- Active filter chips --}}
            @if ($activeFilters->isNotEmpty())
                <div class="flex flex-wrap items-center gap-2 rounded-xl border border-brand/20 bg-brand/5 px-4 py-3 dark:border-brand/30 dark:bg-brand/10">
                    <svg class="size-4 shrink-0 text-brand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                    <span class="text-xs font-semibold uppercase tracking-wider text-brand">{{ $tr('Filtres actifs') }}</span>
                    @if ($filters['user_id'])
                        @php $filterUser = $users->firstWhere('id', (int) $filters['user_id']); @endphp
                        <span class="inline-flex items-center gap-1.5 rounded-full border border-brand/30 bg-white px-3 py-1 text-xs font-semibold text-brand dark:border-brand/40 dark:bg-brand/10">
                            {{ $filterUser?->name ?? '#' . $filters['user_id'] }}
                            <a href="{{ route('profile.activity', array_merge($filters, ['user_id' => ''])) }}" class="grid size-4 place-items-center rounded-full hover:bg-brand/10">×</a>
                        </span>
                    @endif
                    @if ($filters['method'])
                        <span class="inline-flex items-center gap-1.5 rounded-full border border-brand/30 bg-white px-3 py-1 text-xs font-semibold text-brand dark:border-brand/40 dark:bg-brand/10">
                            {{ $filters['method'] }}
                            <a href="{{ route('profile.activity', array_merge($filters, ['method' => ''])) }}" class="grid size-4 place-items-center rounded-full hover:bg-brand/10">×</a>
                        </span>
                    @endif
                    @if ($filters['from'])
                        <span class="inline-flex items-center gap-1.5 rounded-full border border-brand/30 bg-white px-3 py-1 text-xs font-semibold text-brand dark:border-brand/40 dark:bg-brand/10">
                            ≥ {{ $filters['from'] }}
                            <a href="{{ route('profile.activity', array_merge($filters, ['from' => ''])) }}" class="grid size-4 place-items-center rounded-full hover:bg-brand/10">×</a>
                        </span>
                    @endif
                    @if ($filters['to'])
                        <span class="inline-flex items-center gap-1.5 rounded-full border border-brand/30 bg-white px-3 py-1 text-xs font-semibold text-brand dark:border-brand/40 dark:bg-brand/10">
                            ≤ {{ $filters['to'] }}
                            <a href="{{ route('profile.activity', array_merge($filters, ['to' => ''])) }}" class="grid size-4 place-items-center rounded-full hover:bg-brand/10">×</a>
                        </span>
                    @endif
                    @if ($filters['q'])
                        <span class="inline-flex items-center gap-1.5 rounded-full border border-brand/30 bg-white px-3 py-1 text-xs font-semibold text-brand dark:border-brand/40 dark:bg-brand/10">
                            "{{ \Illuminate\Support\Str::limit($filters['q'], 30) }}"
                            <a href="{{ route('profile.activity', array_merge($filters, ['q' => ''])) }}" class="grid size-4 place-items-center rounded-full hover:bg-brand/10">×</a>
                        </span>
                    @endif
                    <span class="ml-auto text-xs text-slate-500">{{ $logs->total() }} {{ $tr('résultat(s)') }}</span>
                </div>
            @endif
        </form>
    </section>

    {{-- Results table --}}
    <section class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
        @if ($logs->total() > 0)
        <div class="overflow-x-auto">
            <table class="w-full min-w-[1120px] text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:border-white/10 dark:bg-white/5 dark:text-slate-400">
                        <th class="px-5 py-3.5">{{ $tr('Date') }}</th>
                        <th class="px-5 py-3.5">{{ $tr('Utilisateur') }}</th>
                        <th class="px-5 py-3.5">{{ $tr('Action') }}</th>
                        <th class="px-5 py-3.5">{{ $tr('Méthode') }}</th>
                        <th class="px-5 py-3.5">{{ $tr('Chemin') }}</th>
                        <th class="px-5 py-3.5">{{ $tr('IP') }}</th>
                        <th class="px-5 py-3.5 text-right">{{ $tr('Détail') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-white/5">
                    @foreach ($logs as $log)
                        @php
                            $properties = $log->properties ?? [];
                            $method = (string) data_get($properties, 'method', '—');
                            $payload = data_get($properties, 'payload', []);
                            $routeParameters = data_get($properties, 'route_parameters', []);
                            $payloadJson = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            $routeJson = json_encode($routeParameters, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            $friendlyAction = $actionLabels[$log->action] ?? null;
                        @endphp
                        <tr class="group transition hover:bg-slate-50/70 dark:hover:bg-white/[0.02]">
                            <td class="whitespace-nowrap px-5 py-4">
                                <p class="font-semibold text-slate-900 dark:text-white">{{ $log->created_at?->format('d/m/Y') }}</p>
                                <p class="mt-0.5 text-xs text-slate-400">{{ $log->created_at?->format('H:i') }}</p>
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex items-center gap-3">
                                    <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-brand/10 text-xs font-bold text-brand">{{ Str::upper(Str::substr($log->user?->name ?? $tr('Système'), 0, 2)) }}</span>
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-semibold text-slate-900 dark:text-white">{{ $log->user?->name ?? $tr('Système / invité') }}</p>
                                        <p class="truncate text-xs text-slate-400">{{ $log->user?->email ?? $tr('Action système') }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-4">
                                <div class="max-w-[280px]">
                                    <p class="truncate font-semibold text-slate-900 dark:text-white" title="@if ($friendlyAction){{ $tr($friendlyAction) }}@else{{ $log->action }}@endif">
                                        @if ($friendlyAction)
                                            {{ $tr($friendlyAction) }}
                                        @else
                                            {{ $log->action }}
                                        @endif
                                    </p>
                                    <p class="mt-0.5 truncate text-xs text-slate-400">
                                        @if ($friendlyAction)
                                            {{ $log->action }}
                                        @else
                                            {{ class_basename((string) $log->subject_type) ?: 'Application' }}{{ $log->subject_id ? ' #'.$log->subject_id : '' }}
                                        @endif
                                    </p>
                                </div>
                            </td>
                            <td class="px-5 py-4">
                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-bold @switch($method) @case('POST') bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400 @break @case('PUT') bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-400 @break @case('PATCH') bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400 @break @case('DELETE') bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-400 @break @default bg-slate-100 text-slate-600 dark:bg-white/10 dark:text-slate-300 @endswitch">
                                    {{ $method }}
                                </span>
                            </td>
                            <td class="px-5 py-4">
                                <div class="max-w-[320px]">
                                    <p class="truncate text-sm text-slate-700 dark:text-slate-300" title="{{ data_get($properties, 'path', '—') }}">{{ data_get($properties, 'path', '—') }}</p>
                                    <p class="mt-0.5 text-xs text-slate-400">HTTP {{ data_get($properties, 'status_code', '—') }}</p>
                                </div>
                            </td>
                            <td class="px-5 py-4 text-xs text-slate-500">{{ data_get($properties, 'ip', '—') }}</td>
                            <td class="px-5 py-4 text-right">
                                <button type="button" onclick="document.getElementById('audit-log-{{ $log->id }}').showModal()" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-semibold text-slate-600 transition hover:border-brand/40 hover:text-brand hover:bg-brand/5 dark:border-white/10 dark:bg-transparent dark:text-slate-300 dark:hover:bg-white/5">
                                    <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                    {{ $tr('Voir') }}
                                </button>
                            </td>
                        </tr>

                        {{-- Detail dialog --}}
                        <dialog id="audit-log-{{ $log->id }}" class="app-dialog w-[min(840px,calc(100vw-2rem))] rounded-2xl border border-slate-200 bg-white p-0 text-slate-950 shadow-2xl backdrop:bg-slate-950/45 dark:border-white/10 dark:bg-slate-950 dark:text-slate-100">
                            <div class="flex items-start gap-4 border-b border-slate-200 px-6 py-5 dark:border-white/10">
                                <span class="grid size-12 shrink-0 place-items-center rounded-xl bg-brand/10 text-sm font-bold text-brand">{{ Str::upper(Str::substr($log->user?->name ?? $tr('Système'), 0, 2)) }}</span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-xs font-semibold uppercase tracking-wider text-brand">{{ $tr('Détail activité') }}</p>
                                    <h3 class="mt-1 text-lg font-bold text-slate-950 dark:text-white">
                                        @if ($friendlyAction)
                                            {{ $tr($friendlyAction) }}
                                        @else
                                            {{ $log->action }}
                                        @endif
                                    </h3>
                                    <p class="mt-1 text-sm text-slate-500">{{ $log->created_at?->format('d/m/Y H:i:s') }} · {{ $log->user?->name ?? $tr('Système / invité') }}{{ $log->user?->email ? ' · '.$log->user?->email : '' }}</p>
                                </div>
                                <button class="dialog-close grid size-9 shrink-0 place-items-center rounded-xl border border-slate-200 text-lg font-semibold text-slate-500 transition hover:bg-slate-50 hover:text-slate-700 dark:border-white/10 dark:hover:bg-white/5 dark:hover:text-white" type="button">×</button>
                            </div>

                            <div class="px-6 py-5">
                                {{-- Meta grid --}}
                                <div class="grid gap-3 sm:grid-cols-4">
                                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 dark:border-white/10 dark:bg-white/5">
                                        <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">{{ $tr('Méthode') }}</p>
                                        <span class="mt-1.5 inline-flex rounded-lg px-2 py-0.5 text-xs font-bold @switch($method) @case('POST') bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400 @break @case('PUT') bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-400 @break @case('PATCH') bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400 @break @case('DELETE') bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-400 @break @default bg-slate-100 text-slate-600 dark:bg-white/10 dark:text-slate-300 @endswitch">{{ $method }}</span>
                                    </div>
                                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 dark:border-white/10 dark:bg-white/5">
                                        <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">{{ $tr('Statut') }}</p>
                                        <p class="mt-1.5 text-sm font-bold text-slate-950 dark:text-white">{{ data_get($properties, 'status_code', '—') }}</p>
                                    </div>
                                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 dark:border-white/10 dark:bg-white/5">
                                        <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">{{ $tr('IP') }}</p>
                                        <p class="mt-1.5 truncate text-sm font-medium text-slate-700 dark:text-slate-300">{{ data_get($properties, 'ip', '—') }}</p>
                                    </div>
                                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 dark:border-white/10 dark:bg-white/5">
                                        <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">{{ $tr('Sujet') }}</p>
                                        <p class="mt-1.5 truncate text-sm font-medium text-slate-700 dark:text-slate-300">{{ class_basename((string) $log->subject_type) ?: 'App' }}{{ $log->subject_id ? ' #'.$log->subject_id : '' }}</p>
                                    </div>
                                </div>

                                {{-- Route & URL --}}
                                <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-white/10 dark:bg-white/5">
                                    <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">{{ $tr('Route') }}</p>
                                    <p class="mt-1 text-sm font-medium text-slate-700 dark:text-slate-300">{{ data_get($properties, 'route', '—') }}</p>
                                    <p class="mt-0.5 break-all text-xs text-slate-400">{{ data_get($properties, 'path', '—') }}</p>
                                    <p class="mt-1 break-all text-xs text-slate-400">{{ data_get($properties, 'url', '—') }}</p>
                                </div>

                                {{-- Data --}}
                                <div class="mt-4 grid gap-4 lg:grid-cols-2">
                                    <div>
                                        <p class="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $tr('Données envoyées') }}</p>
                                        <pre class="max-h-72 overflow-auto rounded-xl border border-slate-200 bg-slate-950 p-4 text-xs leading-relaxed text-emerald-200 dark:border-white/10">{{ $payloadJson ?: '{}' }}</pre>
                                    </div>
                                    <div>
                                        <p class="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $tr('Paramètres route') }}</p>
                                        <pre class="max-h-72 overflow-auto rounded-xl border border-slate-200 bg-slate-950 p-4 text-xs leading-relaxed text-sky-200 dark:border-white/10">{{ $routeJson ?: '{}' }}</pre>
                                    </div>
                                </div>
                                <p class="mt-4 break-all text-xs text-slate-400">User-agent: {{ data_get($properties, 'user_agent', '—') }}</p>
                            </div>
                        </dialog>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="border-t border-slate-200 px-5 py-3 dark:border-white/10">
            {{ $logs->links() }}
        </div>
        @else
        <div class="flex flex-col items-center justify-center px-6 py-20 text-center">
            <span class="grid size-16 place-items-center rounded-2xl bg-slate-100 text-slate-400 dark:bg-white/5 dark:text-slate-500">
                <svg class="size-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
            </span>
            <h3 class="mt-4 text-base font-semibold text-slate-700 dark:text-slate-300">{{ $tr('Aucune activité trouvée') }}</h3>
            <p class="mt-1 max-w-sm text-sm text-slate-500">{{ $tr('Aucune action ne correspond aux filtres sélectionnés. Essayez d\'élargir la recherche.') }}</p>
        </div>
        @endif
    </section>
</x-layouts.app>
