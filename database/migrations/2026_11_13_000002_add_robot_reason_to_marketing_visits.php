<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why a landing-page visit looks automated (no field ever touched, a value
 * the form cannot produce, the hidden anti-spam field filled). Null for a
 * person. Such visits stay listed but leave the funnel.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketing_visits') || Schema::hasColumn('marketing_visits', 'robot_reason')) {
            return;
        }

        Schema::table('marketing_visits', function (Blueprint $table) {
            $table->string('robot_reason', 20)->nullable()->after('refused_fields');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('marketing_visits', 'robot_reason')) {
            Schema::table('marketing_visits', function (Blueprint $table) {
                $table->dropColumn('robot_reason');
            });
        }
    }
};
