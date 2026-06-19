<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            $table->softDeletes()->after('updated_at');
        });

        Schema::table('contacts', function (Blueprint $table): void {
            $table->softDeletes()->after('updated_at');
        });

        Schema::table('sales', function (Blueprint $table): void {
            $table->softDeletes()->after('updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });

        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });

        Schema::table('sales', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
