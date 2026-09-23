<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Location;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\SaleItem;
use App\Models\SaleItemModifier;
use App\Models\Tenant;
use App\Services\Inventory\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Line options that are not sub-products.
 *
 * A variant has its own pile on a shelf; a modifier does not. Some modifiers
 * do eat into ANOTHER article's stock — extra cheese, a shot of syrup — and
 * that is the only inventory they touch.
 */
class ModifierTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Item $burger;
    private Item $cheese;
    private Location $location;
    private ModifierGroup $extras;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->tenant = Tenant::firstOrFail();
        $this->location = Location::where('tenant_id', $this->tenant->id)
            ->where('is_default', true)->firstOrFail();

        $items = Item::where('tenant_id', $this->tenant->id)
            ->where('type', '!=', 'service')->take(2)->get();
        [$this->burger, $this->cheese] = [$items[0], $items[1]];

        $this->extras = ModifierGroup::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Suppléments',
            'min_select' => 0, 'max_select' => 2,
        ]);
        $this->burger->modifierGroups()->attach($this->extras->id, [
            'tenant_id' => $this->tenant->id, 'sort_order' => 0,
        ]);
    }

    private function modifier(string $name, float $delta, ?Item $consumes = null, float $qty = 1): Modifier
    {
        return Modifier::create([
            'tenant_id' => $this->tenant->id,
            'modifier_group_id' => $this->extras->id,
            'name' => $name,
            'price_delta' => $delta,
            'linked_item_id' => $consumes?->id,
            'consumes_quantity' => $qty,
        ]);
    }

    private function stock(Item $item, int $quantity): void
    {
        app(InventoryService::class)->move(new \App\Services\Inventory\MovementDTO(
            tenantId: $this->tenant->id, itemId: $item->id, variantId: null,
            locationId: $this->location->id,
            type: \App\Services\Inventory\InventoryMovementType::PURCHASE,
            quantityChanged: $quantity,
        ));
    }

    private function onHand(Item $item): int
    {
        return app(InventoryService::class)->quantity(
            $this->tenant->id, $item->id, null, $this->location->id,
        );
    }

    private function checkout(array $modifierIds, int $quantity = 1, ?float $price = 100): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('pos.store'), [
            'cart' => json_encode([array_filter([
                'id' => $this->burger->id,
                'quantity' => $quantity,
                'price' => $price,
                'modifier_ids' => $modifierIds,
            ], fn ($v) => $v !== null)]),
            'cash_amount' => 5000,
        ]);
    }

    public function test_a_modifier_adds_its_delta_to_the_line_price(): void
    {
        $this->stock($this->burger, 10);
        $cheeseExtra = $this->modifier('Fromage', 5);

        // No line price: the till must take the article's plus the option's.
        $this->post(route('pos.store'), [
            'cart' => json_encode([[
                'id' => $this->burger->id, 'quantity' => 1,
                'modifier_ids' => [$cheeseExtra->id],
            ]]),
            'cash_amount' => 5000,
        ])->assertRedirect();

        $line = SaleItem::latest('id')->firstOrFail();
        $this->assertSame((float) $this->burger->sale_price + 5, (float) $line->unit_price);
    }

    public function test_a_negative_delta_takes_money_off(): void
    {
        // "Sans fromage -2 DH" is as real as a supplement.
        $this->stock($this->burger, 10);
        $less = $this->modifier('Sans fromage', -2);

        $this->post(route('pos.store'), [
            'cart' => json_encode([[
                'id' => $this->burger->id, 'quantity' => 1, 'modifier_ids' => [$less->id],
            ]]),
            'cash_amount' => 5000,
        ])->assertRedirect();

        $this->assertSame(
            (float) $this->burger->sale_price - 2,
            (float) SaleItem::latest('id')->firstOrFail()->unit_price,
        );
    }

    public function test_what_was_chosen_is_recorded_on_the_line(): void
    {
        $this->stock($this->burger, 10);
        $cheeseExtra = $this->modifier('Fromage', 5);

        $this->checkout([$cheeseExtra->id])->assertRedirect();

        $recorded = SaleItemModifier::latest('id')->firstOrFail();
        $this->assertSame('Fromage', $recorded->name);
        $this->assertSame('5.00', (string) $recorded->price_delta);
    }

    public function test_the_name_and_price_are_snapshots(): void
    {
        // Renaming a supplement next month must not rewrite what a customer
        // was charged for last week.
        $this->stock($this->burger, 10);
        $cheeseExtra = $this->modifier('Fromage', 5);
        $this->checkout([$cheeseExtra->id])->assertRedirect();

        $cheeseExtra->update(['name' => 'Cheddar affiné', 'price_delta' => 9]);

        $recorded = SaleItemModifier::latest('id')->firstOrFail();
        $this->assertSame('Fromage', $recorded->name);
        $this->assertSame('5.00', (string) $recorded->price_delta);
    }

    public function test_a_linked_modifier_eats_into_that_articles_stock(): void
    {
        // The case you asked for: extra cheese should make the cheese fall.
        $this->stock($this->burger, 10);
        $cheeseExtra = $this->modifier('Fromage', 5, $this->cheese);
        // Measured against what was there: the seeder already stocks this
        // article, so an absolute figure asserts the fixture, not the change.
        $before = $this->onHand($this->cheese);

        $this->checkout([$cheeseExtra->id])->assertRedirect();

        $this->assertSame($before - 1, $this->onHand($this->cheese));
    }

    public function test_consumption_scales_with_the_line_quantity(): void
    {
        // Three burgers with extra cheese eat three portions, not one.
        $this->stock($this->burger, 10);
        $cheeseExtra = $this->modifier('Fromage', 5, $this->cheese);
        $before = $this->onHand($this->cheese);

        $this->checkout([$cheeseExtra->id], quantity: 3)->assertRedirect();

        $this->assertSame($before - 3, $this->onHand($this->cheese));
    }

    public function test_a_portion_bigger_than_one_is_respected(): void
    {
        $this->stock($this->burger, 10);
        $double = $this->modifier('Double fromage', 9, $this->cheese, qty: 2);
        $before = $this->onHand($this->cheese);

        $this->checkout([$double->id])->assertRedirect();

        $this->assertSame($before - 2, $this->onHand($this->cheese));
    }

    public function test_a_modifier_with_no_link_touches_no_stock(): void
    {
        // The majority: it changes the price and the ticket, nothing else.
        $this->stock($this->burger, 10);
        $noIce = $this->modifier('Sans glace', 0);
        $before = $this->onHand($this->cheese);

        $this->checkout([$noIce->id])->assertRedirect();

        $this->assertSame($before, $this->onHand($this->cheese));
    }

    public function test_a_modifier_from_a_group_the_article_does_not_offer_is_refused(): void
    {
        $this->stock($this->burger, 10);
        $otherGroup = ModifierGroup::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Cuisson', 'min_select' => 0,
        ]);
        $foreign = Modifier::create([
            'tenant_id' => $this->tenant->id, 'modifier_group_id' => $otherGroup->id,
            'name' => 'Saignant', 'price_delta' => 0,
        ]);

        $this->checkout([$foreign->id])->assertSessionHasErrors();
    }

    public function test_more_than_the_group_allows_is_refused(): void
    {
        $this->stock($this->burger, 10);
        $a = $this->modifier('Fromage', 5);
        $b = $this->modifier('Bacon', 7);
        $c = $this->modifier('Oeuf', 4);

        $this->checkout([$a->id, $b->id, $c->id])->assertSessionHasErrors();
    }

    public function test_a_required_group_must_be_answered(): void
    {
        // "Choose a cuisson" is not optional.
        $this->stock($this->burger, 10);
        $cooking = ModifierGroup::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Cuisson',
            'min_select' => 1, 'max_select' => 1,
        ]);
        $this->burger->modifierGroups()->attach($cooking->id, [
            'tenant_id' => $this->tenant->id, 'sort_order' => 1,
        ]);
        Modifier::create([
            'tenant_id' => $this->tenant->id, 'modifier_group_id' => $cooking->id,
            'name' => 'Saignant', 'price_delta' => 0,
        ]);

        $this->checkout([])->assertSessionHasErrors();
    }

    public function test_a_disabled_modifier_cannot_be_chosen(): void
    {
        $this->stock($this->burger, 10);
        $retired = $this->modifier('Fromage', 5);
        $retired->update(['is_active' => false]);

        $this->checkout([$retired->id])->assertSessionHasErrors();
    }

    public function test_a_line_with_no_options_still_sells(): void
    {
        // Modifiers must stay entirely optional for the shops that never use
        // them.
        $this->stock($this->burger, 10);

        $this->checkout([])->assertRedirect();

        $this->assertSame(0, SaleItemModifier::count());
    }
}
