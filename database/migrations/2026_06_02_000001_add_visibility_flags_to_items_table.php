<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            if (! Schema::hasColumn('items', 'is_enabled')) {
                $table->boolean('is_enabled')->default(true)->index();
            }

            if (! Schema::hasColumn('items', 'checkout_visible')) {
                $table->boolean('checkout_visible')->default(true)->index();
            }
        });

        DB::table('items')
            ->orderBy('id')
            ->select(['id', 'status', 'metadata'])
            ->chunkById(200, function ($items): void {
                foreach ($items as $item) {
                    $metadata = json_decode((string) $item->metadata, true) ?: [];
                    DB::table('items')
                        ->where('id', $item->id)
                        ->update([
                            'is_enabled' => $item->status !== 'archived',
                            'checkout_visible' => array_key_exists('checkout_visible', $metadata) ? (bool) $metadata['checkout_visible'] : true,
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            if (Schema::hasColumn('items', 'is_enabled')) {
                $table->dropColumn('is_enabled');
            }

            if (Schema::hasColumn('items', 'checkout_visible')) {
                $table->dropColumn('checkout_visible');
            }
        });
    }
};
