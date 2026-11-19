<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The walk-in queue (« file d'attente »): customers who come without a
 * booking and wait their turn. waiting -> called -> seated (an agenda
 * booking takes over) -> done, or left. A called customer holds that
 * barber's time for their estimated length.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('queue_entries')) {
            Schema::create('queue_entries', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                // 1, 2, 3 … restarting each shop day: what the screen shows.
                $table->unsignedInteger('ticket_number');
                $table->date('queue_date');
                $table->string('customer_name', 160)->nullable();
                $table->string('customer_phone', 40)->nullable();
                $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
                $table->foreignId('item_id')->nullable()->constrained('items')->nullOnDelete();
                $table->foreignId('variant_id')->nullable()->constrained('item_variants')->nullOnDelete();
                $table->string('service_name', 190)->nullable();
                // Asked for (null = first free), then who actually called them.
                $table->foreignId('preferred_staff_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('staff_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->unsignedSmallInteger('duration_minutes')->default(30);
                // waiting | called | seated | done | left
                $table->string('status', 12)->default('waiting');
                $table->timestamp('arrived_at');
                $table->timestamp('called_at')->nullable();
                $table->timestamp('seated_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->foreignId('appointment_id')->nullable()->constrained('appointments')->nullOnDelete();
                $table->timestamps();
                $table->index(['tenant_id', 'queue_date', 'status']);
            });
        }

        // Managers and cashiers run the queue, like the agenda.
        if (Schema::hasTable('roles')) {
            DB::table('roles')->whereIn('key', ['manager', 'cashier'])->orderBy('id')
                ->chunkById(200, function ($roles): void {
                    foreach ($roles as $role) {
                        $permissions = json_decode((string) $role->permissions, true) ?: [];
                        if (in_array('*', $permissions, true) || in_array('queue.*', $permissions, true)) {
                            continue;
                        }
                        $permissions[] = 'queue.*';
                        DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode(array_values($permissions))]);
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_entries');
    }
};
