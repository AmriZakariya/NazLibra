@php
    $themeDefaults = [
        'primary' => '#2563EB',
        'accent' => '#0D9488',
        'success' => '#16A34A',
        'background' => '#F6F8FB',
        'surface_color' => '#FFFFFF',
        'surface_muted' => '#EEF4FF',
        'text' => '#111827',
        'muted' => '#667085',
        'border' => '#D8E1EE',
        'font_scale' => '1',
        'radius' => '12',
        'density' => 'comfortable',
    ];
    $theme = array_merge($themeDefaults, $tenant->settings['theme'] ?? []);
@endphp
<!DOCTYPE html>
<html lang="fr" dir="ltr" style="--brand-primary: {{ $theme['primary'] }}; --brand-accent: {{ $theme['accent'] }}; --brand-success: {{ $theme['success'] }}; --app-bg: {{ $theme['background'] }}; --surface: {{ $theme['surface_color'] }}; --surface-muted: {{ $theme['surface_muted'] }}; --text-main: {{ $theme['text'] }}; --text-muted: {{ $theme['muted'] }}; --border-soft: {{ $theme['border'] }}; --font-scale: {{ $theme['font_scale'] }}; --brand-radius: {{ $theme['radius'] }}px;">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="light dark">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ $title ?? 'LibrairePro' }}</title>
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

                foreach ($query as $key => $value) {
                    if ((string) ($currentQuery[$key] ?? '') !== (string) $value) {
                        return false;
                    }
                }

                return true;
            };
            $articleLinks = [
                ['label' => 'Ajouter un article', 'icon' => '+', 'href' => route('catalog', ['panel' => 'ajouter'])],
                ['label' => 'Ajouter un service', 'icon' => '+', 'href' => route('catalog', ['panel' => 'ajouter-service'])],
                ['label' => "Liste d'articles", 'icon' => '≡', 'href' => route('catalog', ['panel' => 'articles'])],
                ['label' => 'Liste des catégories', 'icon' => '≡', 'href' => route('catalog', ['panel' => 'categories'])],
                ['label' => 'Liste des marques', 'icon' => '≡', 'href' => route('catalog', ['panel' => 'marques'])],
                ['label' => 'Liste des variantes', 'icon' => '≡', 'href' => route('catalog', ['panel' => 'variantes'])],
                ['label' => 'Imprimer des étiquettes', 'icon' => '▥', 'href' => route('catalog.labels')],
                ['label' => 'Importer des éléments', 'icon' => '↤', 'href' => route('catalog', ['panel' => 'import', 'kind' => 'items'])],
                ['label' => "Services d'importation", 'icon' => '↤', 'href' => route('catalog', ['panel' => 'import', 'kind' => 'services'])],
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
                    ['label' => 'Liste des ventes', 'icon' => '≡', 'href' => route('module', ['module' => 'sales', 'section' => 'list'])],
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
                    ['label' => 'Liste avancée', 'icon' => '≡', 'href' => route('module', ['module' => 'finance', 'section' => 'advances'])],
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
                    ['label' => "Liste d'ajustement", 'icon' => '≡', 'href' => route('catalog', ['panel' => 'articles', 'stock' => 'low'])],
                    ['label' => 'Liste de transfert', 'icon' => '≡', 'href' => route('catalog', ['panel' => 'articles', 'section' => 'stock-transfer'])],
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
            <aside class="sticky top-0 hidden h-screen w-72 shrink-0 overflow-y-auto border-r border-slate-200 bg-white/90 px-4 py-5 backdrop-blur md:block dark:border-white/10 dark:bg-slate-950/90">
                <a href="{{ route('dashboard') }}" class="flex items-center gap-3 rounded-xl px-2 py-2">
                    <span class="grid size-10 place-items-center rounded-xl bg-brand text-sm font-bold text-white shadow-sm">LP</span>
                    <span>
                        <span class="block text-sm font-semibold">{{ $tenant->name }}</span>
                        <span class="block text-xs text-slate-500 dark:text-slate-400">SaaS librairie · {{ strtoupper($tenant->currency) }}</span>
                    </span>
                </a>

                <nav class="mt-7 space-y-1 pb-8">
                    @foreach ($nav as $item)
                        <a href="{{ $item['href'] }}" class="group flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition {{ $active === $item['key'] ? 'bg-brand text-white shadow-sm' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-950 dark:text-slate-300 dark:hover:bg-white/5 dark:hover:text-white' }}">
                            <span class="grid size-6 place-items-center rounded-md text-base {{ $active === $item['key'] ? 'bg-white/15' : 'bg-slate-100 text-slate-500 group-hover:text-slate-900 dark:bg-white/5 dark:text-slate-300' }}">{{ $item['icon'] }}</span>
                            {{ $item['label'] }}
                            @if (! empty($item['children']))
                                <span class="ms-auto text-xs {{ $active === $item['key'] ? 'text-white/70' : 'text-slate-400' }}">⌄</span>
                            @endif
                        </a>
                        @if (! empty($item['children']))
                            <div class="ms-5 mt-1 max-h-64 space-y-1 overflow-y-auto border-s border-slate-200 ps-3 dark:border-white/10">
                                @foreach ($item['children'] as $child)
                                    @php($childActive = $isCurrentLink($child['href']))
                                    <a href="{{ $child['href'] }}" class="flex items-center gap-2 rounded-md px-2 py-2 text-xs font-medium transition {{ $childActive ? 'bg-brand/10 text-brand dark:bg-brand/20 dark:text-white' : 'text-slate-500 hover:bg-slate-100 hover:text-slate-950 dark:text-slate-400 dark:hover:bg-white/5 dark:hover:text-white' }}">
                                        <span class="grid size-5 place-items-center rounded text-[11px] {{ $childActive ? 'bg-brand text-white' : 'bg-slate-100 text-slate-500 dark:bg-white/5' }}">{{ $child['icon'] }}</span>
                                        <span class="truncate">{{ $child['label'] }}</span>
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    @endforeach
                </nav>

                <div class="mt-7 rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-white/10 dark:bg-white/[0.03]">
                    <div class="flex items-center justify-between">
                        <p class="text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">Mode rentrée</p>
                        <x-status-pill tone="success">Actif</x-status-pill>
                    </div>
                    <p class="mt-3 text-sm text-slate-600 dark:text-slate-300">Cache POS, alertes stock et commandes rapides prêts pour les pics de caisse.</p>
                </div>
            </aside>

            <main class="min-w-0 flex-1">
                <header class="sticky top-0 z-20 border-b border-slate-200 bg-white/85 px-4 py-3 backdrop-blur dark:border-white/10 dark:bg-slate-950/80 lg:px-8">
                    <div class="flex items-center gap-3">
                        <details class="relative">
                            <summary class="grid size-11 cursor-pointer list-none place-items-center rounded-lg bg-brand text-lg font-semibold text-white shadow-sm">+</summary>
                            <div class="absolute left-0 top-12 z-40 w-64 overflow-hidden rounded-xl border border-slate-200 bg-white py-2 shadow-xl dark:border-white/10 dark:bg-slate-950">
                                @foreach ($quickAdds as $quickAdd)
                                    <a href="{{ $quickAdd['href'] }}" class="block px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-white/5">{{ $quickAdd['label'] }}</a>
                                @endforeach
                            </div>
                        </details>
                        <form action="{{ route('catalog') }}" class="relative flex-1">
                            <input name="q" value="{{ request('q') }}" class="h-11 w-full rounded-lg border border-slate-200 bg-slate-50 px-4 text-sm outline-none transition placeholder:text-slate-400 focus:border-brand focus:bg-white focus:ring-4 focus:ring-brand/10 dark:border-white/10 dark:bg-white/5 dark:focus:bg-slate-900" placeholder="Rechercher titre, ISBN, code-barres, client...">
                        </form>
                        <button class="app-theme-toggle grid size-11 place-items-center rounded-lg border border-slate-200 bg-white text-slate-600 transition hover:bg-slate-50 dark:border-white/10 dark:bg-white/5 dark:text-slate-200" type="button" aria-label="Basculer le thème">◐</button>
                        <button class="app-rtl-toggle hidden rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-50 sm:block dark:border-white/10 dark:bg-white/5 dark:text-slate-200" type="button">العربية</button>
                        <a href="{{ route('pos') }}" class="rounded-lg bg-brand px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition brightness-100 hover:brightness-110">Caisse</a>
                        <div class="grid size-11 place-items-center rounded-full text-sm font-bold text-white" style="background: var(--brand-primary)">AE</div>
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
