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
                'status' => 'draft',
                'source_location_id' => $source->id,
                'destination_location_id' => $destination->id,
                // The names as they read TODAY. A location renamed next year
                // must not rewrite what this paper said.
                'store_from' => $source->name,
                'warehouse_from' => null,
                'store_to' => $destination->name,
                'warehouse_to' => null,
                'total_quantity' => collect($lines)->sum('quantity'),
                'lines' => $this->lineSnapshots($lines),
                'note' => $data['note'] ?? null,
                'created_by' => auth()->id(),
                'transferred_at' => ! empty($data['transferred_at'])
                    ? Carbon::parse($data['transferred_at'])
                    : now(),
            ]);

            return $transfer->fresh();
        });
    }

    /**
     * Sends the goods: they leave the source and are en route.
     *
     * The availability read when the draft was written is not the one that
     * matters — the stock may have been sold since — so it is checked again
     * here, at the moment it actually moves.
     */
    public function send(StockTransfer $transfer): StockTransfer
    {
        return DB::transaction(function () use ($transfer): StockTransfer {
            $transfer = StockTransfer::whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($transfer, ['draft'], "Seul un brouillon peut être envoyé.");

            $tenant = Tenant::whereKey($transfer->tenant_id)->firstOrFail();
            $source = $this->location($tenant, $transfer->source_location_id, 'source_location_id');

            foreach ($transfer->lines ?? [] as $index => $line) {
                $itemId = (int) ($line['item_id'] ?? 0);
                $quantity = (int) ($line['quantity'] ?? 0);
                if ($itemId <= 0 || $quantity <= 0) {
                    continue;
                }

                $available = $this->inventory->available($tenant->id, $itemId, null, $source->id);
                if ($available < $quantity) {
                    throw ValidationException::withMessages([
                        'transfer' => 'Stock insuffisant pour '.($line['name'] ?? 'un article').' à '
                            .$source->name.'. Disponible : '.$available.'.',
                    ]);
                }

                $this->move(
                    $tenant, $transfer, $itemId, $quantity, $source->id,
                    InventoryMovementType::TRANSFER_OUT,
                    'Envoi transfert '.$transfer->number.(($line['note'] ?? null) ? ' · '.$line['note'] : ''),
                    'Transfert vers '.$transfer->store_to,
                );
            }

            $transfer->update([
                'status' => 'in_transit',
                'sent_at' => now(),
                'sent_by' => auth()->id(),
            ]);

            return $transfer->fresh();
        });
    }

    /**
     * Receives the goods at the destination.
     *
     * [$quantities] is keyed by line index and may be SHORT of what was sent:
     * a pallet arrives with a box missing more often than anyone would like.
     * Only what arrived is added to the destination, and the difference stays
     * on the transfer as a shortfall rather than being quietly absorbed.
     *
     * @param  array<int|string, int|string|null>  $quantities
     */
    public function receive(StockTransfer $transfer, array $quantities = [], ?string $note = null): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $quantities, $note): StockTransfer {
            $transfer = StockTransfer::whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($transfer, ['in_transit'], "Seul un transfert envoyé peut être réceptionné.");

            $tenant = Tenant::whereKey($transfer->tenant_id)->firstOrFail();
            $destination = $this->location($tenant, $transfer->destination_location_id, 'destination_location_id');

            $lines = [];
            foreach ($transfer->lines ?? [] as $index => $line) {
                $itemId = (int) ($line['item_id'] ?? 0);
                $sent = (int) ($line['quantity'] ?? 0);

                // Nothing said about a line means it all arrived: the common
                // case is a clean receipt, and it should need no typing.
                $received = array_key_exists($index, $quantities) && $quantities[$index] !== null && $quantities[$index] !== ''
                    ? (int) $quantities[$index]
                    : $sent;

                if ($received < 0 || $received > $sent) {
                    throw ValidationException::withMessages([
                        "received.$index" => 'La quantité reçue doit être comprise entre 0 et '.$sent.'.',
                    ]);
                }

                if ($itemId > 0 && $received > 0) {
                    $this->move(
                        $tenant, $transfer, $itemId, $received, $destination->id,
                        InventoryMovementType::TRANSFER_IN,
                        'Réception transfert '.$transfer->number,
                        'Transfert depuis '.$transfer->store_from,
                    );
                }

                $lines[] = array_merge($line, ['received_quantity' => $received]);
            }

            $transfer->update([
                'lines' => $lines,
                'status' => 'received',
                'received_at' => now(),
                'received_by' => auth()->id(),
                'receipt_note' => $note,
            ]);

            return $transfer->fresh();
        });
    }

    /**
     * Copies a transfer's lines into a fresh brouillon.
     *
     * The quantities are the ones that were SENT, not the ones received: a
     * duplicate is "the same delivery again", and a short receipt last week
     * is not a reason to order less this week.
     */
    public function duplicate(StockTransfer $transfer): StockTransfer
    {
        $tenant = Tenant::whereKey($transfer->tenant_id)->firstOrFail();

        return $this->create($tenant, [
            'source_location_id' => $transfer->source_location_id,
            'destination_location_id' => $transfer->destination_location_id,
            'note' => $transfer->note,
            'items' => collect($transfer->lines ?? [])->map(fn (array $line): array => [
                'item_id' => $line['item_id'] ?? null,
                'quantity' => $line['quantity'] ?? 0,
                'note' => $line['note'] ?? null,
            ])->all(),
        ]);
    }

    /** Throws unless the transfer is in one of [$allowed]. */
    private function assertStatus(StockTransfer $transfer, array $allowed, string $message): void
    {
        if (! in_array($transfer->status, $allowed, true)) {
            throw ValidationException::withMessages(['transfer' => $message]);
        }
    }

    /**
     * Undoes whatever has actually happened, and closes the transfer.
     *
     * A reversal rather than a deletion: the movements that happened are
     * facts, and an inventory ledger that can be edited after the fact
     * answers no question anyone asks of it. What gets reversed depends on
     * how far the goods got:
     *
     *   brouillon   nothing moved, so nothing is moved back;
     *   envoyé      they left the source, so they go back to it;
     *   reçu        they arrived, so they come out of the destination and go
     *               back to the source — but only the quantity that ARRIVED.
     *               Stock lost in transit stays lost; putting it back would
     *               invent units nobody ever had.
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

            $wasDraft = $transfer->isDraft();

            if (! $wasDraft && (! $transfer->source_location_id || ! $transfer->destination_location_id)) {
                throw ValidationException::withMessages([
                    'transfer' => "Ce transfert n'a pas d'emplacements enregistrés et ne peut pas être annulé automatiquement.",
                ]);
            }

            if (! $wasDraft) {
                $tenant = Tenant::whereKey($transfer->tenant_id)->firstOrFail();
                $arrived = $transfer->isReceived();

                foreach ($transfer->lines ?? [] as $line) {
                    $itemId = (int) ($line['item_id'] ?? 0);
                    $sent = (int) ($line['quantity'] ?? 0);
                    $quantity = $arrived
                        ? (int) ($line['received_quantity'] ?? $sent)
                        : $sent;

                    if ($itemId <= 0 || $quantity <= 0) {
                        continue;
                    }

                    // Out of the destination first, when they got that far: if
                    // the goods have already moved on from there this fails and
                    // the whole reversal rolls back rather than inventing stock
                    // at the source.
                    if ($arrived) {
                        $this->move(
                            $tenant, $transfer, $itemId, $quantity,
                            (int) $transfer->destination_location_id,
                            InventoryMovementType::TRANSFER_OUT,
                            'Annulation transfert '.$transfer->number,
                        );
                    }

                    $this->move(
                        $tenant, $transfer, $itemId, $quantity,
                        (int) $transfer->source_location_id,
                        InventoryMovementType::TRANSFER_IN,
                        'Annulation transfert '.$transfer->number,
                    );
                }
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
     * Rewrites a brouillon's lines. Only a brouillon: once the goods have
     * left, the paper describes something that happened.
     */
    public function updateDraft(StockTransfer $transfer, array $data): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $data): StockTransfer {
            $transfer = StockTransfer::whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            $this->assertStatus($transfer, ['draft'], 'Seul un brouillon peut être modifié.');

            $tenant = Tenant::whereKey($transfer->tenant_id)->firstOrFail();
            $source = $this->location($tenant, $data['source_location_id'] ?? null, 'source_location_id');
            $destination = $this->location($tenant, $data['destination_location_id'] ?? null, 'destination_location_id');

            if ($source->id === $destination->id) {
                throw ValidationException::withMessages([
                    'destination_location_id' => 'La destination doit être différente de la source.',
                ]);
            }

            $lines = $this->resolveLines($tenant, $source, $data['items'] ?? []);

            $transfer->update([
                'source_location_id' => $source->id,
                'destination_location_id' => $destination->id,
                'store_from' => $source->name,
                'store_to' => $destination->name,
                'total_quantity' => collect($lines)->sum('quantity'),
                'lines' => $this->lineSnapshots($lines),
                'note' => $data['note'] ?? null,
                'transferred_at' => ! empty($data['transferred_at'])
                    ? Carbon::parse($data['transferred_at'])
                    : $transfer->transferred_at,
            ]);

            return $transfer->fresh();
        });
    }

    /** Throws unless the transfer can still be torn up rather than reversed. */
    public function deleteDraft(StockTransfer $transfer): void
    {
        $this->assertStatus($transfer, ['draft'], 'Seul un brouillon peut être supprimé.');

        $transfer->delete();
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

    /**
     * What a line looks like once written down.
     *
     * @param  array<int, array{item: Item, quantity: int, available: int, note: string|null}>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function lineSnapshots(array $lines): array
    {
        return collect($lines)->map(fn (array $line): array => [
            'item_id' => $line['item']->id,
            'item_code' => $line['item']->item_code,
            'name' => $line['item']->title,
            'barcode' => $line['item']->barcode,
            'quantity' => $line['quantity'],
            'available_stock' => $line['available'],
            'note' => $line['note'],
        ])->all();
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
