<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreignId('virtual_device_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('virtual_device_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('device_name_snapshot')->nullable();
            $table->string('device_code_snapshot')->nullable();
            $table->string('real_device_platform')->nullable();
            $table->string('real_device_browser')->nullable();
            $table->string('real_device_ip', 45)->nullable();
            $table->string('real_device_user_agent', 500)->nullable();

            $table->index(['tenant_id', 'virtual_device_id']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'virtual_device_id']);
            $table->dropForeign(['virtual_device_id']);
            $table->dropForeign(['virtual_device_session_id']);
            $table->dropColumn([
                'virtual_device_id',
                'virtual_device_session_id',
                'device_name_snapshot',
                'device_code_snapshot',
                'real_device_platform',
                'real_device_browser',
                'real_device_ip',
                'real_device_user_agent',
            ]);
        });
    }
};
