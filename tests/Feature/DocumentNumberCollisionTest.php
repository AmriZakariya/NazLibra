<?php

namespace Tests\Feature;

use App\Models\DocumentSequence;
use App\Models\Item;
use App\Models\Location;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Documents\DocumentNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A refund at the till died on « UNIQUE constraint failed: sale_returns.number »:
 * the demo cleanup had deleted the number sequences but kept the returns, so
 * the counter restarted at RTN00001 while RTN00001 still existed. A counter
 * that has fallen behind its table now skips the numbers the table holds.
 */
class DocumentNumberCollisionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Location $location;
    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->tenant = Tenant::first();
        $this->user = User::first();
        $this->location = Location::where('tenant_id', $this->tenant->id)->firstOrFail();
        $this->item = Item::create([
            'tenant_id' => $this->tenant->id, 'title' => 'Article test', 'type' => 'service',
            'status' => 'active', 'sale_price' => 10, 'purchase_price' => 0, 'stock_quantity' => 0,
        ]);
    }

    private function token(): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $this->user->email, 'password' => 'password', 'device_name' => 'till-'.Str::uuid(),
        ])->json('token');
    }

    private function sell(string $token, int $quantity = 3): Sale
    {
        $saleId = $this->withToken($token)->postJson('/api/v1/pos/sales', [
            'idempotency_key' => (string) Str::uuid(),
            'location_id' => $this->location->id,
            'items' => [['item_id' => $this->item->id, 'quantity' => $quantity]],
            'payments' => ['cash' => 10 * $quantity, 'card' => 0, 'transfer' => 0, 'advance' => 0],
        ])->assertCreated()->json('sale.id');

        return Sale::findOrFail($saleId);
    }

    private function refundOne(string $token, Sale $sale)
    {
        return $this->withToken($token)->postJson("/api/v1/pos/sales/{$sale->id}/returns", [
            'idempotency_key' => (string) Str::uuid(),
            'refund_method' => 'cash',
            'return_lines' => [['sale_item_id' => $sale->items()->value('id'), 'quantity' => 1, 'stock_action' => 'restock']],
        ]);
    }

    public function test_a_refund_after_the_sequences_were_reset_takes_the_next_free_number(): void
    {
        $token = $this->token();
        $sale = $this->sell($token);
        $this->refundOne($token, $sale)->assertCreated();
        $first = SaleReturn::where('tenant_id', $this->tenant->id)->value('number');
        $this->assertSame('RTN00001', $first);

        // What the demo cleanup did: the sequences go, the documents stay.
        DocumentSequence::where('tenant_id', $this->tenant->id)->delete();

        $this->refundOne($token, $sale)->assertCreated();
        $this->assertSame(
            ['RTN00001', 'RTN00002'],
            SaleReturn::where('tenant_id', $this->tenant->id)->orderBy('id')->pluck('number')->all(),
        );
    }

    public function test_a_soft_deleted_document_still_holds_its_number(): void
    {
        $sale = $this->sell($this->token());
        $sale->delete();
        DocumentSequence::where('tenant_id', $this->tenant->id)->delete();

        $next = app(DocumentNumberGenerator::class)->nextIn($this->tenant, 'sale', null, Sale::class)['number'];

        $this->assertNotSame($sale->number, $next, 'the trashed sale keeps its number on the unique index');
    }

    public function test_the_back_office_and_the_till_number_returns_in_one_series(): void
    {
        $numbers = app(DocumentNumberGenerator::class);
        SaleReturn::forceCreate([
            'tenant_id' => $this->tenant->id, 'sale_id' => $this->sell($this->token())->id,
            'number' => 'RTN00001', 'status' => 'approved', 'refund_method' => 'cash',
            'total_amount' => 10, 'lines' => [], 'returned_at' => now(),
        ]);

        $this->assertSame('RTN00002', $numbers->nextIn($this->tenant, 'return', 'RTN', SaleReturn::class)['number']);
        $this->assertSame('RTN00003', $numbers->nextIn($this->tenant, 'return', null, SaleReturn::class)['number']);
    }

    public function test_the_demo_cleanup_takes_the_returns_with_the_sales(): void
    {
        $token = $this->token();
        $this->refundOne($token, $this->sell($token))->assertCreated();
        $owner = User::where('email', 'amina@librairie-atlas.ma')->firstOrFail();

        $this->actingAs($owner)
            ->post(route('settings.demo-maintenance.run', 'clear_sales'), ['confirmation' => 'DEMO'])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, SaleReturn::where('tenant_id', $this->tenant->id)->count());
    }
}
