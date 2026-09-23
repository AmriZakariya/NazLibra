<?php

namespace App\Services\Inventory;

use App\Models\Item;
use App\Models\Location;
use App\Models\StockTransfer;
use App\Models\Tenant;
use App\Services\Documents\DocumentNumberGenerator;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moving stock from one location to another.
 *
 * This used to live in the controller and take the two ends as TYPED NAMES,
 * fuzzy-matched against the location table with a fallback to the default
 * location. Two things followed from that, both silent:
 *
 *   - a typo sent the goods to the default location instead of the one meant;
 *   - when neither name matched, source and destination became the SAME
 *     location, the code skipped the movement entirely, and the transfer
 *     still reported itself completed. A shop reading its transfer list saw
 *     stock that had never moved.
 *
 * Locations are now ids chosen from a list, an unknown one is an error rather
 * than a fallback, and a transfer always moves stock.
 */
class StockTransferService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly DocumentNumberGenerator $numbers,
    ) {
    }

    /**
     * @param  array{source_location_id: int|string, destination_location_id: int|string,
     *               items: array<int, array{item_id?: int|string|null, quantity?: int|string|null, note?: string|null}>,
     *               note?: string|null, transferred_at?: string|null}  $data
     */
    public function create(Tenant $tenant, array $data): StockTransfer
    {
        return DB::transaction(function () use ($tenant, $data): StockTransfer {
            $source = $this->location($tenant, $data['source_location_id'] ?? null, 'source_location_id');
            $destination = $this->location($tenant, $data['destination_location_id'] ?? null, 'destination_location_id');

            if ($source->id === $destination->id) {
                throw ValidationException::withMessages([
                    'destination_location_id' => 'La destination doit être différente de la source.',
                ]);
            }

            $lines = $this->resolveLines($tenant, $source, $data['items'] ?? []);

            $number = $this->numbers->next(
                $tenant,
                'stock_transfer',
                'TRS',
                fn (string $candidate): bool => StockTransfer::where('tenant_id', $tenant->id)
                    ->where('number', $candidate)
                    ->exists(),
            );

            $transfer = StockTransfer::create([
                'tenant_id' => $tenant->id,
                'number' => $number['number'],
                'status' => 'completed',
                'source_location_id' => $source->id,
                'destination_location_id' => $destination->id,
                // The names as they read TODAY. A location renamed next year
                // must not rewrite what this paper said.
                'store_from' => $source->name,
                'warehouse_from' => null,
                'store_to' => $destination->name,
                'warehouse_to' => null,
                'total_quantity' => collect($lines)->sum('quantity'),
                'lines' => collect($lines)->map(fn (array $line) => [
                    'item_id' => $line['item']->id,
                    'item_code' => $line['item']->item_code,
                    'name' => $line['item']->title,
                    'barcode' => $line['item']->barcode,
                    'quantity' => $line['quantity'],
                    'available_stock' => $line['available'],
                    'note' => $line['note'],
                ])->all(),
                'note' => $data['note'] ?? null,
                'created_by' => auth()->id(),
                'transferred_at' => ! empty($data['transferred_at'])
                    ? Carbon::parse($data['transferred_at'])
                    : now(),
            ]);

            $this->moveAll($tenant, $transfer, $lines, $source, $destination);

            return $transfer->fresh();
        });
    }

    /**
     * Sends the goods back where they came from and closes the transfer.
     *
     * A reversal rather than a deletion: the movements that happened are
     * facts, and an inventory ledger that can be edited after the fact
     * answers no question anyone asks of it.
     */
    public function cancel(StockTransfer $transfer, string $reason): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $reason): StockTransfer {
            $transfer = StockTransfer::whereKey($transfer->id)->lockForUpdate()->firstOrFail();

            if ($transfer->status === 'cancelled') {
                throw ValidationException::withMessages([
                    'transfer' => 'Ce transfert est déjà annulé.',
                ]);
            }
            if (! $transfer->source_location_id || ! $transfer->destination_location_id) {
                throw ValidationException::withMessages([
                    'transfer' => "Ce transfert n'a pas d'emplacements enregistrés et ne peut pas être annulé automatiquement.",
                ]);
            }

            $tenant = Tenant::whereKey($transfer->tenant_id)->firstOrFail();

            foreach ($transfer->lines ?? [] as $line) {
                $itemId = (int) ($line['item_id'] ?? 0);
                $quantity = (int) ($line['quantity'] ?? 0);
                if ($itemId <= 0 || $quantity <= 0) {
                    continue;
                }

                // Out of the destination first: if the goods have already
                // moved on from there, this fails and the whole reversal rolls
                // back rather than inventing stock at the source.
                $this->move(
                    $tenant, $transfer, $itemId, $quantity,
                    (int) $transfer->destination_location_id,
                    InventoryMovementType::TRANSFER_OUT,
                    'Annulation transfert '.$transfer->number,
                );
                $this->move(
                    $tenant, $transfer, $itemId, $quantity,
                    (int) $transfer->source_location_id,
                    InventoryMovementType::TRANSFER_IN,
                    'Annulation transfert '.$transfer->number,
                );
            }

            $transfer->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => auth()->id(),
                'cancellation_reason' => $reason,
            ]);

            return $transfer->fresh();
        });
    }

    /**
     * @return array<int, array{item: Item, quantity: int, available: int, note: string|null}>
     */
    private function resolveLines(Tenant $tenant, Location $source, array $items): array
    {
        $lines = [];

        foreach ($items as $index => $line) {
            $itemId = (int) ($line['item_id'] ?? 0);
            $quantity = (int) ($line['quantity'] ?? 0);
            if ($itemId <= 0 || $quantity <= 0) {
                continue;
            }

            $item = Item::where('tenant_id', $tenant->id)
                ->where('type', '!=', 'service')
                ->lockForUpdate()
                ->find($itemId);

            if (! $item) {
                throw ValidationException::withMessages([
                    "items.$index.item_id" => "Cet article est introuvable ou n'est pas stockable.",
                ]);
            }

            // Availability AT THE SOURCE, never the global figure: the whole
            // point of a transfer is that stock sits in one place and is
            // wanted in another.
            $available = $this->inventory->available($tenant->id, $item->id, null, $source->id);
            if ($available < $quantity) {
                throw ValidationException::withMessages([
                    "items.$index.quantity" => 'Stock insuffisant pour '.$item->title.' à '.$source->name
                        .'. Disponible : '.$available.'.',
                ]);
            }

            $lines[] = [
                'item' => $item,
                'quantity' => $quantity,
                'available' => $available,
                'note' => $line['note'] ?? null,
            ];
        }

        if ($lines === []) {
            throw ValidationException::withMessages([
                'items' => 'Ajoutez au moins une ligne avec un article et une quantité.',
            ]);
        }

        return $lines;
    }

    private function location(Tenant $tenant, mixed $id, string $field): Location
    {
        $location = Location::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->find((int) $id);

        // Deliberately NOT falling back to the default location: that is how
        // a mistyped destination used to swallow a pallet of stock without
        // anyone being told.
        if (! $location) {
            throw ValidationException::withMessages([
                $field => 'Emplacement introuvable ou inactif.',
            ]);
        }

        return $location;
    }

    /** @param array<int, array{item: Item, quantity: int, available: int, note: string|null}> $lines */
    private function moveAll(
        Tenant $tenant,
        StockTransfer $transfer,
        array $lines,
        Location $source,
        Location $destination,
    ): void {
        foreach ($lines as $line) {
            $note = 'Transfert stock '.$transfer->number
                .($line['note'] ? ' · '.$line['note'] : '');

            $this->move(
                $tenant, $transfer, (int) $line['item']->id, $line['quantity'],
                $source->id, InventoryMovementType::TRANSFER_OUT, $note,
                'Transfert vers '.$destination->name,
            );
            $this->move(
                $tenant, $transfer, (int) $line['item']->id, $line['quantity'],
                $destination->id, InventoryMovementType::TRANSFER_IN, $note,
                'Transfert depuis '.$source->name,
            );
        }
    }

    private function move(
        Tenant $tenant,
        StockTransfer $transfer,
        int $itemId,
        int $quantity,
        int $locationId,
        string $type,
        string $note,
        ?string $reason = null,
    ): void {
        $this->inventory->move(new MovementDTO(
            tenantId: $tenant->id,
            itemId: $itemId,
            variantId: null,
            locationId: $locationId,
            type: $type,
            quantityChanged: $quantity,
            userId: auth()->id(),
            referenceType: StockTransfer::class,
            referenceId: $transfer->id,
            referenceNumber: $transfer->number,
            note: $note,
            reason: $reason,
        ));
    }
}
