<?php

namespace App\Services\Documents;

use App\Models\Contact;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Item;
use App\Models\Tenant;
use App\Services\CashRegisterService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceService
{
    public function __construct(
        private readonly CommercialDocumentCalculator $calculator,
        private readonly DocumentNumberGenerator $numbers,
        private readonly DocumentAuditTrail $audit,
        private readonly CashRegisterService $cashRegister,
    ) {
    }

    public function create(Tenant $tenant, array $data): Invoice
    {
        return DB::transaction(function () use ($tenant, $data): Invoice {
            $customer = $this->customer($tenant, $data);
            $number = $this->numbers->next($tenant, 'invoice', $data['serial_prefix'] ?? 'FAC');
            $calculation = $this->calculator->calculate($this->payloadWithSnapshots($tenant, $data));
            $issueDate = Carbon::parse($data['issue_date'] ?? now())->toDateString();
            $dueDate = ! empty($data['due_date']) ? Carbon::parse($data['due_date'])->toDateString() : null;
            if (! $dueDate) {
                throw ValidationException::withMessages(['due_date' => "La date d'échéance est obligatoire."]);
            }
            if ($dueDate && $dueDate < $issueDate) {
                throw ValidationException::withMessages(['due_date' => "La date d'échéance doit être après la date d'émission."]);
            }

            $invoice = Invoice::create(array_merge($this->documentFields($tenant, $data, $customer, $calculation), [
                'number' => $number['number'],
                'serial_prefix' => $number['prefix'],
                'serial_number' => $number['serial'],
                'status' => $data['status'] ?? 'draft',
                'issue_date' => $issueDate,
                'due_date' => $dueDate,
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
                'amount_paid' => '0.00',
                'amount_refunded' => '0.00',
                'balance_due' => $calculation['total'],
            ]));

            $this->replaceLines($invoice, $calculation['lines']);
            $this->audit->record($tenant, $invoice, 'created', null, $invoice->status);

            return $invoice->fresh(['items', 'customer']);
        });
    }

    public function update(Invoice $invoice, array $data, ?int $expectedVersion = null): Invoice
    {
        return DB::transaction(function () use ($invoice, $data, $expectedVersion): Invoice {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $this->assertEditable($invoice);
            if ($expectedVersion !== null && (int) $invoice->version !== $expectedVersion) {
                throw ValidationException::withMessages(['version' => 'Cette facture a été modifiée par un autre utilisateur. Rechargez la page.']);
            }

            $tenant = $invoice->tenant()->firstOrFail();
            $before = $invoice->only(['status', 'total', 'balance_due', 'customer_id', 'due_date']);
            $customer = $this->customer($tenant, $data + ['customer_id' => $invoice->customer_id]);
            $calculation = $this->calculator->calculate($this->payloadWithSnapshots($tenant, $data));
            $issueDate = ! empty($data['issue_date']) ? Carbon::parse($data['issue_date'])->toDateString() : $invoice->issue_date?->toDateString();
            $dueDate = array_key_exists('due_date', $data)
                ? (! empty($data['due_date']) ? Carbon::parse($data['due_date'])->toDateString() : null)
                : $invoice->due_date?->toDateString();
            if (! $dueDate) {
                throw ValidationException::withMessages(['due_date' => "La date d'échéance est obligatoire."]);
            }
            if ($dueDate && $issueDate && $dueDate < $issueDate) {
                throw ValidationException::withMessages(['due_date' => "La date d'échéance doit être après la date d'émission."]);
            }
            $paid = $this->paymentTotal($invoice);
            if ((float) $paid > (float) $calculation['total']) {
                throw ValidationException::withMessages(['total' => 'Le nouveau total serait inférieur aux paiements déjà enregistrés.']);
            }

            $invoice->fill(array_merge($this->documentFields($tenant, $data, $customer, $calculation), [
                'issue_date' => $issueDate,
                'due_date' => $dueDate,
                'updated_by' => auth()->id(),
                'version' => $invoice->version + 1,
                'amount_paid' => $paid,
                'balance_due' => number_format(max(0, (float) $calculation['total'] - (float) $paid), 2, '.', ''),
            ]));
            $invoice->save();
            $this->replaceLines($invoice, $calculation['lines']);
            $this->refreshPaymentStatus($invoice);
            $this->audit->record($tenant, $invoice, 'updated', $before['status'], $invoice->status, $this->changes($before, $invoice));

            return $invoice->fresh(['items', 'payments']);
        });
    }

    public function send(Invoice $invoice): Invoice
    {
        return $this->transition($invoice, 'sent', ['draft', 'sent'], ['sent_at' => now()]);
    }

    public function duplicate(Invoice $source): Invoice
    {
        $source->loadMissing('items');
        $data = $this->dataFromInvoice($source);
        $copy = $this->create($source->tenant()->firstOrFail(), array_merge($data, [
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'duplicated_from_id' => $source->id,
        ]));
        $copy->forceFill(['duplicated_from_id' => $source->id])->save();

        return $copy->fresh(['items']);
    }

    public function cancel(Invoice $invoice, string $reason): Invoice
    {
        return DB::transaction(function () use ($invoice, $reason): Invoice {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($this->paymentTotal($invoice) > 0) {
                throw ValidationException::withMessages(['invoice' => 'Impossible d’annuler simplement une facture avec paiement. Utilisez un avoir ou remboursement.']);
            }
            if (in_array($invoice->status, ['paid', 'cancelled'], true)) {
                throw ValidationException::withMessages(['invoice' => 'Cette facture ne peut pas être annulée.']);
            }
            $from = $invoice->status;
            $invoice->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => auth()->id(),
                'cancellation_reason' => $reason,
                'version' => $invoice->version + 1,
            ]);
            $this->audit->record($invoice->tenant()->firstOrFail(), $invoice, 'cancelled', $from, 'cancelled', [], $reason);

            return $invoice->fresh();
        });
    }

    public function archive(Invoice $invoice): Invoice
    {
        $from = $invoice->status;
        $invoice->update(['archived_at' => now(), 'archived_by' => auth()->id()]);
        $this->audit->record($invoice->tenant()->firstOrFail(), $invoice, 'archived', $from, $invoice->status);

        return $invoice->fresh();
    }

    public function restore(Invoice $invoice): Invoice
    {
        $from = $invoice->status;
        $invoice->update(['archived_at' => null, 'archived_by' => null]);
        $this->audit->record($invoice->tenant()->firstOrFail(), $invoice, 'restored', $from, $invoice->status);

        return $invoice->fresh();
    }

    public function recordPayment(Invoice $invoice, array $data): InvoicePayment
    {
        return DB::transaction(function () use ($invoice, $data): InvoicePayment {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if (in_array($invoice->status, ['draft', 'cancelled'], true)) {
                throw ValidationException::withMessages(['invoice' => 'Cette facture ne peut pas recevoir de paiement dans son état actuel.']);
            }
            if (! empty($data['idempotency_key'])) {
                $existing = InvoicePayment::where('tenant_id', $invoice->tenant_id)->where('idempotency_key', $data['idempotency_key'])->first();
                if ($existing) {
                    $this->cashRegister->recordInvoicePayment($invoice->tenant()->firstOrFail(), $invoice, $existing);

                    return $existing;
                }
            }

            $amount = round((float) ($data['amount'] ?? 0), 2);
            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => 'Le paiement doit être supérieur à zéro.']);
            }
            $balance = round((float) $invoice->balance_due, 2);
            if ($amount - $balance > 0.001) {
                throw ValidationException::withMessages(['amount' => 'Le paiement ne peut pas dépasser le reste à payer.']);
            }

            $tenant = $invoice->tenant()->firstOrFail();
            $number = $this->numbers->next($tenant, 'invoice_payment', 'IPAY');
            $payment = InvoicePayment::create([
                'tenant_id' => $tenant->id,
                'invoice_id' => $invoice->id,
                'contact_id' => $invoice->customer_id,
                'user_id' => auth()->id(),
                'number' => $number['number'],
                'method' => $data['method'] ?? 'cash',
                'currency' => $invoice->currency,
                'amount' => number_format($amount, 2, '.', ''),
                'paid_at' => ! empty($data['paid_at']) ? Carbon::parse($data['paid_at']) : now(),
                'reference' => $data['reference'] ?? null,
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'note' => $data['note'] ?? null,
                'metadata' => ['immutable' => true],
            ]);
            $from = $invoice->status;
            $this->refreshPaymentStatus($invoice);
            $this->cashRegister->recordInvoicePayment($tenant, $invoice->fresh(), $payment);
            $this->audit->record($tenant, $invoice->fresh(), 'payment_recorded', $from, $invoice->fresh()->status, [
                'payment' => ['number' => $payment->number, 'amount' => $payment->amount, 'method' => $payment->method],
            ]);

            return $payment;
        });
    }

    public function refreshPaymentStatus(Invoice $invoice): Invoice
    {
        $invoice->refresh();
        if ($invoice->status === 'cancelled') {
            return $invoice;
        }
        $paid = $this->paymentTotal($invoice);
        $total = round((float) $invoice->total, 2);
        $balance = max(0, round($total - $paid, 2));
        $status = $invoice->status;
        if ($balance <= 0.001 && $total > 0) {
            $status = 'paid';
        } elseif ($paid > 0) {
            $status = 'partially_paid';
        } elseif (in_array($status, ['paid', 'partially_paid'], true)) {
            $status = 'sent';
        }
        if (in_array($status, ['sent', 'partially_paid'], true) && $invoice->due_date && $invoice->due_date->endOfDay()->isPast()) {
            $status = 'overdue';
        }

        $invoice->forceFill([
            'status' => $status,
            'amount_paid' => number_format($paid, 2, '.', ''),
            'balance_due' => number_format($balance, 2, '.', ''),
            'paid_at' => $status === 'paid' ? now() : null,
            'version' => $invoice->version + 1,
        ])->save();

        return $invoice->fresh();
    }

    private function transition(Invoice $invoice, string $toStatus, array $allowedFrom, array $extra = []): Invoice
    {
        return DB::transaction(function () use ($invoice, $toStatus, $allowedFrom, $extra): Invoice {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if (! in_array($invoice->status, $allowedFrom, true)) {
                throw ValidationException::withMessages(['status' => 'Transition de statut non autorisée.']);
            }
            $from = $invoice->status;
            $invoice->update(array_merge($extra, ['status' => $toStatus, 'version' => $invoice->version + 1]));
            $this->audit->record($invoice->tenant()->firstOrFail(), $invoice, $toStatus, $from, $toStatus);

            return $invoice->fresh();
        });
    }

    private function assertEditable(Invoice $invoice): void
    {
        if ($invoice->status === 'draft') {
            return;
        }
        if ($invoice->status === 'sent' && $this->paymentTotal($invoice) <= 0) {
            return;
        }
        throw ValidationException::withMessages(['invoice' => 'Cette facture contient un paiement ou est clôturée. Les lignes et totaux ne peuvent plus être modifiés directement.']);
    }

    private function paymentTotal(Invoice $invoice): float
    {
        return round((float) $invoice->payments()->sum('amount'), 2);
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
            'assigned_to' => $data['assigned_to'] ?? null,
            'source_estimate_id' => $data['source_estimate_id'] ?? null,
            'duplicated_from_id' => $data['duplicated_from_id'] ?? null,
            'currency' => strtoupper($data['currency'] ?? $tenant->currency ?? 'MAD'),
            'service_date' => $data['service_date'] ?? null,
            'customer_snapshot' => $data['customer_snapshot'] ?? $this->customerSnapshot($customer, $data),
            'additional_recipients' => $data['additional_recipients'] ?? null,
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

    private function replaceLines(Invoice $invoice, array $lines): void
    {
        $invoice->items()->delete();
        foreach ($lines as $line) {
            $invoice->items()->create([
                'tenant_id' => $invoice->tenant_id,
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

    private function dataFromInvoice(Invoice $invoice): array
    {
        return [
            'customer_id' => $invoice->customer_id,
            'customer_snapshot' => $invoice->customer_snapshot,
            'currency' => $invoice->currency,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(15)->toDateString(),
            'document_discount_type' => $invoice->document_discount_type,
            'document_discount_value' => $invoice->document_discount_value,
            'fee_total' => $invoice->fee_total,
            'rounding_total' => $invoice->rounding_total,
            'customer_message' => $invoice->customer_message,
            'internal_note' => $invoice->internal_note,
            'terms' => $invoice->terms,
            'footer' => $invoice->footer,
            'customer_reference' => $invoice->customer_reference,
            'lines' => $invoice->items->map(fn ($line) => [
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

    private function changes(array $before, Invoice $invoice): array
    {
        $changes = [];
        foreach ($before as $key => $old) {
            $new = $invoice->getAttribute($key);
            if ((string) $old !== (string) $new) {
                $changes[$key] = ['old' => $old, 'new' => $new];
            }
        }

        return $changes;
    }
}
