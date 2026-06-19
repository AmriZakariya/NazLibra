<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashRegisterSession;
use App\Models\Tenant;
use App\Services\CashRegisterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Cash register session management for the mobile app.
 *
 * One open session per store at a time.  Opening when a session is already
 * open returns the existing session (idempotent).
 */
class CashRegisterController extends Controller
{
    public function __construct(private readonly CashRegisterService $cashRegister) {}

    /**
     * GET /api/v1/cash-register
     *
     * Returns the current open session for the resolved store, if any.
     *
     * @OA\Get(
     *     path="/api/v1/cash-register",
     *     operationId="cashRegisterStatus",
     *     tags={"Cash Register"},
     *     summary="Get current cash register session status",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="X-Tenant-Slug", in="header", required=true, @OA\Schema(type="string")),
     *     @OA\Parameter(name="X-Location-Id", in="header", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=200,
     *         description="Cash register status",
     *         @OA\JsonContent(
     *             @OA\Property(property="ok", type="boolean", example=true),
     *             @OA\Property(property="is_open", type="boolean"),
     *             @OA\Property(property="session", type="object", nullable=true)
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function status(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant  = $request->attributes->get('api_tenant');
        $session = $this->cashRegister->openSession($tenant);

        return response()->json([
            'ok'          => true,
            'is_open'     => $session !== null,
            'session'     => $session,
        ]);
    }

    /**
     * POST /api/v1/cash-register/open
     *
     * { "opening_amount": 500.00, "note": "" }
     *
     * @OA\Post(
     *     path="/api/v1/cash-register/open",
     *     operationId="cashRegisterOpen",
     *     tags={"Cash Register"},
     *     summary="Open a new cash register session (idempotent)",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="X-Tenant-Slug", in="header", required=true, @OA\Schema(type="string")),
     *     @OA\Parameter(name="X-Location-Id", in="header", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"opening_amount"},
     *             @OA\Property(property="opening_amount", type="number", format="float", example=500.00),
     *             @OA\Property(property="note", type="string", nullable=true)
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Session opened",
     *         @OA\JsonContent(
     *             @OA\Property(property="ok", type="boolean", example=true),
     *             @OA\Property(property="session", type="object")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function open(Request $request): JsonResponse
    {
        $data = $request->validate([
            'opening_amount' => ['required', 'numeric', 'min:0'],
            'note'           => ['nullable', 'string', 'max:500'],
        ]);

        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('api_tenant');

        $session = DB::transaction(function () use ($tenant, $data): CashRegisterSession {
            // If already open, return existing session.
            $existing = $this->cashRegister->openSession($tenant, lock: true);
            if ($existing) {
                return $existing;
            }

            $max = CashRegisterSession::where('tenant_id', $tenant->id)
                ->where('number', 'like', 'CAI%')
                ->pluck('number')
                ->map(fn ($n) => (int) preg_replace('/\D+/', '', (string) $n))
                ->max() ?? 0;

            $storeKey = (string) data_get($tenant->settings, 'current_store', 'default');

            return CashRegisterSession::create([
                'tenant_id'            => $tenant->id,
                'opened_by'            => auth()->id(),
                'store_key'            => $storeKey,
                'number'               => 'CAI'.str_pad((string) ($max + 1), 5, '0', STR_PAD_LEFT),
                'status'               => 'open',
                'opening_amount'       => round((float) $data['opening_amount'], 2),
                'expected_cash_amount' => round((float) $data['opening_amount'], 2),
                'opened_at'            => now(),
                'note'                 => $data['note'] ?? null,
            ]);
        });

        return response()->json(['ok' => true, 'session' => $session], 201);
    }

    /**
     * POST /api/v1/cash-register/close
     *
     * { "counted_cash_amount": 650.00, "closing_note": "" }
     *
     * @OA\Post(
     *     path="/api/v1/cash-register/close",
     *     operationId="cashRegisterClose",
     *     tags={"Cash Register"},
     *     summary="Close the current cash register session",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="X-Tenant-Slug", in="header", required=true, @OA\Schema(type="string")),
     *     @OA\Parameter(name="X-Location-Id", in="header", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"counted_cash_amount"},
     *             @OA\Property(property="counted_cash_amount", type="number", format="float", example=650.00),
     *             @OA\Property(property="closing_note", type="string", nullable=true)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Session closed",
     *         @OA\JsonContent(
     *             @OA\Property(property="ok", type="boolean", example=true),
     *             @OA\Property(property="session", type="object"),
     *             @OA\Property(property="difference", type="number", format="float")
     *         )
     *     ),
     *     @OA\Response(response=422, description="No open session"),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function close(Request $request): JsonResponse
    {
        $data = $request->validate([
            'counted_cash_amount' => ['required', 'numeric', 'min:0'],
            'closing_note'        => ['nullable', 'string', 'max:500'],
        ]);

        /** @var Tenant $tenant */
        $tenant  = $request->attributes->get('api_tenant');
        $session = $this->cashRegister->openSession($tenant, lock: false);

        if (! $session) {
            return response()->json(['ok' => false, 'message' => 'Aucune session ouverte.'], 422);
        }

        $counted    = round((float) $data['counted_cash_amount'], 2);
        $expected   = round((float) $session->expected_cash_amount, 2);
        $difference = round($counted - $expected, 2);

        $session->update([
            'status'               => 'closed',
            'closed_by'            => auth()->id(),
            'counted_cash_amount'  => $counted,
            'difference_amount'    => $difference,
            'closed_at'            => now(),
            'closing_note'         => $data['closing_note'] ?? null,
        ]);

        return response()->json([
            'ok'         => true,
            'session'    => $session->fresh(),
            'difference' => $difference,
        ]);
    }

    /**
     * POST /api/v1/cash-register/movements
     *
     * Record a manual cash in/out (float, petty cash, etc.).
     *
     * { "direction": "in|out", "amount": 100.00, "note": "Petty cash" }
     *
     * @OA\Post(
     *     path="/api/v1/cash-register/movements",
     *     operationId="cashRegisterMovement",
     *     tags={"Cash Register"},
     *     summary="Record a manual cash in/out movement",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="X-Tenant-Slug", in="header", required=true, @OA\Schema(type="string")),
     *     @OA\Parameter(name="X-Location-Id", in="header", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"direction","amount"},
     *             @OA\Property(property="direction", type="string", enum={"in","out"}),
     *             @OA\Property(property="amount", type="number", format="float", minimum=0.01, example=100.00),
     *             @OA\Property(property="note", type="string", nullable=true),
     *             @OA\Property(property="reference", type="string", nullable=true)
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Movement recorded",
     *         @OA\JsonContent(
     *             @OA\Property(property="ok", type="boolean", example=true),
     *             @OA\Property(property="movement", type="object")
     *         )
     *     ),
     *     @OA\Response(response=422, description="No open session"),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function movement(Request $request): JsonResponse
    {
        $data = $request->validate([
            'direction' => ['required', 'in:in,out'],
            'amount'    => ['required', 'numeric', 'min:0.01'],
            'note'      => ['nullable', 'string', 'max:500'],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);

        /** @var Tenant $tenant */
        $tenant  = $request->attributes->get('api_tenant');
        $session = $this->cashRegister->openSession($tenant);

        if (! $session) {
            return response()->json(['ok' => false, 'message' => 'Aucune session ouverte.'], 422);
        }

        $movement = $this->cashRegister->recordMovement(
            $tenant,
            $session,
            $data['direction'] === 'in' ? 'cash_in' : 'cash_out',
            $data['direction'],
            (float) $data['amount'],
            [
                'reference' => $data['reference'] ?? null,
                'note'      => $data['note'] ?? null,
            ]
        );

        return response()->json(['ok' => true, 'movement' => $movement], 201);
    }
}
