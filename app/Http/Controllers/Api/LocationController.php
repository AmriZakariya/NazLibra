<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocationController extends Controller
{
    /**
     * GET /api/v1/locations — all active locations for the authenticated user's tenant.
     *
     * @OA\Get(
     *     path="/api/v1/locations",
     *     operationId="locationIndex",
     *     tags={"Locations"},
     *     summary="List all active locations for the tenant",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="X-Tenant-Slug", in="header", required=true, @OA\Schema(type="string")),
     *     @OA\Parameter(name="X-Location-Id", in="header", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=200,
     *         description="List of locations",
     *         @OA\JsonContent(
     *             @OA\Property(property="ok", type="boolean", example=true),
     *             @OA\Property(property="locations", type="array", @OA\Items(
     *                 @OA\Property(property="id", type="integer"),
     *                 @OA\Property(property="name", type="string"),
     *                 @OA\Property(property="type", type="string"),
     *                 @OA\Property(property="address", type="string", nullable=true),
     *                 @OA\Property(property="is_default", type="boolean"),
     *                 @OA\Property(property="is_active", type="boolean")
     *             ))
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
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
