<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Working hours for the salon agenda.
 *
 * - staff_schedules: a person's weekly hours, one row per interval (two on a
 *   day with a lunch break); a row with no times is a day off. A person with
 *   no row at all simply follows the shop's opening hours.
 * - schedule_exceptions: one date for one person — off, or other hours.
 * - shop_closures: holidays, a range of dates when the whole shop is shut.
 * - appointment_blocks: « Pause », « Absent », « Formation » on a person's
 *   agenda. A table of its own: a block is not a booking, so it never reaches
 *   a sale, a report or a customer's history.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('staff_schedules')) {
            Schema::create('staff_schedules', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                // ISO weekday: 1 = Monday … 7 = Sunday.
                $table->unsignedTinyInteger('weekday');
                // "09:00"; both null = a day off.
                $table->string('starts_at', 5)->nullable();
                $table->string('ends_at', 5)->nullable();
                $table->timestamps();
                $table->index(['tenant_id', 'user_id', 'weekday']);
            });
        }

        if (! Schema::hasTable('schedule_exceptions')) {
            Schema::create('schedule_exceptions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->date('date');
                // off | custom
                $table->string('kind', 10);
                $table->string('starts_at', 5)->nullable();
                $table->string('ends_at', 5)->nullable();
                $table->string('note', 190)->nullable();
                $table->timestamps();
                $table->index(['tenant_id', 'date']);
            });
        }

        if (! Schema::hasTable('shop_closures')) {
            Schema::create('shop_closures', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->date('starts_on');
                $table->date('ends_on');
                $table->string('label', 190)->nullable();
                $table->timestamps();
                $table->index(['tenant_id', 'starts_on', 'ends_on']);
            });
        }

        if (! Schema::hasTable('appointment_blocks')) {
            Schema::create('appointment_blocks', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->timestamp('starts_at');
                $table->unsignedSmallInteger('duration_minutes');
                // pause | absent | training
                $table->string('kind', 20)->default('pause');
                $table->string('note', 190)->nullable();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['tenant_id', 'user_id', 'starts_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_blocks');
        Schema::dropIfExists('shop_closures');
        Schema::dropIfExists('schedule_exceptions');
        Schema::dropIfExists('staff_schedules');
    }
};
