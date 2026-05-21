<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('taxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('rate', 8, 4)->default(0);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->text('description')->nullable()->after('color');
        });

        Schema::table('brands', function (Blueprint $table) {
            $table->text('description')->nullable()->after('type');
        });

        Schema::table('items', function (Blueprint $table) {
            $table->foreignId('unit_id')->nullable()->after('brand_id')->constrained()->nullOnDelete();
            $table->foreignId('tax_id')->nullable()->after('unit_id')->constrained()->nullOnDelete();
            $table->string('item_code')->nullable()->after('status');
            $table->string('item_group')->default('Single')->after('item_code');
            $table->unsignedInteger('nb_item')->nullable()->after('item_group');
            $table->string('sku')->nullable()->after('barcode');
            $table->string('custom_barcode1')->nullable()->after('sku');
            $table->string('sac')->nullable()->after('custom_barcode1');
            $table->string('hsn')->nullable()->after('sac');
            $table->string('editor')->nullable()->after('author');
            $table->string('verifier')->nullable()->after('editor');
            $table->string('translator')->nullable()->after('verifier');
            $table->string('edition_year')->nullable()->after('translator');
            $table->string('edition_number')->nullable()->after('edition_year');
            $table->string('theme')->nullable()->after('edition_number');
            $table->string('paper_type')->nullable()->after('theme');
            $table->string('cover_type')->nullable()->after('paper_type');
            $table->string('collection')->nullable()->after('cover_type');
            $table->string('delivery_note')->nullable()->after('collection');
            $table->string('invoice_reference')->nullable()->after('delivery_note');
            $table->decimal('seller_points', 12, 2)->default(0)->after('invoice_reference');
            $table->string('discount_type')->default('Percentage')->after('seller_points');
            $table->decimal('discount', 12, 2)->default(0)->after('discount_type');
            $table->decimal('price', 12, 2)->default(0)->after('discount');
            $table->string('tax_type')->default('Exclusive')->after('price');
            $table->decimal('profit_margin', 8, 2)->default(0)->after('tax_type');
            $table->decimal('reseller_sale_price', 12, 2)->default(0)->after('sale_price');
            $table->decimal('mrp', 12, 2)->default(0)->after('reseller_sale_price');
            $table->string('warehouse')->nullable()->after('mrp');
            $table->integer('opening_stock')->default(0)->after('warehouse');
            $table->unique(['tenant_id', 'item_code']);
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'item_code']);
            $table->dropConstrainedForeignId('unit_id');
            $table->dropConstrainedForeignId('tax_id');
            $table->dropColumn([
                'item_code',
                'item_group',
                'nb_item',
                'sku',
                'custom_barcode1',
                'sac',
                'hsn',
                'editor',
                'verifier',
                'translator',
                'edition_year',
                'edition_number',
                'theme',
                'paper_type',
                'cover_type',
                'collection',
                'delivery_note',
                'invoice_reference',
                'seller_points',
                'discount_type',
                'discount',
                'price',
                'tax_type',
                'profit_margin',
                'reseller_sale_price',
                'mrp',
                'warehouse',
                'opening_stock',
            ]);
        });

        Schema::table('brands', function (Blueprint $table) {
            $table->dropColumn('description');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('description');
        });

        Schema::dropIfExists('taxes');
        Schema::dropIfExists('units');
    }
};
