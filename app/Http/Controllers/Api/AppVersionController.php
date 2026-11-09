<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AppVersions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * MASTER, public: asked by the app at launch, before any space is chosen, so
 * a shop whose own space is down still learns it must update.
 */
class AppVersionController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $data = $request->validate([
            'platform' => ['required', 'string', 'in:android,ios'],
            'version' => ['nullable', 'string', 'max:32'],
        ]);

        return response()->json(AppVersions::decide($data['platform'], $data['version'] ?? null));
    }
}
