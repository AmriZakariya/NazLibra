<?php

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * The demo maintenance engine: wipes a tenant's business data by GROUP.
 *
 * ALWAYS KEPT, never touched here: users, roles, the user-tenant pivot and
 * its access, PINs, the company profile and settings, locations (shops),
 * virtual devices / terminals, taxes, units, printers, financial accounts
 * (only their balance goes back to the opening one).
 *
 * Groups reference each other (an appointment points at a service, a sale at
 * a customer…). Ticking a group therefore pulls in the groups that reference
 * it (PULLS): expand() does it, the screen mirrors it in JS, and the server
 * never trusts the screen. Deletions then run children first, whatever the
 * order the groups were ticked in.
 */
class DemoReset
{
    public const GROUPS = [
        'sales', 'appointments', 'gifts', 'stock', 'catalog', 'contacts',
        'purchases', 'documents', 'finance', 'online', 'schedules',
    ];

    /** Group => the groups that hold references to it and must go with it. */
    public const PULLS = [
        'catalog' => ['sales', 'stock', 'purchases', 'online', 'gifts', 'appointments', 'documents'],
        'contacts' => ['sales', 'appointments', 'gifts', 'purchases', 'documents', 'online', 'finance'],
    ];

    /** Children first. */
    private const ORDER = [
        'appointments', 'gifts', 'sales', 'online', 'documents', 'purchases',
        'finance', 'stock', 'catalog', 'contacts', 'schedules',
    ];

    /** @var list<string> files to remove once the transaction has committed */
    private array $files = [];

    private static array $columnCache = [];

    public function __construct(private readonly Tenant $tenant, private readonly bool $hard = false) {}

    /** The old one-button actions, expressed as groups. */
    public static function groupsForAction(string $action): array
    {
        return match ($action) {
            'clear_sales' => ['sales'],
            'clear_inventory' => ['stock'],
            'clear_items' => ['catalog'],
            'clear_online_orders' => ['online'],
            'clear_purchases' => ['purchases'],
            'clear_documents' => ['documents'],
            'clear_finance' => ['finance'],
            'clear_contacts' => ['contacts'],
            'reset_demo' => self::GROUPS,
            default => [],
        };
    }

    /** @return list<string> the selection plus every group that references it */
    public static function expand(array $groups): array
    {
        $set = array_values(array_intersect(self::GROUPS, array_map('strval', $groups)));

        do {
            $before = count($set);
            foreach ($set as $group) {
                foreach (self::PULLS[$group] ?? [] as $pulled) {
                    if (! in_array($pulled, $set, true)) {
                        $set[] = $pulled;
                    }
                }
            }
        } while (count($set) !== $before);

        return array_values(array_intersect(self::GROUPS, $set));
    }

    /** Runs the (already expanded) groups. @return array<string,int> */
    public function run(array $groups): array
    {
        $this->files = [];
        $groups = self::expand($groups);
        $counts = [];

        foreach (self::ORDER as $group) {
            if (in_array($group, $groups, true)) {
                foreach ($this->{'purge'.ucfirst($group)}() as $key => $n) {
                    $counts[$key] = ($counts[$key] ?? 0) + (int) $n;
                }
            }
        }

        return $counts;
    }

    /** Photos and attachments, removed after the commit (a rollback keeps them). */
    public function deleteFiles(): void
    {
        foreach (array_unique($this->files) as $path) {
            try {
                Storage::disk('public')->delete($path);
            } catch (\Throwable) {
                // A file already gone is the goal.
            }
        }
        $this->files = [];
    }

    /** Rows per group, for the screen. */
    public static function counts(Tenant $tenant): array
    {
        $c = fn (string $table) => DB::table($table)->where('tenant_id', $tenant->id)
            ->when(self::hasColumn($table, 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))->count();
        $sum = fn (array $tables) => array_sum(array_map($c, $tables));

        return [
            'sales' => $sum(['sales', 'sale_returns', 'pos_tickets', 'tip_payouts', 'loyalty_point_transactions', 'delivery_orders']),
            'appointments' => $sum(['appointments', 'appointment_blocks', 'queue_entries']),
            'gifts' => $sum(['gift_cards', 'service_packages', 'customer_packages']),
            'stock' => $sum(['stock_movements', 'item_location_stock', 'stock_adjustments', 'stocktakes', 'stock_transfers']),
            'catalog' => $sum(['items', 'categories', 'brands']),
            'contacts' => $sum(['contacts']),
            'purchases' => $sum(['purchases', 'purchase_payments', 'purchase_returns']),
            'documents' => $sum(['invoices', 'estimates', 'quotations', 'sale_invoices']),
            'finance' => $sum(['cash_register_sessions', 'cash_register_movements', 'expenses', 'account_transactions', 'coupons', 'discount_rules', 'customer_advances']),
            'online' => $sum(['online_orders']),
            'schedules' => $sum(['staff_schedules', 'schedule_exceptions', 'shop_closures']),
        ];
    }

    // ── groups ──────────────────────────────────────────────────────────

    private function purgeAppointments(): array
    {
        return [
            'queue_entries' => $this->purge('queue_entries', $this->t('queue_entries'), true),
            'appointment_blocks' => $this->purge('appointment_blocks', $this->t('appointment_blocks'), true),
            'appointments' => $this->purge('appointments', $this->t('appointments'), true),
        ];
    }

    private function purgeGifts(): array
    {
        return [
            'gift_card_transactions' => $this->purge('gift_card_transactions', $this->t('gift_card_transactions'), true),
            'gift_cards' => $this->purge('gift_cards', $this->t('gift_cards'), true),
            'customer_package_movements' => $this->purge('customer_package_movements', $this->t('customer_package_movements'), true),
            'customer_packages' => $this->purge('customer_packages', $this->t('customer_packages'), true),
            'service_packages' => $this->purge('service_packages', $this->t('service_packages')),
        ];
    }

    private function purgeSales(): array
    {
        $sales = fn () => DB::table('sales')->where('tenant_id', $this->tenant->id);
        $saleIds = fn () => $sales()->select('id');
        $saleMovements = fn () => $this->t('stock_movements')->whereIn('reference_type', [\App\Models\Sale::class, \App\Models\SaleReturn::class]);

        // What a kept table still points at a sale with: let go of it.
        foreach (['appointments', 'gift_cards', 'gift_card_transactions', 'customer_packages', 'customer_package_movements'] as $table) {
            $this->t($table)->whereIn('sale_id', $saleIds())->update(['sale_id' => null]);
        }
        $this->t('online_orders')->whereNotNull('converted_sale_id')->update(['converted_sale_id' => null, 'converted_by' => null]);
        $this->t('quotations')->whereNotNull('converted_sale_id')->update(['converted_sale_id' => null]);

        $counts = [
            'sale_item_modifiers' => $this->t('sale_item_modifiers')->delete(),
            'sale_item_consumptions' => $this->t('sale_item_consumptions')->delete(),
            'kds_lines' => $this->t('kds_lines')->delete(),
            'kds_fires' => $this->t('kds_fires')->delete(),
            'pos_tickets' => $this->purge('pos_tickets', $this->t('pos_tickets')),
            'sale_items' => $this->purge('sale_items', DB::table('sale_items')->whereIn('sale_id', $saleIds())),
            'sale_payments' => $this->purge('sale_payments', $this->t('sale_payments')),
            'coupon_sale' => $this->t('coupon_sale')->delete(),
            'discount_rule_sale' => $this->t('discount_rule_sale')->delete(),
            'delivery_orders' => $this->t('delivery_orders')->delete(),
            'sale_invoices' => $this->purge('sale_invoices', $this->t('sale_invoices')),
            'tip_payouts' => $this->t('tip_payouts')->delete(),
            'loyalty_point_transactions' => $this->purge('loyalty_point_transactions', $this->t('loyalty_point_transactions')),
            'cash_register_movements' => $this->purge('cash_register_movements', $this->t('cash_register_movements')->whereIn('sale_id', $saleIds())),
        ];

        $counts['sale_stock_movements'] = $this->deleteMovements($saleMovements());
        $counts['sale_returns'] = $this->purge('sale_returns', $this->t('sale_returns'));
        $counts['sales'] = $this->purge('sales', $sales());
        $counts['loyalty_points_reset'] = $this->t('contacts')->where('loyalty_points', '!=', 0)->update(['loyalty_points' => 0, 'updated_at' => now()]);

        return $counts;
    }

    private function purgeOnline(): array
    {
        $orderIds = $this->t('online_orders')->select('id');
        $this->t('sales')->whereNotNull('source_online_order_id')->update(['source_online_order_id' => null]);

        return [
            'online_order_items' => DB::table('online_order_items')->whereIn('online_order_id', $orderIds)->delete(),
            'online_orders' => $this->t('online_orders')->delete(),
        ];
    }

    private function purgeDocuments(): array
    {
        $this->t('sales')->whereNotNull('source_invoice_id')->update(['source_invoice_id' => null]);
        $this->t('estimates')->update(['converted_invoice_id' => null]);
        $this->t('quotations')->update(['converted_sale_id' => null]);

        return [
            'invoice_items' => $this->t('invoice_items')->delete(),
            'invoice_payments' => $this->t('invoice_payments')->delete(),
            'estimate_items' => $this->t('estimate_items')->delete(),
            'document_status_histories' => $this->t('document_status_histories')->delete(),
            'sale_invoices' => $this->purge('sale_invoices', $this->t('sale_invoices')),
            'estimates' => $this->t('estimates')->delete(),
            'invoices' => $this->t('invoices')->delete(),
            'quotations' => $this->t('quotations')->delete(),
            // Numbering restarts at 00001.
            'document_sequences' => $this->t('document_sequences')->delete(),
        ];
    }

    private function purgePurchases(): array
    {
        $purchaseIds = $this->t('purchases')->select('id');

        return [
            'purchase_stock_movements' => $this->deleteMovements($this->t('stock_movements')->where('reference_type', \App\Models\Purchase::class)),
            'purchase_items' => $this->purge('purchase_items', DB::table('purchase_items')->whereIn('purchase_id', $purchaseIds)),
            'purchase_payments' => $this->purge('purchase_payments', $this->t('purchase_payments')),
            'purchase_returns' => $this->purge('purchase_returns', $this->t('purchase_returns')),
            'purchases' => $this->purge('purchases', $this->t('purchases')),
        ];
    }

    private function purgeFinance(): array
    {
        $counts = [
            'cash_register_movements' => $this->purge('cash_register_movements', $this->t('cash_register_movements')),
            'cash_register_sessions' => $this->purge('cash_register_sessions', $this->t('cash_register_sessions')),
            'account_transactions' => $this->t('account_transactions')->delete(),
            'customer_advances' => $this->t('customer_advances')->delete(),
            'expenses' => $this->t('expenses')->delete(),
            'expense_categories' => $this->t('expense_categories')->delete(),
            'coupon_sale' => $this->t('coupon_sale')->delete(),
            'discount_rule_sale' => $this->t('discount_rule_sale')->delete(),
            'coupons' => $this->t('coupons')->delete(),
            'discount_rules' => $this->t('discount_rules')->delete(),
            'account_balance_reset' => $this->t('financial_accounts')->update(['current_balance' => DB::raw('opening_balance'), 'updated_at' => now()]),
        ];

        $this->t('contacts')->update(['advance_balance' => 0, 'outstanding_balance' => 0, 'updated_at' => now()]);

        return $counts;
    }

    private function purgeStock(): array
    {
        $layerIds = $this->t('inventory_layers')->select('id');
        $items = DB::table('items')->where('tenant_id', $this->tenant->id)->select('id');

        return [
            'stocktake_items' => $this->t('stocktake_items')->delete(),
            'stocktakes' => $this->t('stocktakes')->delete(),
            'stock_adjustments' => $this->t('stock_adjustments')->delete(),
            'stock_transfers' => $this->t('stock_transfers')->delete(),
            'inventory_layer_consumptions' => DB::table('inventory_layer_consumptions')->whereIn('inventory_layer_id', $layerIds)->delete(),
            'inventory_layers' => $this->t('inventory_layers')->delete(),
            'stock_movements' => $this->t('stock_movements')->delete(),
            // A projection: trashed rows would only collide with the next one.
            'item_location_stock' => $this->t('item_location_stock')->delete(),
            'item_stock_reset' => DB::table('items')->where('tenant_id', $this->tenant->id)->update(['stock_quantity' => 0, 'updated_at' => now()]),
            'variant_stock_reset' => DB::table('item_variants')->whereIn('item_id', $items)->update(['stock_quantity' => 0, 'updated_at' => now()]),
        ];
    }

    private function purgeCatalog(): array
    {
        $items = fn () => DB::table('items')->where('tenant_id', $this->tenant->id)->select('id');

        foreach (DB::table('items')->where('tenant_id', $this->tenant->id)->whereNotNull('images')->pluck('images') as $json) {
            foreach ((array) json_decode((string) $json, true) as $path) {
                if (is_string($path) && $path !== '' && ! preg_match('#^(https?:)?//#', $path)) {
                    $this->files[] = $path;
                }
            }
        }

        $counts = [
            'loans' => $this->t('loans')->delete(),
            'item_staff' => $this->t('item_staff')->delete(),
            'item_components' => DB::table('item_components')->where(fn ($q) => $q->whereIn('item_id', $items())->orWhereIn('component_item_id', $items()))->delete(),
            'item_modifier_groups' => $this->t('item_modifier_groups')->delete(),
            'item_option_types' => $this->t('item_option_types')->delete(),
            'item_variant_values' => $this->t('item_variant_values')->delete(),
            'item_variants' => DB::table('item_variants')->whereIn('item_id', $items())->delete(),
            'modifiers' => $this->t('modifiers')->delete(),
            'modifier_groups' => $this->t('modifier_groups')->delete(),
            'option_values' => $this->t('option_values')->delete(),
            'option_types' => $this->t('option_types')->delete(),
            'variant_options' => $this->t('variant_options')->delete(),
            'items' => $this->purge('items', DB::table('items')->where('tenant_id', $this->tenant->id)),
        ];

        $categoryIds = $this->t('categories')->select('id');
        if ($this->hard) {
            DB::table('printer_group_categories')->whereIn('category_id', $categoryIds)->delete();
        }
        $this->t('categories')->whereNotNull('parent_id')->update(['parent_id' => null]);
        $counts['categories'] = $this->purge('categories', $this->t('categories'));
        $counts['brands'] = $this->purge('brands', $this->t('brands'));

        return $counts;
    }

    private function purgeContacts(): array
    {
        foreach ($this->t('contacts')->whereNotNull('attachment_path')->pluck('attachment_path') as $path) {
            if (is_string($path) && $path !== '') {
                $this->files[] = $path;
            }
        }

        return [
            'loans' => $this->t('loans')->delete(),
            'contact_transactions' => $this->purge('contact_transactions', $this->t('contact_transactions')),
            // Notes, attachments and balances live on the row: they go with it.
            'contacts' => $this->purge('contacts', $this->t('contacts')),
        ];
    }

    private function purgeSchedules(): array
    {
        return [
            'staff_schedules' => $this->t('staff_schedules')->delete(),
            'schedule_exceptions' => $this->t('schedule_exceptions')->delete(),
            'shop_closures' => $this->t('shop_closures')->delete(),
        ];
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function t(string $table): Builder
    {
        return DB::table($table)->where('tenant_id', $this->tenant->id);
    }

    /** Soft-deletes what soft-deletes unless the permanent option is on. */
    private function purge(string $table, Builder $query, bool $forceHard = false): int
    {
        if (! $forceHard && ! $this->hard && self::hasColumn($table, 'deleted_at')) {
            return $query->whereNull('deleted_at')->update(['deleted_at' => now()]);
        }

        return $query->delete();
    }

    /** A movement's layer rows and consumptions follow it (cascade), but say it. */
    private function deleteMovements(Builder $movements): int
    {
        $ids = (clone $movements)->select('id');
        DB::table('inventory_layer_consumptions')->whereIn('outgoing_movement_id', $ids)->delete();

        return $movements->delete();
    }

    private static function hasColumn(string $table, string $column): bool
    {
        return self::$columnCache["$table.$column"] ??= Schema::hasColumn($table, $column);
    }
}
