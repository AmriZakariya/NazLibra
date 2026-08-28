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
    $layoutBusinessMode = \App\Support\BusinessMode::current($tenant);
    $moduleSettings = \App\Support\AppModules::settings($tenant);
    $enabledModules = $moduleSettings['enabled'];
    $moduleOrder = $moduleSettings['order'];
    $locale = \App\Support\Locale::current($tenant);
    $direction = \App\Support\Locale::dir($locale);
    $tr = fn (string $text): string => \App\Support\Locale::t($text, $locale);
    $timezoneLabel = \App\Support\TenantClock::label($tenant);
    $timezoneOffset = \App\Support\TenantClock::offset($tenant);
    $appVersion = config('app.version', '1.0.0-beta.5');
    $releaseLabel = app()->environment('production') ? $tr('Production') : \Illuminate\Support\Str::headline(app()->environment());
    $authUser = auth()->user();
    $isOwner = $authUser !== null && (string) ($tenant->users()->whereKey($authUser->id)->first()?->pivot?->role ?? '') === 'owner';
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
    $layoutMoney = fn ($amount): string => number_format((float) $amount, 2, ',', ' ').' DH';
    $virtualDevicesEnabled = (bool) data_get($tenant->settings, 'features.virtual_devices', false);
    $showCashDrawerNavbar = (bool) data_get($tenant->settings, 'pos.show_cash_drawer_navbar', true) && ($enabledModules['cash_register'] ?? true);
    $layoutCashRegisterSession = $showCashDrawerNavbar
        ? \App\Models\CashRegisterSession::query()
            ->where('tenant_id', $tenant->id)
            ->where('store_key', $layoutCurrentStore['key'] ?? null)
            ->where('status', 'open')
            ->latest('opened_at')
            ->first()
        : null;
    $appIcon32 = route('app.icon', 32);
    $appIcon192 = route('app.icon', 192);
    $appIcon512 = route('app.icon', 512);
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $direction }}" data-locale="{{ $locale }}" style="--brand-primary: {{ $theme['primary'] }}; --brand-accent: {{ $theme['accent'] }}; --brand-success: {{ $theme['success'] }}; --brand-warning: {{ $theme['warning'] ?? '#D97706' }}; --brand-danger: {{ $theme['danger'] ?? '#E11D48' }}; --brand-info: {{ $theme['info'] ?? '#0284C7' }}; --app-bg: {{ $theme['background'] }}; --surface: {{ $theme['surface_color'] }}; --surface-muted: {{ $theme['surface_muted'] }}; --text-main: {{ $theme['text'] }}; --text-muted: {{ $theme['muted'] }}; --border-soft: {{ $theme['border'] }}; --font-scale: {{ $theme['font_scale'] }}; --brand-radius: {{ $theme['radius'] }}px;">
    <head>
        <script>
            (function(){
                if(localStorage.getItem('librairepro-app-fullscreen')==='1'){
                    document.documentElement.classList.add('app-fullscreen-mode');
                }
                document.documentElement.classList.add('app-loading');
            })();
        </script>
        <style>
            html.app-loading * { transition: none !important; animation: none !important; }
            .app-fullscreen-mode .app-sidebar:not(.is-visible){transform:translateX(-100%)!important;opacity:0!important;pointer-events:none!important;}
            html[dir="rtl"].app-fullscreen-mode .app-sidebar:not(.is-visible){transform:translateX(100%)!important;}
            .app-fullscreen-mode .app-sidebar.is-visible{transform:translateX(0)!important;opacity:1!important;pointer-events:auto!important;}
            .app-fullscreen-mode .app-main-shell{width:100%!important;}
            .app-fullscreen-mode .app-topbar{padding-block:10px!important;padding-inline:16px!important;}
            .app-fullscreen-mode .app-page-content{padding:16px!important;}
        </style>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="light dark">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        @if ($virtualDevicesEnabled)
            <meta name="device-heartbeat" content="{{ route('device.heartbeat') }}">
        @endif
        <title>{{ $title ?? 'LibrairePro' }}</title>
        <link rel="manifest" href="/manifest.json">
        <meta name="theme-color" content="{{ $theme['primary'] }}">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">
        <meta name="apple-mobile-web-app-title" content="{{ $tenant->name }}">
        <link rel="apple-touch-icon" href="{{ $appIcon192 }}">
        <link rel="icon" type="image/png" sizes="192x192" href="{{ $appIcon192 }}">
        <link rel="icon" type="image/png" sizes="512x512" href="{{ $appIcon512 }}">
        <link rel="icon" type="image/png" sizes="32x32" href="{{ $appIcon32 }}">
        <link rel="shortcut icon" href="{{ $appIcon32 }}" type="image/x-icon">
        <script>
            const libraireProForceCollapsedSidebar = @json(request()->routeIs('pos'));
            if (libraireProForceCollapsedSidebar) {
                localStorage.setItem('librairepro-sidebar', 'collapsed');
            }
            const libraireProSidebarState = localStorage.getItem('librairepro-sidebar');
            if (libraireProSidebarState === null || libraireProSidebarState === 'collapsed') {
                document.documentElement.classList.add('sidebar-collapsed');
            }
            if (localStorage.getItem('librairepro-app-fullscreen') === '1') {
                document.documentElement.classList.add('app-fullscreen-mode');
            }
        </script>
        <script>
            window.libraireProLocale = @json($locale);
            window.libraireProTranslations = @json($locale === 'ar' ? \App\Support\Locale::arabic() : []);
        </script>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-slate-50 text-slate-950 antialiased dark:bg-slate-950 dark:text-slate-100">
        <script>requestAnimationFrame(()=>setTimeout(()=>document.documentElement.classList.remove('app-loading'),0));</script>
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
            $canActivateNavItem = function (array $item): bool {
                if (request()->routeIs('stock')) {
                    return ($item['key'] ?? null) === 'stock';
                }

                if (request()->routeIs('catalog', 'catalog.labels')) {
                    return ($item['key'] ?? null) === 'catalog';
                }

                return ($item['key'] ?? null) !== 'stock';
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
                ['module_key' => 'sales', 'label' => 'Ventes', 'href' => route('pos')],
                ['module_key' => 'invoices', 'label' => 'Devis', 'href' => route('module', ['module' => 'invoices', 'section' => 'estimate-add'])],
                ['module_key' => 'purchases', 'label' => 'Achat', 'href' => route('module', ['module' => 'purchases', 'section' => 'add'])],
                ['module_key' => 'customers', 'label' => 'Client', 'href' => route('module', ['module' => 'contacts', 'section' => 'customer-add'])],
                ['module_key' => 'suppliers', 'label' => 'Fournisseur', 'href' => route('module', ['module' => 'contacts', 'section' => 'supplier-add'])],
                ['module_key' => 'catalog', 'label' => 'Article', 'href' => route('catalog', ['panel' => 'ajouter'])],
                ['module_key' => 'expenses', 'label' => 'Frais', 'href' => route('module', ['module' => 'finance', 'section' => 'expense-add'])],
            ];
            $quickAdds = collect($quickAdds)
                ->filter(fn (array $item) => $enabledModules[$item['module_key']] ?? true)
                ->values()
                ->all();
            $nav = [
                ['key' => 'dashboard', 'label' => 'Tableau de bord', 'icon' => '⌂', 'href' => route('dashboard')],
                ['key' => 'guide', 'label' => 'Guide fonctionnalités', 'icon' => '□', 'href' => route('functionality-guide')],
                ['key' => 'catalog', 'label' => 'Articles', 'icon' => '▦', 'href' => route('catalog'), 'children' => $articleLinks],
                ['key' => 'sales', 'label' => 'Ventes', 'icon' => '₧', 'href' => route('pos'), 'children' => [
                    ['label' => 'Point de vente', 'icon' => '◉', 'href' => route('pos')],
                    ['label' => 'Ajouter une vente', 'icon' => '+', 'href' => route('pos')],
                    ['label' => 'Liste des ventes', 'icon' => '≡', 'href' => route('module', 'sales')],
                    ['label' => 'Paiements des ventes', 'icon' => '≡', 'href' => route('module', ['module' => 'sales', 'section' => 'payments'])],
                    ['label' => 'Liste des retours de vente', 'icon' => '≡', 'href' => route('module', ['module' => 'sales', 'section' => 'returns'])],
                ]],
                ['key' => 'online_orders', 'label' => 'Précommandes', 'icon' => '◷', 'href' => route('module', 'online-orders'), 'children' => [
                    ['label' => 'Nouvelle précommande', 'icon' => '+', 'href' => route('module', ['module' => 'online-orders', 'section' => 'add'])],
                    ['label' => 'Liste des précommandes', 'icon' => '≡', 'href' => route('module', ['module' => 'online-orders', 'section' => 'list'])],
                ]],
                ['key' => 'invoices', 'label' => 'Facturation', 'icon' => '▤', 'href' => route('module', 'invoices'), 'children' => [
                    ['label' => 'Nouvelle facture', 'icon' => '+', 'href' => route('module', ['module' => 'invoices', 'section' => 'invoice-add'])],
                    ['label' => 'Liste des factures', 'icon' => '≡', 'href' => route('module', ['module' => 'invoices', 'section' => 'invoices'])],
                    ['label' => 'Nouveau devis', 'icon' => '+', 'href' => route('module', ['module' => 'invoices', 'section' => 'estimate-add'])],
                    ['label' => 'Liste des devis', 'icon' => '≡', 'href' => route('module', ['module' => 'invoices', 'section' => 'estimates'])],
                ]],
                ['key' => 'deliveries', 'label' => 'Livraisons', 'icon' => '⇢', 'href' => route('module', ['module' => 'sales', 'section' => 'delivery'])],
                ['key' => 'purchases', 'label' => 'Achat', 'icon' => '↧', 'href' => route('module', 'purchases'), 'children' => [
                    ['label' => 'Nouvel achat', 'icon' => '+', 'href' => route('module', ['module' => 'purchases', 'section' => 'add'])],
                    ['label' => "Liste d'achat", 'icon' => '≡', 'href' => route('module', ['module' => 'purchases', 'section' => 'list'])],
                    ['label' => "Liste des retours d'achat", 'icon' => '≡', 'href' => route('module', ['module' => 'purchases', 'section' => 'returns'])],
                ]],
                ['key' => 'loans', 'label' => 'Emprunts', 'icon' => '▤', 'href' => route('module', 'loans')],
                ['key' => 'expenses', 'label' => 'Dépenses', 'icon' => '−', 'href' => route('module', ['module' => 'finance', 'section' => 'expenses']), 'children' => [
                    ['label' => 'Ajouter une dépense', 'icon' => '+', 'href' => route('module', ['module' => 'finance', 'section' => 'expense-add'])],
                    ['label' => 'Liste des dépenses', 'icon' => '≡', 'href' => route('module', ['module' => 'finance', 'section' => 'expenses'])],
                    ['label' => 'Liste des catégories', 'icon' => '≡', 'href' => route('module', ['module' => 'finance', 'section' => 'expense-categories'])],
                ]],
                // Removed from top-level nav: devis now live under Facturation (module invoices).
                ['key' => 'customers', 'label' => 'Les clients', 'icon' => '◌', 'href' => route('module', ['module' => 'contacts', 'section' => 'customers']), 'children' => [
                    ['label' => 'Ajouter un client', 'icon' => '+', 'href' => route('module', ['module' => 'contacts', 'section' => 'customer-add'])],
                    ['label' => 'Liste des clients', 'icon' => '≡', 'href' => route('module', ['module' => 'contacts', 'section' => 'customers'])],
                    ['label' => 'Importer des clients', 'icon' => '↤', 'href' => route('module', ['module' => 'contacts', 'section' => 'import-customers'])],
                ]],
                ['key' => 'suppliers', 'label' => 'Les fournisseurs', 'icon' => '▱', 'href' => route('module', ['module' => 'contacts', 'section' => 'suppliers']), 'children' => [
                    ['label' => 'Ajouter un fournisseur', 'icon' => '+', 'href' => route('module', ['module' => 'contacts', 'section' => 'supplier-add'])],
                    ['label' => 'Liste des fournisseurs', 'icon' => '≡', 'href' => route('module', ['module' => 'contacts', 'section' => 'suppliers'])],
                    ['label' => 'Importer des fournisseurs', 'icon' => '↤', 'href' => route('module', ['module' => 'contacts', 'section' => 'import-suppliers'])],
                ]],
                ['key' => 'advances', 'label' => 'Avance', 'icon' => '$', 'href' => route('module', ['module' => 'finance', 'section' => 'advances']), 'children' => [
                    ['label' => 'Ajouter une avance', 'icon' => '+', 'href' => route('module', ['module' => 'finance', 'section' => 'advance-add'])],
                    ['label' => 'Liste des avances', 'icon' => '≡', 'href' => route('module', ['module' => 'finance', 'section' => 'advances'])],
                ]],
                ['key' => 'coupons', 'label' => 'Coupons', 'icon' => '◇', 'href' => route('module', ['module' => 'finance', 'section' => 'coupons']), 'children' => [
                    ['label' => 'Créer un coupon client', 'icon' => '+', 'href' => route('module', ['module' => 'finance', 'section' => 'customer-coupon-add'])],
                    ['label' => 'Liste des coupons client', 'icon' => '≡', 'href' => route('module', ['module' => 'finance', 'section' => 'customer-coupons'])],
                    ['label' => 'Créer un coupon', 'icon' => '+', 'href' => route('module', ['module' => 'finance', 'section' => 'coupon-add'])],
                    ['label' => 'Maître des coupons', 'icon' => '≡', 'href' => route('module', ['module' => 'finance', 'section' => 'coupons'])],
                ]],
                ['key' => 'discounts', 'label' => 'Remises', 'icon' => '%', 'href' => route('module', ['module' => 'finance', 'section' => 'discounts']), 'children' => [
                    ['label' => 'Créer une remise', 'icon' => '+', 'href' => route('module', ['module' => 'finance', 'section' => 'discount-add'])],
                    ['label' => 'Liste des remises', 'icon' => '≡', 'href' => route('module', ['module' => 'finance', 'section' => 'discounts'])],
                ]],
                ['key' => 'accounts', 'label' => 'Comptes', 'icon' => '▦', 'href' => route('module', ['module' => 'finance', 'section' => 'accounts']), 'children' => [
                    ['label' => 'Ajouter un compte', 'icon' => '+', 'href' => route('module', ['module' => 'finance', 'section' => 'account-add'])],
                    ['label' => 'Liste des comptes', 'icon' => '≡', 'href' => route('module', ['module' => 'finance', 'section' => 'accounts'])],
                    ['label' => "Liste des transferts d'argent", 'icon' => '≡', 'href' => route('module', ['module' => 'finance', 'section' => 'transfers'])],
                    ['label' => 'Liste de dépôt', 'icon' => '≡', 'href' => route('module', ['module' => 'finance', 'section' => 'deposits'])],
                    ['label' => 'Transactions en espèces', 'icon' => '⇄', 'href' => route('module', ['module' => 'finance', 'section' => 'cash'])],
                ]],
                ['key' => 'cash_register', 'label' => 'Tiroir caisse', 'icon' => '▣', 'href' => route('module', 'cash-register')],
                ['key' => 'stock', 'label' => 'Stock', 'icon' => '⌛', 'href' => route('stock'), 'children' => [
                    ['label' => "Ajouter ajustement", 'icon' => '+', 'href' => route('stock', ['panel' => 'stock-adjustment-add'])],
                    ['label' => "Liste d'ajustement", 'icon' => '≡', 'href' => route('stock', ['panel' => 'stock-adjustments'])],
                    ['label' => 'Ajouter transfert', 'icon' => '+', 'href' => route('stock', ['panel' => 'stock-transfer-add'])],
                    ['label' => 'Liste de transfert', 'icon' => '≡', 'href' => route('stock', ['panel' => 'stock-transfers'])],
                ]],
                ['key' => 'users', 'label' => 'Utilisateurs', 'icon' => '♙', 'href' => route('module', ['module' => 'settings', 'section' => 'users']), 'children' => [
                    ['label' => 'Liste des utilisateurs', 'icon' => '≡', 'href' => route('module', ['module' => 'settings', 'section' => 'users'])],
                    ['label' => 'Liste des rôles', 'icon' => '≡', 'href' => route('module', ['module' => 'settings', 'section' => 'roles'])],
                ]],
                ['key' => 'messaging', 'label' => 'Messagerie', 'icon' => '✉', 'href' => route('module', ['module' => 'settings', 'section' => 'messaging']), 'children' => [
                    ['label' => 'Envoyer le message', 'icon' => '✉', 'href' => route('module', ['module' => 'settings', 'section' => 'messaging'])],
                    ['label' => 'Modèles de messagerie', 'icon' => '≡', 'href' => route('module', ['module' => 'settings', 'section' => 'message-templates'])],
                ]],
                ['key' => 'reports', 'label' => 'Rapports', 'icon' => '▥', 'href' => route('module', 'reports')],
                ['key' => 'stores', 'label' => 'Magasin', 'icon' => '▣', 'href' => route('module', ['module' => 'settings', 'section' => 'warehouses'])],
                ['key' => 'settings', 'label' => 'Paramètres', 'icon' => '⚙', 'href' => route('module', 'settings'), 'children' => [
                    ['label' => 'Vue d’ensemble', 'icon' => '◉', 'href' => route('module', ['module' => 'settings', 'section' => 'overview'])],
                    ['label' => 'Store & activité', 'icon' => '▣', 'href' => route('module', ['module' => 'settings', 'section' => 'company'])],
                    ['label' => 'Société', 'icon' => '·', 'href' => route('module', ['module' => 'settings', 'section' => 'company'])],
                    ['label' => 'Magasins', 'icon' => '·', 'href' => route('module', ['module' => 'settings', 'section' => 'warehouses'])],
                    ['label' => 'Caisse & stock', 'icon' => '·', 'href' => route('module', ['module' => 'settings', 'section' => 'store'])],
                    ['label' => 'PDF', 'icon' => '·', 'href' => route('module', ['module' => 'settings', 'section' => 'documents'])],
                    ['label' => 'Thème', 'icon' => '·', 'href' => route('module', ['module' => 'settings', 'section' => 'theme'])],
                    ['label' => 'Modules', 'icon' => '·', 'href' => route('module', ['module' => 'settings', 'section' => 'modules'])],
                    ['label' => 'Compte & équipe', 'icon' => '♙', 'href' => route('module', ['module' => 'settings', 'section' => 'users'])],
                    ['label' => 'Utilisateurs', 'icon' => '·', 'href' => route('module', ['module' => 'settings', 'section' => 'users'])],
                    ['label' => 'Rôles', 'icon' => '·', 'href' => route('module', ['module' => 'settings', 'section' => 'roles'])],
                    ['label' => 'Mot de passe', 'icon' => '·', 'href' => route('module', ['module' => 'settings', 'section' => 'password'])],
                    ['label' => 'Appareils', 'icon' => '🖥', 'href' => route('module', ['module' => 'settings', 'section' => 'virtual-devices'])],
                    ['label' => 'Appareils virtuels', 'icon' => '·', 'href' => route('module', ['module' => 'settings', 'section' => 'virtual-devices'])],
                    ['label' => 'Matériel POS', 'icon' => '·', 'href' => route('module', ['module' => 'settings', 'section' => 'hardware'])],
                    ['label' => 'Groupes d’impression', 'icon' => '·', 'href' => route('module', ['module' => 'settings', 'section' => 'printer-groups'])],
                    ['label' => 'Référentiels & communication', 'icon' => '▦', 'href' => route('module', ['module' => 'settings', 'section' => 'taxes'])],
                    ['label' => 'Taxes', 'icon' => '·', 'href' => route('module', ['module' => 'settings', 'section' => 'taxes'])],
                    ['label' => 'Unités', 'icon' => '·', 'href' => route('module', ['module' => 'settings', 'section' => 'units'])],
                    ['label' => 'Paiement', 'icon' => '·', 'href' => route('module', ['module' => 'settings', 'section' => 'payment-types'])],
                    ['label' => 'Pays', 'icon' => '·', 'href' => route('module', ['module' => 'settings', 'section' => 'countries'])],
                    ['label' => 'États', 'icon' => '·', 'href' => route('module', ['module' => 'settings', 'section' => 'states'])],
                    ['label' => 'Messagerie', 'icon' => '·', 'href' => route('module', ['module' => 'settings', 'section' => 'messaging'])],
                    ['label' => 'Modèles', 'icon' => '·', 'href' => route('module', ['module' => 'settings', 'section' => 'message-templates'])],
                    ['label' => 'API messages', 'icon' => '·', 'href' => route('module', ['module' => 'settings', 'section' => 'sms-api'])],
                    ['label' => 'Données démo', 'icon' => '·', 'href' => route('module', ['module' => 'settings', 'section' => 'demo-data'])],
                    ...($isOwner ? [['label' => "Journal d'activité", 'icon' => '▥', 'href' => route('profile.activity')]] : []),
                ]],
            ];
            $nav = collect($nav)
                ->filter(fn (array $item) => $enabledModules[$item['key']] ?? true)
                ->sortBy(function (array $item, int $index) use ($moduleOrder): int {
                    $position = array_search($item['key'], $moduleOrder, true);

                    return ($position === false ? 999000 : $position * 1000) + $index;
                })
                ->values()
                ->all();
            $commandLinks = collect($nav)
                ->flatMap(function (array $item) use ($tr) {
                    $links = [[
                        'label' => $item['label'],
                        'translated_label' => $tr($item['label']),
                        'section' => 'Module',
                        'translated_section' => $tr('Module'),
                        'kind' => 'Module',
                        'key' => $item['key'],
                        'icon' => $item['icon'],
                        'href' => $item['href'],
                    ]];

                    foreach (($item['children'] ?? []) as $child) {
                        $links[] = [
                            'label' => $child['label'],
                            'translated_label' => $tr($child['label']),
                            'section' => $item['label'],
                            'translated_section' => $tr($item['label']),
                            'kind' => 'Sous-module',
                            'key' => $item['key'],
                            'icon' => $child['icon'],
                            'href' => $child['href'],
                        ];
                    }

                    return $links;
                })
                ->unique(fn (array $link) => $link['href'].'|'.$link['label'])
                ->values();
            $commandModuleAliases = [
                'dashboard' => 'dashboard tableau board home accueil الرئيسية لوحة القيادة kpi analytics stats statistiques اليوم today',
                'catalog' => 'catalog catalogue articles items produits books livres خدمات services isbn barcode code barre categories catégories marques publishers éditeurs variants variantes labels etiquettes étiquettes import excel inventory مخزون منتجات كتب خدمات تصنيفات',
                'sales' => 'sales ventes pos caisse checkout payment paiement ticket receipt reçu facture refund retour encaissement barcode scanner مبيعات صندوق تذكرة دفع',
                'online_orders' => 'online order online orders preorder pre-order precommande précommande precommandes précommandes commande web commandes web whatsapp reservation réservation reserve client status statut pending confirmed ready fulfilled طلب مسبق طلبات مسبقة اونلاين واتساب',
                'invoices' => 'invoice invoices facture factures facturation billing devis estimate proforma pro-forma due échéance pdf print طباعة فاتورة فواتير عرض سعر تقدير',
                'deliveries' => 'delivery deliveries livraison livraisons bl dispatch shipping expédition توصيل شحن',
                'purchases' => 'purchase purchases achat achats supplier fournisseur commande réception po order مشتريات موردين طلبات',
                'loans' => 'library loans emprunts prêt livre membre pénalité retard reservation مكتبة إعارة استعارة أعضاء',
                'expenses' => 'expense expenses depense dépense frais charges cost paiement مصاريف تكاليف',
                'quotations' => 'quote quotation devis proforma estimation عرض سعر تقدير',
                'customers' => 'customer customers client clients contact crm téléphone phone cin زبون عميل عملاء',
                'suppliers' => 'supplier suppliers fournisseur fournisseurs vendor ice rc مورد موردين',
                'advances' => 'advance advances avance acompte crédit credit solde balance دفعة مسبقة رصيد',
                'coupons' => 'coupon coupons promo promotion code réduction discount قسيمة كوبون تخفيض',
                'discounts' => 'discount discounts remise remises rabais réduction promo percentage percent fixed value تخفيض خصم',
                'accounts' => 'account accounts compte comptes banque bank cash treasury trésorerie transfer deposit dépôt تحويل إيداع بنك',
                'cash_register' => 'cash register tiroir caisse drawer ouverture close clôture cloture balance fond espèces z report نقد صندوق درج',
                'stock' => 'stock inventory inventaire rupture quantity quantité alert alerte adjustment ajustement transfer transfert warehouse depot valeur volume مخزون جرد كمية تحويل تعديل',
                'users' => 'user users utilisateur utilisateurs équipe staff role roles rôles permission access accès pin profile profil photo مستخدمين صلاحيات أدوار',
                'messaging' => 'message messaging messagerie sms whatsapp email template modèle notification campaign campagne رسائل واتساب بريد',
                'reports' => 'reports rapport rapports analytics profit loss ventes paiements taxes stock reporting تقارير إحصائيات',
                'stores' => 'store stores magasin magasins warehouse dépôt depot current store magasin courant فرع مخزن متجر',
                'settings' => 'settings paramètres parametres configuration setup preferences préférences timezone time zone fuseau horaire language langue thème theme modules app mode hardware printer printer groups groupes impression routage taxes units payment types إعدادات منطقة زمنية لغة ثيم طابعة',
            ];
            $commandAliases = [
                'Point de vente' => 'pos caisse encaisser barcode scan scanner ticket paiement payment cash carte card comptoir checkout sell sale receipt reçu بيع صندوق ماسح دفع',
                'Ajouter une vente' => 'vente manuelle manual sale invoice facture client customer paiement payment',
                'Liste des ventes' => 'ticket facture invoice historique history refund remboursement retour return paiement serial number numéro série',
                'Nouvelle précommande' => 'online order preorder precommande commande web whatsapp reservation client acompte statut',
                'Liste des précommandes' => 'online orders precommandes commandes web statut suivi pending confirmed ready fulfilled',
                'Facturation' => 'facturation invoices factures devis estimates proforma module billing',
                'Nouvelle facture' => 'nouvelle facture invoice create add créer commercial document',
                'Liste des factures' => 'liste factures invoices échéance pdf billing',
                'Nouveau devis' => 'nouveau devis estimate quote proforma create add',
                'Liste des devis' => 'liste devis estimates quotes proforma',
                'Paiements des ventes' => 'encaissement payment payments cash carte card virement advance avance reçu receipt',
                'Liste de livraison' => 'delivery deliveries bl bon livraison dispatch expédition shipping',
                'Ajouter un article' => 'item produit livre isbn article product book barcode code barre stock prix price catalogue create add إضافة منتج كتاب',
                'Liste d\'articles' => 'items articles products produits books livres services catalogue search recherche filtre filter export datatable قائمة منتجات',
                'Ajouter un service' => 'service prestation non physical sans stock frais membership abonnement create add خدمة',
                'Services d\'importation' => 'import imports excel csv spreadsheet upload migration mylibrairie articles services bulk رفع استيراد',
                'Imprimer des étiquettes' => 'labels label barcode etiquette étiquette zpl thermal thermique prix price impression print طباعة ملصقات باركود',
                'Liste des catégories' => 'category categories categorie catégories famille structure rayon uncategorized non catégorisé تصنيفات',
                'Liste des marques' => 'brand brands marque marques éditeur editeur publisher publishing house maison édition ناشر علامة',
                'Liste des variantes' => 'variant variants variante variantes attribut attribute format taille size couleur color edition édition خيارات',
                'Liste des unités' => 'unit units unite unité mesure measure piece pièce pack boite boîte وحدات قياس',
                'Liste des impôts' => 'tax taxes taxe taxes tva vat fiscal impôt impot no tva sans tva ضريبة',
                'Ajouter ajustement' => 'stock correction inventaire adjustment quantité rupture manual add تعديل مخزون',
                'Liste d\'ajustement' => 'historique history stock inventory inventaire correction adjustment movements mouvements',
                'Ajouter transfert' => 'stock transfer transfert warehouse magasin depot dépôt move déplacer',
                'Liste de transfert' => 'transfert transfer stock suivi depot dépôt magasin warehouse',
                'Stock' => 'stock inventaire rupture alerte quantité quantity inventory adjustment transfer movement mouvement history valeur value volume مخزون',
                'Ajouter un client' => 'customer client contact crm téléphone phone cin add create زبون عميل',
                'Liste des clients' => 'contacts clients customers crm solde balance avance crédit credit export قائمة عملاء',
                'Ajouter un fournisseur' => 'supplier fournisseur vendor achat purchase ice rc add create مورد',
                'Liste des fournisseurs' => 'suppliers fournisseurs vendors achats purchases solde balance export قائمة موردين',
                'Ajouter une avance' => 'advance avance acompte crédit credit client solde balance add',
                'Liste des avances' => 'advances avances payments paiements client credit crédit balance solde',
                'Ajouter un compte' => 'account compte bank banque caisse rib iban add create',
                'Liste des comptes' => 'accounts comptes bank banque cash trésorerie treasury balance',
                'Tiroir caisse' => 'cash register tiroir caisse drawer open close ouverture clôture cloture balance fond espèces especes z report navbar',
                'Transactions en espèces' => 'cash movements mouvements caisse espèces especes money monnaie drawer',
                'Guide fonctionnalités' => 'guide documentation docs summary fonctionnalités fonctionnalites user guide modules routes help aide',
                'Rapports' => 'reports rapport rapports analytics statistiques sales ventes stock profit loss pertes taxes payments paiements',
                'Liste des utilisateurs' => 'user users utilisateur utilisateurs team équipe equipe staff access accès acces permission permissions pin role role rôle photo',
                'Liste des rôles' => 'role roles rôle rôles permissions access accès droits droits utilisateur',
                'Magasin' => 'store warehouse magasin depot dépôt current magasin courant branch location فرع مخزن',
                'Société' => 'company société societe profil magasin store profile logo ice address adresse invoice facture ticket timezone time zone fuseau horaire currency devise language langue date format format date المنطقة الزمنية',
                'Mode d’activité' => 'business mode app mode mode activité activite librairie library pharmacy pharmacie retail droguerie commerce type industry secteur',
                'Modules' => 'modules enable disable activer désactiver desactiver ordre order menu sidebar navigation functionality fonctionnalité fonctionnalites',
                'Types de paiement' => 'payment types types paiement cash espèces carte card transfer virement advance avance cheque chèque',
                'Changer le mot de passe' => 'password mot de passe security sécurité securite account compte profile profil',
                'Paramètres' => 'settings paramètres parametres configuration setup preferences préférences timezone time zone fuseau horaire language langue theme thème taxes units modules hardware إعدادات',
                'Journal d\'activité' => 'activity log logs audit journal activité activite traçabilité tracabilite user actions device technical debug historique',
                'API SMS/WhatsApp' => 'sms whatsapp api messaging messages provider twilio sendgrid resend canal channel',
                'Matériel' => 'hardware matériel materiel printer imprimante thermal thermique escpos usb serial tiroir caisse drawer barcode scanner lecteur code-barres',
                'Matériel POS' => 'hardware matériel materiel pos printer imprimante thermal thermique escpos usb serial tiroir caisse drawer barcode scanner lecteur code-barres groupe groupes impression routing routage',
                'Groupes d’impression' => 'printer groups printer group groupes impression groupe imprimante groupe imprimantes routage routing cuisine ticket receipt station station impression kitchen printer rules règles regles catégories categories',
                'Créer une remise' => 'discount remise rabais réduction reduction promo percentage percent fixed value panier cart item article payment method méthode paiement',
                'Liste des remises' => 'discounts remises rabais réductions reductions promo linked tickets ventes coupons',
                'Remises' => 'discounts remises rabais réductions reductions promo percentage percent',
                'Créer un coupon' => 'coupon promo code réduction reduction discount client cart pos appliquer',
                'Maître des coupons' => 'coupons coupon list liste master promo code discounts linked tickets ventes',
            ];
        @endphp

        <div class="flex min-h-screen">
            <div class="app-sidebar-peek-zone" data-sidebar-peek></div>
            <aside class="app-sidebar sticky top-0 hidden h-screen w-72 shrink-0 overflow-hidden md:flex md:flex-col" data-sidebar>
                <div class="sidebar-brand flex h-[76px] shrink-0 items-center gap-3 px-4">
                    <a href="{{ route('dashboard') }}" class="sidebar-brand-link flex min-w-0 flex-1 items-center gap-3" title="{{ $tenant->name }}">
                        <span class="sidebar-logo grid size-10 shrink-0 place-items-center bg-brand text-sm font-bold text-white shadow-sm">LP</span>
                        <span class="sidebar-label min-w-0">
                            <span class="block truncate text-sm font-semibold">{{ $tenant->name }}</span>
                            <span class="block truncate text-xs text-slate-500 dark:text-slate-400">SaaS {{ $layoutBusinessMode['short_label'] }} · {{ strtoupper($tenant->currency) }}</span>
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
                            $navCanBeActive = $canActivateNavItem($item);
                            $childrenActive = $navCanBeActive && collect($children)->contains(fn ($child) => $isCurrentLink($child['href']));
                            $linkActive = $navCanBeActive && $isCurrentLink($item['href']);
                            $itemActive = $linkActive || $childrenActive;
                        @endphp
                        @if (! empty($item['children']))
                            <details class="sidebar-group" data-sidebar-group="{{ Str::slug($item['label']) }}" data-nav-key="{{ $item['key'] }}" @if($childrenActive || $linkActive) open @endif>
                                <summary class="sidebar-link group {{ $itemActive ? 'is-active' : '' }}" title="{{ $tr($item['label']) }}">
                                    <span class="sidebar-icon" data-initial="{{ Str::upper(Str::substr($item['label'], 0, 1)) }}">{{ $item['icon'] }}</span>
                                    <span class="sidebar-label truncate">{{ $tr($item['label']) }}</span>
                                    <span class="sidebar-chevron ms-auto">⌄</span>
                                </summary>
                                <div class="sidebar-children">
                                    <a href="{{ $item['href'] }}" class="sidebar-child {{ $linkActive ? 'is-active' : '' }}" @if($linkActive) aria-current="page" data-current-nav @endif>
                                        <span class="sidebar-child-dot"></span>
                                        <span class="truncate">{{ $tr('Vue principale') }}</span>
                                    </a>
                                @foreach ($item['children'] as $child)
                                    @php
                                        $childActive = $isCurrentLink($child['href']);
                                    @endphp
                                    <a href="{{ $child['href'] }}" class="sidebar-child {{ $childActive ? 'is-active' : '' }}" title="{{ $tr($child['label']) }}" @if($childActive) aria-current="page" data-current-nav @endif>
                                        <span class="sidebar-child-icon">{{ $child['icon'] }}</span>
                                        <span class="truncate">{{ $tr($child['label']) }}</span>
                                    </a>
                                @endforeach
                                </div>
                            </details>
                        @else
                            <a href="{{ $item['href'] }}" class="sidebar-link group {{ $itemActive ? 'is-active' : '' }}" title="{{ $tr($item['label']) }}" data-nav-key="{{ $item['key'] }}" @if($itemActive) aria-current="page" data-current-nav @endif>
                                <span class="sidebar-icon" data-initial="{{ Str::upper(Str::substr($item['label'], 0, 1)) }}">{{ $item['icon'] }}</span>
                                <span class="sidebar-label truncate">{{ $tr($item['label']) }}</span>
                            </a>
                        @endif
                    @endforeach
                </nav>

                <div class="sidebar-release m-3 mt-0">
                    <span class="sidebar-release-mark">v</span>
                    <span class="sidebar-label min-w-0">
                        <strong>{{ $appVersion }}</strong>
                        <small>{{ $releaseLabel }} · {{ \App\Support\TenantClock::format(now(), $tenant, 'd/m/Y') }}</small>
                    </span>
                </div>
                <button class="sidebar-peek-toggle mx-3 mb-3" type="button" data-sidebar-peek-toggle aria-pressed="true" title="Afficher automatiquement le menu au survol">
                    <span class="sidebar-peek-icon" aria-hidden="true">
                        <svg class="sidebar-peek-eye size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg class="sidebar-peek-hand hidden size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 11V7a2 2 0 1 1 4 0v4"/><path d="M12 10V6a2 2 0 1 1 4 0v6"/><path d="M16 11V8a2 2 0 1 1 4 0v7a6 6 0 0 1-6 6h-2.2a6 6 0 0 1-5.1-2.8L4 14a1.9 1.9 0 0 1 3.1-2.2L9 14"/></svg>
                    </span>
                    <span class="sidebar-peek-copy">
                        <span class="sidebar-peek-kicker">{{ $tr('Affichage menu') }}</span>
                        <span class="sidebar-peek-label">Au survol</span>
                    </span>
                    <span class="sidebar-peek-badge">Auto</span>
                </button>

            </aside>

            <main class="app-main-shell min-w-0 flex-1">
                <header class="app-topbar sticky top-0 z-20 border-b px-4 py-3 backdrop-blur lg:px-8">
                    <div class="flex items-center gap-3">
                        <details class="topbar-quick-add relative">
                            <summary class="grid size-11 cursor-pointer list-none place-items-center bg-brand text-lg font-semibold text-white shadow-sm">+</summary>
                            <div class="absolute left-0 top-12 z-40 w-64 overflow-hidden rounded-xl border border-slate-200 bg-white py-2 shadow-xl dark:border-white/10 dark:bg-slate-950">
                                @foreach ($quickAdds as $quickAdd)
                                    <a href="{{ $quickAdd['href'] }}" class="block px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-white/5">{{ $tr($quickAdd['label']) }}</a>
                                @endforeach
                            </div>
                        </details>
                        <div class="app-command-menu relative flex-1" data-command-menu>
                            <label class="app-command-input">
                                <span class="app-command-icon">⌕</span>
                                <input type="search" data-command-input autocomplete="off" placeholder="{{ $tr('Trouver une section, une action, un paramètre...') }}">
                                <kbd>⌘K</kbd>
                            </label>
                            <div class="app-command-panel hidden" data-command-panel>
                                <div class="flex items-center justify-between border-b border-slate-200 px-3 py-2 dark:border-white/10">
                                    <span class="text-xs font-semibold uppercase text-slate-500">{{ $tr('Accès rapide') }}</span>
                                    <span class="text-[11px] font-medium text-slate-400"><strong data-command-count>{{ $commandLinks->count() }}</strong> · {{ $tr('Entrée pour ouvrir') }}</span>
                                </div>
                                <div class="max-h-[360px] overflow-y-auto p-2">
                                    @foreach ($commandLinks as $commandLink)
                                        @php
                                            $labelAliases = $commandAliases[$commandLink['label']] ?? '';
                                            $sectionAliases = $commandAliases[$commandLink['section']] ?? '';
                                            $moduleAliases = $commandModuleAliases[$commandLink['key']] ?? '';
                                            $hrefAliases = match (true) {
                                                Str::contains($commandLink['href'], 'section=company') => 'company profile société societe magasin store profile timezone time zone fuseau horaire date format format date currency devise language langue business mode app mode المنطقة الزمنية التوقيت العملة اللغة',
                                                Str::contains($commandLink['href'], 'section=store') => 'pos settings caisse stock oversell rupture prix editable out of stock drawer tiroir',
                                                Str::contains($commandLink['href'], 'section=modules') => 'modules enable disable activer désactiver order ordre sidebar menu',
                                                Str::contains($commandLink['href'], 'section=theme') => 'theme thème colors couleurs dark mode light mode appearance apparence',
                                                Str::contains($commandLink['href'], 'section=printer-groups') => 'printer group printer groups groupe groupes impression routage routing catégories categories station cuisine ticket receipt imprimante imprimantes',
                                                Str::contains($commandLink['href'], 'section=hardware') => 'printer imprimante thermal thermique barcode scanner tiroir drawer serial usb printer group printer groups groupe groupes impression routage routing catégories categories station cuisine ticket',
                                                Str::contains($commandLink['href'], 'section=taxes') => 'tax taxes tva vat impôt impot fiscal',
                                                Str::contains($commandLink['href'], 'section=payment-types') => 'payment paiement cash card carte virement cheque chèque',
                                                default => '',
                                            };
                                            $commandSearch = Str::lower($commandLink['label'].' '.$commandLink['translated_label'].' '.$commandLink['section'].' '.$commandLink['translated_section'].' '.$commandLink['kind'].' '.$labelAliases.' '.$sectionAliases.' '.$moduleAliases.' '.$hrefAliases);
                                        @endphp
                                        <a href="{{ $commandLink['href'] }}" class="app-command-item" data-command-item data-command-kind="{{ $commandLink['kind'] }}" data-command-key="{{ $commandLink['key'] }}" data-command-title="{{ Str::lower($commandLink['label']) }}" data-command-label="{{ Str::lower($commandLink['label'].' '.$commandLink['translated_label']) }}" data-command-module="{{ $commandLink['section'] }}" data-command-aliases="{{ Str::lower($labelAliases.' '.$sectionAliases.' '.$moduleAliases.' '.$hrefAliases) }}" data-command-search="{{ $commandSearch }}">
                                            <span class="app-command-item-icon">{{ $commandLink['icon'] }}</span>
                                            <span class="min-w-0 flex-1">
                                                <strong>{{ $commandLink['translated_label'] }}</strong>
                                                <small>{{ $tr($commandLink['kind']) }} · {{ $commandLink['translated_section'] }}</small>
                                            </span>
                                            <em>{{ $tr('Ouvrir') }}</em>
                                        </a>
                                    @endforeach
                                    <div class="app-command-empty hidden" data-command-empty>
                                        <strong>{{ $tr('Aucun raccourci trouvé') }}</strong>
                                        <span>{{ $tr('Essayez “caisse”, “taxes”, “article”, “stock” ou “utilisateurs”.') }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        @if ($enabledModules['catalog'] ?? true)
                            <div class="app-product-search relative hidden md:block" data-product-search data-product-search-url="{{ route('catalog.quick-search') }}">
                                <label class="app-product-search-input">
                                    <span class="app-product-search-icon">▦</span>
                                    <input type="search" data-product-search-input autocomplete="off" placeholder="{{ $tr('Produit...') }}">
                                </label>
                                <div class="app-product-search-panel hidden" data-product-search-panel>
                                    <div class="app-product-search-head">
                                        <span>{{ $tr('Articles & services') }}</span>
                                        <small><strong data-product-search-count>0</strong> {{ $tr('résultat(s)') }}</small>
                                    </div>
                                    <div class="app-product-search-results" data-product-search-results></div>
                                    <div class="app-product-search-empty hidden" data-product-search-empty>
                                        <strong>{{ $tr('Aucun produit trouvé') }}</strong>
                                        <span>{{ $tr('Essayez un nom, ISBN, SKU, code article ou code-barres.') }}</span>
                                    </div>
                                </div>
                            </div>
                        @endif
                        @if ($showCashDrawerNavbar)
                            <a href="{{ route('module', 'cash-register') }}" class="topbar-cashdrawer {{ $layoutCashRegisterSession ? 'is-open' : 'is-closed' }}" title="{{ $layoutCashRegisterSession ? $tr('Tiroir ouvert') : $tr('Tiroir fermé') }}">
                                <span class="topbar-cashdrawer-icon">TC</span>
                                <span class="topbar-cashdrawer-copy hidden xl:block">
                                    <strong>{{ $layoutCashRegisterSession ? $layoutMoney($layoutCashRegisterSession->expected_cash_amount) : $tr('Tiroir fermé') }}</strong>
                                    <small>{{ $layoutCashRegisterSession ? $layoutCashRegisterSession->number : $tr('Ouvrir') }}</small>
                                </span>
                            </a>
                        @endif

                        {{-- Tools dropdown (small screens) --}}
                        <details class="relative lg:hidden">
                            <summary class="grid size-11 cursor-pointer list-none place-items-center rounded-lg border border-slate-200 bg-white text-slate-600 transition hover:border-brand/40 hover:bg-slate-50 hover:text-brand dark:border-white/10 dark:bg-white/5 dark:text-slate-200" aria-label="{{ $tr('Outils') }}" title="{{ $tr('Outils') }}">
                                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>
                                </svg>
                            </summary>
                            <div class="absolute right-0 top-12 z-40 w-64 overflow-hidden rounded-xl border border-slate-200 bg-white p-2 shadow-xl dark:border-white/10 dark:bg-slate-950">
                                <div class="space-y-1">
                                    <button class="app-sidebar-nav-toggle w-full flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-white/5" type="button" data-sidebar-nav-toggle>
                                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="9" y1="3" x2="9" y2="21"/></svg>
                                        {{ $tr('Menu') }}
                                    </button>
                                    <button class="app-fullscreen-toggle w-full flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-white/5" type="button" data-fullscreen-toggle>
                                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"/></svg>
                                        <span class="fullscreen-label">{{ $tr('Plein écran') }}</span>
                                    </button>
                                    <form action="{{ route('session.lock') }}" method="POST">
                                        @csrf
                                        <button class="w-full flex items-center gap-3 rounded-lg px-3 py-2 text-left text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-white/5" type="submit">
                                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                            {{ $tr('Verrouiller') }}
                                        </button>
                                    </form>
                                    @if ($layoutStores->count() > 1)
                                    <details class="relative">
                                        <summary class="flex cursor-pointer list-none items-center gap-3 rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-white/5">
                                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                                            {{ $tr('Magasin') }}
                                        </summary>
                                        <div class="p-2">
                                            <form action="{{ route('settings.current-store.update') }}" method="POST" class="space-y-2">
                                                @csrf
                                                <select name="current_store" class="h-10 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">
                                                    @foreach ($layoutStores->where('is_active', true) as $store)
                                                        <option value="{{ $store['key'] }}" @selected($layoutCurrentStore['key'] === $store['key'])>{{ $store['name'] }}</option>
                                                    @endforeach
                                                </select>
                                                <button class="w-full rounded-lg bg-brand px-3 py-2 text-sm font-semibold text-white">{{ $tr('Changer') }}</button>
                                            </form>
                                            <a href="{{ route('module', ['module' => 'settings', 'section' => 'warehouses']) }}" class="mt-2 block rounded-lg border border-slate-200 px-3 py-2 text-center text-sm font-semibold dark:border-white/10">{{ $tr('Gérer les magasins') }}</a>
                                        </div>
                                    </details>
                                    @endif
                                    <form action="{{ route('locale.switch', \App\Support\Locale::opposite($locale)) }}" method="POST">
                                        @csrf
                                        <button class="w-full flex items-center gap-3 rounded-lg px-3 py-2 text-left text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-white/5" type="submit">
                                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                                            {{ $locale === 'ar' ? 'Français' : 'العربية' }}
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </details>

                        {{-- Individual buttons (large screens) --}}
                        <div class="hidden lg:flex items-center gap-2">
                            <button class="app-sidebar-nav-toggle grid size-11 place-items-center rounded-lg border border-slate-200 bg-white text-slate-600 transition hover:border-brand/40 hover:bg-slate-50 hover:text-brand dark:border-white/10 dark:bg-white/5 dark:text-slate-200" type="button" aria-label="{{ $tr('Afficher le menu') }}" title="{{ $tr('Afficher le menu') }}" data-sidebar-nav-toggle>
                                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="9" y1="3" x2="9" y2="21"/></svg>
                            </button>
                            <button class="app-fullscreen-toggle grid size-11 place-items-center rounded-lg border border-slate-200 bg-white text-slate-600 transition hover:border-brand/40 hover:bg-slate-50 hover:text-brand dark:border-white/10 dark:bg-white/5 dark:text-slate-200" type="button" aria-label="{{ $tr('Mode plein écran') }}" title="{{ $tr('Mode plein écran') }}" aria-pressed="false" data-fullscreen-toggle>
                                <span class="app-fullscreen-enter" aria-hidden="true">⛶</span>
                                <span class="app-fullscreen-exit hidden" aria-hidden="true">×</span>
                            </button>
                            <form action="{{ route('session.lock') }}" method="POST">
                                @csrf
                                <button class="grid size-11 place-items-center rounded-lg border border-slate-200 bg-white text-slate-600 transition hover:border-amber-400/50 hover:bg-amber-50 hover:text-amber-600 dark:border-white/10 dark:bg-white/5 dark:text-slate-200 dark:hover:border-amber-400/30 dark:hover:bg-amber-500/10" type="submit" aria-label="Verrouiller la session" title="Verrouiller la session">
                                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                </button>
                            </form>
                            @if ($layoutStores->count() > 1)
                            <details class="relative">
                                <summary class="current-store-trigger grid size-11 cursor-pointer list-none place-items-center rounded-lg border border-slate-200 bg-white text-slate-600 transition hover:border-brand/40 hover:text-brand dark:border-white/10 dark:bg-white/5 dark:text-slate-200" title="{{ $layoutCurrentStore['name'] }}">
                                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                                </summary>
                                <div class="absolute right-0 top-12 z-40 w-72 max-h-[calc(100dvh-5rem)] overflow-y-auto overscroll-contain rounded-xl border border-slate-200 bg-white p-3 shadow-xl [scrollbar-width:thin] dark:border-white/10 dark:bg-slate-950">
                                    <p class="px-1 pb-2 text-xs font-semibold uppercase text-slate-500">{{ $tr('Magasin courant') }}</p>
                                    <form action="{{ route('settings.current-store.update') }}" method="POST" class="space-y-2">
                                        @csrf
                                        <select name="current_store" class="h-10 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-slate-900">
                                            @foreach ($layoutStores->where('is_active', true) as $store)
                                                <option value="{{ $store['key'] }}" @selected($layoutCurrentStore['key'] === $store['key'])>{{ $store['name'] }}</option>
                                            @endforeach
                                        </select>
                                        <button class="w-full rounded-lg bg-brand px-3 py-2 text-sm font-semibold text-white">{{ $tr('Changer') }}</button>
                                    </form>
                                    <a href="{{ route('module', ['module' => 'settings', 'section' => 'warehouses']) }}" class="mt-2 block rounded-lg border border-slate-200 px-3 py-2 text-center text-sm font-semibold dark:border-white/10">{{ $tr('Gérer les magasins') }}</a>
                                </div>
                            </details>
                            @endif
                        </div>

                        {{-- POS --}}
                        <a href="{{ route('pos') }}" class="grid size-11 place-items-center rounded-lg bg-brand text-white shadow-sm transition brightness-100 hover:brightness-110" title="{{ $tr('Caisse') }}">
                            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/>
                            </svg>
                        </a>

                        {{-- Profile --}}
                        @auth
                            @php
                                $accountUser = auth()->user();
                                $accountTenant = $accountUser?->currentTenant ?? $tenant;
                                $accountTimezoneLabel = \App\Support\TenantClock::label($accountTenant);
                                $accountTimezoneOffset = \App\Support\TenantClock::offset($accountTenant);
                                $accountCurrentTime = \App\Support\TenantClock::currentTimeLabel($accountTenant);
                                $accountBusinessMode = \App\Support\BusinessMode::current($accountTenant);
                                $accountActivityKey = \App\Support\ItemTypes::activityForTenant($accountTenant);
                                $accountActivityOptions = \App\Support\ItemTypes::activityOptions();
                                $accountActivityLabel = $accountActivityOptions[$accountActivityKey]['label'] ?? \Illuminate\Support\Str::headline($accountActivityKey);
                                $accountTenantUser = $accountTenant?->users()->whereKey($accountUser?->id)->first();
                                $accountRoleKey = (string) ($accountTenantUser?->pivot?->role ?? '');
                                $accountRoleName = \App\Models\Role::where('tenant_id', $accountTenant?->id)->where('key', $accountRoleKey)->value('name') ?: ucfirst($accountRoleKey ?: 'Aucun rôle');
                            @endphp
                            <details class="relative">
                                <summary class="grid size-11 cursor-pointer list-none place-items-center overflow-hidden rounded-full text-sm font-bold text-white" @unless($accountUser->profile_photo_path) style="background: {{ $accountUser->avatar_color ?: 'var(--brand-primary)' }}" @endunless>
                                    @if ($accountUser->profile_photo_path)
                                        <img src="{{ asset('storage/'.$accountUser->profile_photo_path) }}" alt="{{ $accountUser->name }}" class="h-full w-full object-cover">
                                    @else
                                        {{ Str::upper(Str::substr($accountUser->name, 0, 2)) }}
                                    @endif
                                </summary>
                                <div class="absolute right-0 top-12 z-40 w-72 max-h-[calc(100dvh-5rem)] overflow-y-auto overscroll-contain rounded-xl border border-slate-200 bg-white p-3 shadow-xl [scrollbar-width:thin] dark:border-white/10 dark:bg-slate-950">
                                    <div class="flex items-center gap-3 border-b border-slate-200 pb-3 dark:border-white/10">
                                        <x-user-avatar :user="$accountUser" size="sm" rounded="rounded-lg" />
                                        <span class="min-w-0">
                                            <strong class="block truncate text-sm">{{ $accountUser->name }}</strong>
                                            <small class="block truncate text-xs text-slate-500">{{ $accountUser->email }}</small>
                                    <span class="mt-1 inline-flex max-w-full items-center rounded-full bg-brand/10 px-2 py-0.5 text-[11px] font-semibold text-brand">{{ $accountRoleName }}</span>
                                </span>
                            </div>
                            <a href="{{ route('module', ['module' => 'settings', 'section' => 'company']) }}#timezone" class="mt-3 flex items-center justify-between gap-3 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm transition hover:border-brand/40 hover:bg-white dark:border-white/10 dark:bg-white/5 dark:hover:bg-white/10">
                                    <span class="min-w-0">
                                        <span class="block text-[11px] font-bold uppercase tracking-[0.08em] text-slate-500 dark:text-slate-400">{{ $tr('Fuseau horaire') }}</span>
                                        <strong class="mt-0.5 block truncate text-slate-900 dark:text-white">{{ $accountTimezoneLabel }}</strong>
                                        <span class="mt-0.5 block text-xs font-semibold text-slate-500 dark:text-slate-400">{{ $tr('Heure locale') }}: {{ $accountCurrentTime }}</span>
                                    </span>
                                <span class="shrink-0 rounded-full bg-brand/10 px-2.5 py-1 text-xs font-bold text-brand">{{ $accountTimezoneOffset }}</span>
                            </a>
                            <a href="{{ route('module', ['module' => 'settings', 'section' => 'store']) }}" class="mt-2 flex items-center justify-between gap-3 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm transition hover:border-brand/40 hover:bg-white dark:border-white/10 dark:bg-white/5 dark:hover:bg-white/10">
                                <span class="min-w-0">
                                    <span class="block text-[11px] font-bold uppercase tracking-[0.08em] text-slate-500 dark:text-slate-400">{{ $tr("Type d'activité") }}</span>
                                    <strong class="mt-0.5 block truncate text-slate-900 dark:text-white">{{ $accountActivityLabel }}</strong>
                                    <span class="mt-0.5 block text-xs font-semibold text-slate-500 dark:text-slate-400">{{ $accountBusinessMode['label'] ?? $tr('Mode commerce') }}</span>
                                </span>
                                <span class="shrink-0 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-700 ring-1 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20">{{ $accountBusinessMode['short_label'] ?? 'POS' }}</span>
                            </a>
                            @php
                                $accountCostingMethod = data_get($accountTenant?->settings, 'inventory.costing_method', 'lifo');
                                $accountCostingLabels = [
                                    'lifo' => ['label' => 'LIFO', 'sub' => 'Dernier entré, premier sorti', 'color' => 'bg-violet-50 text-violet-700 ring-violet-200 dark:bg-violet-500/10 dark:text-violet-300 dark:ring-violet-500/20'],
                                    'fifo' => ['label' => 'FIFO', 'sub' => 'Premier entré, premier sorti', 'color' => 'bg-sky-50 text-sky-700 ring-sky-200 dark:bg-sky-500/10 dark:text-sky-300 dark:ring-sky-500/20'],
                                    'wac'  => ['label' => 'CMP', 'sub' => 'Coût moyen pondéré', 'color' => 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20'],
                                ];
                                $accountCostingInfo = $accountCostingLabels[$accountCostingMethod] ?? $accountCostingLabels['lifo'];
                            @endphp
                            <a href="{{ route('module', ['module' => 'settings', 'section' => 'store']) }}#inventory" class="mt-2 flex items-center justify-between gap-3 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm transition hover:border-brand/40 hover:bg-white dark:border-white/10 dark:bg-white/5 dark:hover:bg-white/10">
                                <span class="min-w-0">
                                    <span class="block text-[11px] font-bold uppercase tracking-[0.08em] text-slate-500 dark:text-slate-400">{{ $tr('Valorisation du stock') }}</span>
                                    <strong class="mt-0.5 block truncate text-slate-900 dark:text-white">{{ $accountCostingInfo['label'] }}</strong>
                                    <span class="mt-0.5 block text-xs font-semibold text-slate-500 dark:text-slate-400">{{ $accountCostingInfo['sub'] }}</span>
                                </span>
                                <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-bold ring-1 {{ $accountCostingInfo['color'] }}">{{ $accountCostingInfo['label'] }}</span>
                            </a>
                            @if ($virtualDevicesEnabled)
                                @php
                                    $deviceSessionId = session('virtual_device_session_id');
                                    $currentDevice = null;
                                    if ($deviceSessionId) {
                                        $currentDevice = \App\Models\VirtualDeviceSession::where('id', $deviceSessionId)
                                            ->whereNull('disconnected_at')
                                            ->with('virtualDevice')
                                            ->first();
                                    }
                                    $isConnected = $currentDevice?->virtualDevice !== null;
                                    $deviceType = $currentDevice?->virtualDevice?->type ?? 'computer';
                                    $typeIcon = match($deviceType) {
                                        'mobile' => '📱', 'tablet' => '📋',
                                        default => '💻'
                                    };
                                @endphp
                                <div class="border-b border-slate-100 px-1 pb-3 pt-2 dark:border-white/10">
                                    <a href="{{ route('device.select') }}" class="group flex items-center gap-3 rounded-xl px-2 py-2.5 transition hover:bg-slate-50 dark:hover:bg-white/5">
                                        <span class="relative grid size-10 shrink-0 place-items-center rounded-xl text-lg transition group-hover:scale-105 {{ $isConnected ? 'bg-gradient-to-br from-emerald-400 to-teal-500 text-white shadow-sm shadow-emerald-500/25' : 'bg-gradient-to-br from-amber-400 to-orange-500 text-white shadow-sm shadow-amber-500/25' }}">
                                            {{ $typeIcon }}
                                            @if ($isConnected)
                                                <span class="absolute -right-0.5 -top-0.5 size-3 rounded-full border-2 border-white bg-emerald-400 dark:border-slate-950">
                                                    <span class="absolute inset-0 animate-ping rounded-full bg-emerald-400"></span>
                                                </span>
                                            @endif
                                        </span>
                                        <span class="min-w-0 flex-1">
                                            <strong class="block truncate text-[13px] font-semibold leading-tight text-slate-900 dark:text-white">
                                                {{ $isConnected ? $currentDevice->virtualDevice->name : $tr('Aucun appareil') }}
                                            </strong>
                                            <span class="mt-0.5 flex items-center gap-1.5">
                                                @if ($isConnected)
                                                    <span class="inline-flex items-center gap-1 text-[11px] font-semibold text-emerald-600 dark:text-emerald-400">
                                                        <span class="size-1.5 rounded-full bg-emerald-500"></span>
                                                        {{ $tr('Connecté') }}
                                                    </span>
                                                    @if ($currentDevice->platform || $currentDevice->browser)
                                                        <span class="text-[11px] text-slate-400">· {{ $currentDevice->platform }} {{ $currentDevice->browser }}</span>
                                                    @endif
                                                @else
                                                    <span class="inline-flex items-center gap-1 text-[11px] font-semibold text-amber-600 dark:text-amber-400">
                                                        <span class="size-1.5 rounded-full bg-amber-500"></span>
                                                        {{ $tr('Non connecté') }}
                                                    </span>
                                                    <span class="text-[11px] text-slate-400">· {{ $tr('Sélectionner') }}</span>
                                                @endif
                                            </span>
                                        </span>
                                        <svg class="size-4 shrink-0 -translate-x-1 text-slate-300 opacity-0 transition-all group-hover:translate-x-0 group-hover:opacity-100" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                                    </a>
                                </div>
                            @endif
                            <a href="{{ route('profile') }}" class="mt-2 block rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-white/5">{{ $tr('Mon profil') }}</a>
                            @if ($isOwner)
                                <a href="{{ route('profile.activity') }}" class="block rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-white/5">{{ $tr("Journal d'activité") }}</a>
                            @endif
                                    <a href="{{ route('module', ['module' => 'settings', 'section' => 'users']) }}" class="block rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-white/5">{{ $tr('Utilisateurs & rôles') }}</a>
                                    <a href="{{ route('module', 'settings') }}" class="block rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-white/5">{{ $tr('Paramètres') }}</a>
                                    <button class="app-theme-toggle w-full flex items-center gap-2 rounded-lg px-3 py-2 text-left text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-white/5" type="button">
                                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                                        {{ $tr('Thème') }}
                                    </button>
                                    <form action="{{ route('locale.switch', \App\Support\Locale::opposite($locale)) }}" method="POST">
                                        @csrf
                                        <button class="w-full rounded-lg px-3 py-2 text-left text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-white/5" type="submit">
                                            <span class="mr-2">🌐</span>{{ $locale === 'ar' ? 'Français' : 'العربية' }}
                                        </button>
                                    </form>
                                    <form action="{{ route('session.lock') }}" method="POST" class="mt-1">
                                        @csrf
                                        <button class="w-full rounded-lg px-3 py-2 text-left text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-white/5" type="submit">{{ $tr('Verrouiller la session') }}</button>
                                    </form>
                                    <form action="{{ route('logout') }}" method="POST" class="mt-2 border-t border-slate-200 pt-2 dark:border-white/10">
                                        @csrf
                                        <button class="w-full rounded-lg px-3 py-2 text-left text-sm font-semibold text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-500/10">{{ $tr('Déconnexion') }}</button>
                                    </form>
                                </div>
                            </details>
                        @else
                            <a href="{{ route('login') }}" class="grid size-11 place-items-center rounded-lg border border-slate-200 bg-white text-slate-700 transition hover:border-brand/40 hover:text-brand dark:border-white/10 dark:bg-white/5 dark:text-slate-200" title="{{ $tr('Connexion') }}">
                                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
                            </a>
                        @endauth
                    </div>
                    @if (session('status'))
                        <div class="hidden" data-app-toast-message="{{ e(session('status')) }}"></div>
                    @endif
                    @if ($errors->any())
                        <div class="mt-3 rounded-lg border border-rose-200 bg-rose-50 px-4 py-2 text-sm font-medium text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300">
                            {{ $errors->first() }}
                        </div>
                    @endif
                </header>

                <div class="app-page-content px-4 py-6 lg:px-8">
                    {{ $slot }}
                </div>
            </main>
        </div>
    </body>
</html>
