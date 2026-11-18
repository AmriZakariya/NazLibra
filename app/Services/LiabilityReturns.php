<?php

namespace App\Services;

use App\Exceptions\GiftCardException;
use App\Exceptions\PackageException;
use App\Models\CustomerPackage;
use App\Models\GiftCard;
use App\Models\GiftCardTransaction;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SaleReturn;
use App\Models\Tenant;
use App\Services\GiftCards\GiftCardService;
use App\Services\Packages\PackageService;
use App\Support\Liabilities;

/**
 * What a refund does to gift cards and packages, shared by the till's API and
 * the back office so both behave the same:
 *
 *  - a refunded gift-card line cancels the card, if nobody has spent from it;
 *  - a refunded package line cancels the package, if no session was used;
 *  - a refunded package-use line gives the session back;
 *  - the part of a refund that was paid with a gift card goes back ON THE CARD,
 *    not into the drawer.
 *
 * Call it inside the refund's transaction, after the SaleReturn exists.
 */
class LiabilityReturns
{
    public function __construct(
        private readonly GiftCardService $cards,
        private readonly PackageService $packages,
    ) {}

    /**
     * @param  array<int, array{sale_item_id:int, quantity:int, total_price:float}>  $lines
     * @return float the amount put back on a gift card (to take off the cash refund)
     */
    public function apply(Tenant $tenant, Sale $sale, array $lines, float $returnTotal, SaleReturn $return, bool $isFullRefund, ?int $userId): float
    {
        $sale->loadMissing('items');
        $items = $sale->items->keyBy('id');
        $nonRevenue = 0.0;

        foreach ($lines as $line) {
            $item = $items->get((int) $line['sale_item_id']);
            if (! $item || ! $item->kind) {
                continue;
            }
            $quantity = (int) $line['quantity'];

            if ($item->kind === Liabilities::GIFT_CARD) {
                $card = GiftCard::where('tenant_id', $tenant->id)->where('sale_item_id', $item->id)->lockForUpdate()->first();
                if ($card && $card->status !== GiftCard::CANCELLED) {
                    if ((float) $card->balance + 0.004 < (float) $card->initial_amount) {
                        throw new GiftCardException('La carte cadeau « '.$card->displayCode().' » vendue sur cette ligne a déjà été utilisée : elle ne peut pas être remboursée.', 'already_used');
                    }
                    $this->cards->cancel($tenant, $card, 'Remboursement de la vente '.$sale->number, $userId);
                }
                $nonRevenue += (float) $line['total_price'];
            } elseif ($item->kind === Liabilities::PACKAGE) {
                $owned = CustomerPackage::where('tenant_id', $tenant->id)->where('sale_item_id', $item->id)->lockForUpdate()->first();
                if ($owned && $owned->status !== CustomerPackage::CANCELLED) {
                    if ($owned->sessions_remaining < $owned->sessions_total) {
                        throw new PackageException('Le forfait « '.$owned->name.' » vendu sur cette ligne est déjà entamé : il ne peut pas être remboursé.', 'already_used');
                    }
                    $this->packages->cancel($tenant, $owned, 'Remboursement de la vente '.$sale->number, $userId);
                }
                $nonRevenue += (float) $line['total_price'];
            } elseif ($item->kind === Liabilities::PACKAGE_USE && $item->customer_package_id) {
                $owned = CustomerPackage::where('tenant_id', $tenant->id)->whereKey($item->customer_package_id)->first();
                if ($owned) {
                    $this->packages->restore($tenant, $owned, $quantity, $sale, $item, $return->id, $userId);
                }
            }
        }

        if ($nonRevenue > 0) {
            $return->forceFill(['non_revenue_amount' => round($nonRevenue, 2)])->save();
        }

        return $this->refundToCard($tenant, $sale, $returnTotal, $return, $isFullRefund, $userId);
    }

    /** The share of this refund that was paid by gift card, put back on that card. */
    private function refundToCard(Tenant $tenant, Sale $sale, float $returnTotal, SaleReturn $return, bool $isFullRefund, ?int $userId): float
    {
        $paidByCard = (float) SalePayment::where('sale_id', $sale->id)->where('method', Liabilities::GIFT_CARD_PAYMENT)->sum('amount');
        if ($paidByCard <= 0.004 || (float) $sale->total_amount <= 0.004) {
            return 0.0;
        }

        $spent = GiftCardTransaction::where('sale_id', $sale->id)->where('type', GiftCardTransaction::REDEEM)->orderBy('id')->first();
        $card = $spent ? GiftCard::where('tenant_id', $tenant->id)->whereKey($spent->gift_card_id)->first() : null;
        // A card the shop has voided takes nothing back: the refund stays on
        // the method chosen at the till.
        if (! $card || $card->status === GiftCard::CANCELLED) {
            return 0.0;
        }

        $alreadyBack = (float) GiftCardTransaction::where('sale_id', $sale->id)
            ->where('gift_card_id', $card->id)->where('type', GiftCardTransaction::REFUND)->sum('amount');
        $left = max(0.0, round($paidByCard - $alreadyBack, 2));
        $share = $isFullRefund
            ? $left
            : min($left, round($returnTotal * $paidByCard / (float) $sale->total_amount, 2));
        $share = min($share, $returnTotal);
        if ($share <= 0.004) {
            return 0.0;
        }

        $this->cards->refund($tenant, $card, $share, $sale, $return->id, $userId, 'Remboursement '.$return->number);

        return round($share, 2);
    }
}
