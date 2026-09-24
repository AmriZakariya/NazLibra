<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Location;
use App\Models\StockTransfer;
use App\Models\Tenant;
use App\Services\Inventory\InventoryService;
use App\Services\Inventory\StockTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The stages a transfer goes through.
 *
 * A transfer used to be a single instant: creating it took the stock out of
 * the source and put it into the destination in the same breath. That is only
 * true when both ends are one room apart. When goods travel there is a
 * stretch where they have left and not arrived, and a shop asking "where is
 * my stock right now?" had nothing to read.
 */
class StockTransferWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Location $source;

    private Location $destination;

    private Item $item;

    private InventoryService $inventory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->tenant = Tenant::firstOrFail();
        $this->inventory = app(InventoryService::class);
        $this->source = Location::where('tenant_id', $this->tenant->id)->where('is_default', true)->firstOrFail();
        $this->destination = Location::where('tenant_id', $this->tenant->id)->whereKeyNot($this->source->id)->firstOrFail();
        $this->item = Item::where('tenant_id', $this->tenant->id)->where('type', '!=', 'service')->firstOrFail();

        $this->stock($this->source, 20);
    }

    private function stock(Location $location, int $quantity): void
    {
        app(\App\Services\Inventory\InventoryLedgerService::class)->createIncomingMovement([
            'tenantId' => $this->tenant->id,
            'itemId' => $this->item->id,
            'variantId' => null,
            'locationId' => $location->id,
            'type' => \App\Services\Inventory\InventoryMovementType::INITIAL_STOCK,
            'quantity' => $quantity,
            'unitCost' => 10.0,
            'occurredAt' => now()->subMinutes(5),
            'syncedAt' => null,
            'userId' => null,
            'idempotencyKey' => 'wf-'.$location->id.'-'.uniqid(),
            'referenceType' => null, 'referenceId' => null, 'referenceNumber' => null,
            'reason' => null, 'note' => null, 'virtualDeviceId' => null,
            'actorNameSnapshot' => null, 'terminalNameSnapshot' => null,
        ]);
    }

    private function service(): StockTransferService
    {
        return app(StockTransferService::class);
    }

    private function available(Location $location): int
    {
        return $this->inventory->available($this->tenant->id, $this->item->id, null, $location->id);
    }

    private function draft(int $quantity = 5): StockTransfer
    {
        return $this->service()->create($this->tenant, [
            'source_location_id' => $this->source->id,
            'destination_location_id' => $this->destination->id,
            'items' => [['item_id' => $this->item->id, 'quantity' => $quantity]],
        ]);
    }

    // ── Brouillon ─────────────────────────────────────────────────────────────

    public function test_a_draft_moves_nothing(): void
    {
        $from = $this->available($this->source);
        $to = $this->available($this->destination);

        $transfer = $this->draft();

        $this->assertTrue($transfer->isDraft());
        // The paper exists; the goods have not been touched.
        $this->assertSame($from, $this->available($this->source));
        $this->assertSame($to, $this->available($this->destination));
    }

    public function test_a_draft_can_be_rewritten(): void
    {
        $transfer = $this->draft(5);

        $this->service()->updateDraft($transfer, [
            'source_location_id' => $this->source->id,
            'destination_location_id' => $this->destination->id,
            'items' => [['item_id' => $this->item->id, 'quantity' => 9]],
            'note' => 'Corrigé',
        ]);

        $this->assertSame(9, (int) $transfer->fresh()->total_quantity);
        $this->assertSame('Corrigé', $transfer->fresh()->note);
    }

    public function test_a_sent_transfer_can_no_longer_be_rewritten(): void
    {
        $transfer = $this->draft();
        $this->service()->send($transfer);

        // Once the goods are gone the paper describes something that happened.
        $this->expectException(ValidationException::class);
        $this->service()->updateDraft($transfer->refresh(), [
            'source_location_id' => $this->source->id,
            'destination_location_id' => $this->destination->id,
            'items' => [['item_id' => $this->item->id, 'quantity' => 1]],
        ]);
    }

    public function test_a_draft_can_be_torn_up_rather_than_reversed(): void
    {
        $transfer = $this->draft();

        $this->service()->deleteDraft($transfer);

        $this->assertNull(StockTransfer::find($transfer->id));
    }

    public function test_a_sent_transfer_cannot_be_deleted(): void
    {
        $transfer = $this->draft();
        $this->service()->send($transfer);

        $this->expectException(ValidationException::class);
        $this->service()->deleteDraft($transfer->refresh());
    }

    // ── Envoi ─────────────────────────────────────────────────────────────────

    public function test_sending_takes_the_goods_out_of_the_source_only(): void
    {
        $from = $this->available($this->source);
        $to = $this->available($this->destination);
        $transfer = $this->draft(5);

        $this->service()->send($transfer);

        $this->assertSame($from - 5, $this->available($this->source));
        // Not there yet. This is the state that did not exist before: gone
        // from one shelf and not on the other.
        $this->assertSame($to, $this->available($this->destination));
        $this->assertTrue($transfer->fresh()->isInTransit());
        $this->assertSame(5, $transfer->fresh()->quantityInTransit());
    }

    public function test_sending_rechecks_the_stock_it_is_about_to_move(): void
    {
        $transfer = $this->draft(20);
        // Sold between writing the brouillon and loading the van. The check
        // done when the draft was written is not the one that matters.
        $this->service()->send($this->draft(15));

        $this->expectException(ValidationException::class);
        $this->service()->send($transfer->refresh());
    }

    public function test_a_transfer_cannot_be_sent_twice(): void
    {
        $transfer = $this->draft();
        $this->service()->send($transfer);

        $this->expectException(ValidationException::class);
        $this->service()->send($transfer->refresh());
    }

    public function test_only_a_sent_transfer_can_be_received(): void
    {
        $this->expectException(ValidationException::class);
        $this->service()->receive($this->draft());
    }

    // ── Réception ─────────────────────────────────────────────────────────────

    public function test_receiving_puts_the_goods_on_the_destination_shelf(): void
    {
        $to = $this->available($this->destination);
        $transfer = $this->draft(5);
        $this->service()->send($transfer);

        $this->service()->receive($transfer->refresh());

        $this->assertSame($to + 5, $this->available($this->destination));
        $this->assertTrue($transfer->fresh()->isReceived());
        $this->assertSame(0, $transfer->fresh()->shortfall());
    }

    public function test_a_short_receipt_only_adds_what_arrived(): void
    {
        $from = $this->available($this->source);
        $to = $this->available($this->destination);
        $transfer = $this->draft(10);
        $this->service()->send($transfer);

        $this->service()->receive($transfer->refresh(), [0 => 8], 'Un carton manquant');

        // Eight arrived, ten left: the two are lost, not quietly absorbed by
        // the destination.
        $this->assertSame($to + 8, $this->available($this->destination));
        $this->assertSame($from - 10, $this->available($this->source));
        $this->assertSame(2, $transfer->fresh()->shortfall());
        $this->assertSame('Un carton manquant', $transfer->fresh()->receipt_note);
    }

    public function test_receiving_more_than_was_sent_is_refused(): void
    {
        $transfer = $this->draft(5);
        $this->service()->send($transfer);

        // Stock cannot appear on the road.
        $this->expectException(ValidationException::class);
        $this->service()->receive($transfer->refresh(), [0 => 6]);
    }

    public function test_saying_nothing_about_a_line_means_it_all_arrived(): void
    {
        $to = $this->available($this->destination);
        $transfer = $this->draft(5);
        $this->service()->send($transfer);

        $this->service()->receive($transfer->refresh(), []);

        $this->assertSame($to + 5, $this->available($this->destination));
    }

    // ── Annulation à chaque étape ─────────────────────────────────────────────

    public function test_cancelling_a_draft_moves_no_stock(): void
    {
        $from = $this->available($this->source);
        $transfer = $this->draft();

        $this->service()->cancel($transfer, 'Plus besoin');

        $this->assertSame($from, $this->available($this->source));
        $this->assertTrue($transfer->fresh()->isCancelled());
    }

    public function test_cancelling_in_transit_sends_the_goods_back(): void
    {
        $from = $this->available($this->source);
        $to = $this->available($this->destination);
        $transfer = $this->draft(5);
        $this->service()->send($transfer);

        $this->service()->cancel($transfer->refresh(), 'Camion annulé');

        $this->assertSame($from, $this->available($this->source));
        // They never arrived, so nothing comes out of the destination.
        $this->assertSame($to, $this->available($this->destination));
    }

    public function test_cancelling_after_receipt_only_returns_what_arrived(): void
    {
        $from = $this->available($this->source);
        $to = $this->available($this->destination);
        $transfer = $this->draft(10);
        $this->service()->send($transfer);
        $this->service()->receive($transfer->refresh(), [0 => 8]);

        $this->service()->cancel($transfer->refresh(), 'Erreur');

        // The two lost in transit stay lost: putting them back would invent
        // units nobody ever had.
        $this->assertSame($to, $this->available($this->destination));
        $this->assertSame($from - 2, $this->available($this->source));
    }

    // ── Duplication ───────────────────────────────────────────────────────────

    public function test_duplicating_makes_a_new_draft_with_the_same_lines(): void
    {
        $original = $this->draft(6);
        $this->service()->send($original);
        $this->service()->receive($original->refresh());

        $copy = $this->service()->duplicate($original->refresh());

        $this->assertTrue($copy->isDraft());
        $this->assertNotSame($original->number, $copy->number);
        $this->assertSame(6, (int) $copy->total_quantity);
        $this->assertSame($original->source_location_id, $copy->source_location_id);
        $this->assertSame($original->destination_location_id, $copy->destination_location_id);
    }

    public function test_a_duplicate_repeats_what_was_sent_not_what_arrived(): void
    {
        $original = $this->draft(10);
        $this->service()->send($original);
        $this->service()->receive($original->refresh(), [0 => 7]);

        $copy = $this->service()->duplicate($original->refresh());

        // "The same delivery again". A box lost last week is not a reason to
        // order less this week.
        $this->assertSame(10, (int) $copy->total_quantity);
    }

    public function test_duplicating_moves_no_stock(): void
    {
        $original = $this->draft(6);
        $this->service()->send($original);
        $this->service()->receive($original->refresh());
        $from = $this->available($this->source);

        $this->service()->duplicate($original->refresh());

        $this->assertSame($from, $this->available($this->source));
    }

    // ── Les routes ────────────────────────────────────────────────────────────

    public function test_the_form_can_create_and_send_in_one_click(): void
    {
        $from = $this->available($this->source);

        $this->call('POST', '/catalogue/stock/transferts', [
            'source_location_id' => $this->source->id,
            'destination_location_id' => $this->destination->id,
            'items' => [['item_id' => $this->item->id, 'quantity' => 3]],
            'send_now' => '1',
        ])->assertRedirect();

        $transfer = StockTransfer::latest('id')->firstOrFail();
        $this->assertTrue($transfer->isInTransit());
        $this->assertSame($from - 3, $this->available($this->source));
    }

    public function test_the_form_leaves_a_draft_when_nothing_is_sent(): void
    {
        $from = $this->available($this->source);

        $this->call('POST', '/catalogue/stock/transferts', [
            'source_location_id' => $this->source->id,
            'destination_location_id' => $this->destination->id,
            'items' => [['item_id' => $this->item->id, 'quantity' => 3]],
        ])->assertRedirect();

        $this->assertTrue(StockTransfer::latest('id')->firstOrFail()->isDraft());
        $this->assertSame($from, $this->available($this->source));
    }

    public function test_the_send_route_moves_the_stock(): void
    {
        $transfer = $this->draft(4);
        $from = $this->available($this->source);

        $this->call('POST', '/catalogue/stock/transferts/'.$transfer->id.'/envoyer')->assertRedirect();

        $this->assertSame($from - 4, $this->available($this->source));
    }

    public function test_the_receive_route_takes_a_short_count(): void
    {
        $transfer = $this->draft(6);
        $this->service()->send($transfer);
        $to = $this->available($this->destination);

        $this->call('POST', '/catalogue/stock/transferts/'.$transfer->id.'/receptionner', [
            'received' => [0 => 4],
            'receipt_note' => 'Deux cassés',
        ])->assertRedirect();

        $this->assertSame($to + 4, $this->available($this->destination));
        $this->assertSame(2, $transfer->fresh()->shortfall());
    }

    public function test_the_duplicate_route_opens_the_new_draft(): void
    {
        $original = $this->draft(5);

        $response = $this->call('POST', '/catalogue/stock/transferts/'.$original->id.'/dupliquer');

        $copy = StockTransfer::latest('id')->firstOrFail();
        $this->assertNotSame($original->id, $copy->id);
        $response->assertRedirect(route('stock', ['panel' => 'stock-transfers', 'detail_transfer' => $copy->id]));
    }

    public function test_the_delete_route_only_takes_a_draft(): void
    {
        $transfer = $this->draft();
        $this->service()->send($transfer);

        $this->call('DELETE', '/catalogue/stock/transferts/'.$transfer->id)
            ->assertSessionHasErrors('transfer');

        $this->assertNotNull(StockTransfer::find($transfer->id));
    }

    public function test_one_tenant_cannot_drive_anothers_transfer(): void
    {
        $other = Tenant::create(['name' => 'Autre', 'slug' => 'autre-'.uniqid()]);
        $transfer = $this->draft();
        $transfer->update(['tenant_id' => $other->id]);

        $this->call('POST', '/catalogue/stock/transferts/'.$transfer->id.'/envoyer')->assertNotFound();
        $this->call('POST', '/catalogue/stock/transferts/'.$transfer->id.'/receptionner')->assertNotFound();
        $this->call('POST', '/catalogue/stock/transferts/'.$transfer->id.'/dupliquer')->assertNotFound();
        $this->call('DELETE', '/catalogue/stock/transferts/'.$transfer->id)->assertNotFound();
    }

    public function test_the_list_offers_the_actions_of_the_stage_it_is_in(): void
    {
        $draft = $this->draft();
        $dialog = \Illuminate\Support\Str::between(
            $this->get('/stock?panel=stock-transfers')->assertOk()->getContent(),
            '<dialog id="transfer-detail-'.$draft->id.'"',
            '</dialog>',
        );

        // Offering "réceptionner" on a brouillon is an invitation to a
        // mistake; offering "envoyer" on something already received is one too.
        $this->assertStringContainsString('Envoyer les articles', $dialog);
        $this->assertStringContainsString('Supprimer le brouillon', $dialog);
        $this->assertStringNotContainsString('Confirmer la réception', $dialog);
        $this->assertStringNotContainsString('Dupliquer', $dialog);
    }

    public function test_a_transfer_in_transit_is_offered_a_receipt(): void
    {
        $transfer = $this->draft();
        $this->service()->send($transfer);

        $dialog = \Illuminate\Support\Str::between(
            $this->get('/stock?panel=stock-transfers')->assertOk()->getContent(),
            '<dialog id="transfer-detail-'.$transfer->id.'"',
            '</dialog>',
        );

        $this->assertStringContainsString('Confirmer la réception', $dialog);
        $this->assertStringNotContainsString('Envoyer les articles', $dialog);
    }

    public function test_a_received_transfer_shows_its_shortfall(): void
    {
        $transfer = $this->draft(10);
        $this->service()->send($transfer);
        $this->service()->receive($transfer->refresh(), [0 => 7], 'Colis éventré');

        $dialog = \Illuminate\Support\Str::between(
            $this->get('/stock?panel=stock-transfers')->assertOk()->getContent(),
            '<dialog id="transfer-detail-'.$transfer->id.'"',
            '</dialog>',
        );

        $this->assertStringContainsString('Écart de réception', $dialog);
        $this->assertStringContainsString('3 unité(s) manquante(s)', $dialog);
        $this->assertStringContainsString('Colis éventré', $dialog);
        $this->assertStringContainsString('Dupliquer', $dialog);
    }

    public function test_a_short_receipt_names_the_line_that_came_up_short(): void
    {
        $second = Item::where('tenant_id', $this->tenant->id)
            ->where('type', '!=', 'service')->whereKeyNot($this->item->id)->firstOrFail();
        $this->stock($this->source, 20);

        $transfer = $this->service()->create($this->tenant, [
            'source_location_id' => $this->source->id,
            'destination_location_id' => $this->destination->id,
            'items' => [
                ['item_id' => $this->item->id, 'quantity' => 6],
                ['item_id' => $second->id, 'quantity' => 4],
            ],
        ]);
        $this->service()->send($transfer);
        $this->service()->receive($transfer->refresh(), [0 => 5]);

        $dialog = \Illuminate\Support\Str::between(
            $this->get('/stock?panel=stock-transfers')->assertOk()->getContent(),
            '<dialog id="transfer-detail-'.$transfer->id.'"',
            '</dialog>',
        );

        // "Il manque 1" helps nobody go and look. The line that came up short
        // has to carry it.
        $this->assertStringContainsString('5 reçu(s)', $dialog);
        // And the line that arrived whole stays quiet.
        $this->assertSame(1, substr_count($dialog, 'reçu(s)'));
    }
}
