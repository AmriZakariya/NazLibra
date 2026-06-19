<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class LocationController extends Controller
{
    /** GET /api/v1/locations — all active locations for the authenticated user's tenant. */
    #[OA\Get(
        path: '/api/v1/locations',
        operationId: 'locationIndex',
        summary: 'List all active locations for the tenant',
        security: [['bearerAuth' => []]],
        tags: ['Locations'],
        parameters: [
            new OA\Parameter(name: 'X-Tenant-Slug', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Location-Id', in: 'header', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of locations',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'ok', type: 'boolean', example: true),
                    new OA\Property(property: 'locations', type: 'array', items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'id', type: 'integer'),
                            new OA\Property(property: 'name', type: 'string'),
                            new OA\Property(property: 'type', type: 'string'),
                            new OA\Property(property: 'address', type: 'string', nullable: true),
                            new OA\Property(property: 'is_default', type: 'boolean'),
                            new OA\Property(property: 'is_active', type: 'boolean'),
                        ]
                    )),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
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
