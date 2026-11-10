<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ItemTypes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Every shop sells Produit and Service; its activity names them and may add
 * its own type (Livre, Médicament…). A general shop saw « Livres » anyway.
 * The shop can now rename or hide them in Paramètres › Types d'articles.
 */
class ShopItemTypesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->tenant = Tenant::firstOrFail();
        $this->owner = User::firstOrFail();
    }

    private function activity(string $activity): void
    {
        $settings = $this->tenant->settings ?? [];
        data_set($settings, 'store.business_activity', $activity);
        data_set($settings, 'company_profile.business_mode', match ($activity) {
            'bookstore' => 'library', 'general' => 'retail', default => $activity,
        });
        $this->tenant->update(['settings' => $settings]);
        $this->tenant->refresh();
    }

    private function values(): array
    {
        return array_column(ItemTypes::forTenant($this->tenant->fresh()), 'value');
    }

    private function syncSettings(): array
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $this->owner->email, 'password' => 'password', 'device_name' => 'till-'.Str::uuid(),
        ])->json('token');
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->getJson('/api/v1/sync/settings')->assertOk()->json();
    }

    public function test_every_shop_has_product_and_service_and_a_general_shop_nothing_else(): void
    {
        $this->activity('general');
        $this->assertSame(['supply', 'service'], $this->values());

        $this->activity('bookstore');
        $this->assertSame(['book', 'supply', 'service'], $this->values());

        $this->activity('restaurant');
        $types = collect(ItemTypes::forTenant($this->tenant->fresh()))->keyBy('value');
        $this->assertSame('Plat / menu', $types['book']['label']);
        $this->assertSame('Plats / menus', $types['book']['plural']);
        $this->assertSame('Produits cuisine', $types['supply']['plural']);
    }

    public function test_the_web_till_and_labels_filter_by_the_shops_own_types(): void
    {
        $this->activity('general');

        foreach ([route('pos'), route('catalog.labels')] as $page) {
            $this->actingAs($this->owner)->get($page)->assertOk()
                ->assertSee('<option value="supply" >Produits</option>', false)
                ->assertSee('<option value="service" >Services</option>', false)
                ->assertDontSee('<option value="book"', false);
        }
    }

    public function test_a_renamed_type_reaches_the_catalogue_the_till_and_the_app(): void
    {
        $this->activity('general');

        $this->actingAs($this->owner)->post(route('settings.item-types.update'), ['types' => [
            'supply' => ['label' => 'Pièce', 'plural' => 'Pièces', 'hint' => 'Moteur, frein…', 'visible' => '1'],
            'service' => ['label' => 'Réparation', 'plural' => 'Réparations', 'hint' => '', 'visible' => '1'],
        ]])->assertRedirect(route('module', ['module' => 'settings', 'section' => 'item-types']));

        $this->assertSame('Pièce', ItemTypes::label($this->tenant->fresh(), 'supply'));
        $this->actingAs($this->owner)->get(route('catalog', ['panel' => 'ajouter']))->assertOk()->assertSee('Pièce')->assertSee('Moteur, frein…');
        $this->actingAs($this->owner)->get(route('pos'))->assertOk()->assertSee('Pièces')->assertSee('Réparations');

        $sync = $this->syncSettings();
        $this->assertSame(
            [['value' => 'supply', 'label' => 'Pièce', 'plural' => 'Pièces', 'hint' => 'Moteur, frein…'],
                // An emptied hint falls back to the activity's.
                ['value' => 'service', 'label' => 'Réparation', 'plural' => 'Réparations', 'hint' => 'Sans stock : coupe, soin, réparation, livraison…']],
            $sync['item_types'],
        );
    }

    public function test_a_hidden_type_leaves_the_form_and_filters_but_its_items_keep_it(): void
    {
        $this->activity('bookstore');
        $book = Item::create([
            'tenant_id' => $this->tenant->id, 'title' => 'Le Petit Prince', 'type' => 'book',
            'status' => 'active', 'sale_price' => 60, 'purchase_price' => 30, 'stock_quantity' => 2,
        ]);

        $this->actingAs($this->owner)->post(route('settings.item-types.update'), ['types' => [
            'book' => ['label' => 'Livre', 'plural' => 'Livres', 'visible' => '0'],
            'supply' => ['label' => 'Produit', 'plural' => 'Produits', 'visible' => '0'],
            'service' => ['label' => 'Service', 'plural' => 'Services', 'visible' => '1'],
        ]])->assertRedirect();

        $this->assertSame(['supply', 'service'], array_column(ItemTypes::visibleForTenant($this->tenant->fresh()), 'value'), 'Produit cannot be hidden');
        $sync = $this->syncSettings();
        $this->assertSame(['supply', 'service'], array_column($sync['item_types'], 'value'));
        $this->assertSame('Livre', $sync['item_type_names']['book']['label'], 'an existing book still has its name');
        $this->assertSame('book', $book->fresh()->type);
        $this->assertSame(['book' => ['hidden' => true]], ItemTypes::overrides($this->tenant->fresh()), 'only what differs is kept');
    }

    public function test_back_to_the_activitys_names(): void
    {
        $this->activity('general');
        $this->actingAs($this->owner)->post(route('settings.item-types.update'), ['types' => [
            'supply' => ['label' => 'Pièce', 'visible' => '1'],
            'service' => ['label' => 'Service', 'visible' => '1'],
        ]]);
        $this->assertSame('Pièce', ItemTypes::label($this->tenant->fresh(), 'supply'));

        $this->actingAs($this->owner)->post(route('settings.item-types.update'), ['reset' => '1'])->assertRedirect();

        $this->assertSame('Produit', ItemTypes::label($this->tenant->fresh(), 'supply'));
        $this->assertSame([], ItemTypes::overrides($this->tenant->fresh()));
    }

    public function test_the_settings_page_lists_the_shops_types(): void
    {
        $this->activity('pharmacy');

        $this->actingAs($this->owner)->get(route('module', ['module' => 'settings', 'section' => 'item-types']))->assertOk()
            ->assertSee('Types d’articles')
            ->assertSee('data-item-type="medication"', false)
            ->assertSee('data-item-type="supply"', false)
            ->assertSee('data-item-type="service"', false)
            ->assertDontSee('data-item-type="book"', false);
    }
}
