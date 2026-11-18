<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerPackage;
use App\Models\ServicePackage;
use App\Services\Packages\PackageService;
use App\Support\AppModules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the till needs to sell and use prepaid packages: the offers, and the
 * packages customers still have. Small, so it is replaced whole at each sync
 * rather than sent as a delta.
 */
class PackageController extends Controller
{
    public function __construct(private readonly PackageService $packages) {}

    /** GET /api/v1/sync/packages */
    public function sync(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('api_tenant');
        if (! AppModules::enabled($tenant, 'packages')) {
            return response()->json(['ok' => true, 'enabled' => false, 'packages' => [], 'customer_packages' => []]);
        }

        return response()->json([
            'ok' => true,
            'enabled' => true,
            'packages' => ServicePackage::where('tenant_id', $tenant->id)->where('is_active', true)->orderBy('name')->get()
                ->map(fn (ServicePackage $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'item_id' => $p->item_id,
                    'variant_id' => $p->variant_id,
                    'sessions' => $p->sessions,
                    'price' => (float) $p->price,
                    'validity_days' => $p->validity_days,
                ])->values(),
            // Only what can still be used; the till drops the rest at once.
            'customer_packages' => CustomerPackage::where('tenant_id', $tenant->id)
                ->where('status', CustomerPackage::ACTIVE)->where('sessions_remaining', '>', 0)->get()
                ->map(fn (CustomerPackage $p) => $this->packages->payload($tenant, $p))
                ->filter(fn (array $p) => $p['status'] === CustomerPackage::ACTIVE)->values(),
        ]);
    }
}
