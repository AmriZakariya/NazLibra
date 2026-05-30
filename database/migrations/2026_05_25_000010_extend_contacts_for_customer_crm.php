<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('code')->nullable()->after('kind');
            $table->string('store_id')->nullable()->after('code');
            $table->string('status')->default('active')->after('client_type');
            $table->decimal('credit_limit', 12, 2)->default(0)->after('ice');
            $table->decimal('opening_balance', 12, 2)->default(0)->after('credit_limit');
            $table->string('tax_number')->nullable()->after('opening_balance');
            $table->string('country')->nullable()->after('address');
            $table->string('state')->nullable()->after('country');
            $table->string('city')->nullable()->after('state');
            $table->string('postcode')->nullable()->after('city');
            $table->string('location_link')->nullable()->after('postcode');
            $table->string('shipping_country')->nullable()->after('location_link');
            $table->string('shipping_state')->nullable()->after('shipping_country');
            $table->string('shipping_city')->nullable()->after('shipping_state');
            $table->string('shipping_postcode')->nullable()->after('shipping_city');
            $table->text('shipping_address')->nullable()->after('shipping_postcode');
            $table->string('shipping_location_link')->nullable()->after('shipping_address');
            $table->string('price_level_type')->default('increase')->after('shipping_location_link');
            $table->decimal('price_level', 8, 2)->default(0)->after('price_level_type');
            $table->string('attachment_path')->nullable()->after('price_level');
            $table->index(['tenant_id', 'kind', 'status']);
            $table->unique(['tenant_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'code']);
            $table->dropIndex(['tenant_id', 'kind', 'status']);
            $table->dropColumn([
                'code',
                'store_id',
                'status',
                'credit_limit',
                'opening_balance',
                'tax_number',
                'country',
                'state',
                'city',
                'postcode',
                'location_link',
                'shipping_country',
                'shipping_state',
                'shipping_city',
                'shipping_postcode',
                'shipping_address',
                'shipping_location_link',
                'price_level_type',
                'price_level',
                'attachment_path',
            ]);
        });
    }
};
