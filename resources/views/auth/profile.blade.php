<x-layouts.app :tenant="$tenant" :active="$active" title="LibrairePro · Profil">
    <section class="flex flex-col gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03] lg:flex-row lg:items-center lg:justify-between">
        <div class="flex items-center gap-4">
            <span class="grid size-14 shrink-0 place-items-center rounded-2xl text-base font-bold text-white shadow-sm" style="background: {{ $user->avatar_color ?: 'var(--brand-primary)' }}">{{ Str::upper(Str::substr($user->name, 0, 2)) }}</span>
            <div>
            <p class="text-sm font-semibold text-brand">Compte utilisateur</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-normal">Mon profil</h1>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <span class="rounded-full bg-brand/10 px-2.5 py-1 text-xs font-semibold text-brand">{{ $roleName }}</span>
                @if ($roleKey)
                    <span class="rounded-full border border-slate-200 px-2.5 py-1 text-xs font-semibold text-slate-500 dark:border-white/10">Rôle: {{ $roleKey }}</span>
                @endif
            </div>
            <p class="mt-2 max-w-3xl text-sm text-slate-600 dark:text-slate-300">Gérez vos informations personnelles et votre mot de passe de connexion.</p>
            </div>
        </div>
        <form action="{{ route('logout') }}" method="POST">
            @csrf
            <button class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-2.5 text-sm font-semibold text-rose-600 transition hover:border-rose-300 hover:bg-rose-100 dark:border-rose-500/30 dark:bg-rose-500/10 dark:hover:bg-rose-500/20">Déconnexion</button>
        </form>
    </section>

    @if ($isOwner)
        <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <p class="text-sm font-semibold text-brand">Traçabilité propriétaire</p>
                    <h2 class="mt-1 text-xl font-semibold tracking-normal">Journal d’activité</h2>
                    <p class="mt-2 max-w-3xl text-sm text-slate-600 dark:text-slate-300">Toutes les actions d’écriture sont enregistrées: utilisateur, date, route, méthode, IP et données utiles nettoyées.</p>
                </div>
                <a href="{{ route('profile.activity') }}" class="inline-flex h-11 items-center justify-center rounded-lg bg-brand px-4 text-sm font-semibold text-white shadow-sm shadow-indigo-500/20 transition hover:brightness-110">Voir tous les logs</a>
            </div>
            <div class="mt-5 grid gap-3 lg:grid-cols-5">
                @forelse ($recentAuditLogs as $log)
                    <a href="{{ route('profile.activity', ['q' => $log->action]) }}" class="rounded-xl border border-slate-200 bg-slate-50 p-4 transition hover:border-brand/40 hover:bg-white dark:border-white/10 dark:bg-white/5 dark:hover:bg-white/10">
                        <div class="flex items-center justify-between gap-3">
                            <span class="truncate text-sm font-semibold text-slate-950 dark:text-white">{{ $log->action }}</span>
                            <span class="shrink-0 rounded-full bg-white px-2 py-1 text-[11px] font-bold text-slate-500 dark:bg-slate-950/60">{{ data_get($log->properties, 'method', '—') }}</span>
                        </div>
                        <p class="mt-2 truncate text-xs text-slate-500">{{ $log->user?->name ?? 'Système' }} · {{ data_get($log->properties, 'path', '—') }}</p>
                        <p class="mt-2 text-xs font-semibold text-slate-400">{{ $log->created_at?->format('d/m/Y H:i') }}</p>
                    </a>
                @empty
                    <div class="rounded-xl border border-dashed border-slate-200 p-5 text-sm text-slate-500 dark:border-white/10 lg:col-span-5">Aucune action enregistrée pour le moment.</div>
                @endforelse
            </div>
        </section>
    @endif

    <section class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1fr)_340px]">
        <form action="{{ route('profile.update') }}" method="POST" class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
            @csrf
            @method('PUT')
            <div class="mb-5 flex items-start justify-between gap-4 border-b border-slate-200 pb-4 dark:border-white/10">
                <div>
                    <h2 class="font-semibold">Informations de connexion</h2>
                    <p class="mt-1 text-sm text-slate-500">Ces informations apparaissent dans l’en-tête et dans les journaux d’activité.</p>
                </div>
            </div>
            <div class="grid gap-4 lg:grid-cols-2">
                <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Nom</span><input name="name" required value="{{ old('name', $user->name) }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Email</span><input name="email" required type="email" value="{{ old('email', $user->email) }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Téléphone</span><input name="phone" value="{{ old('phone', $user->phone) }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900"></label>
                <label class="space-y-1.5"><span class="text-xs font-semibold uppercase text-slate-500">Couleur avatar</span><input name="avatar_color" type="color" value="{{ old('avatar_color', $user->avatar_color) }}" class="h-11 w-full rounded-lg border border-slate-200 p-1 dark:border-white/10 dark:bg-slate-900"></label>
            </div>

            <div class="mt-6 border-t border-slate-200 pt-5 dark:border-white/10">
                <h2 class="font-semibold">Changer le mot de passe</h2>
                <p class="mt-1 text-sm text-slate-500">Laissez les champs vides pour conserver le mot de passe actuel.</p>
                <div class="mt-4 grid gap-4 lg:grid-cols-3">
                    <input name="current_password" type="password" class="h-11 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Mot de passe actuel">
                    <input name="password" type="password" class="h-11 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Nouveau mot de passe">
                    <input name="password_confirmation" type="password" class="h-11 rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Confirmation">
                </div>
            </div>

            <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-end">
                <a href="{{ route('dashboard') }}" class="rounded-lg border border-slate-200 px-5 py-2.5 text-center text-sm font-semibold text-slate-600 transition hover:bg-slate-50 dark:border-white/10 dark:text-slate-200 dark:hover:bg-white/5">Annuler</a>
                <button class="rounded-lg bg-brand px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition brightness-100 hover:brightness-110">Enregistrer</button>
            </div>
        </form>

        <aside class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
            <div class="flex items-center gap-3">
                <span class="grid size-12 place-items-center rounded-xl text-sm font-bold text-white" style="background: {{ $user->avatar_color ?: 'var(--brand-primary)' }}">{{ Str::upper(Str::substr($user->name, 0, 2)) }}</span>
                <div class="min-w-0">
                    <p class="truncate font-semibold">{{ $user->name }}</p>
                    <p class="truncate text-sm text-slate-500">{{ $user->email }}</p>
                </div>
            </div>
            <dl class="mt-5 space-y-3 text-sm">
                <div class="flex justify-between gap-3"><dt class="text-slate-500">Rôle</dt><dd class="font-semibold">{{ $roleName }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-slate-500">Statut</dt><dd class="font-semibold">{{ $user->is_active ? 'Actif' : 'Désactivé' }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-slate-500">Téléphone</dt><dd class="font-semibold">{{ $user->phone ?: '—' }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-slate-500">Compte créé</dt><dd class="font-semibold">{{ $user->created_at?->format('d/m/Y') }}</dd></div>
            </dl>
            <a href="{{ route('module', ['module' => 'settings', 'section' => 'users']) }}" class="mt-5 block rounded-lg border border-slate-200 px-4 py-2.5 text-center text-sm font-semibold text-slate-700 transition hover:border-brand/40 hover:text-brand dark:border-white/10 dark:text-slate-200">Utilisateurs & rôles</a>
        </aside>
    </section>
</x-layouts.app>
