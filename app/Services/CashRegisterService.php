<?php

namespace App\Services;

use App\Models\CashRegisterMovement;
use App\Models\CashRegisterSession;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Tenant;

class CashRegisterService
{
    public function openSession(Tenant $tenant, bool $lock = false, ?string $storeKey = null): ?CashRegisterSession
    {
        $query = CashRegisterSession::where('tenant_id', $tenant->id)
            ->where('status', 'open')
            ->where('store_key', $storeKey ?? $this->currentStoreKey($tenant))
            ->latest('opened_at');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    public function recordMovement(Tenant $tenant, CashRegisterSession $session, string $type, string $direction, float $amount, array $data = []): CashRegisterMovement
    {
        $amount = round(abs($amount), 2);
        $delta = match ($direction) {
            'out' => -$amount,
            'neutral' => 0.0,
            default => $amount,
        };
        $balance = round((float) $session->expected_cash_amount + $delta, 2);
        $session->forceFill(['expected_cash_amount' => $balance])->save();

        return CashRegisterMovement::create([
            'tenant_id' => $tenant->id,
            'cash_register_session_id' => $session->id,
            'user_id' => $data['user_id'] ?? auth()->id(),
            'virtual_device_id' => $data['virtual_device_id'] ?? null,
            'actor_name_snapshot' => $data['actor_name_snapshot'] ?? null,
            'terminal_name_snapshot' => $data['terminal_name_snapshot'] ?? null,
            'sale_id' => $data['sale_id'] ?? null,
            'account_transaction_id' => $data['account_transaction_id'] ?? null,
            'number' => $this->nextMovementNumber($tenant),
            'type' => $type,
            'direction' => $direction,
            'amount' => $amount,
            'balance_after' => $balance,
            'payment_method' => $data['payment_method'] ?? null,
            'reference' => $data['reference'] ?? null,
            'note' => $data['note'] ?? null,
            'moved_at' => $data['moved_at'] ?? now(),
            'metadata' => $data['metadata'] ?? null,
        ]);
    }

    public function recordInvoicePayment(Tenant $tenant, Invoice $invoice, InvoicePayment $payment): ?CashRegisterMovement
    {
        if ($payment->method !== 'cash' || (float) $payment->amount <= 0) {
            return null;
        }

        $metadata = $payment->metadata ?? [];
        if (! empty($metadata['cash_register']['movement_id'])) {
            return CashRegisterMovement::where('tenant_id', $tenant->id)
                ->whereKey($metadata['cash_register']['movement_id'])
                ->first();
        }

        $session = $this->openSession($tenant, true);
        if (! $session) {
            return null;
        }

        $movement = $this->recordMovement($tenant, $session, 'invoice_cash', 'in', (float) $payment->amount, [
            'reference' => $invoice->number,
            'payment_method' => 'cash',
            'note' => 'Encaissement espèces facture '.$invoice->number,
            'moved_at' => $payment->paid_at,
            'metadata' => [
                'source' => InvoicePayment::class,
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->number,
                'invoice_payment_id' => $payment->id,
                'invoice_payment_number' => $payment->number,
                'contact_id' => $invoice->customer_id,
            ],
        ]);

        $metadata['cash_register'] = [
            'session_id' => $session->id,
            'session_number' => $session->number,
            'movement_id' => $movement->id,
            'movement_number' => $movement->number,
        ];
        $payment->forceFill(['metadata' => $metadata])->save();

        return $movement;
    }

    private function currentStoreKey(Tenant $tenant): string
    {
        return (string) data_get($tenant->settings, 'current_store', 'magasin-principal');
    }

    private function nextMovementNumber(Tenant $tenant): string
    {
        $max = CashRegisterMovement::where('tenant_id', $tenant->id)
            ->where('number', 'like', 'CRM%')
            ->pluck('number')
            ->map(fn ($number) => (int) preg_replace('/\D+/', '', (string) $number))
            ->max() ?? 0;

        return 'CRM'.str_pad((string) ($max + 1), 6, '0', STR_PAD_LEFT);
    }
}
