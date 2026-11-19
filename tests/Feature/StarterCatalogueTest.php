<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * « Catalogue type salon (Maroc) »: a new beauty shop in dirhams fills its
 * catalogue in one click, through the same import as an Excel sheet.
 */
class StarterCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('public');
        $this->tenant = Tenant::firstOrFail();
        $this->owner = User::where('email', 'amina@librairie-atlas.ma')->firstOrFail();
        $this->salon('beauty', 'MAD');
        Item::where('tenant_id', $this->tenant->id)->forceDelete();
    }

    private function salon(string $mode, string $currency): void
    {
        $settings = $this->tenant->settings ?? [];
        data_set($settings, 'company_profile.business_mode', $mode);
        $this->tenant->forceFill(['business_mode' => $mode, 'currency' => $currency, 'settings' => $settings])->save();
    }

    private function apply()
    {
        return $this->actingAs($this->owner)->post(route('catalog.starter.apply'));
    }

    private function item(string $title): Item
    {
        return Item::where('tenant_id', $this->tenant->id)->where('title', $title)->sole();
    }

    public function test_applying_creates_the_services_categories_variants_options_and_photos(): void
    {
        $this->apply()->assertRedirect()->assertSessionHas('status', '45 prestations créées, 8 catégories, 45 photos, 30 produits de soin, 15 recettes de produits utilisés.');

        // The care products the services use come with them, but are not services.
        $services = Item::where('tenant_id', $this->tenant->id)->where('type', 'service')->get();
        $this->assertCount(45, $services);
        $this->assertSame(['service'], $services->pluck('type')->unique()->all());
        $this->assertSame(8, $services->pluck('category_id')->unique()->count());
        $this->assertSame(45, $services->filter(fn ($i) => ! empty($i->images))->count());
        foreach ($services as $service) {
            Storage::disk('public')->assertExists($service->images[0]);
            $this->assertStringStartsWith('catalogue/items/', $service->images[0]);
        }

        $massage = $this->item('Massage relaxant');
        $variants = $massage->variants()->orderBy('sort_order')->get();
        $this->assertSame(['30 min', '1 h', '1 h 30'], $variants->pluck('name')->all());
        $this->assertEquals([100.0, 200.0, 300.0], $variants->map->price()->all());
        $this->assertSame([30, 60, 90], $variants->pluck('duration_minutes')->all());

        $pedicure = $this->item('Pédicure');
        $group = $pedicure->modifierGroups()->get();
        $this->assertCount(1, $group);
        $this->assertSame(['Paraffine'], $group->first()->modifiers->pluck('name')->all());
        $this->assertEquals(50.0, (float) $group->first()->modifiers->first()->price_delta);

        $coloration = $this->item('Coloration');
        $this->assertTrue($coloration->price_is_from);
        $this->assertEquals(250.0, (float) $coloration->sale_price);
        $this->assertSame(90, $coloration->duration_minutes);
        $this->assertFalse($this->item('Duvet')->price_is_from);

        $this->assertStringNotContainsStringIgnoringCase('simo', (string) json_encode($services->pluck('description', 'title')));
    }

    public function test_applying_twice_updates_and_never_duplicates_or_replaces_photos(): void
    {
        $this->apply();
        $photo = $this->item('Shampoing')->images[0];
        $this->item('Brushing')->update(['sale_price' => 99]);

        $this->apply()->assertSessionHas('status', '0 prestation créée, 45 mises à jour, 8 catégories, 0 photo.');

        $this->assertSame(45, Item::where('tenant_id', $this->tenant->id)->where('type', 'service')->count());
        $this->assertSame(30, Item::where('tenant_id', $this->tenant->id)->where('type', 'supply')->count());
        $this->assertSame(9, \App\Models\Category::where('tenant_id', $this->tenant->id)->whereHas('items')->count());
        $this->assertSame(3, $this->item('Shampoing')->variants()->count());
        $this->assertSame([$photo], $this->item('Shampoing')->images, 'a photo already there is kept');
        $this->assertEquals(40.0, (float) $this->item('Brushing')->sale_price, 'a re-apply restores the starter price');
        $this->assertCount(1, $this->item('Pédicure')->modifierGroups()->get());
    }

    public function test_applying_creates_the_care_products_and_the_recipes_once(): void
    {
        $this->apply();

        $products = Item::where('tenant_id', $this->tenant->id)->where('type', 'supply')->get();
        $this->assertCount(30, $products);
        $gel = $this->item('Gel nettoyant visage');
        $this->assertEquals(200, (float) $gel->content_quantity);
        $this->assertSame('ml', $gel->content_unit);
        $this->assertEquals(0, (float) $gel->stock_quantity);
        $this->assertSame(0.0, (float) $gel->sale_price, 'not sold retail');
        $this->assertGreaterThan(0, (float) $this->item('Crème solaire SPF 50')->sale_price, 'sold retail too');
        // Equipment is never consumed, so it is not a product at all.
        $this->assertSame(0, Item::where('tenant_id', $this->tenant->id)->where('title', 'like', '%Chauffe-cire%')->count());

        // A facial: a Masque choice group with a default, gloves in pieces.
        $facial = $this->item('Soin visage normal');
        $rows = \App\Models\ItemComponent::where('item_id', $facial->id)->get();
        $mask = $rows->where('choice_group', 'Masque');
        $this->assertCount(2, $mask);
        $this->assertSame(['Masque hydratant'], $mask->where('is_default', true)->map(fn ($r) => Item::find($r->component_item_id)->title)->values()->all());
        $this->assertEquals(2, (float) $rows->firstWhere('component_item_id', $this->item('Gants jetables')->id)->quantity);

        // Waxing scales with the zone: armpits take less wax than legs.
        $wax = fn (string $zone) => (float) \App\Models\ItemComponent::where('item_id', $this->item($zone)->id)
            ->where('component_item_id', $this->item('Cire chaude (pelable)')->id)->value('quantity');
        $this->assertGreaterThan(0, $wax('Aisselles'));
        $this->assertLessThan($wax('Jambes'), $wax('Aisselles'));

        // Again: nothing is duplicated, and a recipe the shop edited stays as it made it.
        $before = \App\Models\ItemComponent::count();
        \App\Models\ItemComponent::where('item_id', $facial->id)->where('component_item_id', $gel->id)->update(['quantity' => 9]);
        $this->apply();
        $this->assertSame(30, Item::where('tenant_id', $this->tenant->id)->where('type', 'supply')->count());
        $this->assertSame($before, \App\Models\ItemComponent::count());
        $this->assertEquals(9, (float) \App\Models\ItemComponent::where('item_id', $facial->id)->where('component_item_id', $gel->id)->value('quantity'));
    }

    public function test_the_care_products_get_a_container_and_a_shops_own_value_is_never_overwritten(): void
    {
        $this->apply();
        $this->assertSame('flacon', $this->item('Gel nettoyant visage')->content_container);
        $this->assertSame('pot', $this->item('Cire chaude (pelable)')->content_container);
        $this->assertSame('boite', $this->item('Gants jetables')->content_container);
        $this->assertSame('paquet', $this->item('Bandes d\'épilation')->content_container);
        $this->assertSame('rouleau', $this->item('Papier d\'examen / draps jetables')->content_container);
        $this->assertSame(0, Item::where('tenant_id', $this->tenant->id)->where('type', 'supply')->whereNull('content_container')->count());

        // The shop renamed one container, and a product made before containers existed has none.
        $this->item('Gel nettoyant visage')->update(['content_container' => 'bidon']);
        $this->item('Talc')->update(['content_container' => null]);
        $this->apply();
        $this->assertSame('bidon', $this->item('Gel nettoyant visage')->content_container, 'the shop\'s own value stays');
        $this->assertSame('pot', $this->item('Talc')->content_container, 'a missing one is filled in');
    }

    public function test_it_is_offered_in_the_empty_catalogue_and_the_import_panel_of_a_beauty_shop(): void
    {
        $this->actingAs($this->owner)->get(route('catalog', ['panel' => 'articles']))
            ->assertOk()->assertSee('data-starter-catalogue', false)->assertSee('45 prestations dans 8 catégories');
        $this->actingAs($this->owner)->get(route('catalog', ['panel' => 'import']))
            ->assertOk()->assertSee('Catalogue type salon (Maroc)');

        $this->apply();
        $this->get(route('catalog', ['panel' => 'articles']))->assertOk()->assertDontSee('data-starter-catalogue', false);
        $this->get(route('catalog', ['panel' => 'import']))->assertOk()->assertSee('data-starter-catalogue', false);
    }

    public function test_it_is_hidden_and_refused_for_another_trade_or_currency(): void
    {
        $this->salon('library', 'MAD');
        $this->actingAs($this->owner)->get(route('catalog', ['panel' => 'import']))->assertOk()->assertDontSee('data-starter-catalogue', false);
        $this->apply()->assertNotFound();

        $this->salon('beauty', 'EUR');
        $this->actingAs($this->owner)->get(route('catalog', ['panel' => 'import']))->assertOk()->assertDontSee('data-starter-catalogue', false);
        $this->apply()->assertNotFound();

        $this->assertSame(0, Item::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_a_cashier_cannot_apply_it(): void
    {
        Role::updateOrCreate(
            ['tenant_id' => $this->tenant->id, 'key' => 'limited'],
            ['name' => 'Limité', 'permissions' => ['sales.create', 'items.view'], 'is_system' => false],
        );
        $cashier = User::create(['name' => 'Employé', 'email' => 'caisse-'.uniqid().'@example.test', 'password' => bcrypt('password-test')]);
        $this->tenant->users()->attach($cashier->id, ['role' => 'limited']);

        $this->actingAs($cashier)->post(route('catalog.starter.apply'))->assertForbidden();
        $this->assertSame(0, Item::where('tenant_id', $this->tenant->id)->count());
    }
}
