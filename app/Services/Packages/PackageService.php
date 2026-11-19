<?php

namespace App\Services\Packages;

use App\Exceptions\PackageException;
use App\Models\CustomerPackage;
use App\Models\CustomerPackageMovement;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\ServicePackage;
use App\Models\Tenant;
use App\Support\TenantClock;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Prepaid packages: selling one to a customer and using its sessions.
 *
 * Like a gift card balance, the sessions left are only ever moved by ONE
 * conditional UPDATE (`sessions_remaining >= n`), inside the sale's own
 * transaction: a sale saved twice (offline retry) or two tills using the last
 * session at once cannot overdraw it.
 */
class PackageService
{
    public static function today(Tenant $tenant): CarbonInterface
    {
        return now(TenantClock::timezone($tenant))->startOfDay();
    }

    public function sell(Tenant $tenant, Sale $sale, SaleItem $line, ServicePackage $package, ?int $userId = null): CustomerPackage
    {
        $existing = CustomerPackage::where('tenant_id', $tenant->id)->where('sale_item_id', $line->id)->first();
        if ($existing) {
            return $existing;
        }
        if (! $sale->contact_id) {
            throw new PackageException('Sélectionnez un client pour vendre un forfait.', 'contact_required');
        }

        $soldAt = $sale->sold_at ?? now();
        $expires = $package->validity_days
            ? $soldAt->copy()->setTimezone(TenantClock::timezone($tenant))->startOfDay()->addDays((int) $package->validity_days)->toDateString()
            : null;

        $owned = CustomerPackage::create([
            'tenant_id' => $tenant->id,
            'contact_id' => $sale->contact_id,
            'package_id' => $package->id,
            'name' => $package->name,
            'item_id' => $package->item_id,
            'variant_id' => $package->variant_id,
            'sessions_total' => $package->sessions,
            'sessions_remaining' => $package->sessions,
            'price_paid' => (float) $line->total_price,
            // Paid, not listed: a package takes no discount at the till, so the
            // line is what the customer handed over. A session is worth a share.
            'session_value' => $package->sessions > 0 ? round((float) $line->total_price / $package->sessions, 4) : null,
            'expires_at' => $expires,
            'status' => CustomerPackage::ACTIVE,
            'sale_id' => $sale->id,
            'sale_item_id' => $line->id,
            'sold_at' => $soldAt,
        ]);
        $this->record($owned, 'sale', $package->sessions, $sale->id, $line->id, null, $userId, 'Vente '.$sale->number);

        return $owned;
    }

    public function find(Tenant $tenant, int $id, bool $lock = false): ?CustomerPackage
    {
        $query = CustomerPackage::where('tenant_id', $tenant->id)->whereKey($id);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    /** Marks a package whose date has passed as expired. */
    public function refreshStatus(Tenant $tenant, CustomerPackage $package): CustomerPackage
    {
        if ($package->status === CustomerPackage::ACTIVE && $package->expires_at !== null
            && $package->expires_at->toDateString() < self::today($tenant)->toDateString()) {
            CustomerPackage::whereKey($package->id)->where('status', CustomerPackage::ACTIVE)->update(['status' => CustomerPackage::EXPIRED]);
            $package->refresh();
        }

        return $package;
    }

    /** Why `$sessions` of this package cannot be used for this service and customer, or null. */
    public function refusal(Tenant $tenant, ?CustomerPackage $package, int $sessions, ?int $contactId, ?int $itemId, ?int $variantId): ?PackageException
    {
        if ($package === null) {
            return new PackageException('Forfait introuvable.', 'not_found');
        }
        $package = $this->refreshStatus($tenant, $package);

        return match (true) {
            $contactId === null || (int) $package->contact_id !== $contactId => new PackageException('Ce forfait appartient à un autre client.', 'other_customer'),
            $itemId === null || (int) $package->item_id !== $itemId => new PackageException('Ce forfait ne couvre pas ce service.', 'other_service'),
            $package->variant_id !== null && (int) $package->variant_id !== (int) $variantId => new PackageException('Ce forfait ne couvre pas cette variante du service.', 'other_variant'),
            $package->status === CustomerPackage::CANCELLED => new PackageException('Ce forfait a été annulé.', 'cancelled'),
            $package->status === CustomerPackage::EXPIRED => new PackageException('Ce forfait a expiré le '.$package->expires_at?->format('d/m/Y').'.', 'expired'),
            $package->status === CustomerPackage::USED || $package->sessions_remaining <= 0 => new PackageException('Ce forfait n’a plus de séance.', 'used'),
            $sessions > $package->sessions_remaining => new PackageException('Il ne reste que '.$package->sessions_remaining.' séance(s) sur ce forfait.', 'insufficient_sessions'),
            default => null,
        };
    }

    /** Uses `$sessions` for a sale line. Call inside the sale's transaction. */
    public function consume(Tenant $tenant, int $customerPackageId, int $sessions, Sale $sale, SaleItem $line, ?int $userId = null): CustomerPackage
    {
        $sessions = max(1, $sessions);

        return DB::transaction(function () use ($tenant, $customerPackageId, $sessions, $sale, $line, $userId): CustomerPackage {
            $package = $this->find($tenant, $customerPackageId, lock: true);
            if ($refusal = $this->refusal($tenant, $package, $sessions, $sale->contact_id ? (int) $sale->contact_id : null, $line->item_id ? (int) $line->item_id : null, $line->variant_id ? (int) $line->variant_id : null)) {
                throw $refusal;
            }

            $used = CustomerPackage::whereKey($package->id)
                ->where('status', CustomerPackage::ACTIVE)
                ->where('sessions_remaining', '>=', $sessions)
                ->update(['sessions_remaining' => DB::raw('sessions_remaining - '.$sessions), 'updated_at' => now()]);
            if ($used !== 1) {
                throw new PackageException('Ce forfait n’a plus assez de séances.', 'insufficient_sessions');
            }

            $package->refresh();
            if ($package->sessions_remaining <= 0) {
                $package->update(['status' => CustomerPackage::USED]);
            }
            $this->record($package, 'use', -$sessions, $sale->id, $line->id, null, $userId, 'Vente '.$sale->number);

            return $package;
        });
    }

    /** Gives sessions back (a refunded line). */
    public function restore(Tenant $tenant, CustomerPackage $package, int $sessions, Sale $sale, ?SaleItem $line, ?int $saleReturnId, ?int $userId = null): CustomerPackage
    {
        return DB::transaction(function () use ($tenant, $package, $sessions, $sale, $line, $saleReturnId, $userId): CustomerPackage {
            $locked = $this->find($tenant, $package->id, lock: true);
            CustomerPackage::whereKey($locked->id)->update([
                'sessions_remaining' => DB::raw('sessions_remaining + '.$sessions),
                'updated_at' => now(),
            ]);
            $locked->refresh();

            // Sessions coming back re-open a used package, and a month is
            // added when its date has passed so they can be spent.
            if ($locked->status !== CustomerPackage::CANCELLED) {
                $updates = ['status' => CustomerPackage::ACTIVE];
                $today = self::today($tenant);
                if ($locked->expires_at !== null && $locked->expires_at->toDateString() < $today->toDateString()) {
                    $updates['expires_at'] = $today->copy()->addMonth()->toDateString();
                }
                $locked->update($updates);
            }
            $this->record($locked, 'refund', $sessions, $sale->id, $line?->id, $saleReturnId, $userId, 'Remboursement vente '.$sale->number);

            return $locked;
        });
    }

    /** A manual correction in sessions, always with a reason. */
    public function adjust(Tenant $tenant, CustomerPackage $package, int $delta, string $reason, ?int $userId = null, ?string $expiresAt = null): CustomerPackageMovement
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new PackageException('Indiquez le motif de l’ajustement.', 'reason_required');
        }

        return DB::transaction(function () use ($tenant, $package, $delta, $reason, $userId, $expiresAt): CustomerPackageMovement {
            $locked = $this->find($tenant, $package->id, lock: true);
            if ($locked->status === CustomerPackage::CANCELLED) {
                throw new PackageException('Un forfait annulé ne peut plus être ajusté.', 'cancelled');
            }
            if ($locked->sessions_remaining + $delta < 0) {
                throw new PackageException('L’ajustement dépasse les séances restantes.', 'insufficient_sessions');
            }

            $locked->sessions_remaining += $delta;
            if ($expiresAt !== null) {
                $locked->expires_at = $expiresAt === '' ? null : $expiresAt;
            }
            if ($locked->sessions_remaining <= 0) {
                $locked->status = CustomerPackage::USED;
            } elseif (in_array($locked->status, [CustomerPackage::USED, CustomerPackage::EXPIRED], true)) {
                $notPast = $locked->expires_at === null || $locked->expires_at->toDateString() >= self::today($tenant)->toDateString();
                $locked->status = $notPast ? CustomerPackage::ACTIVE : CustomerPackage::EXPIRED;
            }
            $locked->save();
            $package->refresh();

            return $this->record($locked, 'adjust', $delta, null, null, null, $userId, $reason);
        });
    }

    public function cancel(Tenant $tenant, CustomerPackage $package, string $reason, ?int $userId = null): CustomerPackageMovement
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new PackageException('Indiquez le motif de l’annulation.', 'reason_required');
        }

        return DB::transaction(function () use ($tenant, $package, $reason, $userId): CustomerPackageMovement {
            $locked = $this->find($tenant, $package->id, lock: true);
            if ($locked->status === CustomerPackage::CANCELLED) {
                throw new PackageException('Ce forfait est déjà annulé.', 'cancelled');
            }
            $left = (int) $locked->sessions_remaining;
            $locked->update(['status' => CustomerPackage::CANCELLED, 'sessions_remaining' => 0]);
            $package->refresh();

            return $this->record($locked, 'cancel', -$left, null, null, null, $userId, $reason);
        });
    }

    /** What the till needs to offer « utiliser le forfait » for a customer. */
    public function payload(Tenant $tenant, CustomerPackage $package): array
    {
        $package = $this->refreshStatus($tenant, $package);

        return [
            'id' => $package->id,
            'contact_id' => $package->contact_id,
            'package_id' => $package->package_id,
            'name' => $package->name,
            'item_id' => $package->item_id,
            'variant_id' => $package->variant_id,
            'sessions_total' => $package->sessions_total,
            'sessions_remaining' => $package->sessions_remaining,
            'expires_at' => $package->expires_at?->toDateString(),
            'status' => $package->status,
        ];
    }

    private function record(CustomerPackage $package, string $type, int $sessions, ?int $saleId, ?int $saleItemId, ?int $saleReturnId, ?int $userId, ?string $reason): CustomerPackageMovement
    {
        return CustomerPackageMovement::create([
            'tenant_id' => $package->tenant_id,
            'customer_package_id' => $package->id,
            'type' => $type,
            'sessions' => $sessions,
            'remaining_after' => max(0, (int) $package->sessions_remaining),
            'sale_id' => $saleId,
            'sale_item_id' => $saleItemId,
            'sale_return_id' => $saleReturnId,
            'user_id' => $userId,
            'reason' => $reason ? mb_substr($reason, 0, 255) : null,
        ]);
    }
}
