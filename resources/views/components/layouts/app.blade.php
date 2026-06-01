@php
    $themeDefaults = [
        'primary' => '#3157D5',
        'accent' => '#0F9F8A',
        'success' => '#16A34A',
        'warning' => '#D97706',
        'danger' => '#E11D48',
        'info' => '#0284C7',
        'background' => '#F4F7FB',
        'surface_color' => '#FFFFFF',
        'surface_muted' => '#EEF3F8',
        'text' => '#101828',
        'muted' => '#64748B',
        'border' => '#D7DEE9',
        'font_scale' => '1',
        'radius' => '12',
        'density' => 'comfortable',
    ];
    $theme = array_merge($themeDefaults, $tenant->settings['theme'] ?? []);
    $layoutStores = collect($tenant->settings['stores'] ?? [])
        ->map(fn ($store) => is_array($store) ? $store : ['name' => (string) $store])
        ->map(function (array $store) use ($tenant) {
            $name = trim((string) ($store['name'] ?? 'Magasin principal'));

            return [
                'key' => (string) ($store['key'] ?? Str::slug($name)),
                'name' => $name,
                'type' => (string) ($store['type'] ?? 'store'),
                'is_active' => (bool) ($store['is_active'] ?? true),
            ];
        })
        ->filter(fn ($store) => $store['name'] !== '')
        ->values();
    if ($layoutStores->isEmpty()) {
        $layoutStores = collect([
            ['key' => 'magasin-principal', 'name' => 'Magasin principal', 'type' => 'store', 'is_active' => true],
            ['key' => 'depot', 'name' => 'Dépôt', 'type' => 'warehouse', 'is_active' => true],
            ['key' => 'rayon-scolaire', 'name' => 'Rayon scolaire', 'type' => 'area', 'is_active' => true],
        ]);
    }
    $layoutCurrentStore = $layoutStores->firstWhere('key', $tenant->settings['current_store'] ?? null) ?? $layoutStores->first();
@endphp
<!DOCTYPE html>
<html lang="fr" dir="ltr" style="--brand-primary: {{ $theme['primary'] }}; --brand-accent: {{ $theme['accent'] }}; --brand-success: {{ $theme['success'] }}; --brand-warning: {{ $theme['warning'] ?? '#D97706' }}; --brand-danger: {{ $theme['danger'] ?? '#E11D48' }}; --brand-info: {{ $theme['info'] ?? '#0284C7' }}; --app-bg: {{ $theme['background'] }}; --surface: {{ $theme['surface_color'] }}; --surface-muted: {{ $theme['surface_muted'] }}; --text-main: {{ $theme['text'] }}; --text-muted: {{ $theme['muted'] }}; --border-soft: {{ $theme['border'] }}; --font-scale: {{ $theme['font_scale'] }}; --brand-radius: {{ $theme['radius'] }}px;">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="light dark">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ $title ?? 'LibrairePro' }}</title>
        <script>
            const libraireProForceCollapsedSidebar = @json(request()->routeIs('pos'));
            if (libraireProForceCollapsedSidebar) {
                localStorage.setItem('librairepro-sidebar', 'collapsed');
            }
            const libraireProSidebarState = localStorage.getItem('librairepro-sidebar');
            if (libraireProSidebarState === null || libraireProSidebarState === 'collapsed') {
                document.documentElement.classList.add('sidebar-collapsed');
            }
        </script>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-slate-50 text-slate-950 antialiased dark:bg-slate-950 dark:text-slate-100">
        @php
            $currentPath = trim(request()->path(), '/');
            $currentQuery = request()->query();
            $isCurrentLink = function (string $href) use ($currentPath, $currentQuery): bool {
                $parts = parse_url($href);
                $path = trim($parts['path'] ?? '/', '/');
                parse_str($parts['query'] ?? '', $query);

                if ($path !== $currentPath) {
                    return false;
                }

                if ($path === 'catalogue/etiquettes' && count($query) === 0) {
                    return true;
                }

                foreach ($query as $key => $value) {
                    if ((string) ($currentQuery[$key] ?? '') !== (string) $value) {
                        return false;
                    }
                }

                return count($query) > 0 || count($currentQuery) === 0;
            };
            $articleLinks = [
                ['label' => 'Ajouter un article', 'icon' => '+', 'href' => route('catalog', ['panel' => 'ajouter'])],
                ['label' => 'Ajouter un service', 'icon' => '+', 'href' => route('catalog', ['panel' => 'ajouter-service'])],
                ['label' => "Liste d'articles", 'icon' => '≡', 'href' => route('catalog', ['panel' => 'articles'])],
                ['label' => 'Liste des catégories', 'icon' => '≡', 'href' => route('catalog', ['panel' => 'categories'])],
                ['label' => 'Liste des marques', 'icon' => '≡', 'href' => route('catalog', ['panel' => 'marques'])],
                ['label' => 'Liste des unités', 'icon' => '≡', 'href' => route('catalog', ['panel' => 'unites'])],
                ['label' => 'Liste des impôts', 'icon' => '%', 'href' => route('catalog', ['panel' => 'impots'])],
                ['label' => 'Liste des variantes', 'icon' => '≡', 'href' => route('catalog', ['panel' => 'variantes'])],
                ['label' => 'Imprimer des étiquettes', 'icon' => '▥', 'href' => route('catalog.labels')],
                ['label' => "Services d'importation", 'icon' => '↤', 'href' => route('catalog', ['panel' => 'import', 'kind' => 'items'])],
            ];
            $quickAdds = [
                ['label' => 'Ventes', 'href' => route('module', ['module' => 'sales', 'section' => 'add'])],
                ['label' => 'Devis', 'href' => route('module', ['module' => 'sales', 'section' => 'quote-add'])],
                ['label' => 'Achat', 'href' => route('module', ['module' => 'purchases', 'section' => 'add'])],
                ['label' => 'Client', 'href' => route('module', ['module' => 'contacts', 'section' => 'customer-add'])],
                ['label' => 'Fournisseur', 'href' => route('module', ['module' => 'contacts', 'section' => 'supplier-add'])],
                ['label' => 'Article', 'href' => route('catalog', ['panel' => 'ajouter'])],
                ['label' => 'Frais', 'href' => route('module', ['module' => 'finance', 'section' => 'expense-add'])],
            ];
            $nav = [
                ['key' => 'dashboard', 'label' => 'Tableau de bord', 'icon' => '⌂', 'href' => route('dashboard')],
                ['key' => 'catalog', 'label' => 'Articles', 'icon' => '▦', 'href' => route('catalog'), 'children' => $articleLinks],
                ['key' => 'sales', 'label' => 'Ventes', 'icon' => '₧', 'href' => route('pos'), 'children' => [
                    ['label' => 'Point de vente', 'icon' => '◉', 'href' => route('pos')],
                    ['label' => 'Ajouter une vente', 'icon' => '+', 'href' => route('module', ['module' => 'sales', 'section' => 'add'])],
                    ['label' => 'Liste des ventes', 'icon' => '≡', 'href' => route('module', 'sales')],
                    ['label' => 'Paiements des ventes', 'icon' => '≡', 'href' => route('module', ['module' => 'sales', 'section' => 'payments'])],
                    ['label' => 'Liste des retours de vente', 'icon' => '≡', 'href' => route('module', ['module' => 'sales', 'section' => 'returns'])],
                    ['label' => 'Liste de livraison', 'icon' => '≡', 'href' => route('module', ['module' => 'sales', 'section' => 'delivery'])],
                ]],
                ['key' => 'sales', 'label' => 'Facture', 'icon' => '▤', 'href' => route('module', ['module' => 'sales', 'section' => 'invoices'])],
                ['key' => 'purchases', 'label' => 'Achat', 'icon' => '↧', 'href' => route('module', 'purchases'), 'children' => [
                    ['label' => 'Nouvel achat', 'icon' => '+', 'href' => route('module', ['module' => 'purchases', 'section' => 'add'])],
                    ['label' => "Liste d'achat", 'icon' => '≡', 'href' => route('module', ['module' => 'purchases', 'section' => 'list'])],
                    ['label' => "Liste des retours d'achat", 'icon' => '≡', 'href' => route('module', ['module' => 'purchases', 'section' => 'returns'])],
                ]],
                ['key' => 'finance', 'label' => 'Dépenses', 'icon' => '−', 'href' => route('module', ['module' => 'finance', 'section' => 'expenses']), 'children' => [
                    ['label' => 'Liste des dépenses', 'icon' => '≡', 'href' => route('module', ['module' => 'finance', 'section' => 'expenses'])],
                    ['label' => 'Liste des catégories', 'icon' => '≡', 'href' => route('module', ['module' => 'finance', 'section' => 'expense-categories'])],
                ]],
                ['key' => 'sales', 'label' => 'Devis', 'icon' => '□', 'href' => route('module', ['module' => 'sales', 'section' => 'quotes']), 'children' => [
                    ['label' => 'Nouveau devis', 'icon' => '+', 'href' => route('module', ['module' => 'sales', 'section' => 'quote-add'])],
                    ['label' => 'Liste de devis', 'icon' => '≡', 'href' => route('module', ['module' => 'sales', 'section' => 'quotes'])],
                ]],
                ['key' => 'contacts', 'label' => 'Les clients', 'icon' => '◌', 'href' => route('module', ['module' => 'contacts', 'section' => 'customers']), 'children' => [
                    ['label' => 'Ajouter un client', 'icon' => '+', 'href' => route('module', ['module' => 'contacts', 'section' => 'customer-add'])],
                    ['label' => 'Liste des clients', 'icon' => '≡', 'href' => route('module', ['module' => 'contacts', 'section' => 'customers'])],
                    ['label' => 'Importer des clients', 'icon' => '↤', 'href' => route('module', ['module' => 'contacts', 'section' => 'import-customers'])],
                ]],
                ['key' => 'contacts', 'label' => 'Les fournisseurs', 'icon' => '▱', 'href' => route('module', ['module' => 'contacts', 'section' => 'suppliers']), 'children' => [
                    ['label' => 'Ajouter un fournisseur', 'icon' => '+', 'href' => route('module', ['module' => 'contacts', 'section' => 'supplier-add'])],
                    ['label' => 'Liste des fournisseurs', 'icon' => '≡', 'href' => route('module', ['module' => 'contacts', 'section' => 'suppliers'])],
                    ['label' => 'Importer des fournisseurs', 'icon' => '↤', 'href' => route('module', ['module' => 'contacts', 'section' => 'import-suppliers'])],
                ]],
                ['key' => 'finance', 'label' => 'Avance', 'icon' => '$', 'href' => route('module', ['module' => 'finance', 'section' => 'advances']), 'children' => [
                    ['label' => 'Ajouter une avance', 'icon' => '+', 'href' => route('module', ['module' => 'finance', 'section' => 'advance-add'])],
                    ['label' => 'Liste des avances', 'icon' => '≡', 'href' => route('module', ['module' => 'finance', 'section' => 'advances'])],
                ]],
                ['key' => 'finance', 'label' => 'Coupons', 'icon' => '◇', 'href' => route('module', ['module' => 'finance', 'section' => 'coupons']), 'children' => [
                    ['label' => 'Créer un coupon client', 'icon' => '+', 'href' => route('module', ['module' => 'finance', 'section' => 'customer-coupon-add'])],
                    ['label' => 'Liste des coupons client', 'icon' => '≡', 'href' => route('module', ['module' => 'finance', 'section' => 'customer-coupons'])],
                    ['label' => 'Créer un coupon', 'icon' => '+', 'href' => route('module', ['module' => 'finance', 'section' => 'coupon-add'])],
                    ['label' => 'Maître des coupons', 'icon' => '≡', 'href' => route('module', ['module' => 'finance', 'section' => 'coupons'])],
                ]],
                ['key' => 'finance', 'label' => 'Comptes', 'icon' => '▦', 'href' => route('module', ['module' => 'finance', 'section' => 'accounts']), 'children' => [
                    ['label' => 'Ajouter un compte', 'icon' => '+', 'href' => route('module', ['module' => 'finance', 'section' => 'account-add'])],
                    ['label' => 'Liste des comptes', 'icon' => '≡', 'href' => route('module', ['module' => 'finance', 'section' => 'accounts'])],
                    ['label' => "Liste des transferts d'argent", 'icon' => '≡', 'href' => route('module', ['module' => 'finance', 'section' => 'transfers'])],
                    ['label' => 'Liste de dépôt', 'icon' => '≡', 'href' => route('module', ['module' => 'finance', 'section' => 'deposits'])],
                    ['label' => 'Transactions en espèces', 'icon' => '⇄', 'href' => route('module', ['module' => 'finance', 'section' => 'cash'])],
                ]],
                ['key' => 'catalog', 'label' => 'Stock', 'icon' => '⌛', 'href' => route('catalog', ['panel' => 'articles', 'stock' => 'low']), 'children' => [
                    ['label' => "Ajouter ajustement", 'icon' => '+', 'href' => route('catalog', ['panel' => 'stock-adjustment-add'])],
                    ['label' => "Liste d'ajustement", 'icon' => '≡', 'href' => route('catalog', ['panel' => 'stock-adjustments'])],
                    ['label' => 'Ajouter transfert', 'icon' => '+', 'href' => route('catalog', ['panel' => 'stock-transfer-add'])],
                    ['label' => 'Liste de transfert', 'icon' => '≡', 'href' => route('catalog', ['panel' => 'stock-transfers'])],
                ]],
                ['key' => 'settings', 'label' => 'Utilisateurs', 'icon' => '♙', 'href' => route('module', ['module' => 'settings', 'section' => 'users']), 'children' => [
                    ['label' => 'Liste des utilisateurs', 'icon' => '≡', 'href' => route('module', ['module' => 'settings', 'section' => 'users'])],
                    ['label' => 'Liste des rôles', 'icon' => '≡', 'href' => route('module', ['module' => 'settings', 'section' => 'roles'])],
                ]],
                ['key' => 'settings', 'label' => 'Messagerie', 'icon' => '✉', 'href' => route('module', ['module' => 'settings', 'section' => 'messaging']), 'children' => [
                    ['label' => 'Envoyer le message', 'icon' => '✉', 'href' => route('module', ['module' => 'settings', 'section' => 'messaging'])],
                    ['label' => 'Modèles de messagerie', 'icon' => '≡', 'href' => route('module', ['module' => 'settings', 'section' => 'message-templates'])],
                ]],
                ['key' => 'reports', 'label' => 'Rapports', 'icon' => '▥', 'href' => route('module', 'reports')],
                ['key' => 'settings', 'label' => 'Magasin', 'icon' => '▣', 'href' => route('module', ['module' => 'settings', 'section' => 'warehouses'])],
                ['key' => 'settings', 'label' => 'Paramètres', 'icon' => '⚙', 'href' => route('module', 'settings'), 'children' => [
                    ['label' => 'Société', 'icon' => '▣', 'href' => route('module', ['module' => 'settings', 'section' => 'company'])],
                    ['label' => 'API SMS/WhatsApp', 'icon' => '▦', 'href' => route('module', ['module' => 'settings', 'section' => 'sms-api'])],
                    ['label' => 'Liste des taxes', 'icon' => '%', 'href' => route('module', ['module' => 'settings', 'section' => 'taxes'])],
                    ['label' => 'Liste des unités', 'icon' => '≡', 'href' => route('module', ['module' => 'settings', 'section' => 'units'])],
                    ['label' => 'Types de paiement', 'icon' => '≡', 'href' => route('module', ['module' => 'settings', 'section' => 'payment-types'])],
                    ['label' => 'Liste des pays', 'icon' => '≡', 'href' => route('module', ['module' => 'settings', 'section' => 'countries'])],
                    ['label' => 'Liste des états', 'icon' => '≡', 'href' => route('module', ['module' => 'settings', 'section' => 'states'])],
                    ['label' => 'Changer le mot de passe', 'icon' => '⌐', 'href' => route('module', ['module' => 'settings', 'section' => 'password'])],
                ]],
            ];
        @endphp

        <div class="flex min-h-screen">
            <aside class="app-sidebar sticky top-0 hidden h-screen w-72 shrink-0 overflow-hidden md:flex md:flex-col" data-sidebar>
                <div class="sidebar-brand flex h-[76px] shrink-0 items-center gap-3 px-4">
                    <a href="{{ route('dashboard') }}" class="sidebar-brand-link flex min-w-0 flex-1 items-center gap-3" title="{{ $tenant->name }}">
                        <span class="sidebar-logo grid size-10 shrink-0 place-items-center bg-brand text-sm font-bold text-white shadow-sm">LP</span>
                        <span class="sidebar-label min-w-0">
                            <span class="block truncate text-sm font-semibold">{{ $tenant->name }}</span>
                            <span class="block truncate text-xs text-slate-500 dark:text-slate-400">SaaS librairie · {{ strtoupper($tenant->currency) }}</span>
                        </span>
                    </a>
                    <button class="sidebar-toggle grid size-9 shrink-0 place-items-center" type="button" aria-label="Réduire le menu" aria-pressed="false" data-sidebar-toggle>
                        <span class="sidebar-toggle-icon">‹</span>
                    </button>
                </div>

                <nav class="sidebar-scroll min-h-0 flex-1 space-y-1 overflow-y-auto px-3 pb-5 pt-2" data-sidebar-scroll>
                    @foreach ($nav as $item)
                        @php
                            $children = $item['children'] ?? [];
                            $childrenActive = collect($children)->contains(fn ($child) => $isCurrentLink($child['href']));
                            $linkActive = $isCurrentLink($item['href']);
                            $itemActive = $linkActive || $childrenActive;
                        @endphp
                        @if (! empty($item['children']))
                            <details class="sidebar-group" data-sidebar-group="{{ Str::slug($item['label']) }}" @if($childrenActive || $linkActive) open @endif>
                                <summary class="sidebar-link group {{ $itemActive ? 'is-active' : '' }}" title="{{ $item['label'] }}">
                                    <span class="sidebar-icon" data-initial="{{ Str::upper(Str::substr($item['label'], 0, 1)) }}">{{ $item['icon'] }}</span>
                                    <span class="sidebar-label truncate">{{ $item['label'] }}</span>
                                    <span class="sidebar-chevron ms-auto">⌄</span>
                                </summary>
                                <div class="sidebar-children">
                                    <a href="{{ $item['href'] }}" class="sidebar-child {{ $linkActive ? 'is-active' : '' }}" @if($linkActive) aria-current="page" data-current-nav @endif>
                                        <span class="sidebar-child-dot"></span>
                                        <span class="truncate">Vue principale</span>
                                    </a>
                                @foreach ($item['children'] as $child)
                                    @php
                                        $childActive = $isCurrentLink($child['href']);
                                    @endphp
                                    <a href="{{ $child['href'] }}" class="sidebar-child {{ $childActive ? 'is-active' : '' }}" title="{{ $child['label'] }}" @if($childActive) aria-current="page" data-current-nav @endif>
                                        <span class="sidebar-child-icon">{{ $child['icon'] }}</span>
                                        <span class="truncate">{{ $child['label'] }}</span>
                                    </a>
                                @endforeach
                                </div>
                            </details>
                        @else
                            <a href="{{ $item['href'] }}" class="sidebar-link group {{ $itemActive ? 'is-active' : '' }}" title="{{ $item['label'] }}" @if($itemActive) aria-current="page" data-current-nav @endif>
                                <span class="sidebar-icon" data-initial="{{ Str::upper(Str::substr($item['label'], 0, 1)) }}">{{ $item['icon'] }}</span>
                                <span class="sidebar-label truncate">{{ $item['label'] }}</span>
                            </a>
                        @endif
                    @endforeach
                </nav>


            </aside>

            <main class="app-main-shell min-w-0 flex-1">
                <header class="app-topbar sticky top-0 z-20 border-b px-4 py-3 backdrop-blur lg:px-8">
                    <div class="flex items-center gap-3">
                        <details class="topbar-quick-add relative">
                            <summary class="grid size-11 cursor-pointer list-none place-items-center bg-brand text-lg font-semibold text-white shadow-sm">+</summary>
                            <div class="absolute left-0 top-12 z-40 w-64 overflow-hidden rounded-xl border border-slate-200 bg-white py-2 shadow-xl dark:border-white/10 dark:bg-slate-950">
                                @foreach ($quickAdds as $quickAdd)
                                    <a href="{{ $quickAdd['href'] }}" class="block px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-white/5">{{ $quickAdd['label'] }}</a>
                                @endforeach
                            </div>
                        </details>
                        <form action="{{ route('catalog') }}" class="app-top-search relative flex-1">
                            <input name="q" value="{{ request('q') }}" class="h-11 w-full rounded-lg border px-4 text-sm outline-none transition focus:border-brand focus:ring-4 focus:ring-brand/10" placeholder="Rechercher titre, ISBN, code-barres, client...">
                        </form>
                        <button class="app-theme-toggle grid size-11 place-items-center rounded-lg border border-slate-200 bg-white text-slate-600 transition hover:bg-slate-50 dark:border-white/10 dark:bg-white/5 dark:text-slate-200" type="button" aria-label="Basculer le thème">◐</button>
                        <details class="relative hidden sm:block">
                            <summary class="current-store-trigger flex h-11 cursor-pointer list-none items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 transition hover:border-brand/40 hover:text-brand dark:border-white/10 dark:bg-white/5 dark:text-slate-200">
                                <span class="grid size-6 place-items-center rounded-md bg-brand/10 text-xs text-brand">▣</span>
                                <span class="max-w-36 truncate">{{ $layoutCurrentStore['name'] }}</span>
                            </summary>
                            <div class="absolute right-0 top-12 z-40 w-72 overflow-hidden rounded-xl border border-slate-200 bg-white p-3 shadow-xl dark:border-white/10 dark:bg-slate-950">
                                <p class="px-1 pb-2 text-xs font-semibold uppercase text-slate-500">Magasin courant</p>
                                <form action="{{ route('settings.current-store.update') }}" method="POST" class="space-y-2">
                                    @csrf
                                    <select name="current_store" class="h-10 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">
                                        @foreach ($layoutStores->where('is_active', true) as $store)
                                            <option value="{{ $store['key'] }}" @selected($layoutCurrentStore['key'] === $store['key'])>{{ $store['name'] }}</option>
                                        @endforeach
                                    </select>
                                    <button class="w-full rounded-lg bg-brand px-3 py-2 text-sm font-semibold text-white">Changer</button>
                                </form>
                                <a href="{{ route('module', ['module' => 'settings', 'section' => 'warehouses']) }}" class="mt-2 block rounded-lg border border-slate-200 px-3 py-2 text-center text-sm font-semibold dark:border-white/10">Gérer les magasins</a>
                            </div>
                        </details>
                        <button class="app-rtl-toggle hidden rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-50 sm:block dark:border-white/10 dark:bg-white/5 dark:text-slate-200" type="button">العربية</button>
                        <a href="{{ route('pos') }}" class="rounded-lg bg-brand px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition brightness-100 hover:brightness-110">Caisse</a>
                        @auth
                            @php
                                $accountUser = auth()->user();
                                $accountTenant = $accountUser?->currentTenant ?? $tenant;
                                $accountTenantUser = $accountTenant?->users()->whereKey($accountUser?->id)->first();
                                $accountRoleKey = (string) ($accountTenantUser?->pivot?->role ?? '');
                                $accountRoleName = \App\Models\Role::where('tenant_id', $accountTenant?->id)->where('key', $accountRoleKey)->value('name') ?: ucfirst($accountRoleKey ?: 'Aucun rôle');
                            @endphp
                            <details class="relative">
                                <summary class="grid size-11 cursor-pointer list-none place-items-center rounded-full text-sm font-bold text-white" style="background: {{ $accountUser->avatar_color ?: 'var(--brand-primary)' }}">{{ Str::upper(Str::substr($accountUser->name, 0, 2)) }}</summary>
                                <div class="absolute right-0 top-12 z-40 w-72 overflow-hidden rounded-xl border border-slate-200 bg-white p-3 shadow-xl dark:border-white/10 dark:bg-slate-950">
                                    <div class="flex items-center gap-3 border-b border-slate-200 pb-3 dark:border-white/10">
                                        <span class="grid size-10 place-items-center rounded-lg text-sm font-bold text-white" style="background: {{ $accountUser->avatar_color ?: 'var(--brand-primary)' }}">{{ Str::upper(Str::substr($accountUser->name, 0, 2)) }}</span>
                                        <span class="min-w-0">
                                            <strong class="block truncate text-sm">{{ $accountUser->name }}</strong>
                                            <small class="block truncate text-xs text-slate-500">{{ $accountUser->email }}</small>
                                            <span class="mt-1 inline-flex max-w-full items-center rounded-full bg-brand/10 px-2 py-0.5 text-[11px] font-semibold text-brand">{{ $accountRoleName }}</span>
                                        </span>
                                    </div>
                                    <a href="{{ route('profile') }}" class="mt-2 block rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-white/5">Mon profil</a>
                                    <a href="{{ route('module', ['module' => 'settings', 'section' => 'users']) }}" class="block rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-white/5">Utilisateurs & rôles</a>
                                    <form action="{{ route('logout') }}" method="POST" class="mt-2 border-t border-slate-200 pt-2 dark:border-white/10">
                                        @csrf
                                        <button class="w-full rounded-lg px-3 py-2 text-left text-sm font-semibold text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-500/10">Déconnexion</button>
                                    </form>
                                </div>
                            </details>
                        @else
                            <a href="{{ route('login') }}" class="rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-brand/40 hover:text-brand dark:border-white/10 dark:bg-white/5 dark:text-slate-200">Connexion</a>
                        @endauth
                    </div>
                    @if (session('status'))
                        <div class="mt-3 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm font-medium text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300">
                            {{ session('status') }}
                        </div>
                    @endif
                    @if ($errors->any())
                        <div class="mt-3 rounded-lg border border-rose-200 bg-rose-50 px-4 py-2 text-sm font-medium text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300">
                            {{ $errors->first() }}
                        </div>
                    @endif
                </header>

                <div class="px-4 py-6 lg:px-8">
                    {{ $slot }}
                </div>
            </main>
        </div>
    </body>
</html>
