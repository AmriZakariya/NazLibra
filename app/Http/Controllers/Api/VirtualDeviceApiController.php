<?php

namespace App\Http\Controllers\Api;

use App\Models\VirtualDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VirtualDeviceApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var \App\Models\Tenant $tenant */
        $tenant = $request->attributes->get('api_tenant');

        if (! (bool) data_get($tenant->settings, 'features.virtual_devices', false)) {
            return response()->json(['ok' => true, 'devices' => []]);
        }

        $devices = VirtualDevice::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'type', 'description']);

        return response()->json(['ok' => true, 'devices' => $devices]);
    }
}
