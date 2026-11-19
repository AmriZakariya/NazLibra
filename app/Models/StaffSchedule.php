<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** One interval of a person's weekly hours (no times = a day off). */
#[Fillable(['tenant_id', 'user_id', 'weekday', 'starts_at', 'ends_at'])]
class StaffSchedule extends Model
{
}
