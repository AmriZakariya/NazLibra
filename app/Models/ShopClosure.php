<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A holiday: the whole shop is shut from [starts_on] to [ends_on], both included. */
#[Fillable(['tenant_id', 'starts_on', 'ends_on', 'label'])]
class ShopClosure extends Model
{
    protected function casts(): array
    {
        return ['starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d'];
    }
}
