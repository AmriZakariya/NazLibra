<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Contact;
use App\Models\ContactTransaction;
use App\Models\Item;
use App\Models\ItemLocationStock;
use App\Models\Location;
use App\Models\Sale;
use App\Models\SaleInvoice;
use App\Models\SalePayment;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Support\UtcDateTime;
use App\Support\TenantClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TimezoneContractTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Location $location;

    private Item $item;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->tenant = Tenant::firstOrFail();
        $this->location = Location::where('tenant_id', $this->tenant->id)->firstOrFail();
        $this->item = Item::where('tenant_id', $this->tenant->id)->where('type', '!=', 'service')->firstOrFail();
        $this->item->update(['status' => 'active', 'sale_price' => 80, 'stock_quantity' => 100]);
        ItemLocationStock::updateOrCreate([
            'tenant_id' => $this->tenant->id,
            'item_id' => $this->item->id,
            'variant_id' => null,
            'location_id' => $this->location->id,
        ], ['quantity' => 100, 'average_cost' => 50]);

        $user = User::where('email', 'amina@librairie-atlas.ma')->firstOrFail();
        $this->token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'timezone-contract-test',
        ])->assertOk()->json('token');
    }

    public function test_utc_and_casablanca_offset_values_are_the_same_instant(): void
    {
        $utc = UtcDateTime::parse('2026-06-24T10:23:50.000000Z');
        $casablancaOffset = UtcDateTime::parse('2026-06-24T11:23:50.000000+01:00');

        $this->assertTrue($utc->equalTo($casablancaOffset));
        $this->assertSame('2026-06-24T10:23:50.000000Z', UtcDateTime::format($casablancaOffset));
        $this->assertSame('2026-06-24 11:23:50', $utc->copy()->setTimezone('Africa/Casablanca')->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('+00:00', config('database.connections.mysql.timezone'));
        $this->assertSame('+00:00', config('database.connections.mariadb.timezone'));

        $this->assertSame('Africa/Casablanca', TenantClock::apply($this->tenant));
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('UTC', date_default_timezone_get());
    }

    public function test_sync_since_equal_instants_return_identical_rows_and_canonical_z_cursor(): void
    {
        $this->travelTo('2026-06-24 12:00:00');
        $this->item->forceFill(['updated_at' => UtcDateTime::parse('2026-06-24T10:23:50Z')])->saveQuietly();

        $utc = $this->apiGet('/api/v1/sync/items?since='.urlencode('2026-06-24T10:23:50.000000Z'))->assertOk();
        $offset = $this->apiGet('/api/v1/sync/items?since='.urlencode('2026-06-24T11:23:50.000000+01:00'))->assertOk();

        $this->assertSame(collect($utc->json('items'))->pluck('id')->all(), collect($offset->json('items'))->pluck('id')->all());
        $this->assertContains($this->item->id, collect($utc->json('items'))->pluck('id')->all());
        $this->assertMatchesRegularExpression('/Z$/', $utc->json('sync_at'));
        $this->assertSame($utc->json('sync_at'), $offset->json('sync_at'));

        $this->apiGet('/api/v1/sync/items?since='.urlencode('2026-06-24T10:23:50'))
            ->assertUnprocessable()->assertJsonValidationErrors('since');
    }

    public function test_sale_sold_at_requires_offset_and_is_stored_as_utc(): void
    {
        $instant = now()->utc()->subMinute()->startOfSecond();
        $localWire = $instant->copy()->setTimezone('+01:00')->format('Y-m-d\TH:i:sP');

        $created = $this->apiPost('/api/v1/pos/sales', $this->salePayload($localWire))->assertCreated();
        $sale = Sale::findOrFail($created->json('sale.id'));
        $this->assertSame(UtcDateTime::format($instant), UtcDateTime::format($sale->sold_at));

        $this->apiPost('/api/v1/pos/sales', $this->salePayload($instant->format('Y-m-d\TH:i:s')))
            ->assertUnprocessable()->assertJsonValidationErrors('sold_at');
    }

    public function test_sale_list_since_requires_offset_and_equal_instants_match(): void
    {
        $this->travelTo('2026-06-24 12:00:00');
        $this->apiPost('/api/v1/pos/sales', $this->salePayload('2026-06-24T10:24:00Z'))->assertCreated();

        $utc = $this->apiGet('/api/v1/pos/sales?since='.urlencode('2026-06-24T10:23:50Z'))->assertOk();
        $offset = $this->apiGet('/api/v1/pos/sales?since='.urlencode('2026-06-24T11:23:50+01:00'))->assertOk();

        $this->assertSame(collect($utc->json('sales'))->pluck('id')->all(), collect($offset->json('sales'))->pluck('id')->all());
        $this->apiGet('/api/v1/pos/sales?since='.urlencode('2026-06-24T10:23:50'))
            ->assertUnprocessable()->assertJsonValidationErrors('since');
    }

    public function test_contact_recorded_at_normalizes_equal_offsets_for_idempotent_replay(): void
    {
        $contact = Contact::create([
            'tenant_id' => $this->tenant->id,
            'kind' => 'client',
            'name' => 'Client timezone',
            'status' => 'active',
        ]);
        $key = (string) Str::uuid();
        $utc = '2026-06-24T10:23:50.000000Z';
        $plusOne = '2026-06-24T11:23:50.000000+01:00';

        $first = $this->apiPost("/api/v1/contacts/{$contact->id}/transactions", [
            'type' => 'gave', 'amount' => 10, 'recorded_at' => $plusOne, 'idempotency_key' => $key,
        ])->assertCreated();

        $this->apiPost("/api/v1/contacts/{$contact->id}/transactions", [
            'type' => 'gave', 'amount' => 10, 'recorded_at' => $utc, 'idempotency_key' => $key,
        ])->assertOk()->assertJsonPath('already_existed', true)
            ->assertJsonPath('transaction.id', $first->json('transaction.id'));

        $transaction = ContactTransaction::findOrFail($first->json('transaction.id'));
        $this->assertSame($utc, UtcDateTime::format($transaction->recorded_at));

        $this->apiPost("/api/v1/contacts/{$contact->id}/transactions", [
            'type' => 'gave', 'amount' => 10, 'recorded_at' => '2026-06-24T10:23:50', 'idempotency_key' => (string) Str::uuid(),
        ])->assertUnprocessable()->assertJsonValidationErrors('recorded_at');
    }

    public function test_every_mobile_sync_resource_advances_and_is_visible_after_a_utc_cursor(): void
    {
        $sinceUtc = '2026-06-24T10:23:50.000000Z';
        $sinceOffset = '2026-06-24T11:23:50.000000+01:00';
        $old = UtcDateTime::parse('2026-06-24T10:23:49Z');
        $this->travelTo(UtcDateTime::parse('2026-06-24T10:23:51Z'));

        $category = Category::where('tenant_id', $this->tenant->id)->firstOrFail();
        $brand = Brand::where('tenant_id', $this->tenant->id)->firstOrFail();
        $unit = Unit::where('tenant_id', $this->tenant->id)->firstOrFail();
        $tax = Tax::where('tenant_id', $this->tenant->id)->firstOrFail();
        $contact = Contact::where('tenant_id', $this->tenant->id)->firstOrFail();
        $stock = ItemLocationStock::where('tenant_id', $this->tenant->id)
            ->where('location_id', $this->location->id)->where('item_id', $this->item->id)->firstOrFail();

        foreach ([$category, $brand, $unit, $tax, $contact, $this->item, $stock] as $model) {
            $model->forceFill(['updated_at' => $old])->saveQuietly();
        }

        $category->update(['description' => 'sync category']);
        $brand->update(['description' => 'sync brand']);
        $unit->update(['description' => 'sync unit']);
        $tax->update(['description' => 'sync tax']);
        $this->item->update(['description' => 'sync item']);
        $stock->increment('quantity');

        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'location_id' => $this->location->id,
            'user_id' => User::where('email', 'amina@librairie-atlas.ma')->value('id'),
            'number' => 'TZ-SYNC-1',
            'status' => 'paid',
            'payment_method' => 'cash',
            'subtotal_amount' => 10,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 10,
            'sold_at' => now()->utc(),
        ]);
        $sale->forceFill(['updated_at' => $old])->saveQuietly();
        SalePayment::create([
            'tenant_id' => $this->tenant->id,
            'sale_id' => $sale->id,
            'number' => 'PAY-TZ-SYNC-1',
            'method' => 'cash',
            'amount' => 10,
            'paid_at' => now()->utc(),
        ]);

        $invoice = SaleInvoice::create([
            'tenant_id' => $this->tenant->id,
            'sale_id' => $sale->id,
            'number' => 'FAC-TZ-SYNC-1',
            'status' => 'paid',
            'issued_at' => now()->utc(),
            'subtotal_amount' => 10,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 10,
        ]);

        $contact->forceFill(['updated_at' => $old])->saveQuietly();
        $transaction = ContactTransaction::create([
            'tenant_id' => $this->tenant->id,
            'contact_id' => $contact->id,
            'type' => 'gave',
            'amount' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'recorded_at' => now()->utc(),
        ]);

        $this->travelTo(UtcDateTime::parse('2026-06-24T10:23:53Z'));

        $metaUtc = $this->apiGet('/api/v1/sync/meta?since='.urlencode($sinceUtc))->assertOk();
        $metaOffset = $this->apiGet('/api/v1/sync/meta?since='.urlencode($sinceOffset))->assertOk();
        foreach (['categories' => $category, 'brands' => $brand, 'units' => $unit, 'taxes' => $tax] as $key => $model) {
            $this->assertContains($model->id, collect($metaUtc->json($key))->pluck('id')->all());
            $this->assertSame(collect($metaUtc->json($key))->pluck('id')->all(), collect($metaOffset->json($key))->pluck('id')->all());
        }

        $this->assertEquivalentSyncIds('/api/v1/sync/items', 'items', $this->item->id, $sinceUtc, $sinceOffset);
        $this->assertEquivalentSyncIds('/api/v1/sync/stock', 'stock', $this->item->id, $sinceUtc, $sinceOffset, 'item_id');
        $this->assertEquivalentSyncIds('/api/v1/sync/contacts', 'contacts', $contact->id, $sinceUtc, $sinceOffset);
        $this->assertEquivalentSyncIds('/api/v1/sync/sales', 'sales', $sale->id, $sinceUtc, $sinceOffset);
        $this->assertEquivalentSyncIds('/api/v1/sync/invoices', 'invoices', $invoice->id, $sinceUtc, $sinceOffset);
        $this->assertEquivalentSyncIds('/api/v1/sync/contact-transactions', 'transactions', $transaction->id, $sinceUtc, $sinceOffset);

        $this->assertTrue($sale->fresh()->updated_at->greaterThan($old), 'SalePayment must touch its parent sale.');
        $this->assertTrue($contact->fresh()->updated_at->greaterThan($old), 'ContactTransaction must touch its parent contact.');
        foreach ([$category, $brand, $unit, $tax, $this->item, $stock, $sale, $invoice, $transaction] as $model) {
            $this->assertStringEndsWith('Z', UtcDateTime::format($model->fresh()->updated_at));
        }
    }

    public function test_stock_deletion_is_emitted_as_a_delta_tombstone(): void
    {
        $this->travelTo(UtcDateTime::parse('2026-06-24T10:23:51Z'));
        $stock = ItemLocationStock::where('tenant_id', $this->tenant->id)
            ->where('location_id', $this->location->id)->where('item_id', $this->item->id)->firstOrFail();
        $stock->delete();
        $this->travelTo(UtcDateTime::parse('2026-06-24T10:23:53Z'));

        $response = $this->apiGet('/api/v1/sync/stock?since='.urlencode('2026-06-24T10:23:50Z'))->assertOk();
        $tombstone = collect($response->json('stock'))->firstWhere('item_id', $this->item->id);

        $this->assertNotNull($tombstone);
        $this->assertNotNull($tombstone['deleted_at']);
        $this->assertStringEndsWith('Z', $tombstone['deleted_at']);
    }

    private function salePayload(string $soldAt): array
    {
        return [
            'idempotency_key' => (string) Str::uuid(),
            'location_id' => $this->location->id,
            'items' => [['item_id' => $this->item->id, 'quantity' => 1, 'unit_price' => 80]],
            'payments' => ['cash' => 80],
            'sold_at' => $soldAt,
        ];
    }

    private function apiGet(string $uri)
    {
        return $this->withToken($this->token)
            ->withHeader('X-Tenant-Slug', $this->tenant->slug)
            ->withHeader('X-Location-Id', (string) $this->location->id)
            ->getJson($uri);
    }

    private function apiPost(string $uri, array $payload)
    {
        return $this->withToken($this->token)
            ->withHeader('X-Tenant-Slug', $this->tenant->slug)
            ->withHeader('X-Location-Id', (string) $this->location->id)
            ->postJson($uri, $payload);
    }

    private function assertEquivalentSyncIds(
        string $endpoint,
        string $key,
        int $expectedId,
        string $sinceUtc,
        string $sinceOffset,
        string $idKey = 'id',
    ): void {
        $utc = $this->apiGet($endpoint.'?since='.urlencode($sinceUtc))->assertOk();
        $offset = $this->apiGet($endpoint.'?since='.urlencode($sinceOffset))->assertOk();
        $utcIds = collect($utc->json($key))->pluck($idKey)->all();
        $offsetIds = collect($offset->json($key))->pluck($idKey)->all();

        $this->assertContains($expectedId, $utcIds, "{$endpoint} did not return the mutated resource.");
        $this->assertSame($utcIds, $offsetIds, "{$endpoint} treated equal instants differently.");
    }
}
