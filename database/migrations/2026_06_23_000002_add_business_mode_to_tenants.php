<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (! Schema::hasColumn('tenants', 'business_mode')) {
                $table->string('business_mode', 50)->default('retail')->after('id');
            }
        });

        Schema::table('items', function (Blueprint $table) {
            if (! Schema::hasColumn('items', 'extra_fields')) {
                $table->json('extra_fields')->nullable()->after('images');
            }
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('extra_fields');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('business_mode');
        });
    }
};
