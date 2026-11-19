<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** One date for one person: off, or other hours than usual. */
#[Fillable(['tenant_id', 'user_id', 'date', 'kind', 'starts_at', 'ends_at', 'note'])]
class ScheduleException extends Model
{
    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d'];
    }
}
