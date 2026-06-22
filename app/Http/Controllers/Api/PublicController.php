<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicController extends Controller
{
    /**
     * Public server info — no authentication required.
     *
     * Resolves tenant (optional) from the X-Tenant-Slug header.
     * Used by the mobile login screen to verify the server URL and preview
     * the tenant name before the user submits credentials.
     */
    public function info(Request $request): JsonResponse
    {
        $tenantName = null;
        $tenantSlug = $request->header('X-Tenant-Slug');

        if ($tenantSlug) {
            $tenant = Tenant::where('slug', $tenantSlug)->first();
            if ($tenant) {
                $tenantName = $tenant->name;
            }
        }

        return response()->json([
            'ok'          => true,
            'app'         => 'NazLibra',
            'tenant_name' => $tenantName,
        ]);
    }
}
