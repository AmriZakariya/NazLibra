<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantInstall extends Model
{
    public const STATUS_QUEUED  = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_LIVE    = 'live';
    public const STATUS_FAILED  = 'failed';

    protected $fillable = [
        'subscription_id', 'subdomain', 'domain', 'docroot', 'db_name',
        'db_user', 'status', 'is_enabled', 'current_step', 'last_action',
        'provision_log', 'commit_sha', 'owner_email', 'provisioned_at',
        'updated_version_at',
    ];

    protected $casts = [
        'is_enabled'         => 'boolean',
        'provisioned_at'     => 'datetime',
        'updated_version_at' => 'datetime',
    ];

    protected $attributes = [
        'is_enabled' => true,
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function url(): string
    {
        return 'https://'.$this->domain;
    }

    /** A live install that hasn't been suspended by the platform admin. */
    public function isLive(): bool
    {
        return $this->status === self::STATUS_LIVE;
    }

    public function isSuspended(): bool
    {
        return $this->isLive() && ! $this->is_enabled;
    }

    /** True when the client's deployed commit differs from the master's HEAD. */
    public function updateAvailable(?string $masterSha): bool
    {
        return $this->isLive()
            && $masterSha
            && $this->commit_sha
            && $this->commit_sha !== $masterSha;
    }

    public function appendLog(string $line): void
    {
        $this->provision_log = trim(($this->provision_log ?? '')."\n".$line);
        $this->save();
    }
}
