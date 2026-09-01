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
        'db_user', 'status', 'is_enabled', 'access_code', 'current_step',
        'last_action', 'provision_log', 'commit_sha', 'owner_email',
        'provisioned_at', 'updated_version_at', 'trial_ends_at', 'paid_at',
    ];

    protected $casts = [
        'is_enabled'         => 'boolean',
        'provisioned_at'     => 'datetime',
        'updated_version_at' => 'datetime',
        'trial_ends_at'      => 'datetime',
        'paid_at'            => 'datetime',
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

    /**
     * Generate a short, unambiguous access code (no 0/O/1/I) that the client
     * enters in the mobile app to reach their space.
     */
    public static function generateAccessCode(int $length = 6): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $code;
    }

    /** Return the current access code, generating + persisting one if missing. */
    public function ensureAccessCode(): string
    {
        if (empty($this->access_code)) {
            $this->forceFill(['access_code' => self::generateAccessCode()])->save();
        }

        return $this->access_code;
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

    /** Manually blocked by the platform admin (same mechanism as suspend). */
    public function isBlocked(): bool
    {
        return ! $this->is_enabled;
    }

    /** The client has settled payment. */
    public function isPaid(): bool
    {
        return $this->paid_at !== null;
    }

    /** Still within the free trial window and not yet paid. */
    public function onTrial(): bool
    {
        return ! $this->isPaid()
            && $this->trial_ends_at !== null
            && $this->trial_ends_at->isFuture();
    }

    /** Trial window elapsed without payment. */
    public function trialExpired(): bool
    {
        return ! $this->isPaid()
            && $this->trial_ends_at !== null
            && $this->trial_ends_at->isPast();
    }

    /** Whole days left in the trial (negative if expired), or null if untracked. */
    public function trialDaysLeft(): ?int
    {
        if ($this->trial_ends_at === null) {
            return null;
        }

        return (int) ceil(now()->floatDiffInDays($this->trial_ends_at, false));
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
