<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrinterGroupCategory extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'group_id',
        'category_id',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(PrinterGroup::class, 'group_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }
}
