<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tenant_id', 'number', 'category', 'label', 'amount', 'payment_method', 'reference', 'note', 'metadata', 'spent_at'])]
class Expense extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'spent_at' => 'date',
            'metadata' => 'array',
        ];
    }
}
