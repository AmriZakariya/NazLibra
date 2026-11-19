<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts created before emails were normalised kept the capitals typed at
 * sign-up; on SQLite a lowercase login then never matched. Lowercased and
 * trimmed here, in PHP (SQLite's LOWER() folds ASCII only), skipping any row
 * whose normalised address another account already holds.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->normalize('users', 'email', unique: true);
        $this->normalize('subscriptions', 'email');
        $this->normalize('tenant_installs', 'owner_email');
        $this->normalize('tenant_installs', 'support_email');
    }

    public function down(): void
    {
        // Lowercase addresses are equivalent; nothing to restore.
    }

    private function normalize(string $table, string $column, bool $unique = false): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        $taken = $unique
            ? DB::table($table)->pluck($column)->filter()->map(fn ($e) => mb_strtolower(trim($e)))->countBy()
            : collect();

        DB::table($table)->whereNotNull($column)->orderBy('id')->select(['id', $column])->get()
            ->each(function ($row) use ($table, $column, $unique, $taken): void {
                $clean = mb_strtolower(trim((string) $row->{$column}));
                if ($clean === $row->{$column}) {
                    return;
                }
                // Two accounts differing only by case: leave both, never merge.
                if ($unique && ($taken[$clean] ?? 0) > 1) {
                    return;
                }
                DB::table($table)->where('id', $row->id)->update([$column => $clean]);
            });
    }
};
