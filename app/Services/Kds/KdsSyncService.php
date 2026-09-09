<?php

namespace App\Services\Kds;

use App\Models\KdsFire;
use App\Models\KdsLine;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Accepts kitchen fires and dishes pushed up from POS devices.
 *
 * The LAN is authoritative during service, so this is a mirror kept for
 * reporting. Two rules are carried over from the app so the cloud cannot
 * disagree with the tills (see naz_pos core/kds/kds_merge.dart):
 *
 *  * **A fire is immutable.** An id already stored is left alone rather than
 *    rewritten. Ids are the client's own and deterministic, so a device that
 *    re-pushes — or two tills that fired the same delta — land on one row.
 *  * **Status is a monotonic join, not last-write-wins.** Batches arrive out
 *    of order and more than once, from several devices. Taking the
 *    furthest-advanced status means a late "cooking" cannot un-plate a dish
 *    that is already ready; last-write-wins would, and the report would then
 *    show prep times that never happened.
 */
class KdsSyncService
{
    /**
     * @param  array<int, array<string, mixed>>  $fires
     * @param  array<int, array<string, mixed>>  $lines
     * @return array{fires: int, lines: int, skipped: int}
     */
    public function sync(
        Tenant $tenant,
        array $fires,
        array $lines,
        ?int $locationId = null,
    ): array {
        $skipped = 0;
        $firesWritten = 0;
        $linesWritten = 0;

        DB::transaction(function () use (
            $tenant, $fires, $lines, $locationId,
            &$skipped, &$firesWritten, &$linesWritten
        ) {
            // Fires first: a dish whose fire is missing has no course or table
            // to be reported against.
            foreach ($fires as $row) {
                $written = $this->storeFire($tenant, $row, $locationId);
                $written ? $firesWritten++ : $skipped++;
            }

            foreach ($lines as $row) {
                $written = $this->storeLine($tenant, $row);
                $written ? $linesWritten++ : $skipped++;
            }
        });

        return [
            'fires' => $firesWritten,
            'lines' => $linesWritten,
            'skipped' => $skipped,
        ];
    }

    /** @param array<string, mixed> $row */
    private function storeFire(Tenant $tenant, array $row, ?int $locationId): bool
    {
        $id = $this->str($row, 'id');
        $ticketId = $this->str($row, 'ticket_id');
        $firedAt = $this->time($row, 'fired_at');

        // A half-formed row from an older build must not become a fire the
        // report then counts.
        if ($id === null || $ticketId === null || $firedAt === null) {
            return false;
        }

        $existing = KdsFire::query()
            ->where('tenant_id', $tenant->id)
            ->find($id);

        // Immutable: already stored is already correct.
        if ($existing !== null) {
            return false;
        }

        KdsFire::query()->create([
            'id' => $id,
            'tenant_id' => $tenant->id,
            'location_id' => $locationId,
            'ticket_id' => $ticketId,
            'ticket_number' => $this->str($row, 'ticket_number'),
            'station_id' => $this->str($row, 'station_id'),
            'station_name' => $this->str($row, 'station_name'),
            'fire_seq' => (int) ($row['fire_seq'] ?? 1),
            'fired_at' => $firedAt,
            'fired_by_user_name' => $this->str($row, 'fired_by_user_name'),
            'table_label' => $this->str($row, 'table_label'),
            'origin_device_id' => $this->str($row, 'origin_device_id'),
            'revision' => (int) ($row['revision'] ?? 0),
            'client_updated_at' => $this->time($row, 'updated_at'),
        ]);

        return true;
    }

    /** @param array<string, mixed> $row */
    private function storeLine(Tenant $tenant, array $row): bool
    {
        $id = $this->str($row, 'id');
        $ticketId = $this->str($row, 'ticket_id');
        $cartLineId = $this->str($row, 'cart_line_id');
        $name = $this->str($row, 'name');
        $firedAt = $this->time($row, 'fired_at');

        if ($id === null || $ticketId === null || $cartLineId === null
            || $name === null || $firedAt === null) {
            return false;
        }

        $status = $this->status($row);
        $existing = KdsLine::query()
            ->where('tenant_id', $tenant->id)
            ->find($id);

        if ($existing === null) {
            KdsLine::query()->create([
                'id' => $id,
                'tenant_id' => $tenant->id,
                'fire_id' => (string) ($row['fire_id'] ?? ''),
                'ticket_id' => $ticketId,
                'station_id' => $this->str($row, 'station_id'),
                'cart_line_id' => $cartLineId,
                'item_id' => $this->itemId($tenant, $row),
                'name' => $name,
                'quantity' => (float) ($row['quantity'] ?? 1),
                'note' => $this->str($row, 'note'),
                'status' => $status,
                'status_at' => $this->time($row, 'status_at'),
                'status_by_user_name' => $this->str($row, 'status_by_user_name'),
                'cancel_was_started' => (bool) ($row['cancel_was_started'] ?? false),
                'fired_at' => $firedAt,
                'origin_device_id' => $this->str($row, 'origin_device_id'),
                'revision' => (int) ($row['revision'] ?? 0),
                'client_updated_at' => $this->time($row, 'updated_at'),
            ]);

            return true;
        }

        $merged = $this->joinStatus($existing->status, $status);

        // Nothing advanced, so nothing to write. Keeps a re-pushed batch from
        // touching every row it mentions.
        if ($merged === $existing->status) {
            return false;
        }

        $existing->update([
            'status' => $merged,
            // The stamp travels with the status it belongs to, so the report
            // measures the kitchen rather than when the packet arrived.
            'status_at' => $this->time($row, 'status_at') ?? $existing->status_at,
            'status_by_user_name' => $this->str($row, 'status_by_user_name')
                ?? $existing->status_by_user_name,
            'cancel_was_started' => $merged === 'cancelled'
                ? (bool) ($row['cancel_was_started'] ?? $existing->cancel_was_started)
                : $existing->cancel_was_started,
            'revision' => max($existing->revision, (int) ($row['revision'] ?? 0)),
            'client_updated_at' => $this->time($row, 'updated_at')
                ?? $existing->client_updated_at,
        ]);

        return true;
    }

    /**
     * Cancellation dominates; otherwise the furthest-advanced status wins.
     *
     * Both halves are commutative and idempotent, which is what makes the
     * merge safe under the arbitrary, repeating delivery order of a queue that
     * several devices push to.
     */
    public function joinStatus(?string $a, ?string $b): string
    {
        if ($a === 'cancelled' || $b === 'cancelled') {
            return 'cancelled';
        }

        return KdsLine::statusRank($a) >= KdsLine::statusRank($b)
            ? (string) ($a ?? 'pending')
            : (string) ($b ?? 'pending');
    }

    /** @param array<string, mixed> $row */
    private function status(array $row): string
    {
        $status = $this->str($row, 'status');

        // An unknown status must not vanish from the report; pending keeps the
        // dish visible and countable.
        return in_array($status, KdsLine::STATUSES, true) ? $status : 'pending';
    }

    /**
     * Resolves the catalogue item, but only within this tenant.
     *
     * A client id that belongs to nobody, or to another tenant, is stored as
     * null rather than being trusted: the dish name is snapshotted on the row,
     * so the report survives without the link.
     *
     * @param  array<string, mixed>  $row
     */
    private function itemId(Tenant $tenant, array $row): ?int
    {
        $raw = $row['item_id'] ?? null;
        if ($raw === null || $raw === '' || ! is_numeric($raw)) {
            return null;
        }

        $exists = DB::table('items')
            ->where('id', (int) $raw)
            ->where('tenant_id', $tenant->id)
            ->exists();

        return $exists ? (int) $raw : null;
    }

    /** @param array<string, mixed> $row */
    private function str(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** @param array<string, mixed> $row */
    private function time(array $row, string $key): ?Carbon
    {
        $value = $this->str($row, $key);
        if ($value === null) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
