<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gift cards (« cartes cadeaux ») and prepaid packages (« forfaits »).
 *
 * Both are SOLD at the till as a sale line and USED later, and in between the
 * shop owes something: money on a card, sessions on a package. So the sale
 * line is tagged (sale_items.kind) and the sale carries how much of its total
 * is not revenue (sales.non_revenue_amount): cash came in, but nothing was
 * earned until the card is spent or the sessions are used.
 *
 * Each balance has a movement table, so a balance is always explainable and
 * never edited in place without a trace.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('gift_cards')) {
            Schema::create('gift_cards', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                // 8 characters without the ones that look alike, upper case,
                // no dash: the dash is only for reading it out.
                $table->string('code', 16);
                $table->decimal('initial_amount', 12, 2);
                $table->decimal('balance', 12, 2);
                $table->string('status', 20)->default('active');
                $table->date('expires_at')->nullable();
                $table->foreignId('buyer_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
                $table->string('recipient_name', 160)->nullable();
                $table->string('recipient_phone', 40)->nullable();
                $table->foreignId('sale_id')->nullable()->constrained('sales')->nullOnDelete();
                // One card per sale line: what makes the issue idempotent.
                $table->unsignedBigInteger('sale_item_id')->nullable()->unique();
                $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('note', 255)->nullable();
                $table->timestamp('sold_at')->nullable();
                $table->timestamps();
                $table->unique(['tenant_id', 'code']);
                $table->index(['tenant_id', 'status']);
            });
        }

        if (! Schema::hasTable('gift_card_transactions')) {
            Schema::create('gift_card_transactions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('gift_card_id')->constrained('gift_cards')->cascadeOnDelete();
                // sale | redeem | refund | adjust | cancel
                $table->string('type', 20);
                // Signed: what it did to the balance.
                $table->decimal('amount', 12, 2);
                $table->decimal('balance_after', 12, 2);
                $table->foreignId('sale_id')->nullable()->constrained('sales')->nullOnDelete();
                $table->unsignedBigInteger('sale_return_id')->nullable();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('reason', 255)->nullable();
                $table->timestamps();
                $table->index(['gift_card_id', 'id']);
                $table->index(['tenant_id', 'type']);
            });
        }

        if (! Schema::hasTable('service_packages')) {
            Schema::create('service_packages', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->string('name', 190);
                $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
                $table->foreignId('variant_id')->nullable()->constrained('item_variants')->nullOnDelete();
                $table->unsignedSmallInteger('sessions');
                $table->decimal('price', 12, 2);
                $table->unsignedSmallInteger('validity_days')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();
                $table->index(['tenant_id', 'is_active']);
            });
        }

        if (! Schema::hasTable('customer_packages')) {
            Schema::create('customer_packages', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
                $table->foreignId('package_id')->nullable()->constrained('service_packages')->nullOnDelete();
                // What was bought, frozen: editing the definition later does
                // not rewrite what a customer already paid for.
                $table->string('name', 190);
                $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
                $table->foreignId('variant_id')->nullable()->constrained('item_variants')->nullOnDelete();
                $table->unsignedSmallInteger('sessions_total');
                $table->unsignedSmallInteger('sessions_remaining');
                $table->decimal('price_paid', 12, 2)->default(0);
                $table->date('expires_at')->nullable();
                // active | used | expired | cancelled
                $table->string('status', 20)->default('active');
                $table->foreignId('sale_id')->nullable()->constrained('sales')->nullOnDelete();
                $table->unsignedBigInteger('sale_item_id')->nullable()->unique();
                $table->timestamp('sold_at')->nullable();
                $table->timestamps();
                $table->index(['tenant_id', 'contact_id', 'status']);
            });
        }

        if (! Schema::hasTable('customer_package_movements')) {
            Schema::create('customer_package_movements', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('customer_package_id')->constrained('customer_packages')->cascadeOnDelete();
                // sale | use | refund | adjust | cancel
                $table->string('type', 20);
                // Signed number of sessions.
                $table->smallInteger('sessions');
                $table->unsignedSmallInteger('remaining_after');
                $table->foreignId('sale_id')->nullable()->constrained('sales')->nullOnDelete();
                $table->unsignedBigInteger('sale_item_id')->nullable();
                $table->unsignedBigInteger('sale_return_id')->nullable();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('reason', 255)->nullable();
                $table->timestamps();
                $table->index(['customer_package_id', 'id']);
            });
        }

        if (Schema::hasTable('sale_items')) {
            Schema::table('sale_items', function (Blueprint $table): void {
                if (! Schema::hasColumn('sale_items', 'kind')) {
                    // gift_card | package | package_use; null is an ordinary line.
                    $table->string('kind', 20)->nullable();
                }
                if (! Schema::hasColumn('sale_items', 'customer_package_id')) {
                    $table->unsignedBigInteger('customer_package_id')->nullable();
                }
            });
        }

        if (Schema::hasTable('sales') && ! Schema::hasColumn('sales', 'non_revenue_amount')) {
            Schema::table('sales', function (Blueprint $table): void {
                // The part of the total that is a liability (a card or a
                // package sold), not revenue.
                $table->decimal('non_revenue_amount', 12, 2)->default(0);
            });
        }

        if (Schema::hasTable('sale_returns') && ! Schema::hasColumn('sale_returns', 'non_revenue_amount')) {
            Schema::table('sale_returns', function (Blueprint $table): void {
                // Part of a refund that gives back a card or package line:
                // it was never revenue, so it is not a revenue deduction.
                $table->decimal('non_revenue_amount', 12, 2)->default(0);
            });
        }

        // Managers run the cards and packages; a cashier may look a card up.
        if (Schema::hasTable('roles')) {
            DB::table('roles')->whereIn('key', ['manager', 'cashier'])->orderBy('id')
                ->chunkById(200, function ($roles): void {
                    foreach ($roles as $role) {
                        $permissions = json_decode((string) $role->permissions, true) ?: [];
                        if (in_array('*', $permissions, true)) {
                            continue;
                        }
                        $add = $role->key === 'manager'
                            ? ['gift_cards.*', 'packages.*']
                            : ['gift_cards.view', 'packages.view'];
                        $merged = array_values(array_unique([...$permissions, ...$add]));
                        if ($merged !== $permissions) {
                            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($merged)]);
                        }
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_package_movements');
        Schema::dropIfExists('customer_packages');
        Schema::dropIfExists('service_packages');
        Schema::dropIfExists('gift_card_transactions');
        Schema::dropIfExists('gift_cards');
        if (Schema::hasColumn('sale_items', 'kind')) {
            Schema::table('sale_items', fn (Blueprint $t) => $t->dropColumn(['kind', 'customer_package_id']));
        }
        if (Schema::hasColumn('sales', 'non_revenue_amount')) {
            Schema::table('sales', fn (Blueprint $t) => $t->dropColumn('non_revenue_amount'));
        }
        if (Schema::hasColumn('sale_returns', 'non_revenue_amount')) {
            Schema::table('sale_returns', fn (Blueprint $t) => $t->dropColumn('non_revenue_amount'));
        }
    }
};
