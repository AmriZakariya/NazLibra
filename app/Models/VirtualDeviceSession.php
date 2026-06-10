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
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'connected_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'disconnected_at' => 'datetime',
        ];
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

    public function isActive(int $heartbeatTimeoutSeconds = 60): bool
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
