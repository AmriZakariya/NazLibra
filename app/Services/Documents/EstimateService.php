<?php

namespace App\Services\Documents;

use App\Models\Contact;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EstimateService
{
    public function __construct(
        private readonly CommercialDocumentCalculator $calculator,
        private readonly DocumentNumberGenerator $numbers,
        private readonly DocumentAuditTrail $audit,
        private readonly InvoiceService $invoices,
    ) {
    }

    public function create(Tenant $tenant, array $data): Estimate
    {
        return DB::transaction(function () use ($tenant, $data): Estimate {
            $customer = $this->customer($tenant, $data);
            $number = $this->numbers->next($tenant, 'estimate', $data['serial_prefix'] ?? 'DEV');
            $calculation = $this->calculator->calculate($this->payloadWithSnapshots($tenant, $data));
            $issueDate = Carbon::parse($data['issue_date'] ?? now())->toDateString();
            $expirationDate = ! empty($data['expiration_date']) ? Carbon::parse($data['expiration_date'])->toDateString() : null;
            if ($expirationDate && $expirationDate < $issueDate) {
                throw ValidationException::withMessages(['expiration_date' => "La date d'expiration doit être après la date d'émission."]);
            }

            $estimate = Estimate::create(array_merge($this->documentFields($tenant, $data, $customer, $calculation), [
                'number' => $number['number'],
                'serial_prefix' => $number['prefix'],
                'serial_number' => $number['serial'],
                'status' => $data['status'] ?? 'draft',
                'issue_date' => $issueDate,
                'expiration_date' => $expirationDate,
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]));

            $this->replaceLines($estimate, $calculation['lines']);
            $this->audit->record($tenant, $estimate, 'created', null, $estimate->status);

            return $estimate->fresh(['items', 'customer']);
        });
    }

    public function update(Estimate $estimate, array $data, ?int $expectedVersion = null): Estimate
    {
        return DB::transaction(function () use ($estimate, $data, $expectedVersion): Estimate {
            $estimate = Estimate::whereKey($estimate->id)->lockForUpdate()->firstOrFail();
            if (! in_array($estimate->status, ['draft', 'sent'], true) || $estimate->converted_invoice_id) {
                throw ValidationException::withMessages(['estimate' => 'Ce devis ne peut plus être modifié.']);
            }
            if ($expectedVersion !== null && (int) $estimate->version !== $expectedVersion) {
                throw ValidationException::withMessages(['version' => 'Ce devis a été modifié par un autre utilisateur. Rechargez la page.']);
            }
            $tenant = $estimate->tenant()->firstOrFail();
            $before = $estimate->only(['status', 'total', 'customer_id', 'expiration_date']);
            $customer = $this->customer($tenant, $data + ['customer_id' => $estimate->customer_id]);
            $calculation = $this->calculator->calculate($this->payloadWithSnapshots($tenant, $data));
            $issueDate = ! empty($data['issue_date']) ? Carbon::parse($data['issue_date'])->toDateString() : $estimate->issue_date?->toDateString();
            $expirationDate = array_key_exists('expiration_date', $data)
                ? (! empty($data['expiration_date']) ? Carbon::parse($data['expiration_date'])->toDateString() : null)
                : $estimate->expiration_date?->toDateString();
            if ($expirationDate && $issueDate && $expirationDate < $issueDate) {
                throw ValidationException::withMessages(['expiration_date' => "La date d'expiration doit être après la date d'émission."]);
            }

            $estimate->fill(array_merge($this->documentFields($tenant, $data, $customer, $calculation), [
                'issue_date' => $issueDate,
                'expiration_date' => $expirationDate,
                'updated_by' => auth()->id(),
                'version' => $estimate->version + 1,
            ]));
            $estimate->save();
            $this->replaceLines($estimate, $calculation['lines']);
            $this->audit->record($tenant, $estimate, 'updated', $before['status'], $estimate->status, $this->changes($before, $estimate));

            return $estimate->fresh(['items']);
        });
    }

    public function markSent(Estimate $estimate): Estimate
    {
        return $this->transition($estimate, 'sent', ['draft', 'sent'], ['sent_at' => now()]);
    }

    public function accept(Estimate $estimate): Estimate
    {
        return $this->transition($estimate, 'accepted', ['sent', 'draft'], ['accepted_at' => now(), 'accepted_by' => auth()->id()]);
    }

    public function decline(Estimate $estimate, ?string $reason = null): Estimate
    {
        return $this->transition($estimate, 'declined', ['sent', 'draft'], ['declined_at' => now(), 'declined_by' => auth()->id(), 'decline_reason' => $reason]);
    }

    public function cancel(Estimate $estimate, string $reason): Estimate
    {
        return $this->transition($estimate, 'cancelled', ['draft', 'sent', 'accepted', 'declined'], ['cancelled_at' => now(), 'cancelled_by' => auth()->id(), 'cancellation_reason' => $reason], $reason);
    }

    public function duplicate(Estimate $source): Estimate
    {
        $source->loadMissing('items');
        $data = $this->dataFromEstimate($source);
        $copy = $this->create($source->tenant()->firstOrFail(), array_merge($data, [
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'expiration_date' => now()->addDays(15)->toDateString(),
            'duplicated_from_id' => $source->id,
        ]));
        $copy->forceFill(['duplicated_from_id' => $source->id])->save();

        return $copy->fresh(['items']);
    }

    public function convertToInvoice(Estimate $estimate, array $data = []): Invoice
    {
        return DB::transaction(function () use ($estimate, $data): Invoice {
            $estimate = Estimate::whereKey($estimate->id)->lockForUpdate()->firstOrFail();
            if ($estimate->converted_invoice_id) {
                return Invoice::whereKey($estimate->converted_invoice_id)->firstOrFail();
            }
            if (! in_array($estimate->status, ['accepted', 'sent'], true)) {
                throw ValidationException::withMessages(['estimate' => 'Seul un devis envoyé ou accepté peut être converti en facture.']);
            }
            if ($this->effectiveStatus($estimate) === 'expired') {
                throw ValidationException::withMessages(['estimate' => 'Ce devis est expiré.']);
            }

            $invoice = $this->invoices->create($estimate->tenant()->firstOrFail(), array_merge($this->dataFromEstimate($estimate), [
                'status' => $data['status'] ?? 'draft',
                'issue_date' => $data['issue_date'] ?? now()->toDateString(),
                'due_date' => $data['due_date'] ?? now()->addDays(15)->toDateString(),
                'source_estimate_id' => $estimate->id,
            ]));

            $from = $estimate->status;
            $estimate->forceFill([
                'status' => 'converted',
                'converted_invoice_id' => $invoice->id,
                'converted_at' => now(),
                'converted_by' => auth()->id(),
                'version' => $estimate->version + 1,
            ])->save();
            $this->audit->record($estimate->tenant()->firstOrFail(), $estimate, 'converted', $from, 'converted', [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->number,
            ]);

            return $invoice->fresh(['items']);
        });
    }

    public function archive(Estimate $estimate): Estimate
    {
        $from = $estimate->status;
        $estimate->update(['archived_at' => now(), 'archived_by' => auth()->id()]);
        $this->audit->record($estimate->tenant()->firstOrFail(), $estimate, 'archived', $from, $estimate->status);

        return $estimate->fresh();
    }

    public function restore(Estimate $estimate): Estimate
    {
        $from = $estimate->status;
        $estimate->update(['archived_at' => null, 'archived_by' => null]);
        $this->audit->record($estimate->tenant()->firstOrFail(), $estimate, 'restored', $from, $estimate->status);

        return $estimate->fresh();
    }

    public function effectiveStatus(Estimate $estimate): string
    {
        if (in_array($estimate->status, ['accepted', 'declined', 'cancelled', 'converted', 'archived'], true)) {
            return $estimate->status;
        }
        if ($estimate->expiration_date && $estimate->expiration_date->endOfDay()->isPast()) {
            return 'expired';
        }

        return $estimate->status;
    }

    private function transition(Estimate $estimate, string $toStatus, array $allowedFrom, array $extra = [], ?string $reason = null): Estimate
    {
        return DB::transaction(function () use ($estimate, $toStatus, $allowedFrom, $extra, $reason): Estimate {
            $estimate = Estimate::whereKey($estimate->id)->lockForUpdate()->firstOrFail();
            if ($estimate->converted_invoice_id || ! in_array($estimate->status, $allowedFrom, true)) {
                throw ValidationException::withMessages(['status' => 'Transition de statut non autorisée.']);
            }
            $from = $estimate->status;
            $estimate->update(array_merge($extra, ['status' => $toStatus, 'version' => $estimate->version + 1]));
            $this->audit->record($estimate->tenant()->firstOrFail(), $estimate, $toStatus, $from, $toStatus, [], $reason);

            return $estimate->fresh();
        });
    }

    private function customer(Tenant $tenant, array $data): ?Contact
    {
        if (empty($data['customer_id'])) {
            return null;
        }

        return Contact::where('tenant_id', $tenant->id)->where('kind', 'client')->whereKey($data['customer_id'])->firstOrFail();
    }

    private function documentFields(Tenant $tenant, array $data, ?Contact $customer, array $calculation): array
    {
        return [
            'tenant_id' => $tenant->id,
            'customer_id' => $customer?->id,
            'location_id' => $data['location_id'] ?? null,
            'duplicated_from_id' => $data['duplicated_from_id'] ?? null,
            'converted_invoice_id' => $data['converted_invoice_id'] ?? null,
            'currency' => strtoupper($data['currency'] ?? $tenant->currency ?? 'MAD'),
            'service_date' => $data['service_date'] ?? null,
            'customer_snapshot' => $data['customer_snapshot'] ?? $this->customerSnapshot($customer, $data),
            'tax_breakdown' => $calculation['tax_breakdown'],
            'custom_fields' => $data['custom_fields'] ?? null,
            'gross_subtotal' => $calculation['gross_subtotal'],
            'line_discount_total' => $calculation['line_discount_total'],
            'document_discount_type' => $calculation['document_discount_type'],
            'document_discount_value' => $calculation['document_discount_value'],
            'document_discount_total' => $calculation['document_discount_total'],
            'subtotal' => $calculation['subtotal'],
            'tax_total' => $calculation['tax_total'],
            'fee_total' => $calculation['fee_total'],
            'rounding_total' => $calculation['rounding_total'],
            'total' => $calculation['total'],
            'customer_message' => $data['customer_message'] ?? null,
            'internal_note' => $data['internal_note'] ?? null,
            'terms' => $data['terms'] ?? null,
            'footer' => $data['footer'] ?? null,
            'customer_reference' => $data['customer_reference'] ?? null,
            'metadata' => $data['metadata'] ?? null,
        ];
    }

    private function customerSnapshot(?Contact $customer, array $data): array
    {
        return [
            'name' => $customer?->name ?? $data['customer_name'] ?? 'Client comptoir',
            'company_name' => $data['company_name'] ?? $customer?->client_type,
            'ice' => $customer?->ice ?? $data['ice'] ?? null,
            'tax_number' => $customer?->tax_number ?? $data['tax_number'] ?? null,
            'email' => $customer?->email ?? $data['email'] ?? null,
            'phone' => $customer?->phone ?? $data['phone'] ?? null,
            'billing_address' => $customer?->address ?? $data['billing_address'] ?? null,
            'shipping_address' => $customer?->shipping_address ?? $data['shipping_address'] ?? null,
        ];
    }

    private function payloadWithSnapshots(Tenant $tenant, array $data): array
    {
        $items = Item::where('tenant_id', $tenant->id)
            ->whereIn('id', collect($data['lines'] ?? [])->pluck('item_id')->filter())
            ->with(['tax', 'unit'])
            ->get()
            ->keyBy('id');

        $lines = collect($data['lines'] ?? [])->map(function (array $line) use ($items) {
            $item = ! empty($line['item_id']) ? $items->get($line['item_id']) : null;
            if (! $item && empty($line['name'])) {
                throw ValidationException::withMessages(['lines' => 'Une ligne personnalisée doit contenir une description.']);
            }

            return array_merge([
                'name' => $item?->title,
                'description' => $item?->description,
                'sku' => $item?->sku,
                'barcode' => $item?->barcode ?? $item?->isbn,
                'item_type' => $item?->type,
                'unit' => $item?->unit?->name,
                'unit_price' => $item?->sale_price ?? 0,
                'tax_rate' => $item?->tax?->rate ?? 0,
                'tax_inclusive' => ($item?->tax_type ?? 'Exclusive') === 'Inclusive',
                'item_snapshot' => $item ? $item->only(['id', 'title', 'item_code', 'barcode', 'isbn', 'sku', 'sale_price', 'tax_type']) : null,
            ], $line);
        })->all();

        return array_merge($data, ['lines' => $lines]);
    }

    private function replaceLines(Estimate $estimate, array $lines): void
    {
        $estimate->items()->delete();
        foreach ($lines as $line) {
            $estimate->items()->create([
                'tenant_id' => $estimate->tenant_id,
                'item_id' => $line['item_id'] ?? null,
                'display_order' => $line['display_order'] ?? 1,
                'item_type' => $line['item_type'] ?? null,
                'sku' => $line['sku'] ?? null,
                'barcode' => $line['barcode'] ?? null,
                'name' => $line['name'] ?? $line['description'] ?? 'Ligne',
                'description' => $line['description'] ?? null,
                'quantity' => $line['quantity'],
                'unit' => $line['unit'] ?? null,
                'unit_price' => $line['unit_price'],
                'discount_type' => $line['discount_type'] ?? 'fixed',
                'discount_value' => $line['discount_value'] ?? 0,
                'discount_amount' => $line['discount_amount'],
                'tax_rate' => $line['tax_rate'],
                'tax_inclusive' => $line['tax_inclusive'] ?? false,
                'tax_amount' => $line['tax_amount'],
                'subtotal' => $line['subtotal'],
                'total' => $line['total'],
                'note' => $line['note'] ?? null,
                'item_snapshot' => $line['item_snapshot'] ?? null,
            ]);
        }
    }

    private function dataFromEstimate(Estimate $estimate): array
    {
        return [
            'customer_id' => $estimate->customer_id,
            'customer_snapshot' => $estimate->customer_snapshot,
            'currency' => $estimate->currency,
            'issue_date' => now()->toDateString(),
            'document_discount_type' => $estimate->document_discount_type,
            'document_discount_value' => $estimate->document_discount_value,
            'fee_total' => $estimate->fee_total,
            'rounding_total' => $estimate->rounding_total,
            'customer_message' => $estimate->customer_message,
            'internal_note' => $estimate->internal_note,
            'terms' => $estimate->terms,
            'footer' => $estimate->footer,
            'customer_reference' => $estimate->customer_reference,
            'lines' => $estimate->items->map(fn ($line) => [
                'item_id' => $line->item_id,
                'name' => $line->name,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit' => $line->unit,
                'unit_price' => $line->unit_price,
                'discount_type' => $line->discount_type,
                'discount_value' => $line->discount_value,
                'tax_rate' => $line->tax_rate,
                'tax_inclusive' => $line->tax_inclusive,
                'note' => $line->note,
            ])->all(),
        ];
    }

    private function changes(array $before, Estimate $estimate): array
    {
        $changes = [];
        foreach ($before as $key => $old) {
            $new = $estimate->getAttribute($key);
            if ((string) $old !== (string) $new) {
                $changes[$key] = ['old' => $old, 'new' => $new];
            }
        }

        return $changes;
    }
}
