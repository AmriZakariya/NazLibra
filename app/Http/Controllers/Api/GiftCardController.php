<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GiftCards\GiftCardService;
use App\Support\AppModules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The till's gift-card lookup: the balance behind a code, before the cashier
 * applies it. Needs the server — the balance lives here — so a till that is
 * offline gets no answer and says so instead of accepting a code blindly.
 */
class GiftCardController extends Controller
{
    public function __construct(private readonly GiftCardService $cards) {}

    /** GET /api/v1/gift-cards/{code} */
    public function show(Request $request, string $code): JsonResponse
    {
        $tenant = $request->attributes->get('api_tenant');
        if (! AppModules::enabled($tenant, 'gift_cards')) {
            return response()->json(['ok' => false, 'error' => 'gift_cards_disabled', 'message' => 'Les cartes cadeaux ne sont pas activées dans cette boutique.'], 404);
        }

        $card = $this->cards->find($tenant, $code);
        if ($refusal = $this->cards->refusal($tenant, $card)) {
            // An unknown code is a 404; a known card that cannot pay (spent,
            // expired, cancelled) is told apart so the till can say why.
            return response()->json([
                'ok' => false,
                'error' => 'gift_card_'.$refusal->reason,
                'message' => $refusal->getMessage(),
                'card' => $card ? $this->cards->payload($tenant, $card) : null,
            ], $card ? 422 : 404);
        }

        return response()->json(['ok' => true, 'card' => $this->cards->payload($tenant, $card)]);
    }
}
