<?php

namespace App\Http\Controllers;

use App\Exceptions\LiabilityException;
use App\Models\GiftCard;
use App\Models\Tenant;
use App\Services\GiftCards\GiftCardService;
use App\Support\AppModules;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Gift cards in the back office: find a card, read its history, cancel or adjust it. */
class GiftCardWebController extends Controller
{
    public function __construct(private readonly GiftCardService $cards) {}

    public function index(Request $request): View
    {
        $tenant = $this->tenant();
        $q = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', '');

        // A card whose date has passed is expired the moment anyone looks.
        GiftCard::where('tenant_id', $tenant->id)->where('status', GiftCard::ACTIVE)
            ->whereNotNull('expires_at')->whereDate('expires_at', '<', GiftCardService::today($tenant)->toDateString())
            ->update(['status' => GiftCard::EXPIRED]);

        $cards = GiftCard::where('tenant_id', $tenant->id)
            ->when($status !== '' && in_array($status, [GiftCard::ACTIVE, GiftCard::USED, GiftCard::EXPIRED, GiftCard::CANCELLED], true), fn ($query) => $query->where('status', $status))
            ->when($q !== '', function ($query) use ($q) {
                $code = GiftCardService::normalize($q);
                $query->where(fn ($nested) => $nested
                    ->where('code', 'like', '%'.$code.'%')
                    ->orWhere('recipient_name', 'like', '%'.$q.'%')
                    ->orWhere('recipient_phone', 'like', '%'.$q.'%')
                    ->orWhereHas('buyer', fn ($buyer) => $buyer->where('name', 'like', '%'.$q.'%')));
            })
            ->with('buyer:id,name')
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        $outstanding = (float) GiftCard::where('tenant_id', $tenant->id)->where('status', GiftCard::ACTIVE)->sum('balance');

        return view('gift-cards.index', [
            'tenant' => $tenant,
            'cards' => $cards,
            'q' => $q,
            'status' => $status,
            'outstanding' => $outstanding,
            'validityMonths' => GiftCardService::validityMonths($tenant),
        ]);
    }

    public function show(GiftCard $giftCard): View
    {
        $tenant = $this->tenant();
        abort_unless($giftCard->tenant_id === $tenant->id, 404);
        $this->cards->refreshStatus($tenant, $giftCard);

        return view('gift-cards.show', [
            'tenant' => $tenant,
            'card' => $giftCard->load('buyer:id,name', 'sale:id,number'),
            'transactions' => $giftCard->transactions()->with(['user:id,name', 'sale:id,number'])->get()->reverse()->values(),
        ]);
    }

    /** The shop's default validity of a new card, in months (0 = never expires). */
    public function settings(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $request->validate(['validity_months' => ['required', 'integer', 'min:0', 'max:120']]);
        $settings = $tenant->settings ?? [];
        $settings['gift_cards']['validity_months'] = (int) $data['validity_months'];
        $tenant->update(['settings' => $settings]);

        return back()->with('status', 'Validité des cartes cadeaux enregistrée.');
    }

    public function cancel(Request $request, GiftCard $giftCard): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless($giftCard->tenant_id === $tenant->id, 404);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        try {
            $this->cards->cancel($tenant, $giftCard, $data['reason'], $request->user()?->id);
        } catch (LiabilityException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('status', 'Carte cadeau annulée.');
    }

    public function adjust(Request $request, GiftCard $giftCard): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless($giftCard->tenant_id === $tenant->id, 404);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'between:-1000000,1000000', 'not_in:0'],
            'reason' => ['required', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date'],
        ]);
        try {
            $this->cards->adjust($tenant, $giftCard, (float) $data['amount'], $data['reason'], $request->user()?->id, $data['expires_at'] ?? null);
        } catch (LiabilityException $e) {
            return back()->withErrors(['amount' => $e->getMessage()]);
        }

        return back()->with('status', 'Carte cadeau ajustée.');
    }

    /** The web till's lookup: the balance behind a code, before it is applied. */
    public function lookup(Request $request): JsonResponse
    {
        $tenant = $this->tenant();
        if (! AppModules::enabled($tenant, 'gift_cards')) {
            return response()->json(['ok' => false, 'message' => 'Les cartes cadeaux ne sont pas activées dans cette boutique.'], 404);
        }
        $card = $this->cards->find($tenant, (string) $request->query('code'));
        if ($refusal = $this->cards->refusal($tenant, $card)) {
            return response()->json(['ok' => false, 'reason' => $refusal->reason, 'message' => $refusal->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'card' => $this->cards->payload($tenant, $card)]);
    }

    private function tenant(): Tenant
    {
        $tenant = TenantContext::require(request(), auth()->user());
        abort_unless(AppModules::enabled($tenant, 'gift_cards'), 404);

        return $tenant;
    }
}
