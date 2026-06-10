@php
    $locale = \App\Support\Locale::current($tenant);
    $tr = fn (string $text): string => \App\Support\Locale::t($text, $locale);
@endphp
<x-layouts.app :tenant="$tenant" :active="$active" title="LibrairePro · {{ $tr('Appareils virtuels') }}">
    <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-brand">{{ $tr('Paramètres · Appareils') }}</p>
                <h1 class="mt-1 text-[1.75rem] font-bold tracking-tight text-slate-950 dark:text-white">{{ $tr('Appareils virtuels') }}</h1>
                <p class="mt-2 max-w-2xl text-sm leading-relaxed text-slate-500 dark:text-slate-400">{{ $tr('Définissez les appareils autorisés dans l\'application. Chaque appareil peut être utilisé par une seule personne à la fois.') }}</p>
            </div>
            <div class="flex gap-3">
                <a href="{{ route('module', ['module' => 'settings', 'section' => 'users']) }}" class="inline-flex h-11 items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:border-brand/40 hover:text-brand dark:border-white/10 dark:bg-transparent dark:text-slate-200">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    {{ $tr('Utilisateurs') }}
                </a>
                <button type="button" onclick="document.getElementById('device-create-dialog').showModal()" class="inline-flex h-11 items-center gap-2 rounded-xl bg-brand px-4 text-sm font-semibold text-white shadow-sm shadow-indigo-500/20 transition hover:brightness-110">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    {{ $tr('Nouvel appareil') }}
                </button>
            </div>
        </div>

        {{-- Stats --}}
        <div class="mt-6 grid gap-3 sm:grid-cols-3">
            <div class="flex items-center gap-4 rounded-xl border border-slate-200 bg-slate-50/80 p-4 dark:border-white/10 dark:bg-white/5">
                <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-brand/10 text-brand">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                </span>
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $tr('Total') }}</p>
                    <strong class="block text-2xl font-black text-slate-950 dark:text-white">{{ $devices->count() }}</strong>
                </div>
            </div>
            <div class="flex items-center gap-4 rounded-xl border border-slate-200 bg-slate-50/80 p-4 dark:border-white/10 dark:bg-white/5">
                <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-emerald-100 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-400">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                </span>
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $tr('Actifs') }}</p>
                    <strong class="block text-2xl font-black text-slate-950 dark:text-white">{{ $devices->where('is_active', true)->count() }}</strong>
                </div>
            </div>
            <div class="flex items-center gap-4 rounded-xl border border-slate-200 bg-slate-50/80 p-4 dark:border-white/10 dark:bg-white/5">
                <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-sky-100 text-sky-600 dark:bg-sky-500/15 dark:text-sky-400">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                </span>
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $tr('Connectés') }}</p>
                    <strong class="block text-2xl font-black text-slate-950 dark:text-white">{{ $devices->filter(fn ($d) => $d->isConnected())->count() }}</strong>
                </div>
            </div>
        </div>
    </section>

    {{-- Device list --}}
    <section class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
        @if ($devices->isNotEmpty())
            <div class="overflow-x-auto">
                <table class="w-full min-w-[960px] text-left text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:border-white/10 dark:bg-white/5 dark:text-slate-400">
                            <th class="px-5 py-3.5">{{ $tr('Appareil') }}</th>
                            <th class="px-5 py-3.5">{{ $tr('Code') }}</th>
                            <th class="px-5 py-3.5">{{ $tr('Statut') }}</th>
                            <th class="px-5 py-3.5">{{ $tr('Connexion') }}</th>
                            <th class="px-5 py-3.5">{{ $tr('Utilisateur connecté') }}</th>
                            <th class="px-5 py-3.5">{{ $tr('Infos réelles') }}</th>
                            <th class="px-5 py-3.5 text-right">{{ $tr('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-white/5">
                        @foreach ($devices as $device)
                            @php
                                $session = $device->activeSession;
                                $connectedUser = $session?->user;
                            @endphp
                            <tr class="group transition hover:bg-slate-50/70 dark:hover:bg-white/[0.02] {{ ! $device->is_active ? 'opacity-50' : '' }}">
                                <td class="px-5 py-4">
                                    <div class="flex items-center gap-3">
                                        <span class="grid size-9 shrink-0 place-items-center rounded-lg {{ $device->is_active ? 'bg-brand/10 text-brand' : 'bg-slate-100 text-slate-400 dark:bg-white/5' }} text-base">
                                            @if ($device->type === 'mobile') 📱
                                            @elseif ($device->type === 'tablet') 📋
                                            @else 💻
                                            @endif
                                        </span>
                                        <div class="min-w-0">
                                            <p class="truncate font-semibold text-slate-900 dark:text-white">{{ $device->name }}</p>
                                            <p class="text-xs text-slate-400">{{ $tr(ucfirst($device->type)) }}{{ $device->description ? ' · '.\Illuminate\Support\Str::limit($device->description, 40) : '' }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-5 py-4">
                                    <code class="rounded-md bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600 dark:bg-white/10 dark:text-slate-300">{{ $device->code }}</code>
                                </td>
                                <td class="px-5 py-4">
                                    @if ($device->is_active)
                                        <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-bold text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400">
                                            <span class="size-1.5 rounded-full bg-current"></span> {{ $tr('Actif') }}
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 rounded-full bg-rose-100 px-2.5 py-1 text-xs font-bold text-rose-700 dark:bg-rose-500/15 dark:text-rose-400">
                                            <span class="size-1.5 rounded-full bg-current"></span> {{ $tr('Inactif') }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-5 py-4">
                                    @if ($session)
                                        <span class="inline-flex items-center gap-1 rounded-full bg-sky-100 px-2.5 py-1 text-xs font-bold text-sky-700 dark:bg-sky-500/15 dark:text-sky-400">
                                            <span class="size-1.5 rounded-full bg-current animate-pulse"></span> {{ $tr('Connecté') }}
                                        </span>
                                        <p class="mt-0.5 text-xs text-slate-400">{{ $session->created_at?->diffForHumans() }}</p>
                                    @else
                                        <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-500 dark:bg-white/10 dark:text-slate-400">
                                            {{ $tr('Libre') }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-5 py-4">
                                    @if ($connectedUser)
                                        <p class="truncate text-sm font-semibold text-slate-900 dark:text-white">{{ $connectedUser->name }}</p>
                                        <p class="text-xs text-slate-400">{{ $connectedUser->email }}</p>
                                    @else
                                        <span class="text-xs text-slate-400">—</span>
                                    @endif
                                </td>
                                <td class="px-5 py-4">
                                    @if ($session)
                                        <div class="space-y-0.5">
                                            <p class="text-xs">{{ $session->platform }} · {{ $session->browser }}</p>
                                            <p class="text-xs text-slate-400 truncate max-w-[180px]" title="{{ $session->ip_address }}">{{ $session->ip_address }}</p>
                                        </div>
                                    @else
                                        <span class="text-xs text-slate-400">—</span>
                                    @endif
                                </td>
                                <td class="px-5 py-4 text-right">
                                    <div class="flex justify-end gap-2">
                                        <button type="button" onclick="document.getElementById('device-edit-{{ $device->id }}').showModal()" class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600 transition hover:border-brand/40 hover:text-brand dark:border-white/10 dark:bg-transparent dark:text-slate-300 dark:hover:bg-white/5">
                                            <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                            {{ $tr('Modifier') }}
                                        </button>
                                        <form action="{{ route('devices.toggle', $device) }}" method="POST" class="inline">
                                            @csrf @method('PUT')
                                            <button class="inline-flex items-center gap-1 rounded-lg border px-3 py-2 text-xs font-semibold transition {{ $device->is_active ? 'border-amber-200 text-amber-700 hover:bg-amber-50 dark:border-amber-500/30 dark:text-amber-400' : 'border-emerald-200 text-emerald-700 hover:bg-emerald-50 dark:border-emerald-500/30 dark:text-emerald-400' }}">
                                                {{ $device->is_active ? $tr('Désactiver') : $tr('Activer') }}
                                            </button>
                                        </form>
                                        <form action="{{ route('devices.destroy', $device) }}" method="POST" class="inline" onsubmit="return confirm('{{ $tr('Supprimer cet appareil ? Les sessions actives seront déconnectées.') }}')">
                                            @csrf @method('DELETE')
                                            <button class="inline-flex items-center gap-1 rounded-lg border border-rose-200 px-3 py-2 text-xs font-semibold text-rose-600 transition hover:bg-rose-50 dark:border-rose-500/30 dark:text-rose-400">
                                                <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>

                            {{-- Edit dialog --}}
                            <dialog id="device-edit-{{ $device->id }}" class="app-dialog w-full max-w-[440px] rounded-2xl border border-slate-200 bg-white p-0 text-slate-950 shadow-2xl backdrop:bg-slate-950/40 dark:border-white/10 dark:bg-slate-950 dark:text-slate-100">
                                <form action="{{ route('devices.update', $device) }}" method="POST">
                                    @csrf @method('PUT')
                                    <div class="flex items-center gap-3 border-b border-slate-200 px-5 py-4 dark:border-white/10">
                                        <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-brand/10 text-lg">
                                            @if ($device->type === 'mobile') 📱
                                            @elseif ($device->type === 'tablet') 📋
                                            @else 💻
                                            @endif
                                        </span>
                                        <div class="min-w-0 flex-1">
                                            <h3 class="font-semibold">{{ $tr('Modifier l\'appareil') }}</h3>
                                            <p class="text-sm text-slate-500">{{ $device->name }}</p>
                                        </div>
                                        <button class="dialog-close grid size-8 shrink-0 place-items-center rounded-lg border border-slate-200 text-lg dark:border-white/10" type="button">×</button>
                                    </div>
                                    <div class="space-y-4 px-5 py-4">
                                        <label class="block space-y-2">
                                            <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $tr('Nom') }} *</span>
                                            <input name="name" required value="{{ $device->name }}" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900">
                                        </label>
                                        <label class="block space-y-2">
                                            <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $tr('Type') }}</span>
                                            <select name="type" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900">
                                                @foreach (['computer' => 'Ordinateur', 'tablet' => 'Tablette', 'mobile' => 'Mobile', 'other' => 'Autre'] as $key => $label)
                                                    <option value="{{ $key }}" @selected($device->type === $key)>{{ $tr($label) }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <label class="block space-y-2">
                                            <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $tr('Description') }}</span>
                                            <textarea name="description" rows="2" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900">{{ $device->description }}</textarea>
                                        </label>
                                    </div>
                                    <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-4 dark:border-white/10">
                                        <button type="button" class="dialog-close rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-white/10 dark:bg-transparent dark:text-slate-300 dark:hover:bg-white/5">{{ $tr('Annuler') }}</button>
                                        <button class="rounded-xl bg-brand px-4 py-2.5 text-sm font-semibold text-white shadow-sm shadow-indigo-500/20 transition hover:brightness-110">{{ $tr('Enregistrer') }}</button>
                                    </div>
                                </form>
                            </dialog>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="flex flex-col items-center justify-center px-6 py-20 text-center">
                <span class="grid size-16 place-items-center rounded-2xl bg-slate-100 text-slate-400 dark:bg-white/5 dark:text-slate-500">🖥</span>
                <h3 class="mt-4 text-base font-semibold text-slate-700 dark:text-slate-300">{{ $tr('Aucun appareil virtuel') }}</h3>
                <p class="mt-1 max-w-sm text-sm text-slate-500">{{ $tr('Créez votre premier appareil virtuel pour commencer à suivre les connexions.') }}</p>
            </div>
        @endif
    </section>

    {{-- Create dialog --}}
    <dialog id="device-create-dialog" class="app-dialog w-full max-w-[440px] rounded-2xl border border-slate-200 bg-white p-0 text-slate-950 shadow-2xl backdrop:bg-slate-950/40 dark:border-white/10 dark:bg-slate-950 dark:text-slate-100">
        <form action="{{ route('devices.store') }}" method="POST">
            @csrf
            <div class="flex items-center gap-3 border-b border-slate-200 px-5 py-4 dark:border-white/10">
                <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-brand/10 text-brand">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                </span>
                <div class="min-w-0 flex-1">
                    <h3 class="font-semibold">{{ $tr('Nouvel appareil') }}</h3>
                    <p class="text-sm text-slate-500">{{ $tr('Ajouter un appareil virtuel') }}</p>
                </div>
                <button class="dialog-close grid size-8 shrink-0 place-items-center rounded-lg border border-slate-200 text-lg dark:border-white/10" type="button">×</button>
            </div>
            <div class="space-y-4 px-5 py-4">
                <label class="block space-y-2">
                    <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $tr('Nom') }} *</span>
                    <input name="name" required class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900" placeholder="{{ $tr('Ex: Ordinateur principal') }}">
                </label>
                <label class="block space-y-2">
                    <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $tr('Type') }}</span>
                    <select name="type" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900">
                        <option value="computer">{{ $tr('Ordinateur') }}</option>
                        <option value="tablet">{{ $tr('Tablette') }}</option>
                        <option value="mobile">{{ $tr('Mobile') }}</option>
                        <option value="other">{{ $tr('Autre') }}</option>
                    </select>
                </label>
                <label class="block space-y-2">
                    <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $tr('Description') }}</span>
                    <textarea name="description" rows="2" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900" placeholder="{{ $tr('Optionnel') }}"></textarea>
                </label>
            </div>
            <div class="flex justify-end gap-3 border-t border-slate-200 px-5 py-4 dark:border-white/10">
                <button type="button" class="dialog-close rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-white/10 dark:bg-transparent dark:text-slate-300 dark:hover:bg-white/5">{{ $tr('Annuler') }}</button>
                <button class="rounded-xl bg-brand px-4 py-2.5 text-sm font-semibold text-white shadow-sm shadow-indigo-500/20 transition hover:brightness-110">{{ $tr('Créer') }}</button>
            </div>
        </form>
    </dialog>
</x-layouts.app>
