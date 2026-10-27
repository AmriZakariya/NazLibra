<?php

namespace App\Support;

use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * What each person on the team brought in over a period: the barber's
 * services, the commission owed on them, and the tips left for them.
 *
 * A line nobody was named on belongs to whoever rang the sale, which is the
 * right answer in a one-chair shop, where the owner is the barber.
 *
 * Revenue is what the customer paid for the line: a discount on the whole
 * ticket is shared across its lines, so a barber's commission is never on
 * money the shop did not take. A tip is not revenue and earns no commission.
 */
class StaffReport
{
    /** Whether the till asks who did each line: on for barbers and salons, else off, unless the shop says. */
    public static function asksStaff(\App\Models\Tenant $tenant): bool
    {
        $explicit = data_get($tenant->settings, 'pos.ask_staff');
        if ($explicit !== null) {
            return (bool) $explicit;
        }

        return in_array(BusinessMode::current($tenant)['key'], ['barber', 'beauty'], true);
    }

    /**
     * @param  Collection<int, Sale>  $sales  with items (and items.item) loaded
     * @return Collection<int, array{user_id:int|null, name:string, services:int, service_revenue:float, product_revenue:float, revenue:float, commission_rate:float, commission:float, tips:float}>
     */
    public static function rows(Collection $sales): Collection
    {
        $sales = $sales->reject(fn (Sale $sale) => in_array($sale->status, ['cancelled', 'refunded'], true));
        $rows = [];

        $row = function (?int $userId) use (&$rows) {
            $key = $userId ?? 0;

            return $rows[$key] ??= [
                'user_id' => $userId, 'services' => 0,
                'service_revenue' => 0.0, 'product_revenue' => 0.0, 'tips' => 0.0,
            ];
        };

        foreach ($sales as $sale) {
            $lineTotal = (float) $sale->items->sum('total_price');
            // The ticket discount, shared out in proportion to each line.
            $paidShare = $lineTotal > 0 ? (float) $sale->total_amount / $lineTotal : 0.0;

            foreach ($sale->items as $line) {
                $userId = $line->staff_user_id ?? $sale->user_id;
                $row($userId);
                $amount = (float) $line->total_price * $paidShare;
                if ($line->item?->type === 'service') {
                    $rows[$userId ?? 0]['services'] += (int) round((float) $line->quantity);
                    $rows[$userId ?? 0]['service_revenue'] += $amount;
                } else {
                    $rows[$userId ?? 0]['product_revenue'] += $amount;
                }
            }

            if ((float) $sale->tip_amount > 0) {
                $userId = $sale->tip_user_id ?? $sale->user_id;
                $row($userId);
                $rows[$userId ?? 0]['tips'] += (float) $sale->tip_amount;
            }
        }

        $users = User::query()
            ->whereIn('id', array_filter(array_keys($rows)))
            ->get(['id', 'name', 'commission_rate'])
            ->keyBy('id');

        return collect($rows)->map(function (array $r) use ($users) {
            $user = $users->get($r['user_id']);
            $rate = (float) ($user?->commission_rate ?? 0);
            $revenue = $r['service_revenue'] + $r['product_revenue'];

            return [
                'user_id' => $r['user_id'],
                'name' => $user?->name ?? 'Non attribué',
                'services' => $r['services'],
                'service_revenue' => round($r['service_revenue'], 2),
                'product_revenue' => round($r['product_revenue'], 2),
                'revenue' => round($revenue, 2),
                'commission_rate' => $rate,
                'commission' => round($revenue * $rate / 100, 2),
                'tips' => round($r['tips'], 2),
            ];
        })->sortByDesc('revenue')->values();
    }
}
