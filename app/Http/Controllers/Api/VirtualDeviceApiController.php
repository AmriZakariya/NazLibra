<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VirtualDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VirtualDeviceApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var \App\Models\Tenant $tenant */
        $tenant = $request->attributes->get('api_tenant');

        if (! (bool) data_get($tenant->settings, 'features.virtual_devices', true)) {
            return response()->json(['ok' => true, 'devices' => []]);
        }

        $devices = VirtualDevice::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->where(function ($query) use ($request): void {
                $query->whereNull('location_id')
                    ->orWhere('location_id', $request->attributes->get('api_location_id'));
            })
            ->orderBy('name')
            ->get(['id', 'location_id', 'name', 'code', 'type', 'description']);

        return response()->json(['ok' => true, 'devices' => $devices]);
    }
}
