<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PrinterGroup extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'tenant_id',
        'virtual_device_id',
        'name',
        'is_receipt_group',
        'print_mode',
    ];

    protected function casts(): array
    {
        return [
            'is_receipt_group' => 'boolean',
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
        return $this->hasMany(PrinterGroupPrinter::class, 'group_id');
    }

    public function printerGroupCategories(): HasMany
    {
        return $this->hasMany(PrinterGroupCategory::class, 'group_id');
    }

    public function printers(): BelongsToMany
    {
        return $this->belongsToMany(Printer::class, 'printer_group_printers', 'group_id', 'printer_id')
            ->withPivot('priority');
    }
}
