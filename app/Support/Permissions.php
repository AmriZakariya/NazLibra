<?php

namespace App\Support;

/**
 * Every permission the application has, and the routes each one guards.
 *
 * There used to be three lists that had to agree and did not: a catalogue in
 * the controller (49 keys), a route map in the middleware (42 gated), and a
 * third copy in the Flutter app (33 keys). Twenty server keys could not be
 * granted from the app at all, four app keys named permissions the server had
 * never heard of, and five catalogue entries gated nothing whatsoever — a
 * checkbox that changed nothing when ticked.
 *
 * Everything reads from here now: the role editor, the middleware, the API.
 * A permission that guards nothing, or a guard naming a permission nobody can
 * grant, is a test failure rather than something to notice in production.
 */
class Permissions
{
    /**
     * Groups, in the order a role editor should show them.
     *
     * @return array<string, array{label: string, permissions: array<string, string>}>
     */
    public static function groups(): array
    {
        return [
            'dashboard' => ['label' => 'Tableau de bord', 'permissions' => [
                'dashboard.view' => 'Voir le tableau de bord',
            ]],
            'catalogue' => ['label' => 'Catalogue', 'permissions' => [
                'items.view' => 'Voir les articles',
                'items.create' => 'Créer un article',
                'items.edit' => 'Modifier un article',
                'items.delete' => 'Supprimer un article',
                'items.import' => 'Importer un catalogue',
            ]],
            'stock' => ['label' => 'Stock', 'permissions' => [
                'stock.view' => 'Voir le stock',
                'stock.adjust' => 'Ajuster le stock',
                'stock.transfer' => 'Transférer entre emplacements',
                'stock.transfer_cancel' => 'Annuler un transfert',
                'stock.stocktake' => 'Faire un inventaire',
            ]],
            'sales' => ['label' => 'Ventes', 'permissions' => [
                'sales.view' => 'Voir les ventes',
                'sales.create' => 'Encaisser',
                'sales.edit' => 'Modifier une vente',
                'sales.delete' => 'Supprimer une vente',
                'sales.refund' => 'Rembourser',
                'sales.payments' => 'Encaisser un règlement',
                'sales.discount' => 'Appliquer une remise',
                'sales.hold' => 'Mettre en attente',
            ]],
            'cash_register' => ['label' => 'Caisse', 'permissions' => [
                'cash_register.view' => 'Voir la caisse',
                'cash_register.open' => 'Ouvrir la caisse',
                'cash_register.close' => 'Clôturer la caisse',
                'cash_register.movements' => 'Entrées / sorties d’espèces',
            ]],
            'online_orders' => ['label' => 'Précommandes', 'permissions' => [
                'online_orders.view' => 'Voir les précommandes',
                'online_orders.create' => 'Créer une précommande',
                'online_orders.edit' => 'Changer le statut',
            ]],
            'invoices' => ['label' => 'Factures', 'permissions' => [
                'invoices.view' => 'Voir les factures',
                'invoices.create' => 'Créer une facture',
                'invoices.edit_draft' => 'Modifier un brouillon',
                'invoices.send' => 'Envoyer',
                'invoices.payments' => 'Encaisser',
                'invoices.cancel' => 'Annuler',
                'invoices.archive' => 'Archiver',
                'invoices.restore' => 'Restaurer',
                'invoices.duplicate' => 'Dupliquer',
            ]],
            'estimates' => ['label' => 'Devis', 'permissions' => [
                'estimates.view' => 'Voir les devis',
                'estimates.create' => 'Créer un devis',
                'estimates.edit' => 'Modifier un devis',
                'estimates.send' => 'Envoyer / accepter / refuser',
                'estimates.convert' => 'Convertir en facture',
                'estimates.duplicate' => 'Dupliquer',
                'estimates.delete' => 'Supprimer un devis',
            ]],
            'purchases' => ['label' => 'Achats', 'permissions' => [
                'purchases.view' => 'Voir les achats',
                'purchases.create' => 'Créer un achat',
                'purchases.receive' => 'Réceptionner',
                'purchases.payments' => 'Régler un fournisseur',
            ]],
            'contacts' => ['label' => 'Contacts', 'permissions' => [
                'contacts.view' => 'Voir les contacts',
                'contacts.create' => 'Créer un contact',
                'contacts.edit' => 'Modifier un contact',
                'contacts.delete' => 'Supprimer un contact',
            ]],
            'finance' => ['label' => 'Finances', 'permissions' => [
                'finance.view' => 'Voir les finances',
                'finance.manage' => 'Gérer dépenses, avances, coupons, comptes',
            ]],
            'reports' => ['label' => 'Rapports', 'permissions' => [
                'reports.view' => 'Voir les rapports',
            ]],
            'settings' => ['label' => 'Paramètres', 'permissions' => [
                'settings.users' => 'Utilisateurs',
                'settings.roles' => 'Rôles et permissions',
                'settings.company' => 'Fiche entreprise et thème',
                'settings.pos' => 'Réglages de caisse',
                'settings.documents' => 'Modèles de documents',
                'settings.messaging' => 'Messagerie et modèles',
                'settings.references' => 'Listes de référence (taxes, pays, moyens de paiement…)',
                'settings.stores' => 'Magasins et emplacements',
                'settings.devices' => 'Terminaux et imprimantes',
                'settings.modules' => 'Activer / désactiver les modules',
                'settings.audit' => 'Journal d’audit',
                'settings.maintenance' => 'Maintenance des données de démonstration',
            ]],
        ];
    }

    /** Every permission key, flat. @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::catalog());
    }

    /** key => label, flat, for anything that wants a simple list. @return array<string, string> */
    public static function catalog(): array
    {
        $flat = [];
        foreach (self::groups() as $group) {
            foreach ($group['permissions'] as $key => $label) {
                $flat[$key] = $label;
            }
        }

        return $flat;
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::catalog());
    }

    /**
     * Route name => the permission it requires.
     *
     * A write route missing from here is open to every user of the tenant,
     * whatever their role — which is how the cash register, stocktakes,
     * purchase payments and another user's PIN came to be ungated.
     *
     * @return array<string, string>
     */
    public static function routeMap(): array
    {
        return [
            'dashboard' => 'dashboard.view',

            'catalog' => 'items.view',
            'catalog.data' => 'items.view',
            'catalog.export' => 'items.view',
            'catalog.labels' => 'items.view',
            'catalog.items.store' => 'items.create',
            'catalog.items.update' => 'items.edit',
            'catalog.items.destroy' => 'items.delete',
            'catalog.categories.store' => 'items.edit',
            'catalog.categories.update' => 'items.edit',
            'catalog.categories.destroy' => 'items.delete',
            'catalog.brands.store' => 'items.edit',
            'catalog.brands.update' => 'items.edit',
            'catalog.brands.destroy' => 'items.delete',
            'catalog.units.store' => 'items.edit',
            'catalog.units.update' => 'items.edit',
            'catalog.units.destroy' => 'items.delete',
            'catalog.taxes.store' => 'items.edit',
            'catalog.taxes.update' => 'items.edit',
            'catalog.taxes.destroy' => 'items.delete',
            'catalog.variants.store' => 'items.edit',
            // Options and the matrix shape the catalogue, so they answer to
            // the same right as editing an article.
            'catalog.options.store' => 'items.edit',
            'catalog.options.update' => 'items.edit',
            'catalog.options.destroy' => 'items.delete',
            'catalog.option-values.store' => 'items.edit',
            'catalog.option-values.update' => 'items.edit',
            'catalog.option-values.destroy' => 'items.delete',
            'catalog.variants.generate' => 'items.edit',
            'catalog.import' => 'items.import',
            'variants.store' => 'items.edit',
            'variants.update' => 'items.edit',
            'variants.duplicate' => 'items.edit',
            'variants.toggle' => 'items.edit',
            'variants.destroy' => 'items.delete',

            'catalog.stock-adjustments.store' => 'stock.adjust',
            'catalog.stock-transfers.store' => 'stock.transfer',
            'catalog.stock-transfers.cancel' => 'stock.transfer_cancel',
            'catalog.stocktakes.store' => 'stock.stocktake',
            'catalog.stocktakes.counts.update' => 'stock.stocktake',
            'catalog.stocktakes.complete' => 'stock.stocktake',

            'pos' => 'sales.create',
            'pos.coupons.preview' => 'sales.create',
            'pos.store' => 'sales.create',
            'pos.tickets.store' => 'sales.create',
            'pos.tickets.destroy' => 'sales.create',
            'sales.store' => 'sales.create',
            'sales.update' => 'sales.edit',
            // Raising an invoice from a sale CREATES a document; it was gated
            // by sales.view, so read-only staff could issue one.
            'sales.invoice.store' => 'invoices.create',
            'sales.pdf' => 'sales.view',
            'sales.invoices.pdf' => 'sales.view',
            'sales.payments.store' => 'sales.payments',
            'sales.refund' => 'sales.refund',
            'sales.destroy' => 'sales.delete',
            'sales.deliveries.store' => 'sales.create',
            'sales.deliveries.update' => 'sales.create',

            'cash-register.open' => 'cash_register.open',
            'cash-register.close' => 'cash_register.close',
            'cash-register.movements.store' => 'cash_register.movements',

            'online-orders.store' => 'online_orders.create',
            'online-orders.status.update' => 'online_orders.edit',
            'online-orders.sale.prepare' => 'sales.create',

            'quotations.store' => 'estimates.create',
            'quotations.update' => 'estimates.edit',
            'quotations.convert' => 'estimates.convert',
            'quotations.destroy' => 'estimates.delete',

            'documents.invoices.store' => 'invoices.create',
            'documents.invoices.update' => 'invoices.edit_draft',
            'documents.invoices.send' => 'invoices.send',
            'documents.invoices.duplicate' => 'invoices.duplicate',
            'documents.invoices.cancel' => 'invoices.cancel',
            'documents.invoices.archive' => 'invoices.archive',
            'documents.invoices.restore' => 'invoices.restore',
            'documents.invoices.payments.store' => 'invoices.payments',
            'documents.invoices.pdf' => 'invoices.view',
            'documents.invoices.data' => 'invoices.view',

            'documents.estimates.store' => 'estimates.create',
            'documents.estimates.update' => 'estimates.edit',
            'documents.estimates.transition' => 'estimates.send',
            'documents.estimates.duplicate' => 'estimates.duplicate',
            'documents.estimates.convert' => 'estimates.convert',
            'documents.estimates.pdf' => 'estimates.view',

            'purchases.store' => 'purchases.create',
            'purchases.receive' => 'purchases.receive',
            'purchases.pdf' => 'purchases.view',
            'purchases.returns.store' => 'purchases.create',
            'purchases.payments.store' => 'purchases.payments',

            'contacts.data' => 'contacts.view',
            'contacts.store' => 'contacts.create',
            'contacts.update' => 'contacts.edit',
            'contacts.destroy' => 'contacts.delete',
            'contacts.import' => 'contacts.create',
            'contacts.import.example' => 'contacts.create',

            'customer-advances.data' => 'finance.view',
            'customer-advances.store' => 'finance.manage',
            'customer-advances.destroy' => 'finance.manage',
            'expenses.store' => 'finance.manage',
            'expenses.categories.store' => 'finance.manage',
            'coupons.store' => 'finance.manage',
            'coupons.update' => 'finance.manage',
            'coupons.destroy' => 'finance.manage',
            'discounts.store' => 'finance.manage',
            'discounts.update' => 'finance.manage',
            'discounts.destroy' => 'finance.manage',
            'accounts.store' => 'finance.manage',
            'accounts.update' => 'finance.manage',
            'accounts.destroy' => 'finance.manage',
            'accounts.deposits.store' => 'finance.manage',
            'accounts.transfers.store' => 'finance.manage',

            // Settings, split out of the old catch-all `settings.theme` — one
            // checkbox labelled "thème" used to open the company profile, the
            // POS, document templates, messaging, every reference list and the
            // password screen.
            'settings.theme.update' => 'settings.company',
            'settings.company.update' => 'settings.company',
            'settings.pos.update' => 'settings.pos',
            'settings.documents.update' => 'settings.documents',
            'settings.messaging.update' => 'settings.messaging',
            'settings.messaging.send' => 'settings.messaging',
            'settings.message-templates.store' => 'settings.messaging',
            'settings.message-templates.update' => 'settings.messaging',
            'settings.message-templates.destroy' => 'settings.messaging',
            'settings.payment-types.store' => 'settings.references',
            'settings.payment-types.update' => 'settings.references',
            'settings.payment-types.destroy' => 'settings.references',
            'settings.countries.store' => 'settings.references',
            'settings.countries.update' => 'settings.references',
            'settings.countries.destroy' => 'settings.references',
            'settings.states.store' => 'settings.references',
            'settings.states.update' => 'settings.references',
            'settings.states.destroy' => 'settings.references',
            'settings.tax-groups.store' => 'settings.references',
            'settings.tax-groups.update' => 'settings.references',
            'settings.tax-groups.destroy' => 'settings.references',
            'settings.current-store.update' => 'settings.stores',
            'settings.stores.store' => 'settings.stores',
            'settings.stores.update' => 'settings.stores',
            'settings.stores.destroy' => 'settings.stores',
            'settings.printer-groups.store' => 'settings.devices',
            'settings.printer-groups.update' => 'settings.devices',
            'settings.printer-groups.destroy' => 'settings.devices',
            'settings.virtual-devices.update' => 'settings.devices',
            'devices.store' => 'settings.devices',
            'devices.update' => 'settings.devices',
            'devices.toggle' => 'settings.devices',
            'devices.destroy' => 'settings.devices',
            'devices.disconnect' => 'settings.devices',
            'settings.modules.update' => 'settings.modules',
            'settings.users.store' => 'settings.users',
            'settings.users.update' => 'settings.users',
            'settings.users.destroy' => 'settings.users',
            // Setting someone else's PIN is taking over their till identity.
            'settings.users.pin' => 'settings.users',
            'settings.roles.store' => 'settings.roles',
            'settings.roles.update' => 'settings.roles',
            'settings.roles.destroy' => 'settings.roles',
            'settings.demo-maintenance.run' => 'settings.maintenance',
        ];
    }

    /**
     * Write routes that are deliberately open to anyone signed in.
     *
     * Each one is either the user acting on their own session, or the device
     * talking about itself. Gating these would lock a cashier out of their own
     * screen. Named explicitly so a new ungated route is a test failure and
     * not an oversight.
     *
     * @return list<string>
     */
    public static function unguardedRoutes(): array
    {
        return [
            'locale.switch',            // the user's own language
            'session.lock',             // locking your own screen
            'session.unlock',
            'session.forgot-pin',       // you cannot ask for help if you need
            'session.send-pin-reset',   // a permission to ask for help
            'settings.password.update', // your OWN password
            'device.connect',           // the terminal announcing itself
            'device.disconnect',
            'device.heartbeat',
        ];
    }

    /**
     * Whether a set of granted permissions satisfies [$required].
     *
     * Supports the wildcards roles are written with: `*` for everything and
     * `group.*` for a whole section.
     *
     * @param  array<int, string>  $granted
     */
    public static function allows(array $granted, string $required): bool
    {
        if (in_array('*', $granted, true) || in_array($required, $granted, true)) {
            return true;
        }

        [$group] = explode('.', $required, 2);

        return in_array($group.'.*', $granted, true);
    }
}
