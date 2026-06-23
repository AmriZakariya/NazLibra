@php
    $locale = \App\Support\Locale::current($tenant);
    $tr = fn (string $text): string => \App\Support\Locale::t($text, $locale);
@endphp
<x-layouts.app :tenant="$tenant" :active="$active" title="LibrairePro · {{ $tr('Mon profil') }}">
    <section class="flex flex-col gap-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-white/[0.03] lg:flex-row lg:items-center lg:justify-between">
        <div class="flex items-center gap-4">
            <x-user-avatar :user="$user" size="xl" rounded="rounded-2xl" />
            <div>
                <p class="text-sm font-semibold text-brand">{{ $tr('Compte utilisateur') }}</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-normal">{{ $tr('Mon profil') }}</h1>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <span class="rounded-full bg-brand/10 px-2.5 py-1 text-xs font-semibold text-brand">{{ $roleName }}</span>
                    @if ($roleKey)
                        <span class="rounded-full border border-slate-200 px-2.5 py-1 text-xs font-semibold text-slate-500 dark:border-white/10">{{ $tr('Rôle') }}: {{ $roleKey }}</span>
                    @endif
                </div>
                <p class="mt-2 max-w-3xl text-sm text-slate-600 dark:text-slate-300">{{ $tr('Gérez vos informations personnelles et votre mot de passe de connexion.') }}</p>
            </div>
        </div>
        <form action="{{ route('logout') }}" method="POST">
            @csrf
            <button class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-2.5 text-sm font-semibold text-rose-600 transition hover:border-rose-300 hover:bg-rose-100 dark:border-rose-500/30 dark:bg-rose-500/10 dark:hover:bg-rose-500/20">{{ $tr('Déconnexion') }}</button>
        </form>
    </section>

    @if ($isOwner)
        <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <p class="text-sm font-semibold text-brand">{{ $tr('Traçabilité propriétaire') }}</p>
                    <h2 class="mt-1 text-xl font-semibold tracking-normal">{{ $tr('Journal d’activité') }}</h2>
                    <p class="mt-2 max-w-3xl text-sm text-slate-600 dark:text-slate-300">{{ $tr('Toutes les actions d’écriture sont enregistrées: utilisateur, date, route, méthode, IP et données utiles nettoyées.') }}</p>
                </div>
                <a href="{{ route('profile.activity') }}" class="inline-flex h-11 items-center justify-center rounded-lg bg-brand px-4 text-sm font-semibold text-white shadow-sm shadow-indigo-500/20 transition hover:brightness-110">{{ $tr('Voir tous les logs') }}</a>
            </div>
            <div class="mt-5 grid gap-3 lg:grid-cols-5">
                @forelse ($recentAuditLogs as $log)
                    <a href="{{ route('profile.activity', ['q' => $log->action]) }}" class="rounded-xl border border-slate-200 bg-slate-50 p-4 transition hover:border-brand/40 hover:bg-white dark:border-white/10 dark:bg-white/5 dark:hover:bg-white/10">
                        <div class="flex items-center justify-between gap-3">
                            <span class="truncate text-sm font-semibold text-slate-950 dark:text-white">{{ $log->action }}</span>
                            <span class="shrink-0 rounded-full bg-white px-2 py-1 text-[11px] font-bold text-slate-500 dark:bg-slate-950/60">{{ data_get($log->properties, 'method', '—') }}</span>
                        </div>
                        <p class="mt-2 truncate text-xs text-slate-500">{{ $log->user?->name ?? $tr('Système') }} · {{ data_get($log->properties, 'path', '—') }}</p>
                        <p class="mt-2 text-xs font-semibold text-slate-400">{{ $log->created_at?->format('d/m/Y H:i') }}</p>
                    </a>
                @empty
                    <div class="rounded-xl border border-dashed border-slate-200 p-5 text-sm text-slate-500 dark:border-white/10 lg:col-span-5">{{ $tr('Aucune action enregistrée pour le moment.') }}</div>
                @endforelse
            </div>
        </section>
    @endif

    <section class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1fr)_340px]">
        <div class="space-y-6">
            <form action="{{ route('profile.update') }}" method="POST" enctype="multipart/form-data" class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                @csrf
                @method('PUT')
                <div class="mb-5 flex items-start justify-between gap-4 border-b border-slate-200 pb-4 dark:border-white/10">
                    <div>
                        <h2 class="font-semibold">{{ $tr('Informations de connexion') }}</h2>
                        <p class="mt-1 text-sm text-slate-500">{{ $tr('Ces informations apparaissent dans l’en-tête et dans les journaux d’activité.') }}</p>
                    </div>
                </div>
                <div class="grid gap-4 lg:grid-cols-2">
                    <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">{{ $tr('Nom') }}</span><input name="name" required value="{{ old('name', $user->name) }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                    <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">{{ $tr('Email') }}</span><input name="email" required type="email" value="{{ old('email', $user->email) }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                    <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">{{ $tr('Téléphone') }}</span><input name="phone" value="{{ old('phone', $user->phone) }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                    <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">{{ $tr('Couleur avatar') }}</span><input name="avatar_color" type="color" value="{{ old('avatar_color', $user->avatar_color) }}" class="h-11 w-full rounded-lg border border-slate-200 p-1 dark:border-white/10 dark:bg-slate-900"></label>
                </div>
                <div class="mt-5 rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-white/10 dark:bg-white/5">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
                        <x-user-avatar :user="$user" size="xl" rounded="rounded-2xl" />
                        <div class="min-w-0 flex-1">
                            <label class="block space-y-1.5">
                                <span class="text-xs font-semibold uppercase text-slate-500">{{ $tr('Photo de profil') }}</span>
                                <input name="profile_photo" type="file" accept="image/*" class="block w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm file:mr-3 file:rounded-md file:border-0 file:bg-brand file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-white dark:border-white/10 dark:bg-slate-900">
                            </label>
                            <p class="mt-2 text-xs text-slate-500">{{ $tr('JPG, PNG ou WebP. Taille maximum 2 Mo.') }}</p>
                            @if ($user->profile_photo_path)
                                <label class="mt-3 inline-flex items-center gap-2 text-sm font-semibold text-rose-600">
                                    <input name="remove_profile_photo" value="1" type="checkbox" class="size-4 accent-rose-500">
                                    {{ $tr('Retirer la photo actuelle') }}
                                </label>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="mt-6 border-t border-slate-200 pt-5 dark:border-white/10">
                    <h2 class="font-semibold">{{ $tr('Changer le mot de passe') }}</h2>
                    <p class="mt-1 text-sm text-slate-500">{{ $tr('Laissez les champs vides pour conserver le mot de passe actuel.') }}</p>
                    <div class="mt-4 grid gap-4 lg:grid-cols-3">
                        <input name="current_password" type="password" class="h-11 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="{{ $tr('Mot de passe actuel') }}">
                        <input name="password" type="password" class="h-11 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="{{ $tr('Nouveau mot de passe') }}">
                        <input name="password_confirmation" type="password" class="h-11 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="{{ $tr('Confirmation') }}">
                    </div>
                </div>

                @if ($isOwner)
                    <div class="mt-6 border-t border-slate-200 pt-5 dark:border-white/10">
                        <div class="mb-4 flex items-center gap-2">
                            <svg class="size-5 text-brand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            <h2 class="font-semibold">{{ $tr('Sécurité caisse') }}</h2>
                        </div>
                        <p class="mt-1 text-sm text-slate-500">{{ $tr('Chaque PIN identifie un utilisateur. Sur l\'écran verrouillé, entrer un PIN bascule la session vers cet utilisateur.') }}</p>
                        <div class="mt-4 grid gap-4 lg:grid-cols-2">
                            <label class="space-y-1.5">
                                <span class="text-xs font-semibold uppercase text-slate-500">{{ $tr('Code PIN (4 chiffres)') }}</span>
                                <input name="pin" type="password" inputmode="numeric" pattern="[0-9]{4}" minlength="4" maxlength="4" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="{{ $tr('Nouveau PIN à 4 chiffres') }}">
                            </label>
                            <label class="space-y-1.5">
                                <span class="text-xs font-semibold uppercase text-slate-500">{{ $tr('Confirmer le PIN') }}</span>
                                <input name="pin_confirmation" type="password" inputmode="numeric" pattern="[0-9]{4}" minlength="4" maxlength="4" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="{{ $tr('Confirmer le PIN') }}">
                            </label>
                        </div>
                        @if ($user->pin_hash)
                            <label class="mt-3 inline-flex items-center gap-2 text-sm font-semibold text-rose-600">
                                <input name="clear_pin" value="1" type="checkbox" class="size-4 accent-rose-500">
                                {{ $tr('Supprimer le PIN') }}
                            </label>
                        @endif
                    </div>
                @endif

                <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-end">
                    <a href="{{ route('dashboard') }}" class="rounded-lg border border-slate-200 px-5 py-2.5 text-center text-sm font-semibold text-slate-600 transition hover:bg-slate-50 dark:border-white/10 dark:text-slate-200 dark:hover:bg-white/5">{{ $tr('Annuler') }}</a>
                    <button class="rounded-lg bg-brand px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition brightness-100 hover:brightness-110">{{ $tr('Enregistrer') }}</button>
                </div>
            </form>
        </div>

        <aside class="space-y-6">
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                <div class="flex items-center gap-3">
                    <x-user-avatar :user="$user" size="lg" rounded="rounded-xl" />
                    <div class="min-w-0">
                        <p class="truncate font-semibold">{{ $user->name }}</p>
                        <p class="truncate text-sm text-slate-500">{{ $user->email }}</p>
                    </div>
                </div>
                <dl class="mt-5 space-y-3 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">{{ $tr('Rôle') }}</dt><dd class="font-semibold">{{ $roleName }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">{{ $tr('Statut') }}</dt><dd class="font-semibold">{{ $user->is_active ? $tr('Actif') : $tr('Désactivé') }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">{{ $tr('Téléphone') }}</dt><dd class="font-semibold">{{ $user->phone ?: '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">{{ $tr('Compte créé') }}</dt><dd class="font-semibold">{{ $user->created_at?->format('d/m/Y') }}</dd></div>
                    @if ($user->pin_hash)
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">{{ $tr('Code PIN') }}</dt><dd class="font-semibold text-emerald-600">{{ $tr('Actif') }}</dd></div>
                    @endif
                </dl>
                <a href="{{ route('module', ['module' => 'settings', 'section' => 'users']) }}" class="mt-5 block rounded-lg border border-slate-200 px-4 py-2.5 text-center text-sm font-semibold text-slate-700 transition hover:border-brand/40 hover:text-brand dark:border-white/10 dark:text-slate-200">{{ $tr('Utilisateurs & rôles') }}</a>
            </div>
        </aside>
    </section>
</x-layouts.app>
