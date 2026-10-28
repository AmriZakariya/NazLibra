<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Barber shop, second step:
 *  - sales.tip_method: how the tip was paid. A card tip reaches the shop's
 *    bank, not the drawer, and the barber still has to be handed it.
 *  - tip_payouts: tips handed over to a barber, so "owed" can go to zero.
 *  - contacts.notes: "dégradé 2 mm, allergique au parfum" — what the barber
 *    should know before the customer sits down.
 *  - appointments: who comes, when, for what, with whom.
 *
 * Existing cashier and manager roles get the appointments rights: the barber
 * at the chair is a cashier, and a module he cannot open is no module.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales') && ! Schema::hasColumn('sales', 'tip_method')) {
            Schema::table('sales', function (Blueprint $table): void {
                $table->string('tip_method', 20)->nullable();
            });
        }

        if (Schema::hasTable('contacts') && ! Schema::hasColumn('contacts', 'notes')) {
            Schema::table('contacts', function (Blueprint $table): void {
                $table->text('notes')->nullable();
            });
        }

        if (! Schema::hasTable('tip_payouts')) {
            Schema::create('tip_payouts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->decimal('amount', 12, 2);
                $table->string('method', 20)->default('cash');
                $table->timestamp('paid_at');
                $table->foreignId('paid_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->unsignedBigInteger('cash_register_movement_id')->nullable();
                $table->string('note', 255)->nullable();
                $table->timestamps();
                $table->index(['tenant_id', 'user_id', 'paid_at']);
            });
        }

        if (! Schema::hasTable('appointments')) {
            Schema::create('appointments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
                $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
                // Kept on the row: a walk-in booked by phone has no file yet.
                $table->string('customer_name', 160)->nullable();
                $table->string('customer_phone', 40)->nullable();
                $table->foreignId('staff_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('item_id')->nullable()->constrained('items')->nullOnDelete();
                $table->string('service_name', 190)->nullable();
                $table->timestamp('starts_at');
                $table->unsignedSmallInteger('duration_minutes')->default(30);
                $table->string('status', 20)->default('booked');
                $table->text('note')->nullable();
                $table->foreignId('sale_id')->nullable()->constrained('sales')->nullOnDelete();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reminded_at')->nullable();
                $table->timestamps();
                $table->index(['tenant_id', 'starts_at']);
                $table->index(['tenant_id', 'staff_user_id', 'starts_at']);
            });
        }

        if (Schema::hasTable('roles')) {
            DB::table('roles')->whereIn('key', ['manager', 'cashier'])->orderBy('id')
                ->chunkById(200, function ($roles): void {
                    foreach ($roles as $role) {
                        $permissions = json_decode((string) $role->permissions, true) ?: [];
                        if (in_array('*', $permissions, true) || in_array('appointments.*', $permissions, true)) {
                            continue;
                        }
                        $permissions[] = 'appointments.*';
                        DB::table('roles')->where('id', $role->id)
                            ->update(['permissions' => json_encode(array_values($permissions))]);
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('tip_payouts');
        if (Schema::hasColumn('contacts', 'notes')) {
            Schema::table('contacts', fn (Blueprint $t) => $t->dropColumn('notes'));
        }
        if (Schema::hasColumn('sales', 'tip_method')) {
            Schema::table('sales', fn (Blueprint $t) => $t->dropColumn('tip_method'));
        }
    }
};
