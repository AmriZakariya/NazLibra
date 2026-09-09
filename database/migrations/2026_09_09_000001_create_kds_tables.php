<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cloud mirror of the kitchen display.
 *
 * The LAN is the source of truth during service — the kitchen has to work with
 * no internet — so this exists for reporting: how long dishes actually took,
 * and how often an order was taken back after the kitchen had started it.
 *
 * Ids are the client's own, and deterministic (ticket + cart line + quantity
 * ever fired), which is what makes the upsert idempotent: a device that
 * re-pushes, or two tills that fired the same delta, land on one row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kds_fires', function (Blueprint $table) {
            // Client-generated, not auto-increment: the row has to keep the
            // identity every device on the LAN already agreed on.
            $table->string('id')->primary();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();

            // Not a foreign key: a ticket is tombstoned when it is cashed, and
            // the kitchen history has to outlive it.
            $table->string('ticket_id');
            $table->string('ticket_number')->nullable();

            $table->string('station_id')->nullable();
            $table->string('station_name')->nullable();
            $table->unsignedInteger('fire_seq')->default(1);
            $table->timestamp('fired_at');
            $table->string('fired_by_user_name')->nullable();
            $table->string('table_label')->nullable();
            $table->string('origin_device_id')->nullable();
            $table->unsignedInteger('revision')->default(0);
            $table->timestamp('client_updated_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'fired_at']);
            $table->index(['tenant_id', 'ticket_id']);
        });

        Schema::create('kds_lines', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('fire_id');
            $table->string('ticket_id');
            $table->string('station_id')->nullable();
            $table->string('cart_line_id');

            $table->foreignId('item_id')->nullable()->constrained('items')->nullOnDelete();
            $table->string('name');
            $table->decimal('quantity', 12, 3)->default(1);
            $table->text('note')->nullable();

            $table->string('status')->default('pending');
            $table->timestamp('status_at')->nullable();
            $table->string('status_by_user_name')->nullable();
            $table->boolean('cancel_was_started')->default(false);

            $table->timestamp('fired_at');
            $table->string('origin_device_id')->nullable();
            $table->unsignedInteger('revision')->default(0);
            $table->timestamp('client_updated_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'fired_at']);
            $table->index('fire_id');
            // "how much of this cart line has the kitchen been given", the same
            // question the app answers locally.
            $table->index(['ticket_id', 'cart_line_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kds_lines');
        Schema::dropIfExists('kds_fires');
    }
};
