<?php

namespace App\Support;

use App\Models\Tenant;

class AppModules
{
    public static function all(): array
    {
        return [
            'dashboard' => ['label' => 'Tableau de bord', 'description' => 'KPIs, activité du jour, rapports rapides et centre d’action.', 'locked' => true, 'default' => true],
            'catalog' => ['label' => 'Catalogue', 'description' => 'Articles, services, catégories, marques, variantes, imports et étiquettes.', 'default' => true],
            'sales' => ['label' => 'Ventes & caisse', 'description' => 'POS, ventes, paiements, retours et workflow comptoir.', 'default' => true],
            'invoices' => ['label' => 'Factures', 'description' => 'Création, consultation, impression et PDF des factures client.', 'default' => true],
            'quotations' => ['label' => 'Devis', 'description' => 'Pro-forma, devis client, expiration et conversion en vente.', 'default' => true],
            'deliveries' => ['label' => 'Livraisons', 'description' => 'Bons de livraison, suivi de préparation et statut de livraison.', 'default' => true],
            'purchases' => ['label' => 'Achats', 'description' => 'Commandes fournisseurs, réception de stock et retours d’achat.', 'default' => true],
            'customers' => ['label' => 'Clients', 'description' => 'Fiches clients, historique, imports, CRM et soldes.', 'default' => true],
            'suppliers' => ['label' => 'Fournisseurs', 'description' => 'Fiches fournisseurs, contacts, imports et suivi achat.', 'default' => true],
            'expenses' => ['label' => 'Dépenses', 'description' => 'Charges, catégories, règlements et historique des frais.', 'default' => true],
            'advances' => ['label' => 'Avances client', 'description' => 'Acomptes, crédits client et soldes disponibles en caisse.', 'default' => true],
            'coupons' => ['label' => 'Coupons', 'description' => 'Coupons client, codes promotionnels et suivi d’utilisation.', 'default' => true],
            'discounts' => ['label' => 'Remises', 'description' => 'Règles de remise, conditions, modes de paiement et tickets liés.', 'default' => true],
            'accounts' => ['label' => 'Comptes', 'description' => 'Comptes banque/caisse, dépôts, transferts et transactions.', 'default' => true],
            'cash_register' => ['label' => 'Tiroir caisse', 'description' => 'Ouverture, mouvements espèces, solde attendu et clôture.', 'default' => true],
            'stock' => ['label' => 'Stock', 'description' => 'Inventaire, mouvements, ajustements, transferts et valorisation.', 'default' => true],
            'loans' => ['label' => 'Emprunts', 'description' => 'Prêts, retours, pénalités, réservations et cartes membre.', 'default' => false],
            'reports' => ['label' => 'Rapports', 'description' => 'Rapports de vente, achat, stock, finance et performance.', 'default' => true],
            'users' => ['label' => 'Utilisateurs', 'description' => 'Comptes équipe, rôles, permissions, PIN et accès magasins.', 'default' => true],
            'messaging' => ['label' => 'Messagerie', 'description' => 'SMS, WhatsApp, emails, modèles et envois manuels.', 'default' => true],
            'stores' => ['label' => 'Magasins', 'description' => 'Magasins, dépôts, magasin courant et accès par équipe.', 'default' => true],
            'guide' => ['label' => 'Guide fonctionnalités', 'description' => 'Vue de contrôle des fonctionnalités et parcours disponibles.', 'default' => true],
            'settings' => ['label' => 'Paramètres', 'description' => 'Société, magasins, utilisateurs, rôles, modules, thème et intégrations.', 'locked' => true, 'default' => true],
        ];
    }

    public static function defaultOrder(): array
    {
        return self::normalizeOrder(array_keys(self::all()));
    }

    public static function settings(Tenant $tenant): array
    {
        $modules = self::all();
        $enabled = collect(data_get($tenant->settings, 'modules.enabled', []));
        $order = collect(data_get($tenant->settings, 'modules.order', self::defaultOrder()))
            ->filter(fn ($key) => isset($modules[$key]))
            ->values();

        $missing = collect(array_keys($modules))->diff($order);
        $order = self::normalizeOrder($order->merge($missing)->values()->all());

        $states = collect($modules)->mapWithKeys(function (array $module, string $key) use ($enabled): array {
            return [$key => (bool) ($module['locked'] ?? false) || (bool) $enabled->get($key, $module['default'] ?? true)];
        })->all();

        return [
            'definitions' => $modules,
            'enabled' => $states,
            'order' => $order,
        ];
    }

    public static function enabled(Tenant $tenant, string $key): bool
    {
        $settings = self::settings($tenant);

        return (bool) ($settings['enabled'][$key] ?? false);
    }

    public static function normalizeOrder(array $order): array
    {
        return collect($order)
            ->filter(fn ($key) => $key !== 'settings')
            ->push('settings')
            ->values()
            ->all();
    }

    public static function keyForModulePage(string $module, string $section = 'list'): ?string
    {
        return match ($module) {
            'sales' => match (true) {
                in_array($section, ['invoices'], true) => 'invoices',
                in_array($section, ['quotes', 'quote-add'], true) => 'quotations',
                in_array($section, ['delivery'], true) => 'deliveries',
                default => 'sales',
            },
            'purchases' => 'purchases',
            'loans' => 'loans',
            'contacts' => match (true) {
                str_contains($section, 'supplier') => 'suppliers',
                default => 'customers',
            },
            'finance' => match (true) {
                str_contains($section, 'expense') => 'expenses',
                str_contains($section, 'advance') => 'advances',
                str_contains($section, 'coupon') => 'coupons',
                str_contains($section, 'discount') => 'discounts',
                in_array($section, ['accounts', 'account-add', 'transfers', 'deposits', 'cash'], true) => 'accounts',
                default => 'accounts',
            },
            'cash-register' => 'cash_register',
            'reports' => 'reports',
            'settings' => match ($section) {
                'users', 'roles' => 'users',
                'warehouses' => 'stores',
                'messaging', 'message-templates', 'sms-api' => 'messaging',
                default => 'settings',
            },
            default => null,
        };
    }

    public static function keyForRouteName(string $name, ?string $module = null, string $section = 'list'): ?string
    {
        if ($name === 'dashboard') {
            return 'dashboard';
        }

        if ($name === 'functionality-guide') {
            return 'guide';
        }

        if ($name === 'stock' || str_starts_with($name, 'catalog.stock-')) {
            return 'stock';
        }

        if ($name === 'module' && $module !== null) {
            return self::keyForModulePage($module, $section);
        }

        if (str_starts_with($name, 'legacy.')) {
            return self::keyForLegacyRoute($name);
        }

        foreach ([
            'catalog.' => 'catalog',
            'variants.' => 'catalog',
            'pos' => 'sales',
            'pos.' => 'sales',
            'sales.invoice.' => 'invoices',
            'sales.invoices.' => 'invoices',
            'sales.deliveries.' => 'deliveries',
            'sales.' => 'sales',
            'quotations.' => 'quotations',
            'purchases.' => 'purchases',
            'contacts.data' => 'customers',
            'contacts.import.example' => 'customers',
            'contacts.import' => 'customers',
            'contacts.' => 'customers',
            'customer-advances.' => 'advances',
            'expenses.' => 'expenses',
            'coupons.' => 'coupons',
            'discounts.' => 'discounts',
            'accounts.' => 'accounts',
            'cash-register.' => 'cash_register',
            'devices.' => 'settings',
            'settings.modules.' => 'settings',
            'settings.users.' => 'users',
            'settings.roles.' => 'users',
            'settings.stores.' => 'stores',
            'settings.current-store.' => 'stores',
            'settings.messaging.' => 'messaging',
            'settings.message-templates.' => 'messaging',
            'settings.virtual-devices.' => 'settings',
            'settings.' => 'settings',
        ] as $prefix => $key) {
            if ($name === rtrim($prefix, '.') || str_starts_with($name, $prefix)) {
                return $key;
            }
        }

        return null;
    }

    private static function keyForLegacyRoute(string $name): ?string
    {
        if (str_starts_with($name, 'legacy.report.')) {
            return 'reports';
        }

        foreach ([
            'legacy.items' => 'catalog',
            'legacy.services' => 'catalog',
            'legacy.categories' => 'catalog',
            'legacy.brands' => 'catalog',
            'legacy.variants' => 'catalog',
            'legacy.import.items' => 'catalog',
            'legacy.import.services' => 'catalog',
            'legacy.tax' => 'catalog',
            'legacy.units' => 'catalog',
            'legacy.stock_' => 'stock',
            'legacy.pos' => 'sales',
            'legacy.sales' => 'sales',
            'legacy.delivery' => 'deliveries',
            'legacy.invoice' => 'invoices',
            'legacy.quotation' => 'quotations',
            'legacy.purchase' => 'purchases',
            'legacy.customers' => 'customers',
            'legacy.suppliers' => 'suppliers',
            'legacy.import.customers' => 'customers',
            'legacy.import.suppliers' => 'suppliers',
            'legacy.expense' => 'expenses',
            'legacy.advance' => 'advances',
            'legacy.customer_coupon' => 'coupons',
            'legacy.discount_coupon' => 'coupons',
            'legacy.accounts' => 'accounts',
            'legacy.money_' => 'accounts',
            'legacy.cash_transactions' => 'accounts',
            'legacy.users' => 'users',
            'legacy.roles' => 'users',
            'legacy.sms' => 'messaging',
            'legacy.warehouse' => 'stores',
            'legacy.store_profile' => 'settings',
            'legacy.payment_types' => 'settings',
            'legacy.country' => 'settings',
            'legacy.state' => 'settings',
            'legacy.password' => 'settings',
        ] as $prefix => $key) {
            if ($name === $prefix || str_starts_with($name, $prefix.'.') || str_starts_with($name, $prefix)) {
                return $key;
            }
        }

        return null;
    }
}
