<?php

namespace App\Http\Middleware;

use App\Models\Location;
use App\Models\Tenant;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves and attaches tenant + location to every API request.
 *
 * Resolution order:
 *   Tenant  : X-Tenant-Slug header → user's current_tenant_id → TenantContext fallback
 *   Location: X-Location-Id header → location_id body/query param → tenant default
 */
class ResolveApiContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $tenant = $this->resolveTenant($request, $user);

        if (! $tenant) {
            return response()->json(['ok' => false, 'message' => 'Tenant introuvable.'], 404);
        }

        if (! $user || ! $user->tenants()->whereKey($tenant->id)->exists()) {
            return response()->json(['ok' => false, 'message' => 'Accès refusé.'], 403);
        }

        $location = $this->resolveLocation($request, $tenant);

        if (($request->header('X-Location-Id') ?? $request->input('location_id')) && ! $location) {
            return response()->json(['ok' => false, 'error' => 'invalid_location', 'message' => 'Emplacement invalide pour ce tenant.'], 422);
        }

        $request->attributes->set('api_tenant', $tenant);
        $request->attributes->set('api_location', $location);
        $request->attributes->set('api_location_id', $location?->id);

        return $next($request);
    }

    private function resolveTenant(Request $request, $user): ?Tenant
    {
        $slug = $request->header('X-Tenant-Slug');
        if ($slug) {
            return Tenant::where('slug', $slug)->first();
        }

        return TenantContext::resolve($request, $user);
    }

    private function resolveLocation(Request $request, Tenant $tenant): ?Location
    {
        $locationId = $request->header('X-Location-Id') ?? $request->input('location_id');

        if ($locationId) {
            return Location::where('tenant_id', $tenant->id)->where('id', (int) $locationId)->first();
        }

        return Location::where('tenant_id', $tenant->id)->where('is_default', true)->first()
            ?? Location::where('tenant_id', $tenant->id)->first();
    }
}
