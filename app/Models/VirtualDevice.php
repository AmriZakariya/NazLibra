<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['tenant_id', 'location_id', 'name', 'code', 'type', 'description', 'is_active'])]
class VirtualDevice extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(VirtualDeviceSession::class);
    }

    public function activeSession(): HasOne
    {
        return $this->hasOne(VirtualDeviceSession::class)
            ->whereNull('disconnected_at')
            ->latest('last_seen_at');
    }

    public function isConnected(): bool
    {
        return $this->activeSession()->exists();
    }
}
