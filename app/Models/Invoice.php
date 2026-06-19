<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'tenant_id', 'customer_id', 'location_id', 'created_by', 'updated_by', 'assigned_to',
    'source_estimate_id', 'duplicated_from_id', 'number', 'serial_prefix', 'serial_number',
    'status', 'currency', 'issue_date', 'service_date', 'due_date', 'customer_snapshot',
    'additional_recipients', 'tax_breakdown', 'custom_fields', 'gross_subtotal',
    'line_discount_total', 'document_discount_type', 'document_discount_value',
    'document_discount_total', 'subtotal', 'tax_total', 'fee_total', 'rounding_total',
    'total', 'amount_paid', 'amount_refunded', 'balance_due', 'customer_message',
    'internal_note', 'terms', 'footer', 'customer_reference', 'version', 'sent_at',
    'viewed_at', 'paid_at', 'cancelled_at', 'cancelled_by', 'cancellation_reason',
    'archived_at', 'archived_by', 'metadata',
])]
class Invoice extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'service_date' => 'date',
            'due_date' => 'date',
            'customer_snapshot' => 'array',
            'additional_recipients' => 'array',
            'tax_breakdown' => 'array',
            'custom_fields' => 'array',
            'gross_subtotal' => 'decimal:2',
            'line_discount_total' => 'decimal:2',
            'document_discount_value' => 'decimal:2',
            'document_discount_total' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'fee_total' => 'decimal:2',
            'rounding_total' => 'decimal:2',
            'total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'amount_refunded' => 'decimal:2',
            'balance_due' => 'decimal:2',
            'sent_at' => 'datetime',
            'viewed_at' => 'datetime',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'archived_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'customer_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('display_order');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class);
    }

    public function sourceEstimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class, 'source_estimate_id');
    }

    public function duplicatedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicated_from_id');
    }

    public function sourceSale(): HasOne
    {
        return $this->hasOne(Sale::class, 'source_invoice_id');
    }
}
