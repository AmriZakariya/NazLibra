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
use App\Models\Tenant;
use App\Models\Tax;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncContractTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private Location $location;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->tenant = Tenant::firstOrFail();
        $this->user = User::where('email', 'amina@librairie-atlas.ma')->firstOrFail();
        $this->location = Location::where('tenant_id', $this->tenant->id)->firstOrFail();
        $this->token = $this->postJson('/api/v1/auth/login', [
            'email' => $this->user->email,
            'password' => 'password',
            'device_name' => 'sync-contract-test',
        ])->assertOk()->json('token');

        // The production cursor intentionally closes on the previous complete
        // second. Keep seeded fixtures outside the open write second so full
        // snapshot assertions are deterministic on second-precision schemas.
        $settledAt = now()->utc()->subMinute()->startOfSecond();
        foreach ([Item::class, Contact::class, Category::class, Brand::class, Unit::class, Tax::class, ItemLocationStock::class, Sale::class, SaleInvoice::class, ContactTransaction::class] as $model) {
            $model::withTrashed()->where('tenant_id', $this->tenant->id)->update(['updated_at' => $settledAt]);
        }
    }

    public function test_item_keyset_cursor_is_stable_with_equal_timestamps_and_concurrent_insert(): void
    {
        $timestamp = now()->subMinute()->startOfSecond();
        Item::where('tenant_id', $this->tenant->id)->update(['updated_at' => $timestamp]);
        $expectedIds = Item::withTrashed()->where('tenant_id', $this->tenant->id)->orderBy('id')->pluck('id')->all();

        $first = $this->syncGet('/api/v1/sync/items?per_page=2')->assertOk();
        $syncAt = $first->json('sync_at');
        $this->assertTrue($first->json('has_more'));
        $this->assertNotEmpty($first->json('next_cursor'));

        $concurrent = Item::create([
            'tenant_id' => $this->tenant->id,
            'title' => 'Créé pendant la synchronisation',
            'type' => 'book',
            'status' => 'active',
            'sale_price' => 10,
            'purchase_price' => 5,
        ]);

        $receivedIds = collect($first->json('items'))->pluck('id')->all();
        $response = $first;
        while ($response->json('has_more')) {
            $response = $this->syncGet('/api/v1/sync/items?cursor='.urlencode($response->json('next_cursor')))->assertOk();
            $this->assertSame($syncAt, $response->json('sync_at'));
            array_push($receivedIds, ...collect($response->json('items'))->pluck('id')->all());
        }

        $this->assertSame($expectedIds, $receivedIds);
        $this->assertNotContains($concurrent->id, $receivedIds);
        $this->travel(2)->seconds();
        $this->syncGet('/api/v1/sync/items?since='.urlencode($syncAt))
            ->assertOk()
            ->assertJsonFragment(['id' => $concurrent->id]);
    }

    public function test_sync_rejects_unsafe_or_invalid_pagination_inputs(): void
    {
        $this->syncGet('/api/v1/sync/items?since=not-a-date')->assertUnprocessable()->assertJsonValidationErrors('since');
        $this->syncGet('/api/v1/sync/items?per_page=0')->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->syncGet('/api/v1/sync/items?page=2')->assertUnprocessable()->assertJsonValidationErrors('cursor');
        $this->syncGet('/api/v1/sync/items?cursor=tampered')->assertUnprocessable()->assertJsonValidationErrors('cursor');
        $this->syncGet('/api/v1/sync/contacts?kind=invalid')->assertUnprocessable()->assertJsonValidationErrors('kind');
    }

    public function test_sync_endpoints_emit_tombstones_and_standard_envelopes(): void
    {
        $sale = Sale::where('tenant_id', $this->tenant->id)->firstOrFail();
        $sale->update(['location_id' => $this->location->id]);
        $invoice = SaleInvoice::create([
            'tenant_id' => $this->tenant->id,
            'sale_id' => $sale->id,
            'contact_id' => $sale->contact_id,
            'user_id' => $this->user->id,
            'number' => 'INV-SYNC-'.Str::random(6),
            'status' => 'issued',
            'issued_at' => now(),
            'total_amount' => $sale->total_amount,
        ]);
        $transaction = ContactTransaction::create([
            'tenant_id' => $this->tenant->id,
            'contact_id' => Contact::where('tenant_id', $this->tenant->id)->firstOrFail()->id,
            'type' => 'got',
            'amount' => 1,
            'recorded_at' => now(),
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $item = Item::where('tenant_id', $this->tenant->id)->firstOrFail();
        $contact = Contact::where('tenant_id', $this->tenant->id)->whereKeyNot($transaction->contact_id)->first()
            ?? Contact::where('tenant_id', $this->tenant->id)->firstOrFail();
        $category = Category::where('tenant_id', $this->tenant->id)->firstOrFail();

        $item->delete();
        $contact->delete();
        $sale->delete();
        $invoice->delete();
        $transaction->delete();
        $category->delete();
        $since = urlencode(now()->subMinute()->toISOString());
        $this->travel(2)->seconds();

        $items = $this->syncGet('/api/v1/sync/items?since='.$since)->assertOk();
        $itemTombstone = collect($items->json('items'))->firstWhere('id', $item->id);
        $this->assertNotNull(data_get($itemTombstone, 'deleted_at'));
        $this->syncGet('/api/v1/sync/contacts?since='.$since)->assertOk()
            ->assertJsonFragment(['id' => $contact->id])->assertJsonPath('next_cursor', null);
        $this->syncGet('/api/v1/sync/sales?since='.$since)->assertOk()
            ->assertJsonFragment(['id' => $sale->id]);
        $this->syncGet('/api/v1/sync/invoices?since='.$since)->assertOk()
            ->assertJsonFragment(['id' => $invoice->id]);
        $this->syncGet('/api/v1/sync/contact-transactions?since='.$since)->assertOk()
            ->assertJsonFragment(['id' => $transaction->id]);
        $this->syncGet('/api/v1/sync/meta?since='.$since)->assertOk()
            ->assertJsonFragment(['id' => $category->id])->assertJsonPath('has_more', false);
    }

    public function test_sales_and_invoices_are_scoped_to_the_resolved_location(): void
    {
        $otherLocation = Location::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Autre magasin',
            'slug' => 'autre-magasin',
            'is_default' => false,
        ]);
        $sales = Sale::where('tenant_id', $this->tenant->id)->take(2)->get();
        $this->assertCount(2, $sales);
        $sales[0]->update(['location_id' => $this->location->id]);
        $sales[1]->update(['location_id' => $otherLocation->id]);
        $this->travel(2)->seconds();

        $response = $this->syncGet('/api/v1/sync/sales')->assertOk();
        $ids = collect($response->json('sales'))->pluck('id');
        $this->assertTrue($ids->contains($sales[0]->id));
        $this->assertFalse($ids->contains($sales[1]->id));

        $this->withToken($this->token)
            ->withHeader('X-Tenant-Slug', $this->tenant->slug)
            ->withHeader('X-Location-Id', '999999')
            ->getJson('/api/v1/sync/settings')
            ->assertUnprocessable()
            ->assertJsonPath('error', 'invalid_location');
    }

    public function test_contact_transaction_replay_is_tenant_scoped_payload_bound_and_applied_once(): void
    {
        $contact = Contact::create([
            'tenant_id' => $this->tenant->id,
            'kind' => 'client',
            'name' => 'Client idempotence',
            'status' => 'active',
            'advance_balance' => 0,
            'outstanding_balance' => 0,
        ]);
        $before = (float) $contact->outstanding_balance;
        $key = (string) Str::uuid();
        $payload = ['type' => 'gave', 'amount' => 25, 'note' => 'offline', 'idempotency_key' => $key];

        $first = $this->withToken($this->token)->postJson("/api/v1/contacts/{$contact->id}/transactions", $payload)->assertCreated();
        $this->withToken($this->token)->postJson("/api/v1/contacts/{$contact->id}/transactions", $payload)
            ->assertOk()->assertJsonPath('already_existed', true)
            ->assertJsonPath('transaction.id', $first->json('transaction.id'));

        $this->assertSame($before + 25, (float) $contact->fresh()->outstanding_balance);
        $this->withToken($this->token)->postJson("/api/v1/contacts/{$contact->id}/transactions", [...$payload, 'amount' => 30])
            ->assertStatus(409)->assertJsonPath('error', 'idempotency_conflict');
    }

    public function test_settings_meta_and_stock_return_stable_non_paginated_contracts(): void
    {
        $this->syncGet('/api/v1/sync/settings')->assertOk()->assertJsonStructure([
            'ok', 'sync_at', 'has_more', 'next_cursor', 'tenant_id', 'location_id',
            'timezone', 'currency', 'locale', 'tenant_name', 'location_name',
            'allow_oversell', 'features_virtual_devices',
        ])->assertJsonPath('has_more', false);

        $this->syncGet('/api/v1/sync/meta')->assertOk()->assertJsonStructure([
            'ok', 'sync_at', 'is_full_snapshot', 'has_more', 'next_cursor', 'categories', 'brands', 'units', 'taxes',
        ])->assertJsonPath('is_full_snapshot', true);

        $this->syncGet('/api/v1/sync/stock')->assertOk()->assertJsonStructure([
            'ok', 'sync_at', 'location_id', 'is_full_snapshot', 'has_more', 'next_cursor', 'stock',
        ])->assertJsonPath('is_full_snapshot', true);
    }

    public function test_missing_or_null_since_returns_full_snapshot(): void
    {
        $expectedItems = Item::withTrashed()->where('tenant_id', $this->tenant->id)->count();
        $expectedContacts = Contact::withTrashed()->where('tenant_id', $this->tenant->id)->count();
        $expectedCategories = Category::withTrashed()->where('tenant_id', $this->tenant->id)->count();

        $this->syncGet('/api/v1/sync/items')->assertOk()
            ->assertJsonPath('is_full_snapshot', true)
            ->assertJsonPath('total', $expectedItems);

        $this->syncGet('/api/v1/sync/contacts')->assertOk()
            ->assertJsonPath('is_full_snapshot', true)
            ->assertJsonPath('total', $expectedContacts);

        $this->syncGet('/api/v1/sync/meta')->assertOk()
            ->assertJsonPath('is_full_snapshot', true)
            ->assertJsonCount($expectedCategories, 'categories');

        $this->syncGet('/api/v1/sync/stock')->assertOk()
            ->assertJsonPath('is_full_snapshot', true);

        $this->syncGet('/api/v1/sync/items?since=')->assertOk()
            ->assertJsonPath('is_full_snapshot', true)
            ->assertJsonPath('total', $expectedItems);
    }

    public function test_non_variant_stock_identity_is_unique_per_tenant_item_and_location(): void
    {
        $stock = ItemLocationStock::where('tenant_id', $this->tenant->id)
            ->whereNull('variant_id')
            ->firstOrFail();

        $this->expectException(QueryException::class);
        ItemLocationStock::create([
            'tenant_id' => $stock->tenant_id,
            'item_id' => $stock->item_id,
            'variant_id' => null,
            'location_id' => $stock->location_id,
            'quantity' => 1,
        ]);
    }

    private function syncGet(string $uri)
    {
        return $this->withToken($this->token)
            ->withHeader('X-Tenant-Slug', $this->tenant->slug)
            ->withHeader('X-Location-Id', (string) $this->location->id)
            ->getJson($uri);
    }
}
