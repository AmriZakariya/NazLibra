<?php

namespace App\Support;

use App\Models\Sale;
use App\Models\Tenant;
use App\Models\TipPayout;
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
    public static function asksStaff(Tenant $tenant): bool
    {
        $explicit = data_get($tenant->settings, 'pos.ask_staff');
        if ($explicit !== null) {
            return (bool) $explicit;
        }

        return in_array(BusinessMode::current($tenant)['key'], ['barber', 'beauty'], true);
    }

    /** Whether the till offers a tip at checkout. Same default as asking who did the cut. */
    public static function tipsEnabled(Tenant $tenant): bool
    {
        $explicit = data_get($tenant->settings, 'pos.tips_enabled');

        return $explicit !== null ? (bool) $explicit : self::asksStaff($tenant);
    }

    /**
     * Tips still owed to each person: every tip left for them, less what was
     * handed over. All time, not the report's period — a tip from last month
     * not yet paid is still owed today.
     *
     * @return array<int, float> user id => amount owed
     */
    public static function tipsOwed(int $tenantId): array
    {
        $earned = Sale::query()
            ->where('tenant_id', $tenantId)
            ->where('status', '!=', 'cancelled')
            ->where('tip_amount', '>', 0)
            ->whereNotNull('tip_user_id')
            ->groupBy('tip_user_id')
            ->selectRaw('tip_user_id AS user_id, SUM(tip_amount) AS total')
            ->pluck('total', 'user_id');

        $paid = TipPayout::query()
            ->where('tenant_id', $tenantId)
            ->groupBy('user_id')
            ->selectRaw('user_id, SUM(amount) AS total')
            ->pluck('total', 'user_id');

        $owed = [];
        foreach ($earned->keys()->merge($paid->keys())->unique() as $userId) {
            $owed[(int) $userId] = round((float) ($earned[$userId] ?? 0) - (float) ($paid[$userId] ?? 0), 2);
        }

        return $owed;
    }

    /**
     * @param  Collection<int, Sale>  $sales  with items (and items.item) loaded
     * @param  array<int, float>|null  $owed  from tipsOwed(); adds "tips_owed" to each row
     * @return Collection<int, array{user_id:int|null, name:string, services:int, service_revenue:float, product_revenue:float, revenue:float, commission_rate:float, commission:float, tips:float, tips_owed:float}>
     */
    public static function rows(Collection $sales, ?array $owed = null): Collection
    {
        // A cancelled sale never happened. A refunded one gave the services
        // back, not the tip: the work was done, and the barber keeps it.
        $sales = $sales->reject(fn (Sale $sale) => $sale->status === 'cancelled');
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
            $saleTotal = (float) $sale->total_amount;
            // What the shop kept: a refund takes its share back from every
            // line, so no commission is paid on money returned.
            $returned = in_array($sale->status, ['refunded', 'partially_refunded'], true)
                ? min($saleTotal, (float) $sale->returns()->sum('total_amount'))
                : 0.0;
            if ($sale->status === 'refunded') {
                $returned = $saleTotal;
            }
            // The ticket discount, shared out in proportion to each line.
            $paidShare = $lineTotal > 0 ? ($saleTotal - $returned) / $lineTotal : 0.0;

            foreach ($sale->items as $line) {
                $userId = $line->staff_user_id ?? $sale->user_id;
                $row($userId);
                $amount = (float) $line->total_price * $paidShare;
                if ($line->item?->type === 'service') {
                    if ($sale->status !== 'refunded') {
                        $rows[$userId ?? 0]['services'] += (int) round((float) $line->quantity);
                    }
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

        // Someone still owed tips appears even in a quiet period.
        foreach ($owed ?? [] as $userId => $amount) {
            if ($amount > 0.001) {
                $row($userId);
            }
        }

        $users = User::query()
            ->whereIn('id', array_filter(array_keys($rows)))
            ->get(['id', 'name', 'commission_rate'])
            ->keyBy('id');

        return collect($rows)->map(function (array $r) use ($users, $owed) {
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
                'tips_owed' => round(max(0.0, (float) ($owed[$r['user_id'] ?? 0] ?? 0)), 2),
            ];
        })->sortByDesc('revenue')->values();
    }
}
