<?php

namespace App\Services\Documents;

use App\Models\DocumentSequence;
use App\Models\Tenant;

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
        $prefix ??= match ($documentType) {
            'invoice', 'invoice_payment' => $documentType === 'invoice' ? 'FAC' : 'IPAY',
            'estimate' => 'DEV',
            default => strtoupper(substr($documentType, 0, 3)),
        };

        $sequence = DocumentSequence::query()
            ->where('tenant_id', $tenant->id)
            ->where('document_type', $documentType)
            ->where('prefix', $prefix)
            ->lockForUpdate()
            ->first();

        if (! $sequence) {
            $sequence = DocumentSequence::create([
                'tenant_id' => $tenant->id,
                'document_type' => $documentType,
                'prefix' => $prefix,
                'next_number' => 1,
                'format' => ['padding' => 5],
            ]);
            $sequence->refresh();
        }

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
     * Preview the next number without consuming a sequence slot.
     * Use for display only — the actual assigned number may differ.
     */
    public function peek(Tenant $tenant, string $documentType, ?string $prefix = null): string
    {
        $prefix ??= match ($documentType) {
            'invoice', 'invoice_payment' => $documentType === 'invoice' ? 'FAC' : 'IPAY',
            'estimate' => 'DEV',
            default => strtoupper(substr($documentType, 0, 3)),
        };

        $sequence = DocumentSequence::query()
            ->where('tenant_id', $tenant->id)
            ->where('document_type', $documentType)
            ->where('prefix', $prefix)
            ->first();

        $serial = (int) ($sequence?->next_number ?? 1);
        $padding = (int) data_get($sequence?->format, 'padding', 5);

        return $prefix.str_pad((string) $serial, $padding, '0', STR_PAD_LEFT);
    }
}
