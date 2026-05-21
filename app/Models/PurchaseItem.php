<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['purchase_id', 'item_id', 'quantity_ordered', 'quantity_received', 'unit_cost'])]
class PurchaseItem extends Model
{
}
