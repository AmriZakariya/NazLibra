<?php

namespace App\Http\Controllers\Api;

use App\Enums\ItemStatus;
use App\Enums\ItemType;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\SaleResource;
use App\Models\Contact;
use App\Models\Item;
use App\Models\ItemLocationStock;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Tenant;
use App\Rules\ExplicitOffsetDateTime;
use App\Support\ApiActionContext;
use App\Support\UtcDateTime;
use App\Services\Documents\DocumentNumberGenerator;
use App\Exceptions\InsufficientStockException;
use App\Services\Inventory\InventoryLedgerService;
use App\Services\Inventory\InventoryMovementType;
use App\Services\Inventory\InventoryService;
use App\Services\LoyaltyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

/**
 * Handles sale submission from the offline-first mobile app.
 *
 * Critical guarantees:
 * - Idempotent: submitting the same idempotency_key twice returns the same sale.
 * - Atomic: stock movement and sale record are always consistent (single transaction).
 * - Honest about conflicts: returns per-item stock errors so the client can handle them.
 * - Cost-accurate: unit_cost snapshotted from average_cost at time of sale.
 */
class SaleController extends Controller
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly InventoryLedgerService $ledger,
        private readonly DocumentNumberGenerator $numbers,
        private readonly LoyaltyService $loyalty,
    ) {}

    /**
     * POST /api/v1/pos/sales
     *
     * Request body:
     * {
     *   "idempotency_key": "<UUID>",          // Required. Stable UUID from client.
     *   "location_id": 1,                     // Optional — falls back to X-Location-Id header.
     *   "contact_id": 5,                      // Optional.
     *   "items": [
     *     {"item_id": 10, "quantity": 2, "unit_price": 45.00, "note": ""}
     *   ],
     *   "payments": {"cash": 90.00, "card": 0, "transfer": 0, "advance": 0},
     *   "discount": {"type": "percent"|"fixed", "value": 0},
     *   "note": "",
     *   "sold_at": "2026-06-19T10:00:00Z"    // Optional, for backfilling offline sales.
     * }
     */
    #[OA\Post(
        path: '/api/v1/pos/sales',
        operationId: 'saleStore',
        summary: 'Submit a new sale (idempotent)',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['idempotency_key', 'items', 'payments'],
                properties: [
                    new OA\Property(property: 'idempotency_key', type: 'string', example: '550e8400-e29b-41d4-a716-446655440000'),
                    new OA\Property(property: 'contact_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'location_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'items', type: 'array', items: new OA\Items(
                        required: ['item_id', 'quantity'],
                        properties: [
                            new OA\Property(property: 'item_id', type: 'integer'),
                            new OA\Property(property: 'quantity', type: 'integer', minimum: 1),
                            new OA\Property(property: 'unit_price', type: 'number', format: 'float', nullable: true),
                            new OA\Property(property: 'note', type: 'string', nullable: true),
                        ]
                    )),
                    new OA\Property(property: 'payments', type: 'object', properties: [
                        new OA\Property(property: 'cash', type: 'number', format: 'float', nullable: true),
                        new OA\Property(property: 'card', type: 'number', format: 'float', nullable: true),
                        new OA\Property(property: 'transfer', type: 'number', format: 'float', nullable: true),
                        new OA\Property(property: 'advance', type: 'number', format: 'float', nullable: true),
                    ]),
                    new OA\Property(property: 'discount', type: 'object', nullable: true, properties: [
                        new OA\Property(property: 'type', type: 'string', enum: ['percent', 'fixed']),
                        new OA\Property(property: 'value', type: 'number', format: 'float'),
                    ]),
                    new OA\Property(property: 'note', type: 'string', nullable: true),
                    new OA\Property(property: 'sold_at', type: 'string', format: 'date-time', nullable: true),
                ]
            )
        ),
        tags: ['Sales'],
        parameters: [
            new OA\Parameter(name: 'X-Tenant-Slug', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Location-Id', in: 'header', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Sale created',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'ok', type: 'boolean', example: true),
                    new OA\Property(property: 'already_existed', type: 'boolean', example: false),
                    new OA\Property(property: 'sale', type: 'object'),
                    new OA\Property(property: 'stock_after', type: 'object'),
                ])
            ),
            new OA\Response(response: 200, description: 'Sale already existed (idempotent replay)'),
            new OA\Response(response: 422, description: 'Validation error or insufficient stock'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'idempotency_key'         => ['required', 'string', 'max:64'],
            'location_id'             => ['nullable', 'integer'],
            'contact_id'              => ['nullable', 'integer'],
            'items'                    => ['required', 'array', 'min:1'],
            'items.*.item_id'          => ['nullable', 'integer'],
            'items.*.custom_name'      => ['nullable', 'string', 'max:255'],
            'items.*.quantity'         => ['required', 'integer', 'min:1'],
            'items.*.unit_price'       => ['nullable', 'numeric', 'min:0'],
            'items.*.note'             => ['nullable', 'string', 'max:500'],
            'payments'                => ['required', 'array'],
            'payments.cash'           => ['nullable', 'numeric', 'min:0'],
            'payments.card'           => ['nullable', 'numeric', 'min:0'],
            'payments.transfer'       => ['nullable', 'numeric', 'min:0'],
            'payments.advance'        => ['nullable', 'numeric', 'min:0'],
            'discount'                => ['nullable', 'array'],
            'discount.type'           => ['nullable', 'in:percent,fixed'],
            'discount.value'          => ['nullable', 'numeric', 'min:0'],
            'note'                    => ['nullable', 'string', 'max:1000'],
            'sold_at'                 => ['nullable', 'string', new ExplicitOffsetDateTime],
            'loyalty_points_redeemed' => ['nullable', 'numeric', 'min:0'],
            'source_online_order_id'  => ['nullable', 'integer'],
        ]);

        $soldAt = ! empty($data['sold_at']) ? UtcDateTime::parse($data['sold_at']) : now()->utc();
        if ($soldAt->lt(now()->utc()->subHours(24)) || $soldAt->gt(now()->utc()->addMinutes(5))) {
            throw ValidationException::withMessages([
                'sold_at' => 'sold_at doit être compris entre les dernières 24 heures et les 5 prochaines minutes.',
            ]);
        }

        /** @var Tenant $tenant */
        $tenant     = $request->attributes->get('api_tenant');
        $locationId = (int) ($data['location_id'] ?? $request->attributes->get('api_location_id'));
        /** @var ApiActionContext $action */
        $action = $request->attributes->get('api_action_context');

        if (! $locationId) {
            return response()->json(['ok' => false, 'message' => 'Aucun emplacement configuré.'], 422);
        }

        // Idempotency check — return existing sale without any side effects.
        $existing = Sale::where('tenant_id', $tenant->id)
            ->where('idempotency_key', $data['idempotency_key'])
            ->first();

        if ($existing) {
            return $this->saleResponse($existing, $data['items'], $tenant->id, $locationId, alreadyExisted: true);
        }

        $allowOversell = (bool) data_get($tenant->settings, 'pos.allow_oversell', false);

        // Validate: each line must have item_id OR custom_name.
        foreach ($data['items'] as $idx => $line) {
            if (empty($line['item_id']) && empty($line['custom_name'])) {
                return response()->json([
                    'ok'      => false,
                    'message' => "La ligne $idx doit avoir un article ou un nom personnalisé.",
                ], 422);
            }
        }

        // Load catalog items for lines that reference them.
        $itemIds   = collect($data['items'])->pluck('item_id')->filter()->unique()->all();
        $items     = $itemIds
            ? Item::with('tax')->where('tenant_id', $tenant->id)->whereIn('id', $itemIds)->get()->keyBy('id')
            : collect();

        // Validate catalog items exist and are active.
        foreach ($data['items'] as $line) {
            if (empty($line['item_id'])) continue;
            $item = $items->get($line['item_id']);
            if (! $item || ! ItemStatus::from($item->status)->isVisible()) {
                return response()->json([
                    'ok'      => false,
                    'error'   => 'item_unavailable',
                    'message' => "L'article ID {$line['item_id']} est indisponible.",
                ], 422);
            }
        }

        $contactId = $data['contact_id'] ?? null;
        $contact   = $contactId
            ? Contact::where('tenant_id', $tenant->id)->find($contactId)
            : null;

        $payments = [
            'cash'     => max(0, (float) ($data['payments']['cash'] ?? 0)),
            'card'     => max(0, (float) ($data['payments']['card'] ?? 0)),
            'transfer' => max(0, (float) ($data['payments']['transfer'] ?? 0)),
            'advance'  => max(0, (float) ($data['payments']['advance'] ?? 0)),
        ];

        // Build sale lines with cost snapshot.
        $saleLines  = [];
        $subtotal   = 0.0;
        $totalCogs  = 0.0;

        foreach ($data['items'] as $line) {
            $isCustom = empty($line['item_id']);

            if ($isCustom) {
                // Out-of-catalog / custom item — no inventory tracking.
                $unitPrice = (float) ($line['unit_price'] ?? 0);
                $lineTotal = round($unitPrice * (int) $line['quantity'], 2);
                $subtotal += $lineTotal;

                $saleLines[] = [
                    'item'        => null,
                    'custom_name' => trim($line['custom_name']),
                    'quantity'    => (int) $line['quantity'],
                    'unit_price'  => $unitPrice,
                    'total_price' => $lineTotal,
                    'note'        => $line['note'] ?? null,
                    'avg_cost'    => 0.0,
                    'line_cogs'   => 0.0,
                    'tax_rate'    => 0.0,
                ];
                continue;
            }

            $item = $items->get($line['item_id']);

            $unitPrice  = $line['unit_price'] !== null
                ? (float) $line['unit_price']
                : (float) $item->sale_price;
            $lineTotal  = round($unitPrice * (int) $line['quantity'], 2);

            // Snapshot the weighted-average cost at this location right now.
            $avgCost = $item->type !== ItemType::Service->value
                ? (float) (ItemLocationStock::where('tenant_id', $tenant->id)
                    ->where('item_id', $item->id)
                    ->where('location_id', $locationId)
                    ->value('average_cost') ?? $item->purchase_price ?? 0)
                : 0.0;

            $lineCogs = round($avgCost * (int) $line['quantity'], 4);
            $subtotal  += $lineTotal;
            $totalCogs += $lineCogs;

            $saleLines[] = [
                'item'        => $item,
                'custom_name' => null,
                'quantity'    => (int) $line['quantity'],
                'unit_price'  => $unitPrice,
                'total_price' => $lineTotal,
                'note'        => $line['note'] ?? null,
                'avg_cost'    => $avgCost,
                'line_cogs'   => $lineCogs,
                'tax_rate'    => max(0.0, min(100.0, (float) ($item->tax?->rate ?? 0))),
            ];
        }

        // Apply sale-level discount.
        $discountType  = $data['discount']['type'] ?? 'fixed';
        $discountValue = (float) ($data['discount']['value'] ?? 0);
        $discount = $discountType === 'percent'
            ? round($subtotal * $discountValue / 100, 2)
            : round(min($subtotal, $discountValue), 2);

        $afterDiscount = max(0, round($subtotal - $discount, 2));

        // Loyalty points redemption — validate before opening the transaction.
        $loyaltyEnabled       = $this->loyalty->isEnabled($tenant);
        $loyaltyCfg           = $this->loyalty->config($tenant);
        $pointsToRedeem       = (float) ($data['loyalty_points_redeemed'] ?? 0);
        $loyaltyDiscount      = 0.0;
        $loyaltyPointsEarned  = 0.0;

        if ($loyaltyEnabled && $pointsToRedeem > 0) {
            if (! $contact) {
                return response()->json([
                    'ok'      => false,
                    'error'   => 'loyalty_requires_contact',
                    'message' => 'Sélectionnez un client pour utiliser vos points de fidélité.',
                ], 422);
            }

            try {
                $loyaltyDiscount = $this->loyalty->validateRedemption(
                    $pointsToRedeem,
                    (float) $contact->loyalty_points,
                    $afterDiscount,
                    $loyaltyCfg,
                );
            } catch (\InvalidArgumentException $e) {
                return response()->json([
                    'ok'      => false,
                    'error'   => 'loyalty_redemption_invalid',
                    'message' => $e->getMessage(),
                ], 422);
            }
        } elseif (! $loyaltyEnabled) {
            $pointsToRedeem = 0.0;
        }

        $total = max(0, round($afterDiscount - $loyaltyDiscount, 2));
        $discountFactor = $subtotal > 0 ? min(1.0, max(0.0, $total / $subtotal)) : 1.0;
        $taxTotal = 0.0;
        foreach ($saleLines as $line) {
            $rate = (float) ($line['tax_rate'] ?? 0);
            if ($rate <= 0 || $line['total_price'] <= 0) {
                continue;
            }

            $discountedLineTotal = (float) $line['total_price'] * $discountFactor;
            $taxTotal += $discountedLineTotal - ($discountedLineTotal / (1 + $rate / 100));
        }
        $taxTotal = round(min($taxTotal, $total), 2);
        $paid  = round(array_sum($payments), 2);

        if ($paid + 0.001 < $total) {
            return response()->json([
                'ok'      => false,
                'error'   => 'underpayment',
                'message' => "Le montant payé ({$paid}) est inférieur au total ({$total}).",
            ], 422);
        }

        if ($payments['advance'] > 0 && ! $contact) {
            return response()->json([
                'ok'      => false,
                'error'   => 'advance_requires_contact',
                'message' => 'Sélectionnez un client pour utiliser une avance.',
            ], 422);
        }

        // Stock pre-check (outside transaction for early feedback without holding locks).
        if (! $allowOversell) {
            $this->reconcileSaleLineStock($tenant->id, $locationId, $saleLines, $action);
            $conflicts = $this->stockConflicts($tenant->id, $locationId, $saleLines);
            if (! empty($conflicts)) {
                return response()->json([
                    'ok'        => false,
                    'error'     => 'insufficient_stock',
                    'message'   => 'Stock insuffisant pour un ou plusieurs articles.',
                    'conflicts' => $conflicts,
                ], 422);
            }
        }

        // Validate source online order if provided.
        $sourceOnlineOrderId = (int) ($data['source_online_order_id'] ?? 0);
        $sourceOnlineOrder   = null;
        if ($sourceOnlineOrderId > 0) {
            $sourceOnlineOrder = \App\Models\OnlineOrder::where('tenant_id', $tenant->id)
                ->whereKey($sourceOnlineOrderId)
                ->first();

            if (! $sourceOnlineOrder) {
                return response()->json(['ok' => false, 'message' => 'Commande introuvable.'], 422);
            }
            if ($sourceOnlineOrder->converted_sale_id !== null) {
                $existingSale = Sale::find($sourceOnlineOrder->converted_sale_id);
                if ($existingSale) {
                    return $this->saleResponse($existingSale, $data['items'], $tenant->id, $locationId, alreadyExisted: true);
                }
            }
            if (! in_array($sourceOnlineOrder->status, ['confirmed', 'preparing', 'ready'], true)) {
                return response()->json(['ok' => false, 'message' => 'Cette commande ne peut pas être convertie dans son état actuel.'], 422);
            }
        }

        // Generate the sale number in its own committed transaction BEFORE the main sale
        // transaction. If the sale transaction later rolls back (e.g. on a unique constraint
        // conflict), the sequence counter is already advanced so the next retry gets a fresh
        // number rather than hitting the same conflict forever.
        $saleNumber = DB::transaction(function () use ($tenant) {
            return $this->numbers->next(
                $tenant,
                'sale',
                null,
                fn ($n) => Sale::where('tenant_id', $tenant->id)->where('number', $n)->exists()
            )['number'];
        });

        $transactionFn = function () use (
            $tenant, $locationId, $contact, $payments, $saleLines,
            $subtotal, $discount, $loyaltyDiscount, $total, $paid, $data,
            $discountFactor, $taxTotal, $totalCogs, $allowOversell, $action, $soldAt,
            $pointsToRedeem, $loyaltyCfg, $loyaltyEnabled, $sourceOnlineOrder,
            &$saleNumber
        ): Sale {
            // Decrement advance balance atomically before the rest.
            if ($contact && $payments['advance'] > 0) {
                $contact->lockForUpdate();
                $contact->refresh();
                if ((float) $contact->advance_balance < $payments['advance']) {
                    throw new \RuntimeException('Avance client insuffisante.');
                }
                $contact->decrement('advance_balance', $payments['advance']);
            }

            // Redeem loyalty points — must happen inside transaction before sale is saved.
            if ($loyaltyEnabled && $contact && $pointsToRedeem > 0) {
                // Re-lock contact if we didn't already (advance may have done it).
                if ($payments['advance'] <= 0) {
                    $contact->lockForUpdate();
                    $contact->refresh();
                }
                if ((float) $contact->loyalty_points < $pointsToRedeem) {
                    throw new \RuntimeException('Solde de points insuffisant.');
                }
                // Debit is recorded after sale ID is known — see below.
            }
            $paymentMethod = collect($payments)
                ->filter(fn ($a) => $a > 0.001)
                ->keys()
                ->join('+') ?: 'cash';

            $changeAmount   = max(0, round($paid - $total, 2));
            $cashChange     = min($payments['cash'], $changeAmount);
            $cashDrawerIn   = max(0, round($payments['cash'] - $cashChange, 2));

            // Points earned are calculated on the final total (after loyalty discount).
            $pointsEarned = ($loyaltyEnabled && $contact)
                ? $this->loyalty->pointsForAmount($total, $loyaltyCfg)
                : 0.0;

            $sale = Sale::create([
                'tenant_id'       => $tenant->id,
                'location_id'     => $locationId,
                'contact_id'      => $contact?->id,
                'user_id'         => $action->actor->id,
                'virtual_device_id' => $action->virtualDevice?->id,
                'actor_name_snapshot' => $action->actor->name,
                'terminal_name_snapshot' => $action->virtualDevice?->name,
                'number'          => $saleNumber,
                'status'          => 'paid',
                'payment_method'  => $paymentMethod,
                'subtotal_amount' => $subtotal,
                'discount_amount' => round($discount + $loyaltyDiscount, 2),
                'tax_amount'      => $taxTotal,
                'total_amount'    => $total,
                'loyalty_points_earned'   => $pointsEarned,
                'loyalty_points_redeemed' => $pointsToRedeem,
                'sold_at'         => $soldAt,
                'idempotency_key'        => $data['idempotency_key'],
                'source_online_order_id' => $sourceOnlineOrder?->id,
                'metadata'               => [
                    'source'       => 'mobile_api',
                    'payments'     => $payments,
                    'paid_amount'  => $paid,
                    'change_amount' => $changeAmount,
                    'cash_register' => [
                        'cash_received' => $payments['cash'],
                        'cash_change'   => $cashChange,
                        'cash_drawer_in' => $cashDrawerIn,
                        'paid_amount'   => $paid,
                        'expected_total' => $total,
                    ],
                    'cogs' => [
                        'total'    => round($totalCogs, 4),
                        'currency' => $tenant->currency ?? 'MAD',
                    ],
                    'line_adjustments' => collect($saleLines)->map(function ($l) use ($discountFactor) {
                        $rate = (float) ($l['tax_rate'] ?? 0);
                        $discountedLineTotal = (float) $l['total_price'] * $discountFactor;
                        $lineTax = $rate > 0
                            ? $discountedLineTotal - ($discountedLineTotal / (1 + $rate / 100))
                            : 0.0;

                        return [
                            'item_id'      => $l['item']?->id,
                            'name'         => $l['item'] ? $l['item']->title : $l['custom_name'],
                            'quantity'     => $l['quantity'],
                            'unit_price'   => $l['unit_price'],
                            'average_cost' => $l['avg_cost'],
                            'cogs'         => $l['line_cogs'],
                            'tax_rate'     => $rate,
                            'tax_amount'   => round($lineTax, 2),
                            'note'         => $l['note'],
                        ];
                    })->values()->all(),
                    'note' => $data['note'] ?? null,
                    'discount' => ['type' => $data['discount']['type'] ?? 'fixed', 'value' => $data['discount']['value'] ?? 0, 'amount' => $discount],
                    'loyalty' => $pointsToRedeem > 0 ? [
                        'points_redeemed'  => $pointsToRedeem,
                        'discount_applied' => $loyaltyDiscount,
                        'points_earned'    => $pointsEarned,
                    ] : null,
                ],
            ]);

            foreach ($saleLines as $line) {
                $sale->items()->create([
                    'item_id'    => $line['item']?->id,
                    'name'       => $line['item'] ? $line['item']->title : $line['custom_name'],
                    'quantity'   => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'total_price' => $line['total_price'],
                    'unit_cost'  => $line['avg_cost'],
                    'total_cost' => $line['line_cogs'],
                ]);

                if ($line['item'] && $line['item']->type !== ItemType::Service->value) {
                    // InventoryLedgerService::createOutgoingMovement() handles LIFO
                    // layer consumption, COGS computation, and stock cache sync.
                    $ledgerResult = $this->ledger->createOutgoingMovement([
                        'tenantId'             => $tenant->id,
                        'itemId'               => $line['item']->id,
                        'variantId'            => null,
                        'locationId'           => $locationId,
                        'type'                 => InventoryMovementType::SALE,
                        'quantity'             => $line['quantity'],
                        'occurredAt'           => $soldAt,
                        'syncedAt'             => null,
                        'userId'               => $action->actor->id,
                        'idempotencyKey'       => 'api-sale-'.$sale->id.'-item-'.$line['item']->id,
                        'referenceType'        => Sale::class,
                        'referenceId'          => $sale->id,
                        'referenceNumber'      => $sale->number,
                        'reason'               => null,
                        'note'                 => 'Vente mobile '.$sale->number,
                        'virtualDeviceId'      => $action->virtualDevice?->id,
                        'actorNameSnapshot'    => $action->actor->name,
                        'terminalNameSnapshot' => $action->virtualDevice?->name,
                        'allowNegative'        => $allowOversell,
                    ]);

                    // Backfill sale item cost with actual LIFO-computed COGS.
                    $actualUnitCost  = $ledgerResult['unitCost'];
                    $actualTotalCost = $ledgerResult['cogs'];
                    $sale->items()
                        ->where('item_id', $line['item']->id)
                        ->update([
                            'unit_cost'  => $actualUnitCost,
                            'total_cost' => $actualTotalCost,
                        ]);

                    // Low-stock status is maintained by syncStockCache via ledger.
                    $freshQty = $this->ledger->availableQuantity($tenant->id, $line['item']->id, null, $locationId);
                    if (! $allowOversell && $freshQty <= 0) {
                        $line['item']->update(['status' => ItemStatus::OutOfStock->value]);
                    }
                }
            }

            // Update sale.cogs with actual LIFO-computed total (from all sale items' total_cost).
            $actualSaleCogs = $sale->items()->sum('total_cost');
            $sale->update(['cogs' => $actualSaleCogs]);

            // Record individual payment lines.
            $remaining = $total;
            foreach ($payments as $method => $amount) {
                $allocated = min($amount, $remaining);
                if ($allocated <= 0.001) {
                    continue;
                }
                $paymentNumber = $this->numbers->next(
                    $tenant,
                    'sale_payment',
                    'PAY',
                    fn (string $n) => SalePayment::where('tenant_id', $tenant->id)->where('number', $n)->exists()
                )['number'];
                SalePayment::create([
                    'tenant_id'  => $tenant->id,
                    'sale_id'    => $sale->id,
                    'contact_id' => $contact?->id,
                    'user_id'    => $action->actor->id,
                    'number'     => $paymentNumber,
                    'method'     => $method,
                    'amount'     => round($allocated, 2),
                    'paid_at'    => $soldAt,
                    'idempotency_key' => 'api-pay-'.$sale->id.'-'.$method,
                ]);
                $remaining -= $allocated;
            }

            // Loyalty point transactions — both redeem and earn recorded after sale exists.
            if ($loyaltyEnabled && $contact) {
                if ($pointsToRedeem > 0) {
                    $this->loyalty->redeem(
                        $contact,
                        $sale,
                        $pointsToRedeem,
                        'api-loyalty-redeem-'.$sale->id,
                    );
                }

                if ($pointsEarned > 0) {
                    $this->loyalty->earn(
                        $contact,
                        $sale,
                        $pointsEarned,
                        'api-loyalty-earn-'.$sale->id,
                    );
                }
            }

            // Mark source online order as fulfilled.
            if ($sourceOnlineOrder) {
                $sourceOnlineOrder->update([
                    'converted_sale_id' => $sale->id,
                    'converted_by'      => $action->actor->id,
                    'converted_at'      => now(),
                    'status'            => 'fulfilled',
                    'payment_status'    => 'paid',
                ]);
            }

            return $sale;
        };

        // Retry up to 3 times on number uniqueness conflicts (concurrent inserts on the same slot).
        // $saleNumber is captured by reference so reassigning it before a retry is picked up
        // by the closure without duplicating the closure body.
        $attempt = 0;
        do {
            try {
                $sale = DB::transaction($transactionFn);
                break;
            } catch (QueryException $exception) {
                $existing = Sale::where('tenant_id', $tenant->id)
                    ->where('idempotency_key', $data['idempotency_key'])
                    ->first();

                if ($existing) {
                    return $this->saleResponse($existing, $data['items'], $tenant->id, $locationId, alreadyExisted: true);
                }

                $isNumberConflict = $exception instanceof UniqueConstraintViolationException
                    && str_contains($exception->getMessage(), 'sales.tenant_id, sales.number');

                if (! $isNumberConflict || ++$attempt >= 3) {
                    throw $exception;
                }

                // Commit a fresh number before the next attempt. Because $saleNumber is captured
                // by reference the closure will use this new value on its next invocation.
                $saleNumber = DB::transaction(function () use ($tenant) {
                    return $this->numbers->next(
                        $tenant,
                        'sale',
                        null,
                        fn ($n) => Sale::where('tenant_id', $tenant->id)->where('number', $n)->exists()
                    )['number'];
                });
            }
        } while (true);

        return $this->saleResponse($sale, $data['items'], $tenant->id, $locationId);
    }

    /** GET /api/v1/pos/sales */
    #[OA\Get(
        path: '/api/v1/pos/sales',
        operationId: 'saleIndex',
        summary: 'List sales for the tenant',
        security: [['bearerAuth' => []]],
        tags: ['Sales'],
        parameters: [
            new OA\Parameter(name: 'X-Tenant-Slug', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Location-Id', in: 'header', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 50, maximum: 200)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated sales',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'ok', type: 'boolean', example: true),
                    new OA\Property(property: 'has_more', type: 'boolean'),
                    new OA\Property(property: 'page', type: 'integer'),
                    new OA\Property(property: 'sales', type: 'array', items: new OA\Items(type: 'object')),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant  = $request->attributes->get('api_tenant');
        $sinceRaw = $request->query('since');
        try {
            $since = $sinceRaw ? UtcDateTime::parse((string) $sinceRaw) : null;
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'since' => 'since doit être une date RFC 3339 avec Z ou un décalage explicite (ex. 2026-06-24T10:23:50Z).',
            ]);
        }
        $perPage = min((int) ($request->query('per_page', 50)), 200);

        $sales = Sale::query()
            ->where('tenant_id', $tenant->id)
            ->with(['items:id,sale_id,item_id,name,quantity,unit_price,total_price,unit_cost,total_cost', 'contact:id,name,phone', 'user:id,name', 'virtualDevice:id,name', 'location:id,name'])
            ->when($since, fn ($q) => $q->where('sold_at', '>', $since))
            ->when($request->query('contact_id'), fn ($q, $contactId) => $q->where('contact_id', $contactId))
            ->when($request->query('payment_method'), fn ($q, $method) => $q->where('payment_method', 'like', "%{$method}%"))
            ->latest('sold_at')
            ->latest('id')
            ->paginate($perPage);

        return response()->json([
            'ok'       => true,
            'has_more' => $sales->hasMorePages(),
            'page'     => $sales->currentPage(),
            'sales'    => SaleResource::collection(collect($sales->items())),
        ]);
    }

    /** GET /api/v1/pos/sales/{sale} */
    #[OA\Get(
        path: '/api/v1/pos/sales/{sale}',
        operationId: 'saleShow',
        summary: 'Get a single sale by ID',
        security: [['bearerAuth' => []]],
        tags: ['Sales'],
        parameters: [
            new OA\Parameter(name: 'X-Tenant-Slug', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Location-Id', in: 'header', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'sale', in: 'path', required: true, description: 'Sale ID', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Sale detail',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'ok', type: 'boolean', example: true),
                    new OA\Property(property: 'sale', type: 'object'),
                ])
            ),
            new OA\Response(response: 404, description: 'Sale not found'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function show(Request $request, Sale $sale): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('api_tenant');

        if ($sale->tenant_id !== $tenant->id) {
            return response()->json(['ok' => false, 'message' => 'Vente introuvable.'], 404);
        }

        $sale->load(['items.item:id,title,barcode', 'contact:id,name,phone', 'payments', 'user:id,name', 'virtualDevice:id,name', 'location:id,name']);

        return response()->json(['ok' => true, 'sale' => SaleResource::make($sale)]);
    }

    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Check aggregated quantities against available stock per item.
     *
     * The client might send the same item_id multiple times (e.g., two gift
     * items of the same SKU in separate lines). We aggregate before checking
     * so a cart with {item 5: qty 10} + {item 5: qty 15} is correctly
     * compared against stock=20 instead of each line seeing 20 individually.
     */
    private function stockConflicts(int $tenantId, int $locationId, array $saleLines): array
    {
        // Aggregate requested quantities per item.
        $needed = [];
        foreach ($saleLines as $line) {
            if ($line['item'] === null || $line['item']->type === ItemType::Service->value) {
                continue;
            }
            $id = $line['item']->id;
            $needed[$id] = ($needed[$id] ?? [
                'item'      => $line['item'],
                'requested' => 0,
            ]);
            $needed[$id]['requested'] += $line['quantity'];
        }

        $conflicts = [];
        foreach ($needed as $id => $entry) {
            // Use layer-based available quantity — authoritative, not the cached column.
            $available = $this->ledger->availableQuantity($tenantId, $id, null, $locationId);
            if ($available < $entry['requested']) {
                $cacheQuantity = (float) (ItemLocationStock::where('tenant_id', $tenantId)
                    ->where('item_id', $id)
                    ->where('location_id', $locationId)
                    ->value('quantity') ?? 0);
                $conflicts[] = [
                    'item_id'        => $entry['item']->id,
                    'name'           => $entry['item']->title,
                    'location_id'    => $locationId,
                    'requested'      => $entry['requested'],
                    'available'      => $available,
                    'cache_quantity' => $cacheQuantity,
                ];
            }
        }
        return $conflicts;
    }

    private function reconcileSaleLineStock(int $tenantId, int $locationId, array $saleLines, ApiActionContext $action): void
    {
        $itemIds = collect($saleLines)
            ->map(fn (array $line) => $line['item']?->id)
            ->filter()
            ->unique()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        if (empty($itemIds)) {
            return;
        }

        // Bulk-check which items actually have cache > layers before entering
        // per-item transactions. In a healthy system (all purchases going through
        // the ledger) this subquery returns nothing and we skip all 10+ transactions.
        $shortfallItemIds = DB::table('item_location_stock as s')
            ->where('s.tenant_id', $tenantId)
            ->where('s.location_id', $locationId)
            ->whereIn('s.item_id', $itemIds)
            ->where('s.quantity', '>', 0)
            ->whereRaw('s.quantity > (
                SELECT COALESCE(SUM(l.remaining_quantity), 0)
                FROM inventory_layers l
                WHERE l.tenant_id = s.tenant_id
                  AND l.item_id   = s.item_id
                  AND l.location_id = s.location_id
                  AND l.remaining_quantity > 0
            ) + 0.0001')
            ->pluck('s.item_id');

        foreach ($shortfallItemIds as $itemId) {
            $this->ledger->reconcilePositiveStockCacheShortfall(
                $tenantId,
                (int) $itemId,
                null,
                $locationId,
                $action->actor->id,
                $action->virtualDevice?->id,
                $action->actor->name,
                $action->virtualDevice?->name,
            );
        }
    }

    /**
     * Build the JSON response with the sale record + a stock snapshot for all
     * sold items so the device can update its local cache immediately.
     */
    private function saleResponse(Sale $sale, array $requestedLines, int $tenantId, int $locationId, bool $alreadyExisted = false): JsonResponse
    {
        $sale->load(['items:id,sale_id,item_id,name,quantity,unit_price,total_price,unit_cost,total_cost', 'contact:id,name,phone', 'user:id,name', 'virtualDevice:id,name', 'location:id,name']);

        // Post-sale stock snapshot so the client updates local stock immediately.
        $itemIds    = collect($requestedLines)->pluck('item_id')->filter()->unique()->all();
        $stockAfter = ItemLocationStock::query()
            ->where('tenant_id', $tenantId)
            ->where('location_id', $locationId)
            ->whereIn('item_id', $itemIds)
            ->get(['item_id', 'quantity', 'reserved_quantity'])
            ->mapWithKeys(fn ($s) => [
                (string) $s->item_id => max(0, (int) $s->quantity - (int) $s->reserved_quantity),
            ]);

        return response()->json([
            'ok'              => true,
            'already_existed' => $alreadyExisted,
            'sale'            => SaleResource::make($sale),
            'stock_after'     => $stockAfter,
        ], $alreadyExisted ? 200 : 201);
    }
}
