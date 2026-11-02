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

    /**
     * A terminal is for the phone app ("mobile") or for a browser (anything
     * else: computer, tablet…). Each side only takes its own: the web once
     * fell back on the owner's phone session and said "Terminal Mobile 2".
     */
    public function isMobile(): bool
    {
        return $this->type === 'mobile';
    }

    /** A browser's terminal ("computer"): the phone app may not sit on it. */
    public function isWebOnly(): bool
    {
        return $this->type === 'computer';
    }

    public function scopeForWeb($query)
    {
        return $query->where(fn ($q) => $q->whereNull('type')->orWhere('type', '!=', 'mobile'));
    }

    public function isConnected(): bool
    {
        return $this->activeSession()->exists();
    }
}
