<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Where the app reports what failed on the device.
 *
 * A failed sync used to live only on the phone, as raw exception text, and
 * support had a screenshot to go on. The app now sends each failure here; it
 * lands in this space's log as "App sync failed", which the platform admin's
 * error journal shows beside the server's own errors.
 */
class DiagnosticsController extends Controller
{
    public function syncError(Request $request): JsonResponse
    {
        $data = $request->validate([
            'section' => ['required', 'string', 'max:40'],
            'message' => ['required', 'string', 'max:2000'],
            'status' => ['nullable', 'integer', 'min:0', 'max:999'],
            'endpoint' => ['nullable', 'string', 'max:200'],
            'app_version' => ['nullable', 'string', 'max:40'],
            'platform' => ['nullable', 'string', 'max:40'],
            'device' => ['nullable', 'string', 'max:120'],
            'occurred_at' => ['nullable', 'string', 'max:40'],
        ]);

        Log::warning('App sync failed: '.$data['section'].' — '.$data['message'], [
            'user_id' => $request->user()?->id,
            'location_id' => $request->attributes->get('api_location_id'),
        ] + array_filter($data, fn ($value) => $value !== null && $value !== ''));

        return response()->json(['ok' => true]);
    }
}
