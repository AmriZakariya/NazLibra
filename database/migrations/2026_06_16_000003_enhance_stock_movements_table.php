<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            if (! Schema::hasColumn('stock_movements', 'variant_id')) {
                $table->foreignId('variant_id')->nullable()->after('item_id')->constrained('item_variants')->nullOnDelete();
            }
            if (! Schema::hasColumn('stock_movements', 'location_id')) {
                $table->foreignId('location_id')->nullable()->after('variant_id')->constrained()->nullOnDelete();
            }
            if (! Schema::hasColumn('stock_movements', 'quantity_before')) {
                $table->integer('quantity_before')->nullable()->after('quantity_delta');
            }
            if (! Schema::hasColumn('stock_movements', 'unit_cost')) {
                $table->decimal('unit_cost', 16, 4)->nullable()->after('quantity_after');
            }
            if (! Schema::hasColumn('stock_movements', 'total_cost')) {
                $table->decimal('total_cost', 16, 4)->nullable()->after('unit_cost');
            }
            if (! Schema::hasColumn('stock_movements', 'reference_number')) {
                $table->string('reference_number', 60)->nullable()->after('reference_id');
            }
            if (! Schema::hasColumn('stock_movements', 'idempotency_key')) {
                $table->string('idempotency_key', 64)->nullable()->unique()->after('note');
            }
            if (! Schema::hasColumn('stock_movements', 'virtual_device_id')) {
                $table->foreignId('virtual_device_id')->nullable()->after('idempotency_key')->constrained('virtual_devices')->nullOnDelete();
            }
            if (! Schema::hasColumn('stock_movements', 'real_device_platform')) {
                $table->string('real_device_platform', 60)->nullable()->after('idempotency_key');
                $table->string('real_device_browser', 120)->nullable()->after('real_device_platform');
                $table->string('real_device_ip', 45)->nullable()->after('real_device_browser');
                $table->text('real_device_user_agent')->nullable()->after('real_device_ip');
            }
            if (! Schema::hasColumn('stock_movements', 'reason')) {
                $table->string('reason', 120)->nullable()->after('note');
            }
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $columns = [
                'variant_id', 'location_id', 'quantity_before', 'unit_cost', 'total_cost',
                'reference_number', 'idempotency_key', 'virtual_device_id', 'real_device_platform',
                'real_device_browser', 'real_device_ip', 'real_device_user_agent', 'reason',
            ];
            foreach ($columns as $column) {
                if (Schema::hasColumn('stock_movements', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
