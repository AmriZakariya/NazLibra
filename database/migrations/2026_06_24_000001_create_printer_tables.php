<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('printers', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('virtual_device_id')->nullable();
            $table->foreign('virtual_device_id')->references('id')->on('virtual_devices')->nullOnDelete();
            $table->string('name');
            $table->enum('connection_type', ['tcp', 'bluetooth', 'usb'])->default('tcp');
            $table->string('address')->nullable();
            $table->unsignedSmallInteger('port')->default(9100);
            $table->unsignedTinyInteger('paper_width')->default(80);
            $table->string('encoding', 20)->default('CP437');
            $table->boolean('cut_paper')->default(true);
            $table->unsignedTinyInteger('copies')->default(1);
            $table->boolean('auto_print_on_checkout')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('printer_groups', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('virtual_device_id')->nullable();
            $table->foreign('virtual_device_id')->references('id')->on('virtual_devices')->nullOnDelete();
            $table->string('name');
            $table->boolean('is_receipt_group')->default(false);
            $table->enum('print_mode', ['primary_fallback', 'simultaneous'])->default('primary_fallback');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('printer_group_printers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('group_id', 36);
            $table->char('printer_id', 36);
            $table->foreign('group_id')->references('id')->on('printer_groups')->cascadeOnDelete();
            $table->foreign('printer_id')->references('id')->on('printers')->cascadeOnDelete();
            $table->unsignedTinyInteger('priority')->default(0);
            $table->unique(['group_id', 'printer_id']);
        });

        Schema::create('printer_group_categories', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('group_id', 36);
            $table->foreign('group_id')->references('id')->on('printer_groups')->cascadeOnDelete();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->foreign('category_id')->references('id')->on('categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('printer_group_categories');
        Schema::dropIfExists('printer_group_printers');
        Schema::dropIfExists('printer_groups');
        Schema::dropIfExists('printers');
    }
};
