<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Subscription extends Model
{
    public const STATUS_PENDING  = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'business_name', 'activity', 'currency', 'contact_name', 'email',
        'phone', 'desired_subdomain', 'heard_about', 'status',
        'rejection_reason', 'reviewed_by', 'reviewed_at', 'meta',
    ];

    protected $casts = [
        'meta'        => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function install(): HasOne
    {
        return $this->hasOne(TenantInstall::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
