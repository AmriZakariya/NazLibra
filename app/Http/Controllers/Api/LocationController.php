<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocationController extends Controller
{
    /** GET /api/v1/locations — all active locations for the authenticated user's tenant. */
    public function index(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('api_tenant');

        $locations = Location::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->get(['id', 'name', 'type', 'address', 'is_default', 'is_active']);

        return response()->json([
            'ok'        => true,
            'locations' => $locations,
        ]);
    }
}
