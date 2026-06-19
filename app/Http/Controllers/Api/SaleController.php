<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Item;
use App\Models\ItemLocationStock;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Tenant;
use App\Services\Documents\DocumentNumberGenerator;
use App\Services\Inventory\InventoryMovementType;
use App\Services\Inventory\InventoryService;
use App\Services\Inventory\MovementDTO;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        private readonly DocumentNumberGenerator $numbers,
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
            'items'                   => ['required', 'array', 'min:1'],
            'items.*.item_id'         => ['required', 'integer'],
            'items.*.quantity'        => ['required', 'integer', 'min:1'],
            'items.*.unit_price'      => ['nullable', 'numeric', 'min:0'],
            'items.*.note'            => ['nullable', 'string', 'max:500'],
            'payments'                => ['required', 'array'],
            'payments.cash'           => ['nullable', 'numeric', 'min:0'],
            'payments.card'           => ['nullable', 'numeric', 'min:0'],
            'payments.transfer'       => ['nullable', 'numeric', 'min:0'],
            'payments.advance'        => ['nullable', 'numeric', 'min:0'],
            'discount'                => ['nullable', 'array'],
            'discount.type'           => ['nullable', 'in:percent,fixed'],
            'discount.value'          => ['nullable', 'numeric', 'min:0'],
            'note'                    => ['nullable', 'string', 'max:1000'],
            'sold_at'                 => ['nullable', 'date'],
        ]);

        /** @var Tenant $tenant */
        $tenant     = $request->attributes->get('api_tenant');
        $locationId = (int) ($data['location_id'] ?? $request->attributes->get('api_location_id'));

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

        // Load all items in one query.
        $itemIds   = collect($data['items'])->pluck('item_id')->unique()->all();
        $items     = Item::where('tenant_id', $tenant->id)->whereIn('id', $itemIds)->get()->keyBy('id');

        // Validate all items exist and are active.
        foreach ($data['items'] as $line) {
            $item = $items->get($line['item_id']);
            if (! $item || $item->status !== 'active') {
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
            $item = $items->get($line['item_id']);

            $unitPrice  = $line['unit_price'] !== null
                ? (float) $line['unit_price']
                : (float) $item->sale_price;
            $lineTotal  = round($unitPrice * (int) $line['quantity'], 2);

            // Snapshot the weighted-average cost at this location right now.
            $avgCost = $item->type !== 'service'
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
                'quantity'    => (int) $line['quantity'],
                'unit_price'  => $unitPrice,
                'total_price' => $lineTotal,
                'note'        => $line['note'] ?? null,
                'avg_cost'    => $avgCost,
                'line_cogs'   => $lineCogs,
            ];
        }

        // Apply discount.
        $discountType  = $data['discount']['type'] ?? 'fixed';
        $discountValue = (float) ($data['discount']['value'] ?? 0);
        $discount = $discountType === 'percent'
            ? round($subtotal * $discountValue / 100, 2)
            : round(min($subtotal, $discountValue), 2);

        $total = max(0, round($subtotal - $discount, 2));
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

        $sale = DB::transaction(function () use (
            $tenant, $locationId, $contact, $payments, $saleLines,
            $subtotal, $discount, $total, $paid, $data,
            $totalCogs, $allowOversell
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

            // Generate atomic sale number (DocumentNumberGenerator uses lockForUpdate).
            $numberData = $this->numbers->next($tenant, 'sale', 'BL');
            $saleNumber = $numberData['number'];
            $soldAt     = ! empty($data['sold_at'])
                ? \Carbon\Carbon::parse($data['sold_at'])
                : now();

            $paymentMethod = collect($payments)
                ->filter(fn ($a) => $a > 0.001)
                ->keys()
                ->join('+') ?: 'cash';

            $changeAmount   = max(0, round($paid - $total, 2));
            $cashChange     = min($payments['cash'], $changeAmount);
            $cashDrawerIn   = max(0, round($payments['cash'] - $cashChange, 2));

            $sale = Sale::create([
                'tenant_id'       => $tenant->id,
                'contact_id'      => $contact?->id,
                'user_id'         => auth()->id(),
                'number'          => $saleNumber,
                'status'          => 'paid',
                'payment_method'  => $paymentMethod,
                'subtotal_amount' => $subtotal,
                'discount_amount' => $discount,
                'tax_amount'      => round($total * 0.2 / 1.2, 2),
                'total_amount'    => $total,
                'sold_at'         => $soldAt,
                'idempotency_key' => $data['idempotency_key'],
                'metadata'        => [
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
                    'line_adjustments' => collect($saleLines)->map(fn ($l) => [
                        'item_id'     => $l['item']->id,
                        'name'        => $l['item']->title,
                        'quantity'    => $l['quantity'],
                        'unit_price'  => $l['unit_price'],
                        'average_cost' => $l['avg_cost'],
                        'cogs'        => $l['line_cogs'],
                        'note'        => $l['note'],
                    ])->values()->all(),
                    'note' => $data['note'] ?? null,
                    'discount' => ['type' => $data['discount']['type'] ?? 'fixed', 'value' => $data['discount']['value'] ?? 0, 'amount' => $discount],
                ],
            ]);

            foreach ($saleLines as $line) {
                $sale->items()->create([
                    'item_id'    => $line['item']->id,
                    'name'       => $line['item']->title,
                    'quantity'   => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'total_price' => $line['total_price'],
                    'unit_cost'  => $line['avg_cost'],
                    'total_cost' => $line['line_cogs'],
                ]);

                if ($line['item']->type !== 'service') {
                    // InventoryService::move() does its own lockForUpdate + idempotency.
                    $this->inventory->move(new MovementDTO(
                        tenantId:       $tenant->id,
                        itemId:         $line['item']->id,
                        variantId:      null,
                        locationId:     $locationId,
                        type:           InventoryMovementType::SALE,
                        quantityChanged: -$line['quantity'],
                        userId:         auth()->id(),
                        referenceType:  Sale::class,
                        referenceId:    $sale->id,
                        referenceNumber: $sale->number,
                        note:           'Vente mobile '.$sale->number,
                        idempotencyKey: 'api-sale-'.$sale->id.'-item-'.$line['item']->id,
                        unitCost:       $line['avg_cost'] > 0 ? $line['avg_cost'] : null,
                        allowNegative:  $allowOversell,
                    ));

                    // Keep denormalised stock_quantity in sync (used for low-stock alerts).
                    $line['item']->decrement('stock_quantity', $line['quantity']);
                    if (! $allowOversell && $line['item']->fresh()->stock_quantity <= 0) {
                        $line['item']->update(['status' => 'out_of_stock']);
                    }
                }
            }

            // Record individual payment lines.
            $remaining = $total;
            foreach ($payments as $method => $amount) {
                $allocated = min($amount, $remaining);
                if ($allocated <= 0.001) {
                    continue;
                }
                $paymentNumber = $this->numbers->next($tenant, 'sale_payment', 'PAY')['number'];
                SalePayment::create([
                    'tenant_id'  => $tenant->id,
                    'sale_id'    => $sale->id,
                    'contact_id' => $contact?->id,
                    'user_id'    => auth()->id(),
                    'number'     => $paymentNumber,
                    'method'     => $method,
                    'amount'     => round($allocated, 2),
                    'paid_at'    => $soldAt,
                    'idempotency_key' => 'api-pay-'.$sale->id.'-'.$method,
                ]);
                $remaining -= $allocated;
            }

            return $sale;
        });

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
        $since   = $request->query('since');
        $perPage = min((int) ($request->query('per_page', 50)), 200);

        $sales = Sale::query()
            ->where('tenant_id', $tenant->id)
            ->with(['items:id,sale_id,item_id,name,quantity,unit_price,total_price,unit_cost,total_cost', 'contact:id,name,phone'])
            ->when($since, fn ($q) => $q->where('sold_at', '>', \Carbon\Carbon::parse($since)))
            ->latest('sold_at')
            ->paginate($perPage);

        return response()->json([
            'ok'       => true,
            'has_more' => $sales->hasMorePages(),
            'page'     => $sales->currentPage(),
            'sales'    => $sales->items(),
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

        $sale->load(['items.item:id,title,barcode', 'contact:id,name,phone', 'payments']);

        return response()->json(['ok' => true, 'sale' => $sale]);
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
            if ($line['item']->type === 'service') {
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
            $available = $this->inventory->available($tenantId, $id, null, $locationId);
            if ($available < $entry['requested']) {
                $conflicts[] = [
                    'item_id'   => $entry['item']->id,
                    'name'      => $entry['item']->title,
                    'requested' => $entry['requested'],
                    'available' => $available,
                ];
            }
        }
        return $conflicts;
    }

    /**
     * Build the JSON response with the sale record + a stock snapshot for all
     * sold items so the device can update its local cache immediately.
     */
    private function saleResponse(Sale $sale, array $requestedLines, int $tenantId, int $locationId, bool $alreadyExisted = false): JsonResponse
    {
        $sale->load(['items:id,sale_id,item_id,name,quantity,unit_price,total_price,unit_cost,total_cost', 'contact:id,name,phone']);

        // Post-sale stock snapshot so the client updates local stock immediately.
        $itemIds    = collect($requestedLines)->pluck('item_id')->unique()->all();
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
            'sale'            => $sale,
            'stock_after'     => $stockAfter,
        ], $alreadyExisted ? 200 : 201);
    }
}
