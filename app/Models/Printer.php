<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Printer extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'tenant_id',
        'virtual_device_id',
        'name',
        'connection_type',
        'address',
        'port',
        'paper_width',
        'encoding',
        'cut_paper',
        'copies',
        'auto_print_on_checkout',
    ];

    protected function casts(): array
    {
        return [
            'cut_paper'               => 'boolean',
            'auto_print_on_checkout'  => 'boolean',
            'port'                    => 'integer',
            'paper_width'             => 'integer',
            'copies'                  => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function virtualDevice(): BelongsTo
    {
        return $this->belongsTo(VirtualDevice::class);
    }

    public function printerGroupPrinters(): HasMany
    {
        return $this->hasMany(PrinterGroupPrinter::class);
    }
}
