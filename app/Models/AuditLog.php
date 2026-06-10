<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id', 'user_id', 'action', 'subject_type', 'subject_id',
    'properties', 'virtual_device_id', 'virtual_device_session_id',
    'device_name_snapshot', 'device_code_snapshot',
    'real_device_platform', 'real_device_browser',
    'real_device_ip', 'real_device_user_agent',
    'friendly_action', 'subject_name_snapshot', 'subject_reference_snapshot',
])]
class AuditLog extends Model
{
    protected function casts(): array
    {
        return [
            'properties' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function virtualDevice(): BelongsTo
    {
        return $this->belongsTo(VirtualDevice::class)->withDefault();
    }

    public function virtualDeviceSession(): BelongsTo
    {
        return $this->belongsTo(VirtualDeviceSession::class)->withDefault();
    }

    public function deviceLabel(): string
    {
        return $this->device_name_snapshot ?: '';
    }

    public function realDeviceLabel(): string
    {
        $parts = array_filter([
            $this->real_device_platform,
            $this->real_device_browser,
            $this->real_device_ip,
        ]);

        return $parts ? implode(' · ', $parts) : '';
    }

    public function hasDeviceInfo(): bool
    {
        return (bool) $this->device_name_snapshot;
    }

    public function friendlyLabel(string $fallback = ''): string
    {
        return $this->friendly_action ?: $fallback;
    }

    public function subjectReference(): string
    {
        return $this->subject_reference_snapshot ?: '';
    }

    public function subjectName(): string
    {
        return $this->subject_name_snapshot ?: '';
    }
}
