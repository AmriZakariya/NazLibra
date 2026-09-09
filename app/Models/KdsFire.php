<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One "send to kitchen" event, mirrored from a POS device.
 *
 * Immutable by design — see the app's core/kds/kds_merge.dart. Nothing here is
 * ever edited after the first write, which is why the upsert can be blind.
 */
class KdsFire extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id', 'tenant_id', 'location_id', 'ticket_id', 'ticket_number',
        'station_id', 'station_name', 'fire_seq', 'fired_at',
        'fired_by_user_name', 'table_label', 'origin_device_id', 'revision',
        'client_updated_at',
    ];

    protected $casts = [
        'fired_at' => 'datetime',
        'client_updated_at' => 'datetime',
        'fire_seq' => 'integer',
        'revision' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(KdsLine::class, 'fire_id');
    }
}
