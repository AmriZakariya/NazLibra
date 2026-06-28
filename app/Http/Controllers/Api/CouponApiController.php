<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CouponApiController extends Controller
{
    // ── GET /api/v1/contacts/{contact}/coupons ────────────────────────────────

    public function forContact(Request $request, Contact $contact): JsonResponse
    {
        $tenant = $request->attributes->get('api_tenant');

        if ($contact->tenant_id !== $tenant->id) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        $coupons = $contact->coupons()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($c) => [
                'id'             => $c->id,
                'name'           => $c->name,
                'code'           => $c->code,
                'type'           => $c->type,
                'value'          => (float) $c->value,
                'minimum_amount' => (float) ($c->minimum_amount ?? 0),
                'max_uses'       => $c->max_uses,
                'used_amount'    => (float) ($c->used_amount ?? 0),
                'expires_at'     => $c->expires_at?->toDateString(),
                'is_active'      => (bool) $c->is_active,
                'notes'          => $c->notes,
            ]);

        return response()->json(['ok' => true, 'coupons' => $coupons]);
    }
}
