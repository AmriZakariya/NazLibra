<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The web wrote 'partially_refunded', the app 'partial_refund': a partial
 * refund made on one side showed as « payé » on the other. One spelling now,
 * the one the apps in the field already understand.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('sales')->where('status', 'partially_refunded')->update(['status' => 'partial_refund']);
    }

    public function down(): void
    {
        // Nothing to undo: both spellings meant the same.
    }
};
