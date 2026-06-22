<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContactTransaction extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'contact_id',
        'type',
        'amount',
        'note',
        'idempotency_key',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'amount'      => 'decimal:2',
            'recorded_at' => 'datetime',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
