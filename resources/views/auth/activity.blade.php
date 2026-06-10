@php
    $locale = \App\Support\Locale::current($tenant);
    $tr = fn (string $text): string => \App\Support\Locale::t($text, $locale);
    $methodTones = ['POST' => 'success', 'PUT' => 'info', 'PATCH' => 'warning', 'DELETE' => 'danger'];
    $activeFilters = collect($filters)->filter(fn ($v) => $v !== '' && $v !== null)->except(['_token', 'page']);

    $subjectNavUrl = function (\App\Models\AuditLog $log) {
        $type = $log->subject_type;
        $id = $log->subject_id;
        if (! $type || ! $id) return null;

        $map = [
            'App\Models\Sale' => ['module' => 'sales', 'section' => 'list', 'param' => 'detail_sale'],
            'App\Models\SaleReturn' => ['module' => 'sales', 'section' => 'returns', 'param' => 'detail_return'],
            'App\Models\Purchase' => ['module' => 'purchases', 'section' => 'list', 'param' => 'detail_purchase'],
            'App\Models\PurchaseReturn' => ['module' => 'purchases', 'section' => 'returns', 'param' => 'detail_purchase_return'],
            'App\Models\Item' => ['route' => 'catalog', 'query' => ['panel' => 'articles']],
            'App\Models\Contact' => ['module' => 'contacts', 'section' => 'customers'],
        ];

        if (isset($map[$type])) {
            $m = $map[$type];
            if (isset($m['route'])) {
                return route($m['route'], $m['query'] ?? []);
            }
            return route('module', array_merge(['module' => $m['module'], 'section' => $m['section']], [$m['param'] => $id]));
        }

        return null;
    };
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
            <div class="relative">
                <svg class="pointer-events-none absolute left-4 top-1/2 size-5 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                <input name="q" value="{{ $filters['q'] }}" placeholder="{{ $tr('Rechercher par action, référence, appareil, route ou URL...') }}" class="h-12 w-full rounded-xl border border-slate-200 bg-white py-3 pl-12 pr-4 text-sm text-slate-950 outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900 dark:text-white" autofocus>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-6">
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
                    <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $tr('Appareil') }}</span>
                    <select name="device_id" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-950 outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15 dark:border-white/10 dark:bg-slate-900 dark:text-white">
                        <option value="">{{ $tr('Tous les appareils') }}</option>
                        @foreach ($devices as $device)
                            <option value="{{ $device->id }}" @selected((string) $filters['device_id'] === (string) $device->id)>{{ $device->name }}</option>
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
                    <button type="submit" class="h-11 flex-1 rounded-xl bg-brand px-4 text-sm font-semibold text-white shadow-sm shadow-indigo-500/20 transition hover:brightness-110">{{ $tr('Filtrer') }}</button>
                    <a href="{{ route('profile.activity') }}" class="inline-flex h-11 shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:border-brand/40 hover:text-brand dark:border-white/10 dark:bg-transparent dark:text-slate-200">{{ $tr('Effacer') }}</a>
                </div>
            </div>

            @if ($activeFilters->isNotEmpty())
                <div class="flex flex-wrap items-center gap-2 rounded-xl border border-brand/20 bg-brand/5 px-4 py-3 dark:border-brand/30 dark:bg-brand/10">
                    <svg class="size-4 shrink-0 text-brand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                    <span class="text-xs font-semibold uppercase tracking-wider text-brand">{{ $tr('Filtres actifs') }}</span>
                    @if ($filters['user_id'])
                        @php $filterUser = $users->firstWhere('id', (int) $filters['user_id']); @endphp
                        <span class="inline-flex items-center gap-1.5 rounded-full border border-brand/30 bg-white px-3 py-1 text-xs font-semibold text-brand dark:border-brand/40 dark:bg-brand/10">{{ $filterUser?->name ?? '#' . $filters['user_id'] }}<a href="{{ route('profile.activity', array_merge($filters, ['user_id' => ''])) }}" class="grid size-4 place-items-center rounded-full hover:bg-brand/10">×</a></span>
                    @endif
                    @if ($filters['device_id'])
                        @php $filterDevice = $devices->firstWhere('id', (int) $filters['device_id']); @endphp
                        <span class="inline-flex items-center gap-1.5 rounded-full border border-brand/30 bg-white px-3 py-1 text-xs font-semibold text-brand dark:border-brand/40 dark:bg-brand/10">{{ $filterDevice?->name ?? '#' . $filters['device_id'] }}<a href="{{ route('profile.activity', array_merge($filters, ['device_id' => ''])) }}" class="grid size-4 place-items-center rounded-full hover:bg-brand/10">×</a></span>
                    @endif
                    @if ($filters['method'])
                        <span class="inline-flex items-center gap-1.5 rounded-full border border-brand/30 bg-white px-3 py-1 text-xs font-semibold text-brand dark:border-brand/40 dark:bg-brand/10">{{ $filters['method'] }}<a href="{{ route('profile.activity', array_merge($filters, ['method' => ''])) }}" class="grid size-4 place-items-center rounded-full hover:bg-brand/10">×</a></span>
                    @endif
                    @if ($filters['from'])
                        <span class="inline-flex items-center gap-1.5 rounded-full border border-brand/30 bg-white px-3 py-1 text-xs font-semibold text-brand dark:border-brand/40 dark:bg-brand/10">≥ {{ $filters['from'] }}<a href="{{ route('profile.activity', array_merge($filters, ['from' => ''])) }}" class="grid size-4 place-items-center rounded-full hover:bg-brand/10">×</a></span>
                    @endif
                    @if ($filters['to'])
                        <span class="inline-flex items-center gap-1.5 rounded-full border border-brand/30 bg-white px-3 py-1 text-xs font-semibold text-brand dark:border-brand/40 dark:bg-brand/10">≤ {{ $filters['to'] }}<a href="{{ route('profile.activity', array_merge($filters, ['to' => ''])) }}" class="grid size-4 place-items-center rounded-full hover:bg-brand/10">×</a></span>
                    @endif
                    @if ($filters['q'])
                        <span class="inline-flex items-center gap-1.5 rounded-full border border-brand/30 bg-white px-3 py-1 text-xs font-semibold text-brand dark:border-brand/40 dark:bg-brand/10">"{{ \Illuminate\Support\Str::limit($filters['q'], 30) }}"<a href="{{ route('profile.activity', array_merge($filters, ['q' => ''])) }}" class="grid size-4 place-items-center rounded-full hover:bg-brand/10">×</a></span>
                    @endif
                    <span class="ml-auto text-xs text-slate-500 audit-result-count">{{ $totals['all'] }} {{ $tr('résultat(s)') }}</span>
                </div>
            @endif
        </form>
    </section>

    {{-- DataTable --}}
    <section class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/[0.03]">
        <div class="p-0">
            <table id="activity-table" class="dataTable display nowrap w-full text-left text-sm" style="width:100%">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:border-white/10 dark:bg-white/5 dark:text-slate-400">
                        <th class="px-5 py-3.5">{{ $tr('Date') }}</th>
                        <th class="px-5 py-3.5">{{ $tr('Utilisateur') }}</th>
                        <th class="px-5 py-3.5">{{ $tr('Action') }}</th>
                        <th class="px-5 py-3.5">{{ $tr('Reference') }}</th>
                        <th class="px-5 py-3.5">{{ $tr('Appareil') }}</th>
                        <th class="px-5 py-3.5 text-right">{{ $tr('Detail') }}</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </section>

    <!-- Detail dialog -->
    <dialog id="audit-detail-dialog" class="app-dialog w-[min(760px,calc(100vw-2rem))] rounded-2xl border border-slate-200 bg-white p-0 text-slate-950 shadow-2xl backdrop:bg-slate-950/45 dark:border-white/10 dark:bg-slate-950 dark:text-slate-100">
        <div id="audit-detail-content"></div>
    </dialog>

    <style>
        /* DataTables v2 wrapper */
        .dt-container { padding: 0 !important; }
        #activity-table thead th { border-bottom: 1px solid #e2e8f0; }
        .dark #activity-table thead th { border-bottom-color: rgba(255,255,255,0.1); }
        #activity-table tbody tr { border-bottom: 1px solid #f1f5f9; transition: background-color 150ms; }
        .dark #activity-table tbody tr { border-bottom-color: rgba(255,255,255,0.05); }
        #activity-table tbody tr:hover { background-color: #f8fafc; }
        .dark #activity-table tbody tr:hover { background-color: rgba(255,255,255,0.03); }
        #activity-table tbody tr:last-child { border-bottom: none; }
        #activity-table td { padding: 0.75rem 1.25rem; vertical-align: middle; }

        /* Pagination */
        .dt-paging { padding: 1rem 1.25rem; border-top: 1px solid #e2e8f0; }
        .dark .dt-paging { border-top-color: rgba(255,255,255,0.1); }
        .dt-info { padding: 1rem 1.25rem; }
        .dt-processing { border-radius: 0.75rem; background: rgba(255,255,255,0.9); border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); font-size: 0.875rem; font-weight: 600; color: #475569; }
        .dark .dt-processing { background: rgba(15,23,42,0.9); border-color: rgba(255,255,255,0.1); color: #94a3b8; }
        .dt-paging-button { border-radius: 0.5rem !important; padding: 0.375rem 0.75rem !important; margin: 0 0.125rem !important; font-size: 0.875rem !important; border: none !important; background: transparent !important; color: #475569 !important; }
        .dark .dt-paging-button { color: #94a3b8 !important; }
        .dt-paging-button:hover { background: #f1f5f9 !important; color: #0f172a !important; }
        .dark .dt-paging-button:hover { background: rgba(255,255,255,0.1) !important; color: #fff !important; }
        .dt-paging-button.current { background: #4f46e5 !important; color: #fff !important; font-weight: 600; }
        .dark .dt-paging-button.current { background: #6366f1 !important; }
        .dt-paging-button.disabled { opacity: 0.4; cursor: not-allowed; }
        .dt-empty { padding: 3rem 1.25rem !important; text-align: center; color: #94a3b8; }
        .dark .dt-empty { color: #64748b; }

        /* Hide default DataTables filter (we use our custom filter bar) */
        .dt-search { display: none !important; }
        .dt-length { display: none !important; }
    </style>

    <script>
        document.addEventListener("DOMContentLoaded", function () {
            var ajaxUrl = "{{ route('profile.activity.data', request()->query()) }}";
            var tableEl = document.getElementById("activity-table");
            if (!tableEl) {
                console.error("Activity table element not found");
                return;
            }

            try {
                window.activityTable = new DataTable(tableEl, {
                    ajax: {
                        url: ajaxUrl,
                        dataSrc: function(json) {
                            if (json.error) {
                                console.error("DataTable server error:", json.error);
                            }
                            return json.data || [];
                        },
                        error: function(xhr, error, thrown) {
                            console.error("DataTable AJAX error:", error, thrown);
                        }
                    },
                    processing: true,
                    serverSide: true,
                    autoWidth: false,
                    pageLength: 25,
                    order: [[0, "desc"]],
                    language: typeof window.dataTableLanguage === "function" ? window.dataTableLanguage() : {},
                    columns: [
                        { data: "created_at", name: "created_at", searchable: false },
                        { data: "user_name", name: "user_id", orderable: false, searchable: false,
                            render: function(data, type, row) {
                                return '<div class="flex items-center gap-3 whitespace-nowrap">' + (row.user_avatar || '') +
                                    '<div class="min-w-0"><p class="truncate text-[13px] font-semibold text-slate-900 dark:text-white">' + (data || "") + '</p>' +
                                    '<p class="truncate text-[11px] text-slate-400">' + (row.user_email || "") + '</p></div></div>';
                            }
                        },
                        { data: "action", name: "friendly_action", orderable: false, searchable: true },
                        { data: "reference", name: "subject_reference_snapshot", orderable: false, searchable: true,
                            render: function(data) { return data || '<span class="text-xs text-slate-400">—</span>'; }
                        },
                        { data: "device", name: "device_name_snapshot", orderable: false, searchable: true,
                            render: function(data) { return data || '<span class="text-xs text-slate-400">—</span>'; }
                        },
                        { data: "nav_url", name: "id", orderable: false, searchable: false, className: "text-right",
                            render: function(data, type, row) {
                                var btns = "";
                                if (data) {
                                    btns += '<a href="' + data + '" class="inline-flex items-center gap-1 rounded-xl border border-brand/20 bg-brand/5 px-3 py-2 text-[12px] font-semibold text-brand transition hover:bg-brand/10 mr-2"><svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg></a>';
                                }
                                btns += '<button type="button" onclick="auditOpenDetail(' + row.id + ')" class="inline-flex items-center gap-1 rounded-xl border border-slate-200 bg-white px-3 py-2 text-[12px] font-semibold text-slate-600 transition hover:border-brand/40 hover:text-brand dark:border-white/10 dark:bg-transparent dark:text-slate-300"><svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>';
                                return btns;
                            }
                        }
                    ],
                    columnDefs: [
                        { targets: [0], className: "whitespace-nowrap" },
                        { targets: "_all", defaultContent: "" }
                    ],
                    createdRow: function(row, data) {
                        row.setAttribute("data-row-id", data.id || "");
                        row.setAttribute("data-row-data", JSON.stringify(data));
                    },
                    drawCallback: function() {
                        var info = this.api().page.info();
                        var chip = document.querySelector(".audit-result-count");
                        if (chip) chip.textContent = info.recordsTotal + " {{ $tr('résultat(s)') }}";
                    }
                });
                console.log("Activity DataTable initialized successfully");
            } catch (e) {
                console.error("DataTable initialization error:", e);
            }
        });

        function auditOpenDetail(id) {
            var row = null;
            window.activityTable.rows().every(function() {
                if (this.data().id == id) {
                    row = this.data();
                    return false;
                }
            });
            if (!row) return;

            var props = row.properties_json ? JSON.parse(row.properties_json) : {};

            var dialog = document.getElementById("audit-detail-dialog");
            var content = document.getElementById("audit-detail-content");

            var methodClass = "bg-slate-100 text-slate-600";
            if (props.method === "POST") methodClass = "bg-emerald-100 text-emerald-700";
            else if (props.method === "PUT") methodClass = "bg-sky-100 text-sky-700";
            else if (props.method === "PATCH") methodClass = "bg-amber-100 text-amber-700";
            else if (props.method === "DELETE") methodClass = "bg-rose-100 text-rose-700";

            content.innerHTML = "" +
                '<div class="flex items-start gap-4 border-b border-slate-100 px-6 py-5 dark:border-white/10">' +
                    '<span class="grid size-11 shrink-0 place-items-center rounded-xl bg-brand/10 text-sm font-bold text-brand">' + (row.user_avatar || "") + '</span>' +
                    '<div class="min-w-0 flex-1 pt-0.5">' +
                        '<h3 class="text-base font-bold text-slate-900 dark:text-white">' + (row.user_name || "") + ' <span class="ml-2 text-sm font-normal text-slate-400">' + (row.created_at || "") + '</span></h3>' +
                        '<p class="mt-1 text-sm font-semibold text-brand">' + (row.action || "") + '</p>' +
                        '<div class="mt-2 flex flex-wrap items-center gap-2">' +
                            (row.reference ? '<span class="inline-flex items-center rounded-md bg-slate-100 px-2 py-1 text-xs font-bold text-slate-600"># ' + row.reference + '</span>' : '') +
                            (row.nav_url ? '<a href="' + row.nav_url + '" class="inline-flex items-center gap-1 rounded-md text-xs font-semibold text-brand hover:underline">\u2197 {{ $tr('Voir') }}</a>' : '') +
                        '</div>' +
                    '</div>' +
                    '<button class="dialog-close grid size-8 shrink-0 place-items-center rounded-lg text-lg text-slate-400 transition hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-white/10 dark:hover:text-white" type="button">&times;</button>' +
                '</div>' +
                '<div class="flex gap-1.5 border-b border-slate-100 bg-slate-50/40 px-3 py-2 dark:border-white/10 dark:bg-white/[0.02]">' +
                    '<button onclick="auditSwitchTab(\'summary\')" id="tab-btn-summary" class="audit-dt-tab inline-flex items-center gap-2 rounded-lg px-4 py-2 text-[13px] font-semibold transition bg-white text-brand shadow-sm ring-1 ring-slate-200/60"><svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg> {{ $tr('Résumé') }}</button>' +
                    (row.device ? '<button onclick="auditSwitchTab(\'device\')" id="tab-btn-device" class="audit-dt-tab inline-flex items-center gap-2 rounded-lg px-4 py-2 text-[13px] font-semibold transition text-slate-500 hover:text-slate-700 hover:bg-white/60"><svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg> {{ $tr('Appareil') }}</button>' : '') +
                    '<button onclick="auditSwitchTab(\'technical\')" id="tab-btn-tech" class="audit-dt-tab inline-flex items-center gap-2 rounded-lg px-4 py-2 text-[13px] font-semibold transition text-slate-500 hover:text-slate-700 hover:bg-white/60"><svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82"/></svg> {{ $tr('Technique') }}</button>' +
                '</div>' +
                '<div id="tab-panel-summary" class="px-6 py-5">' +
                    '<div class="grid gap-3 sm:grid-cols-3">' +
                        '<div class="rounded-xl border border-slate-200 bg-slate-50 p-4"><p class="text-[11px] font-bold uppercase tracking-[0.08em] text-slate-400">{{ $tr('Méthode') }}</p><span class="mt-2 inline-flex rounded-lg px-2.5 py-1 text-xs font-bold ' + methodClass + '">' + (props.method || "\u2014") + '</span></div>' +
                        '<div class="rounded-xl border border-slate-200 bg-slate-50 p-4"><p class="text-[11px] font-bold uppercase tracking-[0.08em] text-slate-400">{{ $tr('Statut') }}</p><p class="mt-2 text-xl font-black text-slate-950">' + (props.status_code || "\u2014") + '</p></div>' +
                        '<div class="rounded-xl border border-slate-200 bg-slate-50 p-4"><p class="text-[11px] font-bold uppercase tracking-[0.08em] text-slate-400">{{ $tr('IP') }}</p><p class="mt-2 truncate text-sm font-bold text-slate-700">' + (props.ip || "\u2014") + '</p></div>' +
                    '</div>' +
                    '<div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-4"><p class="text-[11px] font-bold uppercase tracking-[0.08em] text-slate-400">{{ $tr('Route') }}</p><p class="mt-1 text-sm font-semibold text-slate-800">' + (props.route || "\u2014") + '</p><div class="mt-2 space-y-1 rounded-lg bg-white p-2.5 dark:bg-white/5"><p class="break-all text-[11px] font-mono text-slate-500">' + (props.path || "\u2014") + '</p><p class="break-all text-[11px] font-mono text-slate-400">' + (props.url || "\u2014") + '</p></div></div>' +
                '</div>' +
                (row.device ? '<div id="tab-panel-device" class="hidden px-6 py-5">' + row.device + '</div>' : '') +
                '<div id="tab-panel-tech" class="hidden px-6 py-5">' +
                    '<div class="rounded-xl border border-slate-200 bg-slate-50 p-4"><p class="text-[11px] font-bold uppercase tracking-[0.08em] text-slate-400">{{ $tr('Action') }}</p><p class="mt-1 font-mono text-sm font-semibold text-slate-800">' + (row.action_raw || "") + '</p></div>' +
                    '<div class="mt-4 grid gap-4 lg:grid-cols-2"><div><p class="mb-2 text-xs font-bold uppercase tracking-[0.08em] text-slate-400">{{ $tr('Données') }}</p><pre class="max-h-72 overflow-auto rounded-xl border border-slate-200 bg-slate-950 p-4 text-xs leading-relaxed text-emerald-200">' + (props.payload_json || "{}") + '</pre></div><div><p class="mb-2 text-xs font-bold uppercase tracking-[0.08em] text-slate-400">{{ $tr('Paramètres') }}</p><pre class="max-h-72 overflow-auto rounded-xl border border-slate-200 bg-slate-950 p-4 text-xs leading-relaxed text-sky-200">' + (props.route_json || "{}") + '</pre></div></div>' +
                '</div>';

            // Store row data for tab switching
            dialog._auditRow = row;
            dialog._auditProps = props;
            dialog.showModal();

            // Wire close button
            content.querySelector(".dialog-close")?.addEventListener("click", function() { dialog.close(); });
            dialog.addEventListener("click", function(e) {
                if (e.target === dialog) dialog.close();
            });
        }

        function auditSwitchTab(tab) {
            ["summary", "device", "technical"].forEach(function(t) {
                var btn = document.getElementById("tab-btn-" + t);
                var panel = document.getElementById("tab-panel-" + t);
                if (!btn || !panel) return;
                if (t === tab) {
                    btn.className = "audit-dt-tab inline-flex items-center gap-2 rounded-lg px-4 py-2 text-[13px] font-semibold transition bg-white text-brand shadow-sm ring-1 ring-slate-200/60";
                    panel.classList.remove("hidden");
                } else {
                    btn.className = "audit-dt-tab inline-flex items-center gap-2 rounded-lg px-4 py-2 text-[13px] font-semibold transition text-slate-500 hover:text-slate-700 hover:bg-white/60";
                    panel.classList.add("hidden");
                }
            });
        }
    </script>
</x-layouts.app>
