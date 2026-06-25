<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add soft-delete (deleted_at) to every table that lacked it.
 * Required so mobile clients can detect deletions on the next delta sync.
 */
return new class extends Migration
{
    private array $tables = [
        'purchases',
        'purchase_items',
        'purchase_payments',
        'purchase_returns',
        'sale_items',
        'sale_payments',
        'sale_returns',
        'pos_tickets',
        'virtual_devices',
        'cash_register_sessions',
        'cash_register_movements',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->softDeletes();
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropSoftDeletes();
                });
            }
        }
    }
};
