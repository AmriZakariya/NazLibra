<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Support\StaffReport;
use App\Support\TipPayouts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Tips still owed to the team, and handing them over. */
class TipController extends Controller
{
    /** GET /api/v1/tips — what each person is still owed. */
    public function index(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('api_tenant');
        $owed = StaffReport::tipsOwed($tenant->id);
        $names = User::whereIn('id', array_keys($owed))->pluck('name', 'id');

        return response()->json([
            'ok' => true,
            'owed' => collect($owed)
                ->filter(fn (float $amount) => $amount > 0.001)
                ->map(fn (float $amount, int $userId) => ['user_id' => $userId, 'name' => $names[$userId] ?? '', 'amount' => $amount])
                ->sortByDesc('amount')
                ->values(),
        ]);
    }

    /** POST /api/v1/tips/payouts — handed over (cash leaves the drawer). */
    public function payout(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('api_tenant');
        $data = $request->validate([
            // Not "user_id": the API refuses that name, the actor comes from the token.
            'staff_user_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['nullable', Rule::in(['cash', 'card', 'transfer'])],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $barber = $tenant->users()->whereKey($data['staff_user_id'])->first();
        abort_unless($barber !== null, 404);

        $payout = TipPayouts::pay($tenant, $barber, (float) $data['amount'], $request->attributes->get('api_action_context')?->actor, $data['method'] ?? 'cash', $data['note'] ?? null);

        return response()->json([
            'ok' => true,
            'payout' => ['id' => $payout->id, 'amount' => (float) $payout->amount, 'method' => $payout->method],
            'still_owed' => StaffReport::tipsOwed($tenant->id)[$barber->id] ?? 0.0,
        ], 201);
    }
}
