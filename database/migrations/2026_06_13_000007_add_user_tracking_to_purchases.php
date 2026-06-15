<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('purchases', 'user_id')) {
            Schema::table('purchases', function (Blueprint $table): void {
                $table->foreignId('user_id')
                    ->nullable()
                    ->after('supplier_id')
                    ->constrained()
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('purchases', 'user_id')) {
            Schema::table('purchases', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('user_id');
            });
        }
    }
};
