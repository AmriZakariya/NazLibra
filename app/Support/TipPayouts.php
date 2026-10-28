<?php

namespace App\Support;

use App\Models\Tenant;
use App\Models\TipPayout;
use App\Models\User;
use App\Services\CashRegisterService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Handing a barber their tips.
 *
 * Paid in cash, the money leaves the drawer: it is written as a drawer
 * movement too, or the evening count would be short by exactly the tips
 * and nobody would know why.
 */
class TipPayouts
{
    public static function pay(Tenant $tenant, User $barber, float $amount, ?User $by, string $method = 'cash', ?string $note = null): TipPayout
    {
        $amount = round($amount, 2);
        $owed = StaffReport::tipsOwed($tenant->id)[$barber->id] ?? 0.0;

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Indiquez un montant.']);
        }
        if ($amount > $owed + 0.009) {
            throw ValidationException::withMessages([
                'amount' => 'Il reste '.Money::for($tenant)->format($owed).' de pourboires à verser à '.$barber->name.'.',
            ]);
        }

        return DB::transaction(function () use ($tenant, $barber, $amount, $by, $method, $note) {
            $movementId = null;
            if ($method === 'cash') {
                $cash = app(CashRegisterService::class);
                if ($session = $cash->openSession($tenant, true)) {
                    $movementId = $cash->recordMovement($tenant, $session, 'tip_payout', 'out', $amount, [
                        'user_id' => $by?->id,
                        'note' => 'Pourboires versés à '.$barber->name,
                        'metadata' => ['staff_user_id' => $barber->id],
                    ])->id;
                }
            }

            return TipPayout::create([
                'tenant_id' => $tenant->id,
                'user_id' => $barber->id,
                'amount' => $amount,
                'method' => $method,
                'paid_at' => now(),
                'paid_by_user_id' => $by?->id,
                'cash_register_movement_id' => $movementId,
                'note' => $note,
            ]);
        });
    }
}
