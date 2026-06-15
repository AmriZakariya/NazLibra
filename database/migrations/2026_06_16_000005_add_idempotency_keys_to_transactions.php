<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = [
            'sales' => ['reference_number'],
            'sale_payments' => ['reference'],
            'sale_returns' => [],
            'purchases' => ['metadata'],
            'purchase_returns' => [],
        ];

        foreach ($tables as $tableName => $afterColumns) {
            if (! Schema::hasColumn($tableName, 'idempotency_key')) {
                Schema::table($tableName, function (Blueprint $table) use ($tableName, $afterColumns) {
                    $after = $afterColumns[0] ?? null;
                    $column = $table->string('idempotency_key', 64)->nullable()->after($after);
                    $table->unique(['tenant_id', 'idempotency_key'], "{$tableName}_tenant_id_idempotency_key_unique");
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['sales', 'sale_payments', 'sale_returns', 'purchases', 'purchase_returns'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->dropUnique("{$tableName}_tenant_id_idempotency_key_unique");
                $table->dropColumn('idempotency_key');
            });
        }
    }
};
