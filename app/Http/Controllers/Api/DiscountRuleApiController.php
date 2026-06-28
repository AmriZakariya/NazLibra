<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DiscountRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DiscountRuleApiController extends Controller
{
    // ── GET /api/v1/discount-rules ────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('api_tenant');

        $rules = DiscountRule::where('tenant_id', $tenant->id)
            ->active()
            ->orderBy('name')
            ->get()
            ->map(fn ($r) => [
                'id'             => $r->id,
                'name'           => $r->name,
                'code'           => $r->code,
                'type'           => $r->type,
                'value'          => (float) $r->value,
                'scope'          => $r->scope,
                'minimum_amount' => (float) ($r->minimum_amount ?? 0),
                'payment_methods'=> $r->payment_methods ?? [],
                'starts_at'      => $r->starts_at?->toDateString(),
                'ends_at'        => $r->ends_at?->toDateString(),
                'is_active'      => (bool) $r->is_active,
                'notes'          => $r->notes,
            ]);

        return response()->json(['ok' => true, 'rules' => $rules]);
    }
}
