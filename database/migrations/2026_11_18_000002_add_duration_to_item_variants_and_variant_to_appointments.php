<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A massage is 30 minutes or an hour: the length belongs to the variant that
 * was chosen, not only to the service. Null means « the service's own ».
 * The appointment remembers which variant it was booked for.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('item_variants') && ! Schema::hasColumn('item_variants', 'duration_minutes')) {
            Schema::table('item_variants', function (Blueprint $table): void {
                $table->unsignedSmallInteger('duration_minutes')->nullable();
            });
        }

        if (Schema::hasTable('appointments') && ! Schema::hasColumn('appointments', 'variant_id')) {
            Schema::table('appointments', function (Blueprint $table): void {
                $table->foreignId('variant_id')->nullable()->constrained('item_variants')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('appointments', 'variant_id')) {
            Schema::table('appointments', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('variant_id');
            });
        }

        if (Schema::hasColumn('item_variants', 'duration_minutes')) {
            Schema::table('item_variants', function (Blueprint $table): void {
                $table->dropColumn('duration_minutes');
            });
        }
    }
};
