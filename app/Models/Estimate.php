<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'tenant_id', 'customer_id', 'location_id', 'created_by', 'updated_by',
    'duplicated_from_id', 'converted_invoice_id', 'number', 'serial_prefix',
    'serial_number', 'status', 'currency', 'issue_date', 'expiration_date',
    'service_date', 'customer_snapshot', 'tax_breakdown', 'custom_fields',
    'gross_subtotal', 'line_discount_total', 'document_discount_type',
    'document_discount_value', 'document_discount_total', 'subtotal', 'tax_total',
    'fee_total', 'rounding_total', 'total', 'customer_message', 'internal_note',
    'terms', 'footer', 'customer_reference', 'version', 'sent_at', 'viewed_at',
    'accepted_at', 'accepted_by', 'declined_at', 'declined_by', 'decline_reason',
    'cancelled_at', 'cancelled_by', 'cancellation_reason', 'converted_at',
    'converted_by', 'archived_at', 'archived_by', 'metadata',
])]
class Estimate extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'expiration_date' => 'date',
            'service_date' => 'date',
            'customer_snapshot' => 'array',
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
            'sent_at' => 'datetime',
            'viewed_at' => 'datetime',
            'accepted_at' => 'datetime',
            'declined_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'converted_at' => 'datetime',
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

    public function items(): HasMany
    {
        return $this->hasMany(EstimateItem::class)->orderBy('display_order');
    }

    public function convertedInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'converted_invoice_id');
    }
}
