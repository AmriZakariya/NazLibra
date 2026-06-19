<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Quick contact (customer) management from the mobile app.
 *
 * Cashiers frequently need to create or look up a customer on the fly
 * during a sale without leaving the POS screen.
 */
class ContactController extends Controller
{
    /**
     * GET /api/v1/contacts?q=<name|phone|email>
     *
     * Search customers for the autocomplete field in the POS cart.
     */
    #[OA\Get(
        path: '/api/v1/contacts',
        operationId: 'contactIndex',
        summary: 'Search contacts (customers) for autocomplete',
        security: [['bearerAuth' => []]],
        tags: ['Contacts'],
        parameters: [
            new OA\Parameter(name: 'X-Tenant-Slug', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Location-Id', in: 'header', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'q', in: 'query', required: false, description: 'Search term (name, phone, or email)', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Matching contacts (max 30)',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'ok', type: 'boolean', example: true),
                    new OA\Property(property: 'contacts', type: 'array', items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'id', type: 'integer'),
                            new OA\Property(property: 'name', type: 'string'),
                            new OA\Property(property: 'phone', type: 'string', nullable: true),
                            new OA\Property(property: 'email', type: 'string', nullable: true),
                            new OA\Property(property: 'advance_balance', type: 'number'),
                            new OA\Property(property: 'credit_balance', type: 'number'),
                        ]
                    )),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('api_tenant');
        $q      = trim((string) $request->query('q', ''));

        $query = Contact::query()
            ->where('tenant_id', $tenant->id)
            ->where('type', 'customer');

        if ($q !== '') {
            $query->where(fn ($q2) => $q2
                ->where('name', 'like', "%{$q}%")
                ->orWhere('phone', 'like', "%{$q}%")
                ->orWhere('email', 'like', "%{$q}%"));
        }

        $contacts = $query->limit(30)->get([
            'id', 'name', 'phone', 'email', 'advance_balance', 'credit_balance',
        ]);

        return response()->json(['ok' => true, 'contacts' => $contacts]);
    }

    /** GET /api/v1/contacts/{contact} */
    #[OA\Get(
        path: '/api/v1/contacts/{contact}',
        operationId: 'contactShow',
        summary: 'Get a single contact by ID',
        security: [['bearerAuth' => []]],
        tags: ['Contacts'],
        parameters: [
            new OA\Parameter(name: 'X-Tenant-Slug', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Location-Id', in: 'header', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'contact', in: 'path', required: true, description: 'Contact ID', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Contact detail',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'ok', type: 'boolean', example: true),
                    new OA\Property(property: 'contact', type: 'object'),
                ])
            ),
            new OA\Response(response: 404, description: 'Contact not found'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function show(Request $request, Contact $contact): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('api_tenant');

        if ($contact->tenant_id !== $tenant->id) {
            return response()->json(['ok' => false, 'message' => 'Client introuvable.'], 404);
        }

        return response()->json(['ok' => true, 'contact' => $contact]);
    }

    /**
     * POST /api/v1/contacts — quick-create a customer during a sale.
     *
     * Only name is required; everything else is optional.
     */
    #[OA\Post(
        path: '/api/v1/contacts',
        operationId: 'contactStore',
        summary: 'Quick-create a customer contact',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 150, example: 'John Doe'),
                    new OA\Property(property: 'phone', type: 'string', nullable: true, example: '+212600000000'),
                    new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true),
                    new OA\Property(property: 'address', type: 'string', nullable: true),
                ]
            )
        ),
        tags: ['Contacts'],
        parameters: [
            new OA\Parameter(name: 'X-Tenant-Slug', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Location-Id', in: 'header', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Contact created',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'ok', type: 'boolean', example: true),
                    new OA\Property(property: 'contact', type: 'object'),
                ])
            ),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'    => ['required', 'string', 'max:150'],
            'phone'   => ['nullable', 'string', 'max:30'],
            'email'   => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:300'],
        ]);

        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('api_tenant');

        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'type'      => 'customer',
            'name'      => $data['name'],
            'phone'     => $data['phone'] ?? null,
            'email'     => $data['email'] ?? null,
            'address'   => $data['address'] ?? null,
        ]);

        return response()->json(['ok' => true, 'contact' => $contact], 201);
    }
}
