@php
    $locale = \App\Support\Locale::current($tenant);
    $tr = fn (string $text): string => \App\Support\Locale::t($text, $locale);
    $methodTones = [
        'POST' => 'success',
        'PUT' => 'info',
        'PATCH' => 'warning',
        'DELETE' => 'danger',
    ];
@endphp

<x-layouts.app :tenant="$tenant" :active="$active" title="LibrairePro · {{ $tr('Journal d’activité') }}">
    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div class="min-w-0">
                <p class="text-sm font-semibold text-brand">{{ $tr('Profil · Traçabilité') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-normal text-slate-950 dark:text-white">{{ $tr('Journal d’activité') }}</h1>
                <p class="mt-2 max-w-3xl text-sm text-slate-600 dark:text-slate-300">{{ $tr('Vue propriétaire des actions enregistrées dans l’application: utilisateur, date, module, IP et données utiles nettoyées.') }}</p>
            </div>
            <div class="flex flex-col gap-2 sm:flex-row">
                <a href="{{ route('profile') }}" class="inline-flex h-11 items-center justify-center rounded-lg border border-slate-200 px-4 text-sm font-semibold text-slate-700 transition hover:border-brand/40 hover:text-brand dark:border-white/10 dark:text-slate-200">{{ $tr('Retour profil') }}</a>
                <a href="{{ route('module', ['module' => 'settings', 'section' => 'users']) }}" class="inline-flex h-11 items-center justify-center rounded-lg bg-brand px-4 text-sm font-semibold text-white shadow-sm shadow-indigo-500/20 transition hover:brightness-110">{{ $tr('Utilisateurs') }}</a>
            </div>
        </div>

        <div class="mt-5 grid gap-3 md:grid-cols-3">
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-white/10 dark:bg-white/5">
                <p class="text-xs font-semibold uppercase text-slate-500">{{ $tr('Total enregistré') }}</p>
                <strong class="mt-2 block text-2xl font-black text-slate-950 dark:text-white">{{ number_format($totals['all'], 0, ',', ' ') }}</strong>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-white/10 dark:bg-white/5">
                <p class="text-xs font-semibold uppercase text-slate-500">{{ $tr('Aujourd’hui') }}</p>
                <strong class="mt-2 block text-2xl font-black text-slate-950 dark:text-white">{{ number_format($totals['today'], 0, ',', ' ') }}</strong>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-white/10 dark:bg-white/5">
                <p class="text-xs font-semibold uppercase text-slate-500">{{ $tr('Utilisateurs tracés') }}</p>
                <strong class="mt-2 block text-2xl font-black text-slate-950 dark:text-white">{{ number_format($totals['users'], 0, ',', ' ') }}</strong>
            </div>
        </div>
    </section>

    <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
        <form action="{{ route('profile.activity') }}" method="GET" class="grid gap-3 xl:grid-cols-[minmax(180px,1fr)_150px_150px_130px_minmax(220px,1.3fr)_auto_auto] xl:items-end">
            <label class="space-y-1.5">
                <span class="text-xs font-semibold uppercase text-slate-500">{{ $tr('Utilisateur') }}</span>
                <select name="user_id" class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm text-slate-950 dark:border-white/10 dark:bg-slate-900 dark:text-white">
                    <option value="">{{ $tr('Toutes les utilisateurs') }}</option>
                    @foreach ($users as $filterUser)
                        <option value="{{ $filterUser->id }}" @selected((string) $filters['user_id'] === (string) $filterUser->id)>{{ $filterUser->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="space-y-1.5">
                <span class="text-xs font-semibold uppercase text-slate-500">{{ $tr('Du') }}</span>
                <input name="from" type="date" value="{{ $filters['from'] }}" class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm text-slate-950 dark:border-white/10 dark:bg-slate-900 dark:text-white">
            </label>
            <label class="space-y-1.5">
                <span class="text-xs font-semibold uppercase text-slate-500">{{ $tr('Au') }}</span>
                <input name="to" type="date" value="{{ $filters['to'] }}" class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm text-slate-950 dark:border-white/10 dark:bg-slate-900 dark:text-white">
            </label>
            <label class="space-y-1.5">
                <span class="text-xs font-semibold uppercase text-slate-500">{{ $tr('Méthode') }}</span>
                <select name="method" class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm text-slate-950 dark:border-white/10 dark:bg-slate-900 dark:text-white">
                    <option value="">{{ $tr('Toutes') }}</option>
                    @foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method)
                        <option value="{{ $method }}" @selected($filters['method'] === $method)>{{ $method }}</option>
                    @endforeach
                </select>
            </label>
            <label class="space-y-1.5">
                <span class="text-xs font-semibold uppercase text-slate-500">{{ $tr('Recherche') }}</span>
                <input name="q" value="{{ $filters['q'] }}" placeholder="{{ $tr('Route, écran, action...') }}" class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm text-slate-950 dark:border-white/10 dark:bg-slate-900 dark:text-white">
            </label>
            <button class="h-11 rounded-lg bg-brand px-5 text-sm font-semibold text-white shadow-sm shadow-indigo-500/20 transition hover:brightness-110">{{ $tr('Filtrer') }}</button>
            <a href="{{ route('profile.activity') }}" class="inline-flex h-11 items-center justify-center rounded-lg border border-slate-200 px-5 text-sm font-semibold text-slate-700 transition hover:border-brand/40 hover:text-brand dark:border-white/10 dark:text-slate-200">{{ $tr('Effacer') }}</a>
        </form>
    </section>

    <section class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[1120px] text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-white/5">
                    <tr>
                        <th class="px-4 py-3">{{ $tr('Date') }}</th>
                        <th class="px-4 py-3">{{ $tr('Utilisateur') }}</th>
                        <th class="px-4 py-3">{{ $tr('Action') }}</th>
                        <th class="px-4 py-3">{{ $tr('Méthode') }}</th>
                        <th class="px-4 py-3">{{ $tr('Chemin') }}</th>
                        <th class="px-4 py-3">{{ $tr('IP') }}</th>
                        <th class="px-4 py-3 text-right">{{ $tr('Détail') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-white/10">
                    @forelse ($logs as $log)
                        @php
                            $properties = $log->properties ?? [];
                            $method = (string) data_get($properties, 'method', '—');
                            $payload = data_get($properties, 'payload', []);
                            $routeParameters = data_get($properties, 'route_parameters', []);
                            $payloadJson = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            $routeJson = json_encode($routeParameters, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            $friendlyAction = $actionLabels[$log->action] ?? null;
                        @endphp
                        <tr class="transition hover:bg-slate-50 dark:hover:bg-white/5">
                            <td class="px-4 py-3">
                                <strong class="block text-slate-950 dark:text-white">{{ $log->created_at?->format('d/m/Y') }}</strong>
                                <span class="text-xs text-slate-500">{{ $log->created_at?->format('H:i:s') }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-brand/10 text-xs font-bold text-brand">{{ Str::upper(Str::substr($log->user?->name ?? $tr('Système'), 0, 2)) }}</span>
                                    <div class="min-w-0">
                                        <p class="truncate font-semibold text-slate-950 dark:text-white">{{ $log->user?->name ?? $tr('Système / invité') }}</p>
                                        <p class="truncate text-xs text-slate-500">{{ $log->user?->email ?? $tr('Action système') }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <p class="max-w-[280px] truncate font-semibold text-slate-950 dark:text-white" title="{{ $log->action }}">
                                    @if ($friendlyAction)
                                        {{ $tr($friendlyAction) }}
                                    @else
                                        {{ $log->action }}
                                    @endif
                                </p>
                                @if ($friendlyAction)
                                    <p class="mt-1 max-w-[280px] truncate text-xs text-slate-400">{{ $log->action }}</p>
                                @else
                                    <p class="mt-1 max-w-[280px] truncate text-xs text-slate-500">{{ class_basename((string) $log->subject_type) ?: 'Application' }}{{ $log->subject_id ? ' #'.$log->subject_id : '' }}</p>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <x-status-pill :tone="$methodTones[$method] ?? 'info'">{{ $method }}</x-status-pill>
                            </td>
                            <td class="px-4 py-3">
                                <p class="max-w-[320px] truncate font-medium text-slate-700 dark:text-slate-200">{{ data_get($properties, 'path', '—') }}</p>
                                <p class="mt-1 text-xs text-slate-500">HTTP {{ data_get($properties, 'status_code', '—') }}</p>
                            </td>
                            <td class="px-4 py-3 text-slate-500">{{ data_get($properties, 'ip', '—') }}</td>
                            <td class="px-4 py-3 text-right">
                                <button type="button" onclick="document.getElementById('audit-log-{{ $log->id }}').showModal()" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold transition hover:border-brand hover:text-brand dark:border-white/10">{{ $tr('Voir détail') }}</button>
                            </td>
                        </tr>
                        <dialog id="audit-log-{{ $log->id }}" class="app-dialog w-[min(920px,calc(100vw-2rem))] rounded-2xl border border-slate-200 bg-white p-0 text-slate-950 shadow-2xl backdrop:bg-slate-950/45 dark:border-white/10 dark:bg-slate-950 dark:text-slate-100">
                            <div class="border-b border-slate-200 p-5 dark:border-white/10">
                                <div class="flex items-start justify-between gap-4">
                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold text-brand">{{ $tr('Détail journal') }}</p>
                                        <h3 class="mt-1 truncate text-xl font-semibold">
                                            @if ($friendlyAction)
                                                {{ $tr($friendlyAction) }}
                                            @else
                                                {{ $log->action }}
                                            @endif
                                        </h3>
                                        <p class="mt-1 text-sm text-slate-500">{{ $log->created_at?->format('d/m/Y H:i:s') }} · {{ $log->user?->name ?? $tr('Système / invité') }}</p>
                                    </div>
                                    <button class="dialog-close grid size-9 shrink-0 place-items-center rounded-lg border border-slate-200 text-lg font-semibold dark:border-white/10" type="button">×</button>
                                </div>
                            </div>
                            <div class="grid max-h-[75vh] gap-4 overflow-y-auto p-5 lg:grid-cols-[1fr_1.2fr]">
                                <div class="space-y-3">
                                    <div class="rounded-xl bg-slate-50 p-4 dark:bg-white/5">
                                        <p class="text-xs font-semibold uppercase text-slate-500">{{ $tr('Requête') }}</p>
                                        <dl class="mt-3 space-y-2 text-sm">
                                            <div class="flex justify-between gap-3"><dt class="text-slate-500">{{ $tr('Méthode') }}</dt><dd class="font-semibold">{{ $method }}</dd></div>
                                            <div class="flex justify-between gap-3"><dt class="text-slate-500">{{ $tr('Route') }}</dt><dd class="max-w-[220px] truncate font-semibold">{{ data_get($properties, 'route', '—') }}</dd></div>
                                            <div class="flex justify-between gap-3"><dt class="text-slate-500">{{ $tr('Statut') }}</dt><dd class="font-semibold">{{ data_get($properties, 'status_code', '—') }}</dd></div>
                                            <div class="flex justify-between gap-3"><dt class="text-slate-500">{{ $tr('IP') }}</dt><dd class="font-semibold">{{ data_get($properties, 'ip', '—') }}</dd></div>
                                        </dl>
                                    </div>
                                    <div class="rounded-xl bg-slate-50 p-4 dark:bg-white/5">
                                        <p class="text-xs font-semibold uppercase text-slate-500">{{ $tr('Sujet') }}</p>
                                        <p class="mt-3 text-sm font-semibold">{{ class_basename((string) $log->subject_type) ?: 'Application' }}{{ $log->subject_id ? ' #'.$log->subject_id : '' }}</p>
                                        <p class="mt-2 break-all text-xs text-slate-500">{{ data_get($properties, 'url', '—') }}</p>
                                    </div>
                                </div>
                                <div class="space-y-3">
                                    <div>
                                        <p class="mb-2 text-xs font-semibold uppercase text-slate-500">{{ $tr('Données envoyées') }}</p>
                                        <pre class="max-h-72 overflow-auto rounded-xl border border-slate-200 bg-slate-950 p-4 text-xs leading-relaxed text-slate-100 dark:border-white/10">{{ $payloadJson ?: '{}' }}</pre>
                                    </div>
                                    <div>
                                        <p class="mb-2 text-xs font-semibold uppercase text-slate-500">{{ $tr('Paramètres route') }}</p>
                                        <pre class="max-h-48 overflow-auto rounded-xl border border-slate-200 bg-slate-950 p-4 text-xs leading-relaxed text-slate-100 dark:border-white/10">{{ $routeJson ?: '{}' }}</pre>
                                    </div>
                                    <p class="break-all text-xs text-slate-500">User-agent: {{ data_get($properties, 'user_agent', '—') }}</p>
                                </div>
                            </div>
                        </dialog>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-14 text-center text-sm text-slate-500">{{ $tr('Aucune action ne correspond aux filtres.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-slate-200 px-4 py-3 dark:border-white/10">
            {{ $logs->links() }}
        </div>
    </section>
</x-layouts.app>
