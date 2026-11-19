<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * « Pause », « Absent », « Formation »: time on a person's agenda that cannot
 * be booked. Not an appointment — no customer, never a sale or a report line.
 */
#[Fillable(['tenant_id', 'user_id', 'starts_at', 'duration_minutes', 'kind', 'note', 'created_by_user_id'])]
class AppointmentBlock extends Model
{
    public const KINDS = ['pause', 'absent', 'training'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'duration_minutes' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function endsAt(): \Carbon\CarbonInterface
    {
        return $this->starts_at->copy()->addMinutes($this->duration_minutes);
    }
}
