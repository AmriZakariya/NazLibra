@php
    $group ??= null;
    $selectedPrinterIds = collect($selectedPrinterIds ?? [])->all();
    $selectedCategoryIds = collect($selectedCategoryIds ?? [])->map(fn ($id) => (int) $id)->all();
    $hasCatchAll = (bool) ($hasCatchAll ?? false);
@endphp

<div class="flex items-start justify-between gap-4 border-b border-slate-200 p-5 dark:border-white/10">
    <div>
        <p class="text-sm font-semibold text-brand">Groupes d’impression</p>
        <h3 class="mt-1 text-xl font-semibold">{{ $group ? 'Modifier le groupe' : 'Nouveau groupe d’impression' }}</h3>
        <p class="mt-1 text-sm text-slate-500">Assignez une ou plusieurs imprimantes, puis définissez les catégories à router.</p>
    </div>
    <button class="dialog-close grid size-9 shrink-0 place-items-center rounded-lg border border-slate-200 text-lg font-semibold dark:border-white/10" type="button">×</button>
</div>

<div class="grid gap-5 p-5">
    <div class="grid gap-4 md:grid-cols-2">
        <label class="space-y-1.5">
            <span class="text-xs font-semibold uppercase text-slate-500">Nom du groupe *</span>
            <input name="name" required value="{{ old('name', $group?->name) }}" class="h-11 w-full rounded-lg border border-slate-200 px-3 text-sm dark:border-white/10 dark:bg-slate-900" placeholder="Cuisine, Ticket, Rayon...">
        </label>
        <label class="space-y-1.5">
            <span class="text-xs font-semibold uppercase text-slate-500">Mode d’impression</span>
            <select name="print_mode" class="h-11 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">
                <option value="primary_fallback" @selected(old('print_mode', $group?->print_mode ?? 'primary_fallback') === 'primary_fallback')>Priorité + secours</option>
                <option value="simultaneous" @selected(old('print_mode', $group?->print_mode) === 'simultaneous')>Toutes les imprimantes</option>
            </select>
        </label>
    </div>

    <label class="flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold dark:border-white/10 dark:bg-white/5">
        <input name="is_receipt_group" value="1" type="checkbox" @checked(old('is_receipt_group', $group?->is_receipt_group ?? false)) class="size-4 accent-[var(--brand-primary)]">
        Groupe ticket de caisse
        <span class="font-normal text-slate-500">Utilisé pour les reçus généraux du terminal.</span>
    </label>

    <section class="rounded-xl border border-slate-200 p-4 dark:border-white/10">
        <div class="flex flex-col gap-1">
            <h4 class="font-semibold">Imprimantes assignées</h4>
            <p class="text-sm text-slate-500">Le groupe est indépendant. Vous pouvez le lier à plusieurs imprimantes, y compris sur des terminaux différents.</p>
        </div>
        <div class="mt-3 space-y-4">
            @forelse ($devices as $device)
                @php $devicePrinters = $printersByDevice->get((string) $device->id, collect()); @endphp
                <section class="rounded-lg border border-slate-200 bg-slate-50 p-3 dark:border-white/10 dark:bg-white/5">
                    <div class="mb-2 flex flex-wrap items-center gap-2">
                        <strong class="text-sm">{{ $device->name }}</strong>
                        <span class="text-xs text-slate-500">{{ $devicePrinters->count() }} imprimante(s)</span>
                    </div>
                    <div class="grid gap-2 md:grid-cols-2">
                        @forelse ($devicePrinters as $printer)
                            <label class="flex items-start gap-3 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-slate-950/40">
                                <input name="printer_ids[]" value="{{ $printer->id }}" type="checkbox" @checked(in_array($printer->id, $selectedPrinterIds, true)) class="mt-1 size-4 accent-[var(--brand-primary)]">
                                <span class="min-w-0">
                                    <strong class="block">{{ $printer->name }}</strong>
                                    <span class="block truncate text-xs text-slate-500">{{ strtoupper($printer->connection_type) }} · {{ $printer->address ?: 'adresse non définie' }}</span>
                                </span>
                            </label>
                        @empty
                            <p class="text-sm text-slate-500">Aucune imprimante sur ce terminal.</p>
                        @endforelse
                    </div>
                </section>
            @empty
                <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-100">
                    Aucun terminal disponible.
                </div>
            @endforelse
        </div>
    </section>

    <section class="rounded-xl border border-slate-200 p-4 dark:border-white/10">
        <div class="flex flex-col gap-1">
            <h4 class="font-semibold">Routage par catégories</h4>
            <p class="text-sm text-slate-500">Sélectionnez les familles envoyées à ce groupe. Catch-all reçoit les articles sans règle dédiée.</p>
        </div>
        <label class="mt-3 flex items-center gap-3 rounded-lg border border-teal-200 bg-teal-50 px-3 py-2 text-sm font-semibold text-teal-900 dark:border-teal-500/20 dark:bg-teal-500/10 dark:text-teal-100">
            <input name="catch_all" value="1" type="checkbox" @checked(old('catch_all', $hasCatchAll)) class="size-4 accent-[var(--brand-primary)]">
            Catch-all / toutes les autres catégories
        </label>
        <div class="mt-3 grid max-h-72 gap-2 overflow-y-auto pr-1 md:grid-cols-2">
            @forelse ($categories as $category)
                <label class="flex items-center gap-3 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-slate-950/40">
                    <input name="category_ids[]" value="{{ $category->id }}" type="checkbox" @checked(in_array((int) $category->id, $selectedCategoryIds, true)) class="size-4 accent-[var(--brand-primary)]">
                    <span class="truncate">{{ $category->name }}</span>
                </label>
            @empty
                <p class="text-sm text-slate-500">Aucune catégorie catalogue.</p>
            @endforelse
        </div>
    </section>
</div>

<div class="flex flex-col-reverse gap-2 border-t border-slate-200 bg-slate-50 p-5 dark:border-white/10 dark:bg-white/5 sm:flex-row sm:items-center sm:justify-end">
    <button type="button" class="dialog-close rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold dark:border-white/10 dark:bg-slate-950">Annuler</button>
    <button class="rounded-lg bg-brand px-5 py-2 text-sm font-semibold text-white">{{ $group ? 'Enregistrer' : 'Créer le groupe' }}</button>
</div>
