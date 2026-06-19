<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\ItemLocationStock;
use App\Models\Tenant;
use App\Services\Inventory\InventoryMovementType;
use App\Services\Inventory\InventoryService;
use App\Services\Inventory\MovementDTO;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Manual stock adjustment endpoint for the mobile app.
 *
 * Supports three modes:
 *   - delta      add/remove a signed quantity (manual_add / manual_deduct)
 *   - correction  set an absolute quantity (delta computed server-side)
 *
 * All adjustments are idempotent via client-generated `idempotency_key`.
 * Stock is always mutated server-side through InventoryService::move() to
 * preserve atomic locking and the movement audit log.
 */
class AdjustmentController extends Controller
{
    public function __construct(private readonly InventoryService $inventory) {}

    /**
     * POST /api/v1/inventory/adjustments
     *
     * {
     *   "idempotency_key": "<UUID>",
     *   "item_id": 42,
     *   "variant_id": null,
     *   "mode": "delta|correction",
     *   "quantity": 5,          // signed delta for "delta"; absolute count for "correction"
     *   "reason": "Inventory count",
     *   "note": "Shelf B3"
     * }
     *
     * Returns the resulting stock snapshot and the movement record.
     *
     * @OA\Post(
     *     path="/api/v1/inventory/adjustments",
     *     operationId="adjustmentStore",
     *     tags={"Inventory"},
     *     summary="Manual stock adjustment (delta or correction, idempotent)",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="X-Tenant-Slug", in="header", required=true, @OA\Schema(type="string")),
     *     @OA\Parameter(name="X-Location-Id", in="header", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"idempotency_key","item_id","mode","quantity"},
     *             @OA\Property(property="idempotency_key", type="string", example="550e8400-e29b-41d4-a716-446655440002"),
     *             @OA\Property(property="item_id", type="integer", example=42),
     *             @OA\Property(property="variant_id", type="integer", nullable=true),
     *             @OA\Property(property="mode", type="string", enum={"delta","correction"}, description="delta=signed change, correction=set absolute quantity"),
     *             @OA\Property(property="quantity", type="integer", description="Signed delta (delta mode) or absolute target (correction mode)", example=5),
     *             @OA\Property(property="reason", type="string", nullable=true, example="Inventory count"),
     *             @OA\Property(property="note", type="string", nullable=true, example="Shelf B3")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Adjustment applied",
     *         @OA\JsonContent(
     *             @OA\Property(property="ok", type="boolean", example=true),
     *             @OA\Property(property="already_existed", type="boolean"),
     *             @OA\Property(property="no_change", type="boolean"),
     *             @OA\Property(property="movement", type="object", nullable=true),
     *             @OA\Property(property="stock_after", type="object",
     *                 @OA\Property(property="item_id", type="integer"),
     *                 @OA\Property(property="location_id", type="integer"),
     *                 @OA\Property(property="quantity", type="integer"),
     *                 @OA\Property(property="reserved", type="integer"),
     *                 @OA\Property(property="available", type="integer"),
     *                 @OA\Property(property="average_cost", type="number")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=422, description="Validation error or business rule violation"),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:64'],
            'item_id'         => ['required', 'integer'],
            'variant_id'      => ['nullable', 'integer'],
            'mode'            => ['required', Rule::in(['delta', 'correction'])],
            'quantity'        => ['required', 'integer', 'not_in:0'],
            'reason'          => ['nullable', 'string', 'max:500'],
            'note'            => ['nullable', 'string', 'max:500'],
        ]);

        /** @var Tenant $tenant */
        $tenant     = $request->attributes->get('api_tenant');
        $locationId = $request->attributes->get('api_location_id');

        // Idempotency: return existing movement if the key was already processed.
        $existing = \App\Models\InventoryMovement::where('tenant_id', $tenant->id)
            ->where('idempotency_key', $data['idempotency_key'])
            ->first();

        if ($existing) {
            $stock = ItemLocationStock::where('tenant_id', $tenant->id)
                ->where('item_id', $existing->item_id)
                ->where('location_id', $existing->location_id)
                ->first(['quantity', 'reserved_quantity', 'average_cost']);

            return response()->json([
                'ok'             => true,
                'already_existed' => true,
                'movement'       => $existing,
                'stock_after'    => $this->stockSnapshot($stock, $existing->item_id, $locationId),
            ]);
        }

        try {
            $result = DB::transaction(function () use ($tenant, $locationId, $data): array {
                $item = Item::where('tenant_id', $tenant->id)
                    ->whereKey((int) $data['item_id'])
                    ->lockForUpdate()
                    ->first();

                if (! $item) {
                    throw new \RuntimeException('Article introuvable.');
                }

                if ($item->type === 'service') {
                    throw new \RuntimeException('Impossible d\'ajuster le stock d\'un service.');
                }

                $effectiveLocationId = $locationId ?? $this->inventory->locationIdFromName($tenant->id, null);

                $qty = (int) $data['quantity'];

                if ($data['mode'] === 'correction') {
                    // Absolute target: compute the delta from current stock.
                    if ($qty < 0) {
                        throw new \RuntimeException('La quantité cible ne peut pas être négative pour une correction.');
                    }

                    $stock = ItemLocationStock::where('tenant_id', $tenant->id)
                        ->where('item_id', $item->id)
                        ->where('location_id', $effectiveLocationId)
                        ->lockForUpdate()
                        ->first();

                    $currentQty = (int) ($stock?->quantity ?? $item->stock_quantity);
                    $delta      = $qty - $currentQty;

                    if ($delta === 0) {
                        // No change needed — but we still record a correction at zero.
                        // Use +1 then immediately -1 is wrong; just skip movement and return current state.
                        return [
                            'movement'    => null,
                            'stock_after' => $this->stockSnapshot($stock, $item->id, $effectiveLocationId),
                            'no_change'   => true,
                        ];
                    }

                    $movementType = InventoryMovementType::CORRECTION;
                    $quantityChanged = $delta;
                } else {
                    // Delta mode: positive = add, negative = deduct.
                    $movementType    = $qty > 0 ? InventoryMovementType::MANUAL_ADD : InventoryMovementType::MANUAL_DEDUCT;
                    $quantityChanged = $qty;
                }

                $movement = $this->inventory->move(new MovementDTO(
                    tenantId:        $tenant->id,
                    itemId:          $item->id,
                    variantId:       isset($data['variant_id']) ? (int) $data['variant_id'] : null,
                    locationId:      $effectiveLocationId,
                    type:            $movementType,
                    quantityChanged: $quantityChanged,
                    userId:          auth()->id(),
                    note:            $data['note'] ?? null,
                    reason:          $data['reason'] ?? null,
                    idempotencyKey:  $data['idempotency_key'],
                    allowNegative:   false,
                ));

                $stock = ItemLocationStock::where('tenant_id', $tenant->id)
                    ->where('item_id', $item->id)
                    ->where('location_id', $effectiveLocationId)
                    ->first(['quantity', 'reserved_quantity', 'average_cost']);

                return [
                    'movement'    => $movement,
                    'stock_after' => $this->stockSnapshot($stock, $item->id, $effectiveLocationId),
                    'no_change'   => false,
                ];
            });
        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok'              => true,
            'already_existed' => false,
            'no_change'       => $result['no_change'],
            'movement'        => $result['movement'],
            'stock_after'     => $result['stock_after'],
        ], 201);
    }

    private function stockSnapshot(?ItemLocationStock $stock, int $itemId, int $locationId): array
    {
        return [
            'item_id'       => $itemId,
            'location_id'   => $locationId,
            'quantity'      => (int) ($stock?->quantity ?? 0),
            'reserved'      => (int) ($stock?->reserved_quantity ?? 0),
            'available'     => max(0, (int) ($stock?->quantity ?? 0) - (int) ($stock?->reserved_quantity ?? 0)),
            'average_cost'  => (float) ($stock?->average_cost ?? 0),
        ];
    }
}
