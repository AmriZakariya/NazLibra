@php
    $statusMeta = [
        'visible' => ['label' => 'Visible', 'classes' => 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20'],
        'code' => ['label' => 'Code existe', 'classes' => 'bg-sky-50 text-sky-700 ring-sky-200 dark:bg-sky-500/10 dark:text-sky-300 dark:ring-sky-500/20'],
        'review' => ['label' => 'A revoir', 'classes' => 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20'],
    ];
@endphp

<x-layouts.app :tenant="$tenant" :active="$active" title="LibrairePro · Guide fonctionnalités">
    <section class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
        <div>
            <p class="text-sm font-medium text-brand">App Functionality Summary</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-normal">Guide des fonctionnalités</h1>
            <p class="mt-2 max-w-4xl text-sm text-slate-600 dark:text-slate-300">Résumé scannable des modules, écrans et actions disponibles dans l application, avec les éléments présents en code et les points à revoir.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="#missing-review" class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 text-sm font-semibold text-amber-800 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-200">Missing / To Review</a>
            <a href="{{ route('dashboard') }}" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white shadow-sm">Tableau de bord</a>
        </div>
    </section>

    <section class="mt-6 grid gap-3 md:grid-cols-2 xl:grid-cols-5">
        <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
            <span class="text-xs font-semibold uppercase text-slate-500">Groupes</span>
            <strong class="mt-2 block text-2xl text-slate-950 dark:text-white">{{ $summary['groups'] }}</strong>
        </article>
        <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
            <span class="text-xs font-semibold uppercase text-slate-500">Fonctionnalités</span>
            <strong class="mt-2 block text-2xl text-slate-950 dark:text-white">{{ $summary['features'] }}</strong>
        </article>
        <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
            <span class="text-xs font-semibold uppercase text-slate-500">Visibles UI</span>
            <strong class="mt-2 block text-2xl text-emerald-600">{{ $summary['visible'] }}</strong>
        </article>
        <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
            <span class="text-xs font-semibold uppercase text-slate-500">Code existe</span>
            <strong class="mt-2 block text-2xl text-sky-600">{{ $summary['code'] }}</strong>
        </article>
        <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
            <span class="text-xs font-semibold uppercase text-slate-500">A revoir</span>
            <strong class="mt-2 block text-2xl text-amber-600">{{ $summary['review'] }}</strong>
        </article>
    </section>

    <section class="mt-6 grid gap-3 lg:grid-cols-3 2xl:grid-cols-4">
        @foreach ($groups as $group)
            @php
                $tileTarget = $group['title'] === 'Missing / To Review' ? 'missing-review' : 'guide-'.\Illuminate\Support\Str::slug($group['title']);
            @endphp
            <a href="#{{ $tileTarget }}" class="rounded-xl border border-slate-200 bg-white p-4 text-sm shadow-sm transition hover:border-brand/40 hover:text-brand dark:border-white/10 dark:bg-white/[0.03]">
                <span class="font-semibold">{{ $group['title'] }}</span>
                <span class="mt-1 block text-xs text-slate-500 dark:text-slate-400">{{ count($group['features']) }} élément(s)</span>
            </a>
        @endforeach
    </section>

    <section class="mt-6 space-y-5">
        @foreach ($groups as $group)
            @php
                $groupId = \Illuminate\Support\Str::slug($group['title']);
                $isReviewGroup = $group['title'] === 'Missing / To Review';
            @endphp
            <article id="{{ $isReviewGroup ? 'missing-review' : 'guide-'.$groupId }}" class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
                <div class="border-b border-slate-200 bg-slate-50/70 p-5 dark:border-white/10 dark:bg-white/[0.04]">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h2 class="text-lg font-semibold">{{ $group['title'] }}</h2>
                            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $group['description'] }}</p>
                        </div>
                        <span class="rounded-full bg-white px-3 py-1 text-xs font-semibold text-slate-500 ring-1 ring-slate-200 dark:bg-slate-950 dark:ring-white/10">{{ count($group['features']) }} fonctionnalité(s)</span>
                    </div>
                </div>

                <div class="divide-y divide-slate-200 dark:divide-white/10">
                    @foreach ($group['features'] as $item)
                        @php
                            $status = $statusMeta[$item['status']] ?? $statusMeta['visible'];
                        @endphp
                        <div class="grid gap-3 p-4 lg:grid-cols-[220px_minmax(0,1fr)_130px_110px] lg:items-center">
                            <div>
                                <h3 class="text-sm font-semibold text-slate-950 dark:text-white">{{ $item['name'] }}</h3>
                            </div>
                            <p class="text-sm leading-6 text-slate-600 dark:text-slate-300">{{ $item['description'] }}</p>
                            <span class="inline-flex w-max rounded-full px-2.5 py-1 text-xs font-semibold ring-1 {{ $status['classes'] }}">{{ $status['label'] }}</span>
                            <a href="{{ $item['href'] }}" class="inline-flex h-10 items-center justify-center rounded-lg border border-slate-200 px-3 text-sm font-semibold text-slate-700 transition hover:border-brand/40 hover:text-brand dark:border-white/10 dark:text-slate-200">Ouvrir</a>
                        </div>
                    @endforeach
                </div>
            </article>
        @endforeach
    </section>
</x-layouts.app>
