<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function updatePos(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->tokenCan('*') && ! $user->tokenCan('settings.theme')) {
            return response()->json([
                'ok' => false,
                'error' => 'forbidden',
                'message' => 'Permission insuffisante.',
            ], 403);
        }

        $tenant = $request->attributes->get('api_tenant');

        $data = $request->validate([
            'confirm_cart_line_removal' => ['sometimes', 'required', 'boolean'],
        ]);

        $settings = $tenant->settings ?? [];
        $pos = $settings['pos'] ?? [];

        if (array_key_exists('confirm_cart_line_removal', $data)) {
            $pos['confirm_cart_line_removal'] = (bool) $data['confirm_cart_line_removal'];
        }

        $settings['pos'] = $pos;
        $tenant->update(['settings' => $settings]);

        return response()->json([
            'ok' => true,
            'settings' => [
                'confirm_cart_line_removal' => (bool) data_get(
                    $tenant->fresh()->settings,
                    'pos.confirm_cart_line_removal',
                    true
                ),
            ],
        ]);
    }
}
