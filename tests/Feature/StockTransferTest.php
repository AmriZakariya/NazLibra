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
 * Moving stock between locations.
 *
 * The feature shipped taking its two ends as TYPED NAMES, fuzzy-matched
 * against the location table and falling back to the default location when
 * nothing matched. A typo therefore routed goods somewhere nobody chose, and
 * when neither end resolved the transfer moved nothing at all while still
 * reporting itself completed.
 */
class StockTransferTest extends TestCase
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
        $this->source = Location::where('tenant_id', $this->tenant->id)->where('is_default', true)->firstOrFail();
        $this->destination = Location::where('tenant_id', $this->tenant->id)
            ->where('id', '!=', $this->source->id)->firstOrFail();
        $this->item = Item::where('type', '!=', 'service')->where('stock_quantity', '>', 10)->firstOrFail();
        $this->inventory = app(InventoryService::class);
    }

    private function available(Location $location): int
    {
        return $this->inventory->available($this->tenant->id, $this->item->id, null, $location->id);
    }

    private function transfer(array $overrides = []): StockTransfer
    {
        return app(StockTransferService::class)->create($this->tenant, array_merge([
            'source_location_id' => $this->source->id,
            'destination_location_id' => $this->destination->id,
            'items' => [['item_id' => $this->item->id, 'quantity' => 4]],
        ], $overrides));
    }

    public function test_stock_leaves_the_source_and_arrives_at_the_destination(): void
    {
        $from = $this->available($this->source);
        $to = $this->available($this->destination);

        $this->transfer();

        $this->assertSame($from - 4, $this->available($this->source));
        $this->assertSame($to + 4, $this->available($this->destination));
    }

    public function test_the_total_on_hand_is_unchanged_by_a_transfer(): void
    {
        // Moving goods between two of your own shelves creates nothing and
        // destroys nothing.
        $before = $this->available($this->source) + $this->available($this->destination);

        $this->transfer();

        $this->assertSame($before, $this->available($this->source) + $this->available($this->destination));
    }

    public function test_an_unknown_location_is_refused_rather_than_swapped_for_the_default(): void
    {
        // The old resolver answered "default location" for anything it could
        // not match, which is how a mistyped destination swallowed a pallet.
        //
        // The unknown end is the SOURCE, and the destination is a real,
        // non-default location: a fallback would then quietly succeed by
        // taking the goods out of the default place instead of refusing.
        $atDefault = $this->available($this->source);

        try {
            $this->transfer([
                'source_location_id' => 999999,
                'destination_location_id' => $this->destination->id,
            ]);
            $this->fail('an unknown location must be refused, not replaced');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('source_location_id', $e->errors());
        }

        $this->assertSame(0, StockTransfer::count());
        $this->assertSame($atDefault, $this->available($this->source), 'nothing should have left the default location');
    }

    public function test_availability_is_read_at_the_source_not_across_the_shop(): void
    {
        // The two readings only differ once stock is split across locations,
        // which is exactly the situation a transfer creates. Send four to the
        // far shelf, then try to send five back off it: the shop holds plenty
        // in total, that shelf does not.
        $this->transfer();
        $atDestination = $this->available($this->destination);
        $global = $this->available($this->source) + $atDestination;
        $this->assertGreaterThan($atDestination, $global, 'the fixture must actually split the stock');

        try {
            $this->transfer([
                'source_location_id' => $this->destination->id,
                'destination_location_id' => $this->source->id,
                'items' => [['item_id' => $this->item->id, 'quantity' => $atDestination + 1]],
            ]);
            $this->fail('the source shelf does not hold that many');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Stock insuffisant', json_encode($e->errors(), JSON_UNESCAPED_UNICODE));
        }

        $this->assertSame($atDestination, $this->available($this->destination));
    }

    public function test_an_inactive_location_is_not_a_destination(): void
    {
        $this->destination->update(['is_active' => false]);

        $this->expectException(ValidationException::class);

        $this->transfer();
    }

    public function test_a_transfer_to_the_same_place_is_refused(): void
    {
        // It used to be accepted, recorded as completed, and moved nothing.
        $this->expectException(ValidationException::class);

        $this->transfer(['destination_location_id' => $this->source->id]);
    }

    public function test_more_than_the_source_holds_cannot_be_sent(): void
    {
        // Availability AT THE SOURCE, not the global figure: the whole point
        // of a transfer is that stock sits in one place and is wanted in
        // another.
        $atSource = $this->available($this->source);

        try {
            $this->transfer(['items' => [['item_id' => $this->item->id, 'quantity' => $atSource + 1]]]);
            $this->fail('the service should refuse to send more than the source holds');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Stock insuffisant', json_encode($e->errors(), JSON_UNESCAPED_UNICODE));
        }

        $this->assertSame($atSource, $this->available($this->source), 'nothing should have moved');
    }

    public function test_a_refused_line_moves_no_stock_at_all(): void
    {
        // One bad line must take the whole transfer down, not leave half the
        // pallet moved and half not.
        $other = Item::where('type', '!=', 'service')
            ->where('id', '!=', $this->item->id)
            ->where('stock_quantity', '>', 5)->firstOrFail();
        $before = $this->available($this->source);
        $otherBefore = $this->inventory->available($this->tenant->id, $other->id, null, $this->source->id);

        try {
            $this->transfer(['items' => [
                ['item_id' => $this->item->id, 'quantity' => 2],
                ['item_id' => $other->id, 'quantity' => 9999999],
            ]]);
            $this->fail('the transfer should have been refused');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame($before, $this->available($this->source));
        $this->assertSame($otherBefore, $this->inventory->available($this->tenant->id, $other->id, null, $this->source->id));
        $this->assertSame(0, StockTransfer::count());
    }

    public function test_a_transfer_with_no_usable_line_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->transfer(['items' => [['item_id' => null, 'quantity' => 0]]]);
    }

    public function test_a_service_cannot_be_transferred(): void
    {
        // There is no stock of a haircut to move.
        $service = Item::where('type', 'service')->first();
        if (! $service) {
            $this->markTestSkipped('the seeder has no service item');
        }

        $this->expectException(ValidationException::class);

        $this->transfer(['items' => [['item_id' => $service->id, 'quantity' => 1]]]);
    }

    public function test_the_transfer_records_which_two_places_it_moved_between(): void
    {
        $transfer = $this->transfer();

        $this->assertSame($this->source->id, $transfer->source_location_id);
        $this->assertSame($this->destination->id, $transfer->destination_location_id);
        // Names snapshotted too: a location renamed next year must not
        // rewrite what this transfer said at the time.
        $this->assertSame($this->source->name, $transfer->store_from);
        $this->assertSame($this->destination->name, $transfer->store_to);
    }

    public function test_numbers_are_unique_across_transfers(): void
    {
        // The old helper read max(number) off every row, so two transfers
        // raised together claimed the same one and hit the unique index.
        $numbers = collect(range(1, 3))->map(fn () => $this->transfer()->number);

        $this->assertCount(3, $numbers->unique());
        $numbers->each(fn ($n) => $this->assertStringStartsWith('TRS', $n));
    }

    public function test_a_number_already_taken_is_skipped(): void
    {
        $taken = app(\App\Services\Documents\DocumentNumberGenerator::class)
            ->peek($this->tenant, 'stock_transfer', 'TRS');
        StockTransfer::create([
            'tenant_id' => $this->tenant->id,
            'number' => $taken,
            'status' => 'completed',
            'total_quantity' => 0,
            'lines' => [],
            'transferred_at' => now(),
        ]);

        $this->assertNotSame($taken, $this->transfer()->number);
    }

    public function test_cancelling_sends_the_goods_back(): void
    {
        $from = $this->available($this->source);
        $to = $this->available($this->destination);
        $transfer = $this->transfer();

        app(StockTransferService::class)->cancel($transfer, 'Erreur de saisie');

        $this->assertSame($from, $this->available($this->source));
        $this->assertSame($to, $this->available($this->destination));
    }

    public function test_a_cancelled_transfer_says_so_and_why(): void
    {
        $transfer = app(StockTransferService::class)->cancel($this->transfer(), 'Erreur de saisie');

        $this->assertSame('cancelled', $transfer->status);
        $this->assertSame('Erreur de saisie', $transfer->cancellation_reason);
        $this->assertNotNull($transfer->cancelled_at);
        $this->assertTrue($transfer->isCancelled());
        $this->assertFalse($transfer->isReversible());
    }

    public function test_a_transfer_cannot_be_cancelled_twice(): void
    {
        // Twice would hand the shop a second copy of the goods.
        $transfer = app(StockTransferService::class)->cancel($this->transfer(), 'Erreur');

        $this->expectException(ValidationException::class);
        app(StockTransferService::class)->cancel($transfer, 'Encore');
    }

    public function test_cancelling_is_refused_when_the_goods_have_already_moved_on(): void
    {
        // The stock must still be AT THE DESTINATION to send it back; taking
        // it from a shelf that no longer holds it would invent inventory.
        $transfer = $this->transfer();
        $atDestination = $this->available($this->destination);

        app(StockTransferService::class)->create($this->tenant, [
            'source_location_id' => $this->destination->id,
            'destination_location_id' => $this->source->id,
            'items' => [['item_id' => $this->item->id, 'quantity' => $atDestination]],
        ]);

        try {
            app(StockTransferService::class)->cancel($transfer, 'Trop tard');
            $this->fail('cancelling should fail once the goods have left the destination');
        } catch (\Throwable) {
            // expected
        }

        $this->assertSame('completed', $transfer->fresh()->status, 'a failed cancel must not change the status');
    }

    public function test_the_form_offers_the_shops_own_locations_instead_of_a_text_box(): void
    {
        // The whole defect in one assertion: you pick a place, you do not
        // spell it.
        $this->get(route('stock', ['panel' => 'stock-transfer-add']))
            ->assertOk()
            ->assertSee('name="source_location_id"', false)
            ->assertSee('name="destination_location_id"', false)
            ->assertSee($this->destination->name)
            ->assertDontSee('name="store_from"', false)
            ->assertDontSee('name="warehouse_from"', false);
    }

    public function test_the_route_refuses_a_destination_equal_to_the_source(): void
    {
        $this->post(route('catalog.stock-transfers.store'), [
            'source_location_id' => $this->source->id,
            'destination_location_id' => $this->source->id,
            'items' => [['item_id' => $this->item->id, 'quantity' => 1]],
        ])->assertSessionHasErrors('destination_location_id');

        $this->assertSame(0, StockTransfer::count());
    }

    public function test_the_route_refuses_a_location_belonging_to_another_tenant(): void
    {
        $other = Tenant::create(['name' => 'Autre', 'slug' => 'autre-'.uniqid()]);
        $foreign = Location::create([
            'tenant_id' => $other->id,
            'name' => 'Dépôt voisin',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->post(route('catalog.stock-transfers.store'), [
            'source_location_id' => $this->source->id,
            'destination_location_id' => $foreign->id,
            'items' => [['item_id' => $this->item->id, 'quantity' => 1]],
        ])->assertSessionHasErrors('destination_location_id');

        $this->assertSame(0, StockTransfer::count());
    }

    public function test_the_cancel_route_returns_the_stock(): void
    {
        $from = $this->available($this->source);
        $transfer = $this->transfer();

        $this->post(route('catalog.stock-transfers.cancel', $transfer), ['reason' => 'Erreur de saisie'])
            ->assertRedirect();

        $this->assertSame('cancelled', $transfer->fresh()->status);
        $this->assertSame($from, $this->available($this->source));
    }

    public function test_the_cancel_route_insists_on_a_reason(): void
    {
        // "Why" is the only part of a reversal a reader cannot reconstruct.
        $transfer = $this->transfer();

        $this->post(route('catalog.stock-transfers.cancel', $transfer), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertSame('completed', $transfer->fresh()->status);
    }

    public function test_one_tenant_cannot_cancel_anothers_transfer(): void
    {
        $transfer = $this->transfer();
        $other = Tenant::create(['name' => 'Autre', 'slug' => 'autre-'.uniqid()]);
        $transfer->update(['tenant_id' => $other->id]);

        $this->post(route('catalog.stock-transfers.cancel', $transfer), ['reason' => 'Tentative'])
            ->assertNotFound();
    }

    public function test_a_transfer_without_locations_cannot_be_auto_reversed(): void
    {
        // Rows written before locations were recorded: there is no way to
        // know which two places to move the goods between.
        $legacy = StockTransfer::create([
            'tenant_id' => $this->tenant->id,
            'number' => 'TRS99999',
            'status' => 'completed',
            'total_quantity' => 1,
            'lines' => [['item_id' => $this->item->id, 'quantity' => 1]],
            'transferred_at' => now(),
        ]);

        $this->assertFalse($legacy->isReversible());
        $this->expectException(ValidationException::class);
        app(StockTransferService::class)->cancel($legacy, 'Essai');
    }
}
