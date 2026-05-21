<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tenant_id', 'name', 'type', 'description', 'phone', 'email', 'address'])]
class Brand extends Model
{
}
