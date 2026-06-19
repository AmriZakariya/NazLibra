<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
