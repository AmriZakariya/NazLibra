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
        'db_user', 'status', 'provision_log', 'commit_sha', 'owner_email',
        'provisioned_at',
    ];

    protected $casts = [
        'provisioned_at' => 'datetime',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function url(): string
    {
        return 'https://'.$this->domain;
    }

    public function appendLog(string $line): void
    {
        $this->provision_log = trim(($this->provision_log ?? '')."\n".$line);
        $this->save();
    }
}
