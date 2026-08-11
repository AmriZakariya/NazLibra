<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id', 'virtual_device_id', 'user_id', 'session_id',
    'connection_token', 'user_agent', 'platform', 'browser',
    'ip_address', 'metadata', 'connected_at', 'last_seen_at',
    'disconnected_at', 'disconnect_reason',
])]
class VirtualDeviceSession extends Model
{
    /** Client heartbeat cadence (see app.js). */
    public const HEARTBEAT_INTERVAL_SECONDS = 30;

    /** A session with no heartbeat for this long is considered dead and frees
     *  its device for others (≈4 missed heartbeats — tolerant of a network blip
     *  or a briefly-throttled background tab). */
    public const STALE_AFTER_SECONDS = 120;

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'connected_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'disconnected_at' => 'datetime',
        ];
    }

    /** Sessions that currently occupy a device: not disconnected AND heartbeat fresh. */
    public function scopeLive($query, ?int $graceSeconds = null)
    {
        $grace = $graceSeconds ?? self::STALE_AFTER_SECONDS;

        return $query->whereNull('disconnected_at')
            ->where('last_seen_at', '>=', now()->subSeconds($grace));
    }

    /** Not disconnected, but the heartbeat has lapsed — reapable. */
    public function scopeStale($query, ?int $graceSeconds = null)
    {
        $grace = $graceSeconds ?? self::STALE_AFTER_SECONDS;

        return $query->whereNull('disconnected_at')
            ->where(function ($q) use ($grace): void {
                $q->whereNull('last_seen_at')
                    ->orWhere('last_seen_at', '<', now()->subSeconds($grace));
            });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function virtualDevice(): BelongsTo
    {
        return $this->belongsTo(VirtualDevice::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(int $heartbeatTimeoutSeconds = self::STALE_AFTER_SECONDS): bool
    {
        if ($this->disconnected_at !== null) {
            return false;
        }

        if ($this->last_seen_at === null) {
            return false;
        }

        return $this->last_seen_at->diffInSeconds(now()) <= $heartbeatTimeoutSeconds;
    }

    public function disconnect(string $reason = 'manual'): void
    {
        $this->update([
            'disconnected_at' => now(),
            'disconnect_reason' => $reason,
        ]);
    }
}
