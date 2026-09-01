<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantInstall;
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

    /**
     * Verify a client name + access code (mobile "already registered" flow).
     * Lives on the master install, which holds the client registry. On success
     * returns the client's base URL so the app can point login at it.
     */
    public function clientAccess(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subdomain' => ['required', 'string', 'max:63'],
            'code'      => ['required', 'string', 'max:32'],
        ]);

        // The install registry exists only on the master.
        if (! config('castlit.is_master')) {
            return response()->json(['ok' => false, 'message' => 'Service indisponible.'], 404);
        }

        // Accept a bare subdomain, a full domain, or a URL — keep the first label.
        $subdomain = strtolower(trim($data['subdomain']));
        $subdomain = (string) preg_replace('#^https?://#', '', $subdomain);
        $subdomain = explode('.', $subdomain)[0];
        $subdomain = explode('/', $subdomain)[0];
        $code = strtoupper(trim($data['code']));

        $install = TenantInstall::where('subdomain', $subdomain)->first();

        if (! $install || ! $install->isLive()) {
            return response()->json(['ok' => false, 'message' => 'Client introuvable.'], 404);
        }
        if ($install->isBlocked()) {
            return response()->json([
                'ok' => false, 'message' => 'Cet espace est suspendu. Contactez le support.',
            ], 403);
        }
        if (empty($install->access_code) || ! hash_equals($install->access_code, $code)) {
            return response()->json(['ok' => false, 'message' => 'Code de vérification invalide.'], 422);
        }

        return response()->json([
            'ok'        => true,
            'subdomain' => $install->subdomain,
            'name'      => $install->subdomain,
            'base_url'  => $install->url(),
        ]);
    }
}
