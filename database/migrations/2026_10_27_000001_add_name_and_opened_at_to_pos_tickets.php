<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An open ticket can carry a name ("Table 4", "Mme Alami"), and remembers
 * when it was first opened: held_at moves every time the ticket is parked
 * again, so "open for 25 min" could not be told from it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_tickets', function (Blueprint $table) {
            if (! Schema::hasColumn('pos_tickets', 'name')) {
                $table->string('name', 80)->nullable()->after('number');
            }
            if (! Schema::hasColumn('pos_tickets', 'opened_at')) {
                $table->timestamp('opened_at')->nullable()->after('held_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_tickets', function (Blueprint $table) {
            $table->dropColumn(['name', 'opened_at']);
        });
    }
};
