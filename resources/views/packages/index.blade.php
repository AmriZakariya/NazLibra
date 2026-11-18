@php
    $money = fn ($v) => \App\Support\Money::for($tenant)->format($v);
    $statusLabels = ['active' => 'Actif', 'used' => 'Épuisé', 'expired' => 'Expiré', 'cancelled' => 'Annulé'];
    $input = 'h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900';
    $canManage = \App\Support\Permissions::userCan($tenant, auth()->user(), 'packages.manage');
@endphp
<x-layouts.app :tenant="$tenant" active="packages" title="Forfaits">
    <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
        <p class="text-sm font-semibold text-brand">Ventes</p>
        <h1 class="mt-1 text-[1.75rem] font-bold tracking-tight text-slate-950 dark:text-white">Forfaits</h1>
        <p class="mt-2 max-w-2xl text-sm text-slate-500 dark:text-slate-400">Un forfait est un nombre de séances d’un service, payé d’avance par un client. En caisse, avec le client sélectionné, le service est proposé « avec le forfait » : la ligne est à 0 et une séance est décomptée.</p>
        @if (session('status'))
            <p class="mt-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">{{ session('status') }}</p>
        @endif
        @if ($errors->any())
            <p class="mt-4 rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">{{ $errors->first() }}</p>
        @endif
    </section>

    <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
        <h2 class="font-semibold">Forfaits proposés</h2>
        <div class="mt-4 grid gap-3">
            @forelse ($definitions as $def)
                <form method="POST" action="{{ route('packages.update', $def) }}" class="grid gap-2 rounded-xl border border-slate-200 p-3 lg:grid-cols-[2fr_2fr_1fr_1fr_1fr_auto_auto_auto] dark:border-white/10" data-package-definition>
                    @csrf @method('PUT')
                    <input name="name" value="{{ $def->name }}" class="{{ $input }}" required @disabled(! $canManage)>
                    <select name="item_id" class="{{ $input }}" @disabled(! $canManage)>
                        @foreach ($services as $svc)<option value="{{ $svc->id }}" @selected($svc->id === $def->item_id)>{{ $svc->title }}</option>@endforeach
                    </select>
                    <input type="number" min="1" name="sessions" value="{{ $def->sessions }}" class="{{ $input }}" title="Séances" @disabled(! $canManage)>
                    <input type="number" min="0" step="0.01" name="price" value="{{ $def->price }}" class="{{ $input }}" title="Prix" @disabled(! $canManage)>
                    <input type="number" min="1" name="validity_days" value="{{ $def->validity_days }}" placeholder="Validité (jours)" class="{{ $input }}" @disabled(! $canManage)>
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked($def->is_active) @disabled(! $canManage)> Actif</label>
                    @if ($canManage)
                        <button class="h-11 rounded-lg border border-slate-200 px-3 text-sm font-semibold dark:border-white/10">Enregistrer</button>
                        <button type="submit" form="delete-package-{{ $def->id }}" class="h-11 rounded-lg px-3 text-sm font-semibold text-rose-600">Supprimer</button>
                    @endif
                </form>
                @if ($canManage)
                    <form id="delete-package-{{ $def->id }}" method="POST" action="{{ route('packages.destroy', $def) }}" onsubmit="return confirm('Supprimer ce forfait ?')">@csrf @method('DELETE')</form>
                @endif
            @empty
                <p class="text-sm text-slate-500">Aucun forfait défini.</p>
            @endforelse
        </div>

        @if ($canManage)
            <form method="POST" action="{{ route('packages.store') }}" class="mt-5 grid gap-2 rounded-xl border border-dashed border-slate-300 p-3 lg:grid-cols-[2fr_2fr_1fr_1fr_1fr_auto] dark:border-white/20" data-package-create>
                @csrf
                <input name="name" placeholder="Nom (ex. 5 brushings)" class="{{ $input }}" required>
                <select name="item_id" class="{{ $input }}" required>
                    <option value="">Service…</option>
                    @foreach ($services as $svc)<option value="{{ $svc->id }}">{{ $svc->title }}</option>@endforeach
                </select>
                <input type="number" min="1" name="sessions" placeholder="Séances" class="{{ $input }}" required>
                <input type="number" min="0" step="0.01" name="price" placeholder="Prix" class="{{ $input }}" required>
                <input type="number" min="1" name="validity_days" placeholder="Validité (jours)" class="{{ $input }}">
                <button class="h-11 rounded-lg bg-brand px-4 text-sm font-semibold text-white">Ajouter</button>
            </form>
        @endif
    </section>

    <section class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 p-4 dark:border-white/10">
            <h2 class="font-semibold">Forfaits des clients</h2>
            <form method="GET" action="{{ route('packages') }}" class="flex gap-2">
                <input name="q" value="{{ $q }}" placeholder="Client, téléphone, forfait" class="{{ $input }} w-60">
                <select name="status" class="{{ $input }} w-40">
                    <option value="all" @selected($status === 'all')>Tous</option>
                    @foreach ($statusLabels as $key => $label)<option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>@endforeach
                </select>
                <button class="h-11 rounded-lg bg-brand px-4 text-sm font-semibold text-white">Filtrer</button>
            </form>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-slate-200 text-xs uppercase text-slate-500 dark:border-white/10"><tr><th class="p-3">Client</th><th class="p-3">Forfait</th><th class="p-3">Statut</th><th class="p-3 text-right">Séances</th><th class="p-3">Expire le</th><th class="p-3">Actions</th></tr></thead>
                <tbody>
                    @forelse ($owned as $cp)
                        <tr class="border-b border-slate-100 align-top last:border-0 dark:border-white/5" data-customer-package>
                            <td class="p-3">{{ $cp->contact?->name }}<div class="text-xs text-slate-500">{{ $cp->contact?->phone }}</div></td>
                            <td class="p-3">{{ $cp->name }}</td>
                            <td class="p-3">{{ $statusLabels[$cp->status] ?? $cp->status }}</td>
                            <td class="p-3 text-right font-semibold">{{ $cp->sessions_remaining }} / {{ $cp->sessions_total }}</td>
                            <td class="p-3">{{ $cp->expires_at?->format('d/m/Y') ?? '—' }}</td>
                            <td class="p-3">
                                @if ($canManage && $cp->status !== 'cancelled')
                                    <form method="POST" action="{{ route('customer-packages.adjust', $cp) }}" class="mb-1 flex gap-1">@csrf
                                        <input type="number" name="sessions" placeholder="±" class="h-9 w-16 rounded border border-slate-200 px-2 dark:border-white/10 dark:bg-slate-900" required>
                                        <input name="reason" placeholder="Motif" class="h-9 w-32 rounded border border-slate-200 px-2 dark:border-white/10 dark:bg-slate-900" required>
                                        <button class="h-9 rounded border border-slate-200 px-2 text-xs font-semibold dark:border-white/10">Ajuster</button>
                                    </form>
                                    <form method="POST" action="{{ route('customer-packages.cancel', $cp) }}" class="flex gap-1" onsubmit="return confirm('Annuler ce forfait ?')">@csrf
                                        <input name="reason" placeholder="Motif" class="h-9 w-32 rounded border border-slate-200 px-2 dark:border-white/10 dark:bg-slate-900" required>
                                        <button class="h-9 rounded px-2 text-xs font-semibold text-rose-600">Annuler</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="p-8 text-center text-slate-500">Aucun forfait client.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $owned->links() }}</div>
    </section>
</x-layouts.app>
