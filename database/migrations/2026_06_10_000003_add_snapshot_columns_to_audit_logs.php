<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('friendly_action')->nullable();
            $table->string('subject_name_snapshot')->nullable();
            $table->string('subject_reference_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn([
                'friendly_action',
                'subject_name_snapshot',
                'subject_reference_snapshot',
            ]);
        });
    }
};
