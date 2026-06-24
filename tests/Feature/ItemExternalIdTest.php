<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Item;
use App\Models\Location;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ItemExternalIdTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Location $location;

    private Category $category;

    private Brand $brand;

    private Unit $unit;

    private Tax $tax;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->tenant = Tenant::firstOrFail();
        $this->location = Location::where('tenant_id', $this->tenant->id)->firstOrFail();
        $this->category = Category::where('tenant_id', $this->tenant->id)->firstOrFail();
        $this->brand = Brand::where('tenant_id', $this->tenant->id)->firstOrFail();
        $this->unit = Unit::where('tenant_id', $this->tenant->id)->firstOrFail();
        $this->tax = Tax::where('tenant_id', $this->tenant->id)->firstOrFail();
        $this->user = User::where('email', 'amina@librairie-atlas.ma')->firstOrFail();
        $this->token = $this->postJson('/api/v1/auth/login', [
            'email' => $this->user->email,
            'password' => 'password',
            'device_name' => 'item-external-id-test',
        ])->assertOk()->json('token');
    }

    public function test_create_and_replay_return_one_canonical_item_and_sync_exposes_external_id(): void
    {
        $localId = (string) Str::uuid();
        $payload = [
            'local_id' => $localId,
            'title' => 'Article créé hors ligne',
            'type' => 'book',
            'sale_price' => 89.90,
            'purchase_price' => 50.25,
            'stock_quantity' => 3,
            'category_id' => $this->category->id,
            'brand_id' => $this->brand->id,
            'unit_id' => $this->unit->id,
            'tax_id' => $this->tax->id,
        ];

        $created = $this->apiPost('/api/v1/items', $payload)
            ->assertCreated()
            ->assertJsonPath('already_existed', false)
            ->assertJsonPath('item.external_id', $localId)
            ->assertJsonPath('item.type', 'book')
            ->assertJsonPath('item.item_group', 'Single')
            ->assertJsonPath('item.status', 'active')
            ->assertJsonPath('item.category_id', $this->category->id)
            ->assertJsonPath('item.brand_id', $this->brand->id)
            ->assertJsonPath('item.unit_id', $this->unit->id)
            ->assertJsonPath('item.tax_id', $this->tax->id);

        // Decimal Eloquent casts are intentionally JSON strings.
        $this->assertSame('89.90', $created->json('item.sale_price'));
        $this->assertMatchesRegularExpression('/^IT\d{6,}$/', $created->json('item.item_code'));
        $this->assertDatabaseHas('stock_movements', [
            'item_id' => $created->json('item.id'),
            'location_id' => $this->location->id,
            'type' => 'opening_stock',
            'quantity_delta' => 3,
        ]);

        $replayed = $this->apiPost('/api/v1/items', $payload)
            ->assertOk()
            ->assertJsonPath('already_existed', true)
            ->assertJsonPath('item.external_id', $localId);

        $this->assertSame($created->json('item.id'), $replayed->json('item.id'));
        $this->assertSame(1, Item::where('tenant_id', $this->tenant->id)->where('external_id', $localId)->count());

        $this->travel(2)->seconds();
        $synced = $this->apiGet('/api/v1/sync/items')->assertOk();
        $item = collect($synced->json('items'))->firstWhere('id', $created->json('item.id'));
        $this->assertSame($localId, data_get($item, 'external_id'));
        $this->assertSame($created->json('item.item_code'), data_get($item, 'item_code'));
        $this->assertSame('Single', data_get($item, 'item_group'));
        $this->assertSame('book', data_get($item, 'type'));

        $detail = $this->apiGet('/api/v1/items/'.$created->json('item.id'))->assertOk();
        $this->assertSame($this->unit->id, $detail->json('item.unit_id'));
        $this->assertSame($this->tax->id, $detail->json('item.tax_id'));
    }

    public function test_external_id_is_unique_within_a_tenant_but_reusable_by_another_tenant(): void
    {
        $externalId = (string) Str::uuid();
        Item::create(['tenant_id' => $this->tenant->id, 'external_id' => $externalId, 'title' => 'Tenant A', 'type' => 'book', 'status' => 'active']);

        $otherTenant = Tenant::create([
            'name' => 'Autre tenant',
            'slug' => 'autre-tenant',
            'currency' => 'MAD',
            'locale' => 'fr',
            'timezone' => 'Africa/Casablanca',
        ]);
        Item::create(['tenant_id' => $otherTenant->id, 'external_id' => $externalId, 'title' => 'Tenant B', 'type' => 'book', 'status' => 'active']);

        $this->assertSame(2, Item::where('external_id', $externalId)->count());

        $this->expectException(QueryException::class);
        Item::create(['tenant_id' => $this->tenant->id, 'external_id' => $externalId, 'title' => 'Duplicate', 'type' => 'book', 'status' => 'active']);
    }

    public function test_item_create_requires_a_uuid_local_id(): void
    {
        $this->apiPost('/api/v1/items', [
            'local_id' => 'not-a-uuid',
            'title' => 'Invalid',
            'sale_price' => 10,
        ])->assertUnprocessable()->assertJsonValidationErrors('local_id');

        $this->apiPost('/api/v1/items', [
            'title' => 'Missing',
            'sale_price' => 10,
        ])->assertUnprocessable()->assertJsonValidationErrors('local_id');
    }

    public function test_mobile_book_supply_and_service_use_web_canonical_types_and_stock_rules(): void
    {
        $created = [];
        foreach ([
            'book' => ['title' => 'Livre Mobile Canonique', 'stock' => 0, 'status' => 'out_of_stock'],
            'supply' => ['title' => 'Fourniture Mobile Canonique', 'stock' => 4, 'status' => 'active'],
            'service' => ['title' => 'Service Mobile Canonique', 'stock' => 9, 'status' => 'active'],
        ] as $type => $expectation) {
            $response = $this->apiPost('/api/v1/items', $this->canonicalPayload($type, $expectation['title'], $expectation['stock']))
                ->assertCreated()
                ->assertJsonPath('item.type', $type)
                ->assertJsonPath('item.status', $expectation['status']);
            $created[$type] = $response->json('item');
        }

        $this->assertSame(0, (int) $created['service']['stock_quantity']);
        $this->assertDatabaseMissing('item_location_stock', ['item_id' => $created['service']['id']]);
        $this->assertDatabaseHas('item_location_stock', ['item_id' => $created['book']['id'], 'quantity' => 0]);
        $this->assertDatabaseHas('item_location_stock', ['item_id' => $created['supply']['id'], 'quantity' => 4]);

        $this->actingAs($this->user);
        foreach ([
            'Livre Mobile Canonique' => 'Livre',
            'Fourniture Mobile Canonique' => 'Produit',
            'Service Mobile Canonique' => 'Service',
        ] as $title => $label) {
            $webRow = $this->getJson(route('catalog.data', [
                'panel' => 'articles',
                'draw' => 1,
                'start' => 0,
                'length' => 10,
                'search' => ['value' => $title],
            ]))->assertOk();
            $this->assertStringContainsString($title, data_get($webRow->json(), 'data.0.title', ''));
            $this->assertStringContainsString($label, data_get($webRow->json(), 'data.0.category_type', ''));
        }
    }

    public function test_invalid_type_and_cross_tenant_reference_ids_are_rejected(): void
    {
        $this->apiPost('/api/v1/items', [...$this->canonicalPayload('supply', 'Produit invalide', 0), 'type' => 'product'])
            ->assertUnprocessable()->assertJsonValidationErrors('type');

        $otherTenant = Tenant::create([
            'name' => 'Tenant références',
            'slug' => 'tenant-references',
            'currency' => 'MAD',
            'locale' => 'fr',
            'timezone' => 'Africa/Casablanca',
        ]);
        $foreign = [
            'category_id' => Category::create(['tenant_id' => $otherTenant->id, 'name' => 'Cat étrangère', 'slug' => 'cat-etrangere'])->id,
            'brand_id' => Brand::create(['tenant_id' => $otherTenant->id, 'name' => 'Marque étrangère'])->id,
            'unit_id' => Unit::create(['tenant_id' => $otherTenant->id, 'name' => 'Unité étrangère', 'is_active' => true])->id,
            'tax_id' => Tax::create(['tenant_id' => $otherTenant->id, 'name' => 'Taxe étrangère', 'rate' => 20, 'is_active' => true])->id,
        ];

        foreach ($foreign as $field => $id) {
            $this->apiPost('/api/v1/items', [...$this->canonicalPayload('supply', 'Référence étrangère '.$field, 0), $field => $id])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
    }

    public function test_item_update_uses_canonical_references_and_prohibits_direct_stock_changes(): void
    {
        $created = $this->apiPost('/api/v1/items', $this->canonicalPayload('book', 'Livre à modifier', 0))->assertCreated();
        $itemId = $created->json('item.id');

        $this->withToken($this->token)
            ->withHeader('X-Tenant-Slug', $this->tenant->slug)
            ->withHeader('X-Location-Id', (string) $this->location->id)
            ->putJson('/api/v1/items/'.$itemId, [
                'type' => 'supply',
                'category_id' => $this->category->id,
                'brand_id' => $this->brand->id,
                'unit_id' => $this->unit->id,
                'tax_id' => $this->tax->id,
                'item_group' => 'Pack',
            ])->assertOk()
            ->assertJsonPath('item.type', 'supply')
            ->assertJsonPath('item.item_group', 'Pack')
            ->assertJsonPath('item.status', 'out_of_stock');

        $this->withToken($this->token)
            ->withHeader('X-Tenant-Slug', $this->tenant->slug)
            ->withHeader('X-Location-Id', (string) $this->location->id)
            ->putJson('/api/v1/items/'.$itemId, ['stock_quantity' => 50])
            ->assertUnprocessable()->assertJsonValidationErrors('stock_quantity');
    }

    private function canonicalPayload(string $type, string $title, int $stock): array
    {
        return [
            'local_id' => (string) Str::uuid(),
            'title' => $title,
            'type' => $type,
            'sale_price' => 20,
            'purchase_price' => 10,
            'stock_quantity' => $stock,
            'min_stock_threshold' => 3,
            'category_id' => $this->category->id,
            'brand_id' => $this->brand->id,
            'unit_id' => $this->unit->id,
            'tax_id' => $this->tax->id,
        ];
    }

    private function apiPost(string $uri, array $payload)
    {
        return $this->withToken($this->token)
            ->withHeader('X-Tenant-Slug', $this->tenant->slug)
            ->withHeader('X-Location-Id', (string) $this->location->id)
            ->postJson($uri, $payload);
    }

    private function apiGet(string $uri)
    {
        return $this->withToken($this->token)
            ->withHeader('X-Tenant-Slug', $this->tenant->slug)
            ->withHeader('X-Location-Id', (string) $this->location->id)
            ->getJson($uri);
    }
}
