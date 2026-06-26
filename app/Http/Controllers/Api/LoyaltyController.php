<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\LoyaltyPointTransaction;
use App\Services\LoyaltyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoyaltyController extends Controller
{
    public function __construct(private readonly LoyaltyService $loyalty) {}

    // ── GET /api/v1/contacts/{contact}/loyalty/balance ────────────────────────

    public function balance(Request $request, Contact $contact): JsonResponse
    {
        $tenant = $request->attributes->get('api_tenant');

        if ($contact->tenant_id !== $tenant->id) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        $cfg = $this->loyalty->config($tenant);

        return response()->json([
            'ok'      => true,
            'contact' => [
                'id'              => $contact->id,
                'name'            => $contact->name,
                'loyalty_points'  => (float) $contact->loyalty_points,
            ],
            'config'  => $cfg,
            'value'   => $this->loyalty->pointsToAmount((float) $contact->loyalty_points, $cfg),
        ]);
    }

    // ── GET /api/v1/contacts/{contact}/loyalty/history ────────────────────────

    public function history(Request $request, Contact $contact): JsonResponse
    {
        $tenant  = $request->attributes->get('api_tenant');
        $perPage = min((int) $request->query('per_page', 20), 100);

        if ($contact->tenant_id !== $tenant->id) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        $rows = LoyaltyPointTransaction::where('contact_id', $contact->id)
            ->orderByDesc('recorded_at')
            ->paginate($perPage);

        return response()->json([
            'ok'          => true,
            'has_more'    => $rows->hasMorePages(),
            'page'        => $rows->currentPage(),
            'balance'     => (float) $contact->loyalty_points,
            'transactions' => $rows->map(fn ($t) => [
                'id'             => $t->id,
                'type'           => $t->type,
                'points_amount'  => (float) $t->points_amount,
                'balance_after'  => (float) $t->balance_after,
                'note'           => $t->note,
                'sale_id'        => $t->sale_id,
                'recorded_at'    => $t->recorded_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    // ── POST /api/v1/contacts/{contact}/loyalty/adjust ────────────────────────

    public function adjust(Request $request, Contact $contact): JsonResponse
    {
        $tenant = $request->attributes->get('api_tenant');

        if ($contact->tenant_id !== $tenant->id) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        $data = $request->validate([
            'points' => 'required|numeric',
            'note'   => 'nullable|string|max:255',
        ]);

        $tx = $this->loyalty->adjust(
            $contact,
            (float) $data['points'],
            $data['note'] ?? 'Ajustement manuel',
            $tenant->id,
        );

        return response()->json([
            'ok'            => true,
            'balance'       => (float) $contact->fresh()->loyalty_points,
            'transaction_id' => $tx->id,
        ]);
    }

    // ── GET /api/v1/loyalty/settings ──────────────────────────────────────────

    public function settings(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('api_tenant');

        return response()->json([
            'ok'     => true,
            'config' => $this->loyalty->config($tenant),
        ]);
    }

    // ── PUT /api/v1/loyalty/settings ──────────────────────────────────────────

    public function updateSettings(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('api_tenant');

        $data = $request->validate([
            'enabled'           => 'required|boolean',
            'earn_rate'         => 'required|numeric|min:0.01|max:100',
            'redeem_rate'       => 'required|numeric|min:0.001|max:10',
            'min_redeem_points' => 'required|integer|min:1|max:10000',
            'max_redeem_pct'    => 'required|numeric|min:1|max:100',
        ]);

        $settings             = $tenant->settings ?? [];
        $settings['loyalty']  = $data;

        $tenant->update(['settings' => $settings]);

        return response()->json(['ok' => true, 'config' => $data]);
    }

    // ── POST /api/v1/loyalty/preview ──────────────────────────────────────────

    /** Preview how many points a given amount would earn, and current balance. */
    public function preview(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('api_tenant');

        $data = $request->validate([
            'contact_id'        => 'required|integer',
            'total_amount'      => 'required|numeric|min:0',
            'points_to_redeem'  => 'nullable|numeric|min:0',
        ]);

        $contact = Contact::where('tenant_id', $tenant->id)->find($data['contact_id']);
        if (! $contact) {
            return response()->json(['ok' => false, 'error' => 'contact_not_found'], 404);
        }

        $cfg     = $this->loyalty->config($tenant);
        $balance = (float) $contact->loyalty_points;
        $total   = (float) $data['total_amount'];
        $toRedeem = (float) ($data['points_to_redeem'] ?? 0);

        $wouldEarn    = $this->loyalty->pointsForAmount($total, $cfg);
        $redeemAmount = 0.0;
        $redeemError  = null;

        if ($toRedeem > 0) {
            try {
                $redeemAmount = $this->loyalty->validateRedemption($toRedeem, $balance, $total, $cfg);
            } catch (\InvalidArgumentException $e) {
                $redeemError = $e->getMessage();
            }
        }

        return response()->json([
            'ok'             => true,
            'config'         => $cfg,
            'balance'        => $balance,
            'would_earn'     => $wouldEarn,
            'redeem_amount'  => $redeemAmount,
            'redeem_error'   => $redeemError,
        ]);
    }
}
