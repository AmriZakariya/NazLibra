<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table): void {
            $table->foreignId('contact_id')->nullable()->after('tenant_id')->constrained('contacts')->nullOnDelete();
            $table->string('name')->nullable()->after('contact_id');
            $table->decimal('minimum_amount', 12, 2)->default(0)->after('value');
            $table->unsignedInteger('max_uses')->nullable()->after('minimum_amount');
            $table->decimal('used_amount', 12, 2)->default(0)->after('uses_count');
            $table->text('notes')->nullable()->after('is_active');
            $table->json('metadata')->nullable()->after('notes');
            $table->index(['tenant_id', 'is_active', 'expires_at']);
            $table->index(['tenant_id', 'contact_id']);
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'is_active', 'expires_at']);
            $table->dropIndex(['tenant_id', 'contact_id']);
            $table->dropConstrainedForeignId('contact_id');
            $table->dropColumn(['name', 'minimum_amount', 'max_uses', 'used_amount', 'notes', 'metadata']);
        });
    }
};
