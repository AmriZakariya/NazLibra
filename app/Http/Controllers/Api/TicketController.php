<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PosTicket;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Manages held/pending POS tickets (saved carts).
 *
 * A ticket is a JSON cart snapshot the cashier can park and resume later.
 * Tickets are device-agnostic — any device can pick up any held ticket.
 */
class TicketController extends Controller
{
    /** GET /api/v1/pos/tickets — list all held tickets for this tenant. */
    #[OA\Get(
        path: '/api/v1/pos/tickets',
        operationId: 'ticketIndex',
        summary: 'List all held (parked) POS tickets',
        security: [['bearerAuth' => []]],
        tags: ['Tickets'],
        parameters: [
            new OA\Parameter(name: 'X-Tenant-Slug', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Location-Id', in: 'header', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of held tickets',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'ok', type: 'boolean', example: true),
                    new OA\Property(property: 'tickets', type: 'array', items: new OA\Items(type: 'object')),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('api_tenant');

        $tickets = PosTicket::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'held')
            ->with('contact:id,name,phone')
            ->latest('held_at')
            ->get([
                'id', 'contact_id', 'user_id', 'number', 'status',
                'cart', 'subtotal_amount', 'discount_amount', 'total_amount',
                'note', 'held_at', 'updated_at',
            ]);

        return response()->json(['ok' => true, 'tickets' => $tickets]);
    }

    /**
     * POST /api/v1/pos/tickets — create or update a held ticket.
     *
     * The client should upsert by sending the same `number` to update.
     * If `number` is omitted a new ticket is created.
     */
    #[OA\Post(
        path: '/api/v1/pos/tickets',
        operationId: 'ticketStore',
        summary: 'Create or update a held POS ticket (parked cart)',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['cart'],
                properties: [
                    new OA\Property(property: 'number', type: 'string', nullable: true, description: 'Existing ticket number to update'),
                    new OA\Property(property: 'contact_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'cart', type: 'array', items: new OA\Items(
                        required: ['item_id', 'quantity'],
                        properties: [
                            new OA\Property(property: 'item_id', type: 'integer'),
                            new OA\Property(property: 'quantity', type: 'integer', minimum: 1),
                            new OA\Property(property: 'unit_price', type: 'number', format: 'float', nullable: true),
                            new OA\Property(property: 'note', type: 'string', nullable: true),
                        ]
                    )),
                    new OA\Property(property: 'subtotal_amount', type: 'number', format: 'float', nullable: true),
                    new OA\Property(property: 'discount_amount', type: 'number', format: 'float', nullable: true),
                    new OA\Property(property: 'total_amount', type: 'number', format: 'float', nullable: true),
                    new OA\Property(property: 'note', type: 'string', nullable: true),
                ]
            )
        ),
        tags: ['Tickets'],
        parameters: [
            new OA\Parameter(name: 'X-Tenant-Slug', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Location-Id', in: 'header', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Ticket created or updated',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'ok', type: 'boolean', example: true),
                    new OA\Property(property: 'ticket', type: 'object'),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'number'          => ['nullable', 'string', 'max:20'],
            'contact_id'      => ['nullable', 'integer'],
            'cart'            => ['required', 'array', 'min:1'],
            'cart.*.item_id'  => ['required', 'integer'],
            'cart.*.quantity' => ['required', 'integer', 'min:1'],
            'cart.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'cart.*.note'     => ['nullable', 'string'],
            'subtotal_amount' => ['nullable', 'numeric', 'min:0'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'total_amount'    => ['nullable', 'numeric', 'min:0'],
            'note'            => ['nullable', 'string', 'max:500'],
        ]);

        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('api_tenant');

        // Try to find existing ticket by number for update.
        $ticket = ! empty($data['number'])
            ? PosTicket::where('tenant_id', $tenant->id)->where('number', $data['number'])->first()
            : null;

        if ($ticket) {
            $ticket->update([
                'contact_id'      => $data['contact_id'] ?? $ticket->contact_id,
                'cart'            => $data['cart'],
                'subtotal_amount' => $data['subtotal_amount'] ?? 0,
                'discount_amount' => $data['discount_amount'] ?? 0,
                'total_amount'    => $data['total_amount'] ?? 0,
                'note'            => $data['note'] ?? $ticket->note,
                'held_at'         => now(),
            ]);
        } else {
            // Generate ticket number.
            $max = PosTicket::where('tenant_id', $tenant->id)
                ->where('number', 'like', 'ATT%')
                ->pluck('number')
                ->map(fn ($n) => (int) preg_replace('/\D+/', '', (string) $n))
                ->max() ?? 0;

            $ticket = PosTicket::create([
                'tenant_id'       => $tenant->id,
                'contact_id'      => $data['contact_id'] ?? null,
                'user_id'         => auth()->id(),
                'number'          => 'ATT'.str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT),
                'status'          => 'held',
                'cart'            => $data['cart'],
                'subtotal_amount' => $data['subtotal_amount'] ?? 0,
                'discount_amount' => $data['discount_amount'] ?? 0,
                'total_amount'    => $data['total_amount'] ?? 0,
                'note'            => $data['note'] ?? null,
                'held_at'         => now(),
            ]);
        }

        return response()->json(['ok' => true, 'ticket' => $ticket], 201);
    }

    /** DELETE /api/v1/pos/tickets/{ticket} — discard a held ticket. */
    #[OA\Delete(
        path: '/api/v1/pos/tickets/{ticket}',
        operationId: 'ticketDestroy',
        summary: 'Delete a held POS ticket',
        security: [['bearerAuth' => []]],
        tags: ['Tickets'],
        parameters: [
            new OA\Parameter(name: 'X-Tenant-Slug', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Location-Id', in: 'header', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'ticket', in: 'path', required: true, description: 'Ticket ID', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Ticket deleted',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'ok', type: 'boolean', example: true),
                ])
            ),
            new OA\Response(response: 404, description: 'Ticket not found'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function destroy(Request $request, PosTicket $ticket): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('api_tenant');

        if ($ticket->tenant_id !== $tenant->id) {
            return response()->json(['ok' => false, 'message' => 'Ticket introuvable.'], 404);
        }

        $ticket->delete();

        return response()->json(['ok' => true]);
    }
}
