<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\ItemLocationStock;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Tenant;
use App\Services\CashRegisterService;
use App\Services\Documents\DocumentNumberGenerator;
use App\Services\Inventory\InventoryMovementType;
use App\Services\Inventory\InventoryService;
use App\Services\Inventory\MovementDTO;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Handles sale returns / refunds from the mobile app.
 *
 * Idempotent via `idempotency_key`.  Stock restocking is optional per line.
 */
class ReturnController extends Controller
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly DocumentNumberGenerator $numbers,
        private readonly CashRegisterService $cashRegister,
    ) {}

    /**
     * POST /api/v1/pos/sales/{sale}/returns
     *
     * {
     *   "idempotency_key": "<UUID>",
     *   "refund_method": "cash|credit|account",
     *   "refund_reason": "Defective product",
     *   "return_lines": [
     *     {
     *       "sale_item_id": 12,
     *       "quantity": 1,
     *       "stock_action": "restock|no_restock|damaged|lost|waste",
     *       "reason": ""
     *     }
     *   ]
     * }
     * Omit return_lines to return all items of the sale.
     */
    public function store(Request $request, Sale $sale): JsonResponse
    {
        $data = $request->validate([
            'idempotency_key'              => ['required', 'string', 'max:64'],
            'refund_method'                => ['required', 'in:cash,credit,account'],
            'refund_reason'                => ['nullable', 'string', 'max:500'],
            'return_lines'                 => ['nullable', 'array'],
            'return_lines.*.sale_item_id'  => ['required_with:return_lines', 'integer'],
            'return_lines.*.quantity'      => ['required_with:return_lines', 'integer', 'min:1'],
            'return_lines.*.stock_action'  => ['nullable', 'in:restock,no_restock,damaged,lost,waste'],
            'return_lines.*.reason'        => ['nullable', 'string', 'max:500'],
        ]);

        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('api_tenant');

        if ($sale->tenant_id !== $tenant->id) {
            return response()->json(['ok' => false, 'message' => 'Vente introuvable.'], 404);
        }

        if (in_array($sale->status, ['refunded', 'cancelled'], true)) {
            return response()->json(['ok' => false, 'message' => 'Cette vente est déjà clôturée.'], 422);
        }

        // Idempotency.
        $existing = SaleReturn::where('tenant_id', $tenant->id)
            ->where('idempotency_key', $data['idempotency_key'])
            ->first();
        if ($existing) {
            return response()->json(['ok' => true, 'already_existed' => true, 'return' => $existing]);
        }

        try {
            $saleReturn = DB::transaction(function () use ($tenant, $sale, $data): SaleReturn {
                $sale = Sale::where('tenant_id', $tenant->id)
                    ->whereKey($sale->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $sale->load(['items.item', 'returns']);

                $locationId = $this->inventory->locationIdFromName($tenant->id, null);
                $soldLines  = $sale->items->keyBy('id');

                // Already-returned quantities per sale_item_id.
                $alreadyReturned = [];
                foreach ($sale->returns as $prev) {
                    foreach (($prev->lines ?? []) as $prevLine) {
                        $sId = (int) ($prevLine['sale_item_id'] ?? 0);
                        $alreadyReturned[$sId] = ($alreadyReturned[$sId] ?? 0) + (int) ($prevLine['quantity'] ?? 0);
                    }
                }

                // Build requested return lines.
                $requestedLines = collect($data['return_lines'] ?? [])
                    ->mapWithKeys(fn (array $l) => [(int) $l['sale_item_id'] => $l]);

                if ($requestedLines->isEmpty()) {
                    // Full return: every line at max remaining quantity.
                    $requestedLines = $soldLines->mapWithKeys(fn ($l) => [$l->id => [
                        'sale_item_id' => $l->id,
                        'quantity'     => max(0, (int) $l->quantity - (int) ($alreadyReturned[$l->id] ?? 0)),
                        'stock_action' => 'restock',
                        'reason'       => null,
                    ]]);
                }

                $lines       = [];
                $stockActions = [];
                $returnTotal = 0.0;

                foreach ($requestedLines as $saleItemId => $req) {
                    $line = $soldLines->get((int) $saleItemId);
                    if (! $line) {
                        throw new \RuntimeException("Ligne de retour introuvable: {$saleItemId}.");
                    }

                    $remaining = max(0, (int) $line->quantity - (int) ($alreadyReturned[$line->id] ?? 0));
                    $qty       = min($remaining, max(0, (int) ($req['quantity'] ?? 0)));
                    if ($qty <= 0) {
                        continue;
                    }

                    $stockAction = $req['stock_action'] ?? 'restock';
                    if (! in_array($stockAction, ['restock', 'no_restock', 'damaged', 'lost', 'waste'], true)) {
                        $stockAction = 'restock';
                    }

                    $lineReason = trim((string) ($req['reason'] ?? ''));
                    if (in_array($stockAction, ['damaged', 'lost', 'waste'], true) && $lineReason === '') {
                        throw new \RuntimeException("Motif requis pour action '{$stockAction}' sur l'article {$line->name}.");
                    }

                    $lineTotal    = round(((float) $line->total_price / max(1, (int) $line->quantity)) * $qty, 2);
                    $returnTotal += $lineTotal;
                    $stockActions[] = $stockAction;

                    $lines[] = [
                        'sale_item_id' => $line->id,
                        'item_id'      => $line->item_id,
                        'name'         => $line->name,
                        'quantity'     => $qty,
                        'max_quantity' => $remaining,
                        'unit_price'   => (float) $line->unit_price,
                        'total_price'  => $lineTotal,
                        'stock_action' => $stockAction,
                        'reason'       => $lineReason,
                    ];
                }

                if (empty($lines) || $returnTotal <= 0.001) {
                    throw new \RuntimeException('Aucun article à retourner.');
                }

                $alreadyReturnedAmount = (float) $sale->returns->sum('total_amount');
                $refundable = max(0, (float) $sale->total_amount - $alreadyReturnedAmount);
                if ($returnTotal > $refundable + 0.001) {
                    throw new \RuntimeException('Le montant du retour dépasse le montant remboursable.');
                }

                $stockDisposition = count(array_unique($stockActions)) === 1 ? $stockActions[0] : 'mixed';
                $isFullRefund     = ($alreadyReturnedAmount + $returnTotal + 0.001) >= (float) $sale->total_amount;

                $numberData  = $this->numbers->next($tenant, 'sale_return', 'RET');
                $saleReturn  = SaleReturn::create([
                    'tenant_id'        => $tenant->id,
                    'sale_id'          => $sale->id,
                    'contact_id'       => $sale->contact_id,
                    'user_id'          => auth()->id(),
                    'number'           => $numberData['number'],
                    'status'           => 'approved',
                    'refund_method'    => $data['refund_method'],
                    'refund_scope'     => $isFullRefund ? 'full' : 'partial',
                    'total_amount'     => $returnTotal,
                    'lines'            => $lines,
                    'reason'           => $data['refund_reason'] ?? null,
                    'restock'          => in_array('restock', $stockActions, true),
                    'stock_disposition' => $stockDisposition,
                    'returned_at'      => now(),
                    'idempotency_key'  => $data['idempotency_key'],
                    'metadata'         => [
                        'source'                        => 'mobile_api',
                        'already_returned_before'       => round($alreadyReturnedAmount, 2),
                        'refundable_before'             => round($refundable, 2),
                    ],
                ]);

                // Apply stock movements per line.
                foreach ($lines as $returnLine) {
                    if (! $returnLine['item_id']) {
                        continue;
                    }

                    $item = Item::whereKey($returnLine['item_id'])->lockForUpdate()->first();
                    if (! $item || $item->type === 'service') {
                        continue;
                    }

                    $qty = (int) $returnLine['quantity'];

                    if ($returnLine['stock_action'] === 'restock') {
                        $this->inventory->move(new MovementDTO(
                            tenantId:       $tenant->id,
                            itemId:         $item->id,
                            variantId:      null,
                            locationId:     $locationId,
                            type:           InventoryMovementType::RETURN,
                            quantityChanged: $qty,
                            userId:         auth()->id(),
                            referenceType:  SaleReturn::class,
                            referenceId:    $saleReturn->id,
                            referenceNumber: $saleReturn->number,
                            note:           'Retour mobile '.$saleReturn->number,
                            reason:         $returnLine['reason'] ?: ($data['refund_reason'] ?? null),
                            idempotencyKey: 'api-ret-'.$saleReturn->id.'-'.$returnLine['sale_item_id'].'-restock',
                        ));
                        $item->increment('stock_quantity', $qty);
                        if ($item->status === 'out_of_stock' && $item->fresh()->stock_quantity > 0) {
                            $item->update(['status' => 'active']);
                        }
                    } else {
                        // No physical return: record movement type only.
                        $stock     = ItemLocationStock::where('tenant_id', $tenant->id)
                            ->where('item_id', $item->id)
                            ->where('location_id', $locationId)
                            ->lockForUpdate()->first();

                        $qtySnapshot = (int) ($stock?->quantity ?? $item->stock_quantity);

                        if ($stock && in_array($returnLine['stock_action'], ['damaged', 'lost', 'waste'], true)) {
                            $stock->increment('damaged_quantity', $qty);
                        }

                        \App\Models\InventoryMovement::create([
                            'tenant_id'      => $tenant->id,
                            'item_id'        => $item->id,
                            'variant_id'     => null,
                            'location_id'    => $locationId,
                            'user_id'        => auth()->id(),
                            'type'           => match ($returnLine['stock_action']) {
                                'damaged', 'waste' => InventoryMovementType::DAMAGE,
                                'lost'             => InventoryMovementType::LOSS,
                                default            => InventoryMovementType::REFUND_WITHOUT_RETURN,
                            },
                            'quantity_before' => $qtySnapshot,
                            'quantity_delta'  => 0,
                            'quantity_after'  => $qtySnapshot,
                            'reference_type'  => SaleReturn::class,
                            'reference_id'    => $saleReturn->id,
                            'reference_number' => $saleReturn->number,
                            'note'            => 'Retour non-restocké mobile '.$saleReturn->number,
                            'reason'          => $returnLine['reason'] ?: ($data['refund_reason'] ?? null),
                            'idempotency_key' => 'api-ret-'.$saleReturn->id.'-'.$returnLine['sale_item_id'].'-'.$returnLine['stock_action'],
                        ]);
                    }
                }

                // Cash drawer out if refunded in cash.
                $session = $this->cashRegister->openSession($tenant);
                if ($data['refund_method'] === 'cash' && $session) {
                    $this->cashRegister->recordMovement($tenant, $session, 'sale_refund_cash', 'out', $returnTotal, [
                        'sale_id'         => $sale->id,
                        'reference'       => $saleReturn->number,
                        'payment_method'  => 'cash',
                        'note'            => 'Remboursement espèces retour mobile '.$saleReturn->number,
                        'metadata'        => ['sale_return_id' => $saleReturn->id, 'sale_number' => $sale->number],
                    ]);
                }

                // Update sale status.
                $totalReturned = (float) $sale->returns()->sum('total_amount');
                if ($totalReturned + 0.001 >= (float) $sale->total_amount) {
                    $sale->update(['status' => 'refunded']);
                } else {
                    $sale->update(['status' => 'partial_refund']);
                }

                return $saleReturn;
            });
        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'already_existed' => false, 'return' => $saleReturn], 201);
    }
}
