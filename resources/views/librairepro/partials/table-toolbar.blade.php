@props(['action' => '#', 'query' => '', 'placeholder' => 'Rechercher'])

<form action="{{ $action }}" class="flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-3 dark:border-white/10 dark:bg-white/[0.03] sm:flex-row sm:items-center">
    <input name="q" value="{{ $query }}" class="h-10 min-w-0 flex-1 rounded-lg border border-slate-200 bg-slate-50 px-3 text-sm outline-none focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10 dark:border-white/10 dark:bg-white/5" placeholder="{{ $placeholder }}">
    <select name="status" class="h-10 rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">
        <option value="all">Tous les statuts</option>
        <option value="active" @selected(request('status') === 'active')>Actifs</option>
        <option value="archived" @selected(request('status') === 'archived')>Archivés</option>
        <option value="out_of_stock" @selected(request('status') === 'out_of_stock')>Rupture</option>
    </select>
    <button class="h-10 rounded-lg bg-indigo-600 px-4 text-sm font-semibold text-white" type="submit">Filtrer</button>
    <button class="h-10 rounded-lg border border-slate-200 px-4 text-sm font-semibold text-slate-700 dark:border-white/10 dark:text-slate-200" type="button">Exporter</button>
</form>
