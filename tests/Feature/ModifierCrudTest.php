<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Sale;
use App\Models\SaleItemModifier;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Creating line options in the back office and attaching them to articles. */
class ModifierCrudTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->tenant = Tenant::firstOrFail();
        $this->item = Item::where('tenant_id', $this->tenant->id)
            ->where('type', '!=', 'service')->firstOrFail();
    }

    private function group(string $name = 'Suppléments', array $overrides = []): ModifierGroup
    {
        $this->post(route('catalog.modifier-groups.store'), array_merge([
            'name' => $name, 'min_select' => 0, 'max_select' => 2,
        ], $overrides))->assertRedirect();

        return ModifierGroup::where('tenant_id', $this->tenant->id)
            ->where('name', $name)->firstOrFail();
    }

    public function test_a_group_can_be_created_with_its_rules(): void
    {
        $group = $this->group('Cuisson', ['min_select' => 1, 'max_select' => 1]);

        $this->assertSame(1, $group->min_select);
        $this->assertSame(1, $group->max_select);
        $this->assertTrue($group->isRequired());
    }

    public function test_an_unlimited_group_leaves_the_ceiling_open(): void
    {
        // A burger can take every topping; a ceiling invented here would be
        // wrong for someone.
        $group = $this->group('Garnitures', ['max_select' => null]);

        $this->assertNull($group->max_select);
    }

    public function test_a_maximum_below_the_minimum_is_refused(): void
    {
        $this->post(route('catalog.modifier-groups.store'), [
            'name' => 'Impossible', 'min_select' => 3, 'max_select' => 1,
        ])->assertSessionHasErrors('max_select');
    }

    public function test_a_group_name_cannot_repeat(): void
    {
        $this->group('Suppléments');

        $this->post(route('catalog.modifier-groups.store'), ['name' => 'Suppléments'])
            ->assertSessionHasErrors('name');
    }

    public function test_an_option_can_change_the_price_either_way(): void
    {
        // "Sans fromage -2 DH" is as real as a supplement.
        $group = $this->group();

        $this->post(route('catalog.modifiers.store', $group), [
            'name' => 'Sans fromage', 'price_delta' => -2,
        ])->assertRedirect();

        $this->assertSame('-2.00', (string) Modifier::where('name', 'Sans fromage')->firstOrFail()->price_delta);
    }

    public function test_an_option_can_be_linked_to_the_article_it_eats(): void
    {
        $group = $this->group();
        $cheese = Item::where('tenant_id', $this->tenant->id)
            ->where('id', '!=', $this->item->id)->where('type', '!=', 'service')->firstOrFail();

        $this->post(route('catalog.modifiers.store', $group), [
            'name' => 'Fromage', 'price_delta' => 5,
            'linked_item_id' => $cheese->id, 'consumes_quantity' => 2,
        ])->assertRedirect();

        $modifier = Modifier::where('name', 'Fromage')->firstOrFail();
        $this->assertSame($cheese->id, $modifier->linked_item_id);
        $this->assertTrue($modifier->consumesStock());
    }

    public function test_an_option_with_no_link_consumes_nothing(): void
    {
        $group = $this->group();

        $this->post(route('catalog.modifiers.store', $group), [
            'name' => 'Sans glace', 'price_delta' => 0,
        ])->assertRedirect();

        $this->assertFalse(Modifier::where('name', 'Sans glace')->firstOrFail()->consumesStock());
    }

    public function test_an_option_name_cannot_repeat_within_its_group(): void
    {
        $group = $this->group();
        $this->post(route('catalog.modifiers.store', $group), ['name' => 'Fromage'])->assertRedirect();

        $this->post(route('catalog.modifiers.store', $group), ['name' => 'Fromage'])
            ->assertSessionHasErrors('name');
    }

    public function test_the_same_option_name_may_exist_in_another_group(): void
    {
        $this->post(route('catalog.modifiers.store', $this->group('Suppléments')), ['name' => 'Nature'])
            ->assertRedirect();

        $this->post(route('catalog.modifiers.store', $this->group('Cuisson')), ['name' => 'Nature'])
            ->assertRedirect();

        $this->assertSame(2, Modifier::where('name', 'Nature')->count());
    }

    public function test_a_group_is_attached_to_the_articles_that_offer_it(): void
    {
        $group = $this->group();

        $this->post(route('catalog.modifier-groups.assign', $group), [
            'item_ids' => [$this->item->id],
        ])->assertRedirect();

        $this->assertTrue($this->item->modifierGroups()->whereKey($group->id)->exists());
    }

    public function test_unticking_an_article_detaches_it(): void
    {
        $group = $this->group();
        $this->post(route('catalog.modifier-groups.assign', $group), ['item_ids' => [$this->item->id]]);

        $this->post(route('catalog.modifier-groups.assign', $group), [])->assertRedirect();

        $this->assertFalse($this->item->modifierGroups()->whereKey($group->id)->exists());
    }

    public function test_another_tenants_article_cannot_be_attached(): void
    {
        $group = $this->group();
        $other = Tenant::create(['name' => 'Autre', 'slug' => 'autre-'.uniqid()]);
        $foreign = Item::create([
            'tenant_id' => $other->id, 'title' => 'Ailleurs', 'type' => 'supply',
            'sale_price' => 10, 'item_group' => 'Single',
        ]);

        $this->post(route('catalog.modifier-groups.assign', $group), ['item_ids' => [$foreign->id]])
            ->assertSessionHasErrors('item_ids.0');
    }

    public function test_an_option_already_sold_is_retired_rather_than_deleted(): void
    {
        // sale_item_modifiers.modifier_id nulls on delete, so the old tickets
        // would lose the thread back to what was offered.
        $group = $this->group();
        $this->post(route('catalog.modifiers.store', $group), ['name' => 'Fromage'])->assertRedirect();
        $modifier = Modifier::where('name', 'Fromage')->firstOrFail();

        $sale = Sale::create([
            'tenant_id' => $this->tenant->id, 'number' => 'BL77001', 'status' => 'paid',
            'payment_method' => 'cash', 'total_amount' => 10, 'sold_at' => now(),
        ]);
        $line = $sale->items()->create([
            'item_id' => $this->item->id, 'name' => 'x',
            'quantity' => 1, 'unit_price' => 10, 'total_price' => 10,
        ]);
        SaleItemModifier::create([
            'tenant_id' => $this->tenant->id, 'sale_item_id' => $line->id,
            'modifier_id' => $modifier->id, 'name' => 'Fromage', 'price_delta' => 5,
        ]);

        $this->delete(route('catalog.modifiers.destroy', $modifier))->assertRedirect();

        $this->assertDatabaseHas('modifiers', ['id' => $modifier->id, 'is_active' => false]);
    }

    public function test_an_unsold_option_is_deleted_outright(): void
    {
        $group = $this->group();
        $this->post(route('catalog.modifiers.store', $group), ['name' => 'Fromage'])->assertRedirect();
        $modifier = Modifier::where('name', 'Fromage')->firstOrFail();

        $this->delete(route('catalog.modifiers.destroy', $modifier))->assertRedirect();

        $this->assertDatabaseMissing('modifiers', ['id' => $modifier->id]);
    }

    public function test_one_tenant_cannot_touch_anothers_group(): void
    {
        $group = $this->group();
        $other = Tenant::create(['name' => 'Autre', 'slug' => 'autre-'.uniqid()]);
        $group->update(['tenant_id' => $other->id]);

        $this->delete(route('catalog.modifier-groups.destroy', $group))->assertNotFound();
    }

    public function test_the_panel_shows_the_groups(): void
    {
        $group = $this->group();
        $this->post(route('catalog.modifiers.store', $group), ['name' => 'Fromage', 'price_delta' => 5]);

        $this->get(route('catalog', ['panel' => 'variantes']))
            ->assertOk()
            ->assertSee('Options de ligne')
            ->assertSee('Suppléments')
            ->assertSee('Fromage');
    }
}
