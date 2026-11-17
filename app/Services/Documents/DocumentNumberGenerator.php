<?php

namespace App\Services\Documents;

use App\Models\DocumentSequence;
use App\Models\Invoice;
use App\Models\SaleInvoice;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\SoftDeletes;

class DocumentNumberGenerator
{
    /**
     * Claim the next available document number.
     *
     * @param  \Closure(string):bool|null  $existsCheck  Return true if the candidate number is already taken.
     *                                                   When provided, the generator will advance past any collision.
     */
    public function next(Tenant $tenant, string $documentType, ?string $prefix = null, ?\Closure $existsCheck = null): array
    {
        $prefix ??= $this->defaultPrefix($documentType);

        // The row has to exist BEFORE the lock. `lockForUpdate()->first()`
        // returning null locks nothing, so two tills claiming the very first
        // number of a series both saw "no sequence", both created one, and
        // both handed out 00001. firstOrCreate settles that on the unique
        // index; the lock below then serialises every later claim.
        $sequence = DocumentSequence::firstOrCreate(
            [
                'tenant_id' => $tenant->id,
                'document_type' => $documentType,
                'prefix' => $prefix,
            ],
            [
                'next_number' => 1,
                'format' => ['padding' => 5],
            ],
        );
        $sequence = DocumentSequence::whereKey($sequence->getKey())->lockForUpdate()->firstOrFail();

        $serial = (int) $sequence->next_number;
        $padding = (int) data_get($sequence->format, 'padding', 5);

        // Skip over any numbers that already exist in the target table.
        if ($existsCheck) {
            $guard = 0;
            while ($existsCheck($prefix.str_pad((string) $serial, $padding, '0', STR_PAD_LEFT))) {
                $serial++;
                if (++$guard > 10_000) {
                    break; // safety valve — never infinite-loop
                }
            }
        }

        $number = $prefix.str_pad((string) $serial, $padding, '0', STR_PAD_LEFT);
        $sequence->forceFill(['next_number' => $serial + 1])->save();

        return [
            'number' => $number,
            'prefix' => $prefix,
            'serial' => $serial,
        ];
    }

    /**
     * Claim the next number of a series stored in one table, skipping every
     * number that table already holds — soft-deleted rows included, because
     * they still occupy the (tenant_id, number) unique index.
     *
     * A sequence can fall behind its table: the demo cleanup deletes the
     * sequences but only soft-deletes the documents, and an import can write
     * numbers without claiming them. Without this check the counter restarts
     * at 00001 and the insert dies on the unique index at the cashier's till.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     */
    public function nextIn(Tenant $tenant, string $documentType, ?string $prefix, string $model): array
    {
        return $this->next($tenant, $documentType, $prefix, self::takenIn($tenant, $model));
    }

    /**
     * "Is this number already used in that table?" for one tenant.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     * @return \Closure(string):bool
     */
    public static function takenIn(Tenant $tenant, string $model): \Closure
    {
        return function (string $candidate) use ($tenant, $model): bool {
            $query = in_array(SoftDeletes::class, class_uses_recursive($model), true)
                ? $model::withTrashed()
                : $model::query();

            return $query->where('tenant_id', $tenant->id)->where('number', $candidate)->exists();
        };
    }

    /**
     * Claim the next invoice number, for either kind of invoice.
     *
     * A tenant has ONE legal invoice series. Two tables carry invoices — the
     * module's `invoices` and the `sale_invoices` raised from a cashed sale —
     * and they used to number independently, so a shop could hold two
     * different documents both called FAC00007. The series is shared here so
     * that cannot be reintroduced by a new call site.
     *
     * Soft-deleted rows count: they still occupy the unique index, and a
     * number reused after a deletion would collide on save.
     */
    public function nextInvoice(Tenant $tenant, ?string $prefix = null): array
    {
        $prefix ??= $this->defaultPrefix('invoice');

        return $this->next($tenant, 'invoice', $prefix, fn (string $candidate): bool => Invoice::withTrashed()
            ->where('tenant_id', $tenant->id)
            ->where('number', $candidate)
            ->exists()
            || SaleInvoice::withTrashed()
                ->where('tenant_id', $tenant->id)
                ->where('number', $candidate)
                ->exists());
    }

    /**
     * Preview the next number without consuming a sequence slot.
     * Use for display only — the actual assigned number may differ.
     */
    public function peek(Tenant $tenant, string $documentType, ?string $prefix = null): string
    {
        $prefix ??= $this->defaultPrefix($documentType);

        $sequence = DocumentSequence::query()
            ->where('tenant_id', $tenant->id)
            ->where('document_type', $documentType)
            ->where('prefix', $prefix)
            ->first();

        $serial = (int) ($sequence?->next_number ?? 1);
        $padding = (int) data_get($sequence?->format, 'padding', 5);

        return $prefix.str_pad((string) $serial, $padding, '0', STR_PAD_LEFT);
    }

    private function defaultPrefix(string $documentType): string
    {
        return match ($documentType) {
            // 'BL' (bon de livraison), not 'TKT'. A parked ticket is 'ATT';
            // a sale is the delivery document. The online-order path had to
            // pass 'BL' explicitly to get this, which meant ONE document type
            // was numbered from TWO series — BL00001 from the web and TKT00001
            // from the POS, for the same tenant and the same kind of document.
            'sale'             => 'BL',
            'return'           => 'RTN',
            'invoice'          => 'FAC',
            'invoice_payment'  => 'IPAY',
            'estimate'         => 'DEV',
            'payment'          => 'PAY',
            default            => strtoupper(substr($documentType, 0, 3)),
        };
    }
}
