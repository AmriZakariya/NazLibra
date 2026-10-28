<?php

namespace App\Models;

use App\Casts\PhoneNumber;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A booked chair: who comes, when, for what, with whom.
 *
 * The customer's name and phone sit on the row as well as the contact: a
 * booking taken by phone often has no customer file yet, and the agenda must
 * still say who is coming.
 */
#[Fillable([
    'tenant_id', 'location_id', 'contact_id', 'customer_name', 'customer_phone',
    'staff_user_id', 'item_id', 'service_name', 'starts_at', 'duration_minutes',
    'status', 'note', 'sale_id', 'created_by_user_id', 'reminded_at',
])]
class Appointment extends Model
{
    public const STATUSES = ['booked', 'arrived', 'done', 'no_show', 'cancelled'];

    /** Still holding the chair: the others free the slot. */
    public const ACTIVE = ['booked', 'arrived'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'reminded_at' => 'datetime',
            'duration_minutes' => 'integer',
            'customer_phone' => PhoneNumber::class,
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_user_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function endsAt(): \Carbon\CarbonInterface
    {
        return $this->starts_at->copy()->addMinutes($this->duration_minutes);
    }
}
