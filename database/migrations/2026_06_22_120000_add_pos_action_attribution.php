<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('virtual_devices', function (Blueprint $table): void {
            $table->foreignId('location_id')->nullable()->after('tenant_id')->constrained()->nullOnDelete();
        });

        Schema::table('sales', function (Blueprint $table): void {
            $table->foreignId('location_id')->nullable()->after('tenant_id')->constrained()->nullOnDelete();
            $table->foreignId('virtual_device_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->string('actor_name_snapshot')->nullable()->after('virtual_device_id');
            $table->string('terminal_name_snapshot')->nullable()->after('actor_name_snapshot');
            $table->index(['tenant_id', 'virtual_device_id', 'sold_at']);
        });

        Schema::table('sale_returns', function (Blueprint $table): void {
            $table->foreignId('virtual_device_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->string('actor_name_snapshot')->nullable()->after('virtual_device_id');
            $table->string('terminal_name_snapshot')->nullable()->after('actor_name_snapshot');
        });

        Schema::table('pos_tickets', function (Blueprint $table): void {
            $table->foreignId('virtual_device_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->string('actor_name_snapshot')->nullable()->after('virtual_device_id');
            $table->string('terminal_name_snapshot')->nullable()->after('actor_name_snapshot');
        });

        Schema::table('cash_register_movements', function (Blueprint $table): void {
            $table->foreignId('virtual_device_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->string('actor_name_snapshot')->nullable()->after('virtual_device_id');
            $table->string('terminal_name_snapshot')->nullable()->after('actor_name_snapshot');
        });

        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->string('actor_name_snapshot')->nullable()->after('virtual_device_id');
            $table->string('terminal_name_snapshot')->nullable()->after('actor_name_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', fn (Blueprint $table) => $table->dropColumn(['actor_name_snapshot', 'terminal_name_snapshot']));
        Schema::table('cash_register_movements', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('virtual_device_id');
            $table->dropColumn(['actor_name_snapshot', 'terminal_name_snapshot']);
        });
        Schema::table('pos_tickets', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('virtual_device_id');
            $table->dropColumn(['actor_name_snapshot', 'terminal_name_snapshot']);
        });
        Schema::table('sale_returns', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('virtual_device_id');
            $table->dropColumn(['actor_name_snapshot', 'terminal_name_snapshot']);
        });
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'virtual_device_id', 'sold_at']);
            $table->dropConstrainedForeignId('virtual_device_id');
            $table->dropConstrainedForeignId('location_id');
            $table->dropColumn(['actor_name_snapshot', 'terminal_name_snapshot']);
        });
        Schema::table('virtual_devices', fn (Blueprint $table) => $table->dropConstrainedForeignId('location_id'));
    }
};
