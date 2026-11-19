<?php

namespace App\Models;

use App\Casts\PhoneNumber;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A walk-in customer waiting for a chair (« file d'attente »). */
#[Fillable([
    'tenant_id', 'ticket_number', 'queue_date', 'customer_name', 'customer_phone', 'contact_id',
    'item_id', 'variant_id', 'service_name', 'preferred_staff_user_id', 'staff_user_id',
    'duration_minutes', 'status', 'arrived_at', 'called_at', 'seated_at', 'finished_at', 'appointment_id',
])]
class QueueEntry extends Model
{
    public const WAITING = 'waiting';

    public const CALLED = 'called';

    public const SEATED = 'seated';

    public const DONE = 'done';

    public const LEFT = 'left';

    /** Still in the shop's hands: on the board. */
    public const OPEN = [self::WAITING, self::CALLED, self::SEATED];

    protected function casts(): array
    {
        return [
            'queue_date' => 'date:Y-m-d',
            'arrived_at' => 'datetime',
            'called_at' => 'datetime',
            'seated_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_minutes' => 'integer',
            'customer_phone' => PhoneNumber::class,
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_user_id');
    }

    public function preferredStaff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'preferred_staff_user_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }
}
