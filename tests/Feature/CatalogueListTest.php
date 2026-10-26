<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The catalogue list is the whole catalogue.
 *
 * It used to leave services out unless one searched, so a spa that imported
 * 13 services and 12 products saw 12 rows and thought the rest were lost.
 * Now every type is listed, each row says what it is with a coloured badge,
 * and type chips narrow the list.
 */
class CatalogueListTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->tenant = Tenant::firstOrFail();
        $this->actingAs(User::where('email', 'amina@librairie-atlas.ma')->firstOrFail());
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(array $query = []): array
    {
        return $this->getJson(route('catalog.data', array_merge([
            'panel' => 'articles', 'draw' => 1, 'start' => 0, 'length' => 500,
        ], $query)))->assertOk()->json('data');
    }

    private function make(array $attributes): Item
    {
        $template = Item::where('tenant_id', $this->tenant->id)->firstOrFail();

        return Item::create(array_merge($template->only(['tenant_id', 'category_id', 'unit_id', 'tax_id']), [
            'type' => 'supply',
            'status' => 'active',
            'is_enabled' => true,
            'checkout_visible' => true,
            'online_store_visible' => true,
            'item_code' => 'CL'.random_int(10000, 99999),
            'sale_price' => 100,
            'purchase_price' => 0,
            'price' => 0,
            'stock_quantity' => 5,
            'min_stock_threshold' => 0,
            'images' => [],
        ], $attributes));
    }

    public function test_the_list_holds_every_type_including_services(): void
    {
        $service = $this->make(['type' => 'service', 'title' => 'Massage liste', 'stock_quantity' => 9999]);
        $product = $this->make(['title' => 'Shampooing liste']);

        $response = $this->getJson(route('catalog.data', ['panel' => 'articles', 'draw' => 1, 'start' => 0, 'length' => 10]))->assertOk();
        $this->assertSame(Item::where('tenant_id', $this->tenant->id)->count(), $response->json('recordsTotal'));

        $titles = collect($this->rows())->pluck('title')->implode(' ');
        $this->assertStringContainsString($service->title, $titles);
        $this->assertStringContainsString($product->title, $titles);
    }

    public function test_a_type_chip_narrows_the_list(): void
    {
        $this->make(['type' => 'service', 'title' => 'Massage chip']);

        $services = $this->rows(['type' => 'service']);
        $this->assertNotEmpty($services);
        foreach ($services as $row) {
            $this->assertStringContainsString('catalog-type-badge is-service', $row['title']);
        }

        foreach ($this->rows(['type' => 'supply']) as $row) {
            $this->assertStringNotContainsString('is-service', $row['title']);
        }
    }

    public function test_each_row_says_what_it_is(): void
    {
        $this->make(['type' => 'service', 'title' => 'Badge service']);
        $this->make(['type' => 'supply', 'title' => 'Badge produit']);

        $byTitle = collect($this->rows(['q' => 'Badge']))->keyBy(fn (array $row) => str_contains($row['title'], 'Badge service') ? 'service' : 'product');

        $this->assertStringContainsString('catalog-type-badge is-service', $byTitle['service']['title']);
        $this->assertStringContainsString('catalog-thumb is-placeholder is-service', $byTitle['service']['title'], 'no photo: an icon for the type, not two letters');
        $this->assertStringContainsString('catalog-type-badge is-product', $byTitle['product']['title']);
        $this->assertStringContainsString('sans stock', $byTitle['service']['stock_quantity']);
    }

    public function test_stock_price_and_state_read_at_a_glance(): void
    {
        $this->make(['title' => 'Etat rupture', 'stock_quantity' => 0]);
        $this->make(['title' => 'Etat archive', 'status' => 'archived']);
        $this->make(['title' => 'Etat desactive', 'is_enabled' => false]);
        $this->make(['title' => 'Etat marge', 'sale_price' => 100, 'purchase_price' => 60, 'checkout_visible' => false]);

        $rows = collect($this->rows(['q' => 'Etat']))->mapWithKeys(fn (array $row) => [strip_tags(explode('</p>', $row['title'])[0]) => $row]);

        $this->assertStringContainsString('is-danger', $rows['Etat rupture']['stock_quantity']);
        $this->assertStringContainsString('Archivé', $rows['Etat archive']['status']);
        $this->assertStringContainsString('Désactivé', $rows['Etat desactive']['status']);
        $this->assertStringContainsString('Marge 40 %', $rows['Etat marge']['sale_price']);
        $this->assertStringContainsString('Caché caisse', $rows['Etat marge']['status']);
        $this->assertStringNotContainsString('Caché', $rows['Etat rupture']['status'], 'a flag only when something is hidden');
    }

    public function test_the_page_offers_type_chips_with_counts(): void
    {
        $this->make(['type' => 'service', 'title' => 'Chip count']);
        $services = Item::where('tenant_id', $this->tenant->id)->where('type', 'service')->count();
        $all = Item::where('tenant_id', $this->tenant->id)->count();

        $this->get(route('catalog', ['panel' => 'articles']))
            ->assertOk()
            ->assertSee('data-type-chip="all"', false)
            ->assertSee('Tous <span>'.$all.'</span>', false)
            ->assertSee('data-type-chip="service"', false)
            ->assertSee('<span>'.$services.'</span>', false)
            ->assertDontSee('data-services-pointer', false)
            ->assertDontSee('Uploader Excel');
    }

    public function test_the_services_shortcut_opens_the_list_on_services(): void
    {
        $this->get(route('catalog', ['panel' => 'services']))
            ->assertOk()
            ->assertSee('data-type-input', false)
            ->assertSee('value="service" data-type-input', false);
    }
}
