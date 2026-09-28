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

        if (! $this->isLocationIndependent($request) && ($request->header('X-Location-Id') ?? $request->input('location_id')) && ! $location) {
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

    private function isLocationIndependent(Request $request): bool
    {
        return $request->is('api/v1/locations', 'api/v1/virtual-devices/disconnect', 'api/v1/auth/logout');
    }

    private function resolveLocation(Request $request, Tenant $tenant): ?Location
    {
        $locationId = $this->isLocationIndependent($request) ? null
            : ($request->header('X-Location-Id') ?? $request->input('location_id'));
        $query = app(\App\Services\StoreLocations::class)->available($tenant, $request->user());
        if ($locationId !== null && $locationId !== '') {
            if (! ctype_digit((string) $locationId)) return null;
            return $query->whereKey((int) $locationId)->first();
        }
        return $query->orderByDesc('is_default')->orderBy('id')->first();
    }
}
