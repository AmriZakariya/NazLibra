<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\ItemLocationStock;
use App\Models\Location;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Sale;
use App\Models\SaleItemModifier;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Inventory\InventoryLedgerService;
use App\Services\Inventory\InventoryMovementType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Sizes and line options through the MOBILE till's endpoint.
 *
 * The app is the primary till, and its sales take a different road into the
 * database than the web POS: its own controller, its own validation, its own
 * ledger calls. Everything the web checkout learned about sub-products and
 * options had to be learned again here — and until it was, a size sold on a
 * phone came off the article's pile and a supplement was never recorded.
 */
class MobileSaleVariantModifierTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Location $location;
    private Item $burger;
    private Item $cheese;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->tenant = Tenant::first();
        $this->location = Location::where('tenant_id', $this->tenant->id)->first();
        $this->burger = $this->article('Burger', 40);
        $this->cheese = $this->article('Fromage', 5);
    }

    private function article(string $title, float $price): Item
    {
        $item = Item::create([
            'tenant_id' => $this->tenant->id,
            'title' => $title,
            'type' => 'supply',
            'status' => 'active',
            'sale_price' => $price,
            'purchase_price' => $price / 2,
            'stock_quantity' => 0,
        ]);

        $this->stock($item, null, 100);

        return $item->refresh();
    }

    private function stock(Item $item, ?int $variantId, int $quantity): void
    {
        app(InventoryLedgerService::class)->createIncomingMovement([
            'tenantId' => $this->tenant->id,
            'itemId' => $item->id,
            'variantId' => $variantId,
            'locationId' => $this->location->id,
            'type' => InventoryMovementType::INITIAL_STOCK,
            'quantity' => $quantity,
            'unitCost' => 10.0,
            'occurredAt' => now()->subMinutes(5),
            'syncedAt' => null,
            'userId' => null,
            'idempotencyKey' => 'setup-'.$item->id.'-'.($variantId ?? 0),
            'referenceType' => null,
            'referenceId' => null,
            'referenceNumber' => null,
            'reason' => null,
            'note' => null,
            'virtualDeviceId' => null,
            'actorNameSnapshot' => null,
            'terminalNameSnapshot' => null,
        ]);
    }

    private function token(): string
    {
        $user = User::first();

        return $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'till-'.Str::uuid(),
        ])->json('token');
    }

    /** @param array<int, array<string, mixed>> $items */
    private function sell(array $items, float $cash = 1000): \Illuminate\Testing\TestResponse
    {
        return $this->withToken($this->token())->postJson('/api/v1/pos/sales', [
            'idempotency_key' => Str::uuid()->toString(),
            'location_id' => $this->location->id,
            'items' => $items,
            'payments' => ['cash' => $cash, 'card' => 0, 'transfer' => 0, 'advance' => 0],
        ]);
    }

    private function onHand(Item $item, ?int $variantId = null): float
    {
        return (float) ItemLocationStock::where('tenant_id', $this->tenant->id)
            ->where('item_id', $item->id)
            ->where('variant_id', $variantId)
            ->where('location_id', $this->location->id)
            ->value('quantity');
    }

    private function group(string $name, int $min = 0, ?int $max = null): ModifierGroup
    {
        $group = ModifierGroup::create([
            'tenant_id' => $this->tenant->id,
            'name' => $name,
            'min_select' => $min,
            'max_select' => $max,
        ]);
        $this->burger->modifierGroups()->attach($group->id, ['tenant_id' => $this->tenant->id]);

        return $group;
    }

    private function option(ModifierGroup $group, string $name, float $delta, ?Item $consumes = null, float $qty = 1): Modifier
    {
        return Modifier::create([
            'tenant_id' => $this->tenant->id,
            'modifier_group_id' => $group->id,
            'name' => $name,
            'price_delta' => $delta,
            'linked_item_id' => $consumes?->id,
            'consumes_quantity' => $qty,
        ]);
    }

    // ── Sub-products ──────────────────────────────────────────────────────────

    /** @return array{0: Item, 1: \App\Models\ItemVariant, 2: \App\Models\ItemVariant} */
    private function shirtWithSizes(): array
    {
        $shirt = $this->article('Chemise', 200);
        $variants = [];
        foreach (['S', 'L'] as $name) {
            $variant = $shirt->variants()->create([
                'tenant_id' => $this->tenant->id,
                'name' => $name,
                'combination_key' => strtolower($name),
                'attributes' => [],
                'is_active' => true,
            ]);
            $this->stock($shirt, $variant->id, 10);
            $variants[] = $variant;
        }

        return [$shirt->refresh(), $variants[0], $variants[1]];
    }

    public function test_a_size_sold_on_the_app_comes_off_that_sizes_pile(): void
    {
        [$shirt, $small, $large] = $this->shirtWithSizes();

        $this->sell([
            ['item_id' => $shirt->id, 'variant_id' => $large->id, 'quantity' => 2],
        ])->assertCreated();

        $this->assertSame(8.0, $this->onHand($shirt, $large->id));
        // The other size and the article's own pile are untouched: deducting
        // the article instead invents stock that was never on the shelf.
        $this->assertSame(10.0, $this->onHand($shirt, $small->id));
        $this->assertSame(100.0, $this->onHand($shirt));
    }

    public function test_two_sizes_on_one_ticket_both_move(): void
    {
        [$shirt, $small, $large] = $this->shirtWithSizes();

        // One article, two lines. They share an id, so a movement keyed on
        // the article would have the second deduplicated away.
        $this->sell([
            ['item_id' => $shirt->id, 'variant_id' => $small->id, 'quantity' => 1],
            ['item_id' => $shirt->id, 'variant_id' => $large->id, 'quantity' => 3],
        ])->assertCreated();

        $this->assertSame(9.0, $this->onHand($shirt, $small->id));
        $this->assertSame(7.0, $this->onHand($shirt, $large->id));
    }

    public function test_each_line_keeps_its_own_cost(): void
    {
        [$shirt, $small, $large] = $this->shirtWithSizes();

        $response = $this->sell([
            ['item_id' => $shirt->id, 'variant_id' => $small->id, 'quantity' => 1],
            ['item_id' => $shirt->id, 'variant_id' => $large->id, 'quantity' => 3],
        ]);
        $response->assertCreated();

        $sale = Sale::findOrFail($response->json('sale.id'));
        $lines = $sale->items()->orderBy('id')->get();
        $this->assertEquals(10.0, (float) $lines[0]->total_cost);
        $this->assertEquals(30.0, (float) $lines[1]->total_cost);
        // And the sale's COGS is their sum, not one line counted twice —
        // which also means the column is writable: it was left out of the
        // model's fillable list, so every mobile sale recorded a null cost.
        $this->assertEquals(40.0, (float) $sale->fresh()->cogs);
    }

    // ── Line options ──────────────────────────────────────────────────────────

    public function test_the_chosen_options_are_recorded_against_the_line(): void
    {
        $group = $this->group('Suppléments');
        $cheeseExtra = $this->option($group, 'Fromage', 5);

        $response = $this->sell([
            ['item_id' => $this->burger->id, 'quantity' => 1, 'modifier_ids' => [$cheeseExtra->id]],
        ]);
        $response->assertCreated();

        $recorded = SaleItemModifier::latest('id')->firstOrFail();
        $this->assertSame('Fromage', $recorded->name);
        $this->assertEquals(5, (float) $recorded->price_delta);
    }

    public function test_an_option_is_priced_in_when_the_till_names_no_price(): void
    {
        $group = $this->group('Suppléments');
        $cheeseExtra = $this->option($group, 'Fromage', 5);

        $response = $this->sell([
            ['item_id' => $this->burger->id, 'quantity' => 2, 'modifier_ids' => [$cheeseExtra->id]],
        ]);
        $response->assertCreated();

        $this->assertEquals(90, (float) Sale::findOrFail($response->json('sale.id'))->total_amount);
    }

    public function test_a_negative_option_takes_money_off(): void
    {
        $group = $this->group('Suppléments');
        $less = $this->option($group, 'Sans fromage', -2);

        $response = $this->sell([
            ['item_id' => $this->burger->id, 'quantity' => 1, 'modifier_ids' => [$less->id]],
        ]);
        $response->assertCreated();

        $this->assertEquals(38, (float) Sale::findOrFail($response->json('sale.id'))->total_amount);
    }

    public function test_a_linked_option_eats_into_that_articles_stock(): void
    {
        $group = $this->group('Suppléments');
        $cheeseExtra = $this->option($group, 'Fromage', 5, $this->cheese);
        $before = $this->onHand($this->cheese);

        $this->sell([
            ['item_id' => $this->burger->id, 'quantity' => 3, 'modifier_ids' => [$cheeseExtra->id]],
        ])->assertCreated();

        // Three burgers eat three portions, not one.
        $this->assertSame($before - 3, $this->onHand($this->cheese));
    }

    public function test_an_option_the_article_does_not_offer_is_refused(): void
    {
        $group = ModifierGroup::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Sirops',
            'min_select' => 0,
        ]);
        // Deliberately NOT attached to the burger.
        $syrup = $this->option($group, 'Vanille', 3);

        $this->sell([
            ['item_id' => $this->burger->id, 'quantity' => 1, 'modifier_ids' => [$syrup->id]],
        ])->assertStatus(422);

        $this->assertSame(0, SaleItemModifier::count());
    }

    public function test_a_required_group_must_be_answered(): void
    {
        $group = $this->group('Cuisson', min: 1, max: 1);
        $this->option($group, 'Bleu', 0);

        $this->sell([
            ['item_id' => $this->burger->id, 'quantity' => 1],
        ])->assertStatus(422);
    }

    public function test_a_cap_is_enforced_on_the_app_too(): void
    {
        $group = $this->group('Suppléments', max: 1);
        $first = $this->option($group, 'Fromage', 5);
        $second = $this->option($group, 'Bacon', 8);

        $this->sell([
            ['item_id' => $this->burger->id, 'quantity' => 1, 'modifier_ids' => [$first->id, $second->id]],
        ])->assertStatus(422);
    }

    public function test_a_refused_line_leaves_no_sale_behind(): void
    {
        $group = $this->group('Cuisson', min: 1, max: 1);
        $this->option($group, 'Bleu', 0);
        $before = Sale::count();
        $stockBefore = $this->onHand($this->burger);

        $this->sell([
            ['item_id' => $this->burger->id, 'quantity' => 1],
        ])->assertStatus(422);

        $this->assertSame($before, Sale::count());
        $this->assertSame($stockBefore, $this->onHand($this->burger));
    }

    public function test_a_line_with_no_options_is_unaffected(): void
    {
        $this->group('Suppléments');

        $this->sell([
            ['item_id' => $this->burger->id, 'quantity' => 1],
        ])->assertCreated();

        $this->assertSame(0, SaleItemModifier::count());
    }

    public function test_a_price_the_till_names_is_honoured_over_the_catalogue(): void
    {
        $group = $this->group('Suppléments');
        $cheeseExtra = $this->option($group, 'Fromage', 5);

        // A manager's override, or a price the till computed itself. The
        // server must not quietly re-price the line and charge something the
        // customer was never shown.
        $response = $this->sell([
            [
                'item_id' => $this->burger->id,
                'quantity' => 1,
                'unit_price' => 30,
                'modifier_ids' => [$cheeseExtra->id],
            ],
        ]);
        $response->assertCreated();

        $this->assertEquals(30, (float) Sale::findOrFail($response->json('sale.id'))->total_amount);
    }

    // ── The catalogue the till pulls ──────────────────────────────────────────

    public function test_the_options_reach_the_till_with_the_metadata(): void
    {
        $group = $this->group('Suppléments', max: 2);
        $cheeseExtra = $this->option($group, 'Fromage', 5);

        $meta = $this->withToken($this->token())
            ->getJson('/api/v1/sync/meta')
            ->assertOk()
            ->json();

        $sent = collect($meta['modifier_groups'])->firstWhere('id', $group->id);
        $this->assertNotNull($sent, 'the till cannot draw a sheet it was never sent');
        $this->assertSame('Suppléments', $sent['name']);
        $this->assertSame(2, $sent['max_select']);
        $this->assertContains($this->burger->id, $sent['item_ids']);
        $this->assertSame($cheeseExtra->id, $sent['modifiers'][0]['id']);
        $this->assertEquals(5, $sent['modifiers'][0]['price_delta']);
    }

    public function test_a_group_with_no_cap_is_sent_as_no_cap(): void
    {
        $group = $this->group('Suppléments');
        $this->option($group, 'Fromage', 5);

        $meta = $this->withToken($this->token())->getJson('/api/v1/sync/meta')->json();
        $sent = collect($meta['modifier_groups'])->firstWhere('id', $group->id);

        // Null, not zero: zero would read on the till as "pick nothing".
        $this->assertNull($sent['max_select']);
    }

    public function test_a_retired_group_is_not_sent_to_the_till(): void
    {
        $group = $this->group('Suppléments');
        $this->option($group, 'Fromage', 5);
        $group->update(['is_active' => false]);

        $meta = $this->withToken($this->token())->getJson('/api/v1/sync/meta')->json();

        $this->assertNull(collect($meta['modifier_groups'])->firstWhere('id', $group->id));
    }

    public function test_a_retired_option_is_not_sent_either(): void
    {
        $group = $this->group('Suppléments');
        $kept = $this->option($group, 'Fromage', 5);
        $withdrawn = $this->option($group, 'Bacon', 8);
        $withdrawn->update(['is_active' => false]);

        $meta = $this->withToken($this->token())->getJson('/api/v1/sync/meta')->json();
        $sent = collect($meta['modifier_groups'])->firstWhere('id', $group->id);

        $this->assertSame([$kept->id], array_column($sent['modifiers'], 'id'));
    }

    public function test_one_tenant_is_not_sent_anothers_options(): void
    {
        $group = $this->group('Suppléments');
        $this->option($group, 'Fromage', 5);

        $other = Tenant::create(['name' => 'Autre', 'slug' => 'autre-'.Str::uuid()]);
        ModifierGroup::create([
            'tenant_id' => $other->id,
            'name' => 'Sirops',
            'min_select' => 0,
        ]);

        $meta = $this->withToken($this->token())->getJson('/api/v1/sync/meta')->json();

        $this->assertNull(collect($meta['modifier_groups'])->firstWhere('name', 'Sirops'));
    }
}
