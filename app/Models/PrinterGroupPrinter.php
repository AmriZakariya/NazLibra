<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrinterGroupPrinter extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'group_id',
        'printer_id',
        'priority',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(PrinterGroup::class, 'group_id');
    }

    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class, 'printer_id');
    }
}
