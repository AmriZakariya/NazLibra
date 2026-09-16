<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\Documents\DocumentAuditTrail;
use App\Support\TenantClock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Moves unpaid invoices past their due date to "en retard".
 *
 * Overdue is the one invoice status that becomes true with no one touching
 * the row: the shop closes on Friday with everything "envoyée" and by Monday
 * three of those are late. Until this ran, the status only moved when
 * something else happened to save the invoice — so the list, the status
 * filter and any reminder built on them were all quietly wrong.
 */
class MarkInvoicesOverdue extends Command
{
    protected $signature = 'invoices:mark-overdue {--tenant= : Limit the sweep to one tenant id}';

    protected $description = 'Marque en retard les factures échues et non soldées';

    public function handle(DocumentAuditTrail $audit): int
    {
        $tenants = Tenant::query()
            ->when($this->option('tenant'), fn ($query, $id) => $query->whereKey($id))
            ->get();

        $marked = 0;

        foreach ($tenants as $tenant) {
            // The shop's own midnight, not the server's. A Casablanca shop
            // whose server runs in UTC would otherwise see invoices turn late
            // an hour early — and on the last day of a month that lands the
            // change in the wrong month's ageing report.
            $today = now(TenantClock::timezone($tenant))->toDateString();

            Invoice::query()
                ->where('tenant_id', $tenant->id)
                ->whereIn('status', ['sent', 'viewed', 'partially_paid'])
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<', $today)
                ->where('balance_due', '>', 0)
                ->whereNull('archived_at')
                ->orderBy('id')
                ->chunkById(200, function ($invoices) use ($tenant, $audit, &$marked): void {
                    foreach ($invoices as $invoice) {
                        DB::transaction(function () use ($invoice, $tenant, $audit, &$marked): void {
                            // Re-read under a lock: a cashier may have taken
                            // the closing payment between the chunk query and
                            // here, and stamping that invoice "en retard"
                            // would send a reminder for a settled bill.
                            $fresh = Invoice::whereKey($invoice->id)->lockForUpdate()->first();
                            if (! $fresh || $fresh->status !== $invoice->status || (float) $fresh->balance_due <= 0) {
                                return;
                            }

                            $from = $fresh->status;
                            $fresh->forceFill([
                                'status' => 'overdue',
                                'version' => $fresh->version + 1,
                            ])->save();
                            $audit->record($tenant, $fresh, 'overdue', $from, 'overdue');
                            $marked++;
                        });
                    }
                });
        }

        $this->info($marked.' facture(s) marquée(s) en retard.');

        return self::SUCCESS;
    }
}
