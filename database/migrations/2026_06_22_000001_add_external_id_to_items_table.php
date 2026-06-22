<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            // Mobile local UUID used as an idempotency key so that retried creates
            // (e.g. after a lost network response) never produce a duplicate item.
            $table->string('external_id', 100)->nullable()->after('tenant_id');
            // Unique per tenant so two different tenants can share the same key.
            $table->unique(['tenant_id', 'external_id'], 'items_tenant_external_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropUnique('items_tenant_external_id_unique');
            $table->dropColumn('external_id');
        });
    }
};
