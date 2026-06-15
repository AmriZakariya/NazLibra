<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $suffixFor = function (int $index): string {
            $letters = '';
            $index++;

            while ($index > 0) {
                $index--;
                $letters = chr(65 + ($index % 26)).$letters;
                $index = intdiv($index, 26);
            }

            return $letters;
        };

        DB::table('sales')
            ->select('tenant_id', 'number', DB::raw('count(*) as duplicate_count'))
            ->groupBy('tenant_id', 'number')
            ->having('duplicate_count', '>', 1)
            ->orderBy('tenant_id')
            ->orderBy('number')
            ->get()
            ->each(function ($duplicate) use ($suffixFor): void {
                DB::table('sales')
                    ->where('tenant_id', $duplicate->tenant_id)
                    ->where('number', $duplicate->number)
                    ->orderBy('id')
                    ->pluck('id')
                    ->skip(1)
                    ->values()
                    ->each(function ($id, $index) use ($duplicate, $suffixFor): void {
                        DB::table('sales')
                            ->where('id', $id)
                            ->update(['number' => $duplicate->number.'-'.$suffixFor((int) $index)]);
                    });
            });

        Schema::table('sales', function (Blueprint $table) {
            $table->unique(['tenant_id', 'number'], 'sales_tenant_number_unique');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropUnique('sales_tenant_number_unique');
        });
    }
};
