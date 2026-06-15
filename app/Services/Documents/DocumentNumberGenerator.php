<?php

namespace App\Services\Documents;

use App\Models\DocumentSequence;
use App\Models\Tenant;

class DocumentNumberGenerator
{
    public function next(Tenant $tenant, string $documentType, ?string $prefix = null): array
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
        $number = $prefix.str_pad((string) $serial, $padding, '0', STR_PAD_LEFT);

        $sequence->forceFill(['next_number' => $serial + 1])->save();

        return [
            'number' => $number,
            'prefix' => $prefix,
            'serial' => $serial,
        ];
    }
}
