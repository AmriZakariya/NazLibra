<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who numbers a sale.
 *
 * The server used to number every sale on sync, which meant an offline
 * receipt carried a provisional reference and the accounting record got a
 * different number, with nothing connecting the two — and numbers followed
 * SYNC order, so a 09:00 sale could be numbered after a 19:00 one. A till now
 * assigns its own number from a per-terminal series at the moment of sale, and
 * the server honours it.
 */
class SaleNumberingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->user = User::first();
        $this->item = Item::where('tenant_id', Tenant::first()->id)->firstOrFail();
    }

    /** @param array<string, mixed> $overrides */
    private function sell(array $overrides = [])
    {
        return $this->withToken($this->user->createToken('t')->plainTextToken)
            ->postJson('/api/v1/pos/sales', array_merge([
                'idempotency_key' => 'key-'.uniqid('', true),
                'items' => [
                    ['item_id' => $this->item->id, 'quantity' => 1, 'unit_price' => 10],
                ],
                'payments' => ['cash' => 10],
            ], $overrides));
    }

    public function test_a_till_assigned_number_is_kept_exactly(): void
    {
        // It is already printed on the customer's receipt.
        $this->sell(['number' => 'BL-03-000127'])->assertSuccessful();

        $this->assertDatabaseHas('sales', ['number' => 'BL-03-000127']);
    }

    public function test_two_terminals_do_not_collide(): void
    {
        $this->sell(['number' => 'BL-03-000001'])->assertSuccessful();
        $this->sell(['number' => 'BL-04-000001'])->assertSuccessful();

        $this->assertSame(2, Sale::whereIn('number', [
            'BL-03-000001',
            'BL-04-000001',
        ])->count());
    }

    public function test_a_sale_with_no_number_is_numbered_by_the_server(): void
    {
        // The web back office, which has no terminal series of its own.
        $this->sell()->assertSuccessful();

        $number = Sale::latest('id')->value('number');
        $this->assertNotNull($number);
        $this->assertStringStartsWith('BL', $number);
    }

    public function test_junk_is_ignored_and_the_server_numbers_it(): void
    {
        // A bad client must not be able to poison the register with
        // unreadable numbers.
        foreach (['REF-A3F91C', 'not a number', '   ', '../../etc'] as $junk) {
            $this->sell(['number' => $junk])->assertSuccessful();

            $number = Sale::latest('id')->value('number');
            $this->assertNotSame($junk, $number, "accepted \"$junk\"");
            $this->assertStringStartsWith('BL', $number);
        }
    }

    public function test_the_same_number_twice_does_not_create_two_sales(): void
    {
        // The unique (tenant_id, number) index is the backstop behind the
        // terminal series.
        $this->sell(['number' => 'BL-03-000900'])->assertSuccessful();
        $this->sell(['number' => 'BL-03-000900']);

        $this->assertSame(
            1,
            Sale::where('number', 'BL-03-000900')->count(),
        );
    }

    public function test_the_number_comes_back_in_the_response(): void
    {
        // The app patches its local row from this, so a mismatch here would
        // silently rename the sale the receipt refers to.
        $response = $this->sell(['number' => 'BL-05-000042'])->assertSuccessful();

        $this->assertSame('BL-05-000042', $response->json('sale.number'));
    }

    public function test_a_server_numbered_sale_still_uses_one_series(): void
    {
        // The facture keeps its own continuous sequence; back-office sales
        // must not start a per-terminal one by accident.
        $this->sell()->assertSuccessful();
        $first = Sale::latest('id')->value('number');

        $this->sell()->assertSuccessful();
        $second = Sale::latest('id')->value('number');

        $this->assertNotSame($first, $second);
        // Neither carries a terminal segment.
        $this->assertDoesNotMatchRegularExpression('/^BL-\d+-/', $first);
        $this->assertDoesNotMatchRegularExpression('/^BL-\d+-/', $second);
    }
}
