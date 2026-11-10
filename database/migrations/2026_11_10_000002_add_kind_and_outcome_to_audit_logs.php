<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The journal now records everything: what kind of entry (an action, a page
 * opened, a download, a login) and whether it worked, with the reason when it
 * did not. Existing rows were all successful actions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('audit_logs', 'kind')) {
                $table->string('kind', 16)->default('action');
            }
            if (! Schema::hasColumn('audit_logs', 'outcome')) {
                $table->string('outcome', 10)->default('ok');
            }
            if (! Schema::hasColumn('audit_logs', 'outcome_message')) {
                $table->string('outcome_message', 500)->nullable();
            }
            if (! Schema::hasColumn('audit_logs', 'duration_ms')) {
                $table->unsignedInteger('duration_ms')->nullable();
            }
        });

        $indexes = collect(Schema::getIndexes('audit_logs'))->pluck('name');
        if (! $indexes->contains('audit_logs_tenant_kind_created_index')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->index(['tenant_id', 'kind', 'created_at'], 'audit_logs_tenant_kind_created_index');
            });
        }
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('audit_logs_tenant_kind_created_index');
            $table->dropColumn(['kind', 'outcome', 'outcome_message', 'duration_ms']);
        });
    }
};
