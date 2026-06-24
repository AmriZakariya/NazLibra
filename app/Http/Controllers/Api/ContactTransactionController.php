<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ContactTransaction;
use App\Rules\ExplicitOffsetDateTime;
use App\Support\UtcDateTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ContactTransactionController extends Controller
{
    /**
     * List all transactions for a contact (paginated, newest first).
     */
    public function index(Request $request, Contact $contact): JsonResponse
    {
        $tenant = $request->attributes->get('api_tenant');

        if ((int) $contact->tenant_id !== (int) $tenant->id) {
            return response()->json(['ok' => false, 'message' => 'Accès refusé.'], 403);
        }

        $transactions = ContactTransaction::where('contact_id', $contact->id)
            ->orderByDesc('recorded_at')
            ->get(['id', 'contact_id', 'tenant_id', 'type', 'amount', 'note', 'recorded_at', 'updated_at']);

        return response()->json([
            'ok'           => true,
            'transactions' => $transactions,
        ]);
    }

    /**
     * Create a new transaction and update the contact balance atomically.
     *
     * Type semantics (from the business owner's perspective):
     *   Client  — gave → outstanding_balance +amount (client owes more)
     *   Client  — got  → outstanding_balance -amount (client paid)
     *   Supplier— gave → outstanding_balance -amount (we paid the supplier)
     *   Supplier— got  → outstanding_balance +amount (supplier gave us goods)
     */
    public function store(Request $request, Contact $contact): JsonResponse
    {
        $tenant = $request->attributes->get('api_tenant');

        if ((int) $contact->tenant_id !== (int) $tenant->id) {
            return response()->json(['ok' => false, 'message' => 'Accès refusé.'], 403);
        }

        $validated = $request->validate([
            'type'             => 'required|in:gave,got',
            'amount'           => 'required|numeric|min:0.01',
            'note'             => 'nullable|string|max:500',
            'recorded_at'      => ['nullable', 'string', new ExplicitOffsetDateTime],
            'idempotency_key'  => 'required|string|max:64',
        ]);

        $recordedAt = ! empty($validated['recorded_at']) ? UtcDateTime::parse($validated['recorded_at']) : now()->utc();
        $requestHash = hash('sha256', json_encode([
            'contact_id' => $contact->id,
            'type' => $validated['type'],
            'amount' => number_format((float) $validated['amount'], 2, '.', ''),
            'note' => $validated['note'] ?? null,
            'recorded_at' => isset($validated['recorded_at']) ? UtcDateTime::format($recordedAt) : null,
        ], JSON_THROW_ON_ERROR));

        // Idempotency — return existing if already processed.
        $existing = ContactTransaction::where('tenant_id', $tenant->id)
            ->where('idempotency_key', $validated['idempotency_key'])
            ->first();
        if ($existing) {
            if ((int) $existing->contact_id !== (int) $contact->id || ($existing->request_hash && ! hash_equals($existing->request_hash, $requestHash))) {
                return response()->json(['ok' => false, 'error' => 'idempotency_conflict', 'message' => 'Cette clé a déjà été utilisée avec une autre opération.'], 409);
            }

            return response()->json(['ok' => true, 'already_existed' => true, 'transaction' => $existing]);
        }

        try {
            $tx = DB::transaction(function () use ($contact, $validated, $tenant, $requestHash, $recordedAt) {
                $contact = Contact::where('tenant_id', $tenant->id)->whereKey($contact->id)->lockForUpdate()->firstOrFail();
                $tx = ContactTransaction::create([
                    'tenant_id'       => $tenant->id,
                    'contact_id'      => $contact->id,
                    'type'            => $validated['type'],
                    'amount'          => $validated['amount'],
                    'note'            => $validated['note'] ?? null,
                    'idempotency_key' => $validated['idempotency_key'] ?? null,
                    'request_hash'    => $requestHash,
                    'recorded_at'     => $recordedAt,
                ]);

                $this->applyBalanceEffect($contact, $tx);

                return $tx;
            });
        } catch (QueryException $exception) {
            $existing = ContactTransaction::where('tenant_id', $tenant->id)
                ->where('idempotency_key', $validated['idempotency_key'])
                ->first();
            if (! $existing) {
                throw $exception;
            }
            if ((int) $existing->contact_id !== (int) $contact->id || ($existing->request_hash && ! hash_equals($existing->request_hash, $requestHash))) {
                return response()->json(['ok' => false, 'error' => 'idempotency_conflict', 'message' => 'Cette clé a déjà été utilisée avec une autre opération.'], 409);
            }

            return response()->json(['ok' => true, 'already_existed' => true, 'transaction' => $existing]);
        }

        return response()->json(['ok' => true, 'transaction' => $tx], 201);
    }

    // ── Balance update ──────────────────────────────────────────────────────────

    private function applyBalanceEffect(Contact $contact, ContactTransaction $tx): void
    {
        $isClient   = $contact->kind === 'client';
        $amount     = (float) $tx->amount;
        $outstanding = (float) $contact->outstanding_balance;
        $advance     = (float) $contact->advance_balance;

        if ($isClient) {
            if ($tx->type === 'gave') {
                // Gave credit/goods to client → they owe us more.
                // First consume any advance, then increase outstanding.
                if ($advance >= $amount) {
                    $contact->advance_balance = $advance - $amount;
                } else {
                    $remaining = $amount - $advance;
                    $contact->advance_balance = 0;
                    $contact->outstanding_balance = $outstanding + $remaining;
                }
            } else {
                // Received payment from client → reduces what they owe.
                if ($outstanding >= $amount) {
                    $contact->outstanding_balance = $outstanding - $amount;
                } else {
                    $excess = $amount - $outstanding;
                    $contact->outstanding_balance = 0;
                    $contact->advance_balance = $advance + $excess;
                }
            }
        } else {
            // Supplier
            if ($tx->type === 'got') {
                // Received goods from supplier → we owe them more.
                $contact->outstanding_balance = $outstanding + $amount;
            } else {
                // Paid the supplier → reduces what we owe.
                $contact->outstanding_balance = max(0, $outstanding - $amount);
            }
        }

        $contact->save();
    }
}
