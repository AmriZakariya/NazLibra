<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One movement of a customer package's sessions: sale, use, refund, adjust, cancel. */
#[Fillable(['tenant_id', 'customer_package_id', 'type', 'sessions', 'remaining_after', 'sale_id', 'sale_item_id', 'sale_return_id', 'user_id', 'reason'])]
class CustomerPackageMovement extends Model
{
    public function package(): BelongsTo
    {
        return $this->belongsTo(CustomerPackage::class, 'customer_package_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
