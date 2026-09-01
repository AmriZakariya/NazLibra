<?php

namespace App\Http\Controllers\Castlit;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/**
 * Internal, signed maintenance endpoint used by the platform install after it
 * copies a release into a client directory.  It deliberately runs inside the
 * client application, so it needs no PHP process-execution functions.
 */
class ClientMaintenanceController extends Controller
{
    public function run(Request $request): JsonResponse
    {
        if (config('castlit.is_master')) {
            abort(404);
        }

        $action = (string) $request->input('action');
        $timestamp = (string) $request->header('X-Castlit-Timestamp');
        $signature = (string) $request->header('X-Castlit-Signature');

        if (! in_array($action, ['migrate-and-clear', 'clear-cache'], true) || ! ctype_digit($timestamp)
            || abs(time() - (int) $timestamp) > 300) {
            abort(403);
        }

        $key = (string) config('app.key');
        $expected = hash_hmac('sha256', $action.'|'.$timestamp, $key);
        if ($key === '' || ! hash_equals($expected, $signature)) {
            abort(403);
        }

        try {
            $migrateOutput = '';
            if ($action === 'migrate-and-clear') {
                Artisan::call('config:clear');
                Artisan::call('migrate', ['--force' => true]);
                $migrateOutput = trim(Artisan::output());
            }
            Artisan::call('optimize:clear');

            return response()->json([
                'status' => 'success',
                'message' => $migrateOutput ?: 'Maintenance complete.',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
