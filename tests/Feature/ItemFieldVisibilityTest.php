<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Support\ItemFieldVisibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A haircut asked for its ISBN and its barcode: the article forms carried a
 * bookshop's fields for every shop. A field a shop and type do not use is
 * hidden, and a value already saved under it is never wiped by a save.
 */
class ItemFieldVisibilityTest extends TestCase
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

    private function mode(string $mode): void
    {
        $settings = $this->tenant->settings ?? [];
        unset($settings['store']['business_activity']);
        data_set($settings, 'company_profile.business_mode', $mode);
        $this->tenant->update(['settings' => $settings]);
    }

    private function item(string $type, array $extra = []): Item
    {
        return Item::create($extra + [
            'tenant_id' => $this->tenant->id, 'type' => $type, 'title' => 'Article '.$type,
            'item_code' => 'X'.random_int(1000, 9999), 'isbn' => '978-KEEP', 'barcode' => 'BAR-KEEP', 'sku' => 'SKU-KEEP',
            'author' => 'AUT-KEEP', 'sale_price' => 80, 'purchase_price' => 0, 'price' => 80,
            'stock_quantity' => 0, 'min_stock_threshold' => 0, 'item_group' => 'Single',
            'unit_id' => Unit::where('tenant_id', $this->tenant->id)->value('id'),
            'tax_id' => Tax::where('tenant_id', $this->tenant->id)->value('id'),
        ]);
    }

    private function payload(Item $item, array $extra = []): array
    {
        return $extra + [
            'type' => $item->type, 'title' => $item->title.' bis', 'item_code' => $item->item_code,
            'unit_id' => $item->unit_id, 'tax_id' => $item->tax_id, 'purchase_price' => 0, 'sale_price' => 80,
            'stock_quantity' => 0, 'min_stock_threshold' => 0,
        ];
    }

    public function test_the_rule_by_activity_and_type(): void
    {
        $book = ItemFieldVisibility::for('library', 'book');
        $this->assertTrue($book['isbn'] && $book['barcode'] && $book['sku'] && $book['author']);
        $this->assertSame('Auteur', $book['author_label']);

        $product = ItemFieldVisibility::for('beauty', 'supply');
        $this->assertFalse($product['isbn']);
        $this->assertTrue($product['author'] && $product['barcode'] && $product['sku']);
        $this->assertSame('Marque', $product['author_label']);

        foreach (['library', 'beauty', 'barber', 'retail'] as $activity) {
            $service = ItemFieldVisibility::for($activity, 'service');
            $this->assertFalse($service['isbn'] || $service['author'] || $service['barcode'] || $service['sku'], $activity);
            $this->assertFalse($service['group'], $activity);
        }
        $this->assertTrue(ItemFieldVisibility::for('beauty', 'service', true)['group']);
    }

    public function test_a_salon_product_form_says_marque_and_has_no_isbn(): void
    {
        $this->mode('beauty');

        $this->actingAs($this->owner)->get(route('catalog', ['panel' => 'ajouter']))
            ->assertOk()
            ->assertSee('name="barcode"', false)
            ->assertSee('name="sku"', false)
            ->assertSee('name="author"', false)
            ->assertDontSee('name="isbn"', false);
    }

    public function test_a_bookshop_product_form_keeps_isbn_and_auteur(): void
    {
        $this->mode('library');

        $this->actingAs($this->owner)->get(route('catalog', ['panel' => 'ajouter']))
            ->assertOk()
            ->assertSee('name="isbn"', false)
            ->assertSee('name="author"', false)
            ->assertSee('Auteur');
    }

    public function test_the_service_form_has_none_of_the_product_fields(): void
    {
        $this->mode('beauty');

        $this->actingAs($this->owner)->get(route('catalog', ['panel' => 'ajouter-service']))
            ->assertOk()
            ->assertSee('name="duration_minutes"', false)
            ->assertDontSee('name="barcode"', false)
            ->assertDontSee('name="sku"', false)
            ->assertDontSee('name="isbn"', false)
            ->assertDontSee('name="author"', false);
    }

    public function test_the_service_detail_shows_its_code_and_category_not_a_barcode(): void
    {
        $this->mode('beauty');
        $service = $this->item('service', ['title' => 'Manucure']);

        $this->actingAs($this->owner)->get(route('catalog', ['panel' => 'services', 'edit' => $service->id]))
            ->assertOk()
            ->assertSee($service->item_code)
            ->assertDontSee('BAR-KEEP')
            ->assertDontSee('Sans code-barres')
            ->assertDontSee('name="barcode"', false);
    }

    public function test_the_product_detail_shows_the_barcode(): void
    {
        $this->mode('beauty');
        $product = $this->item('supply');

        $this->actingAs($this->owner)->get(route('catalog', ['panel' => 'articles', 'edit' => $product->id]))
            ->assertOk()
            ->assertSee('BAR-KEEP')
            ->assertDontSee('name="isbn"', false);
    }

    public function test_saving_a_service_does_not_wipe_what_its_form_never_showed(): void
    {
        $this->mode('beauty');
        $service = $this->item('service');

        $this->actingAs($this->owner)->put(route('catalog.items.update', $service), $this->payload($service))
            ->assertSessionHasNoErrors();

        $service->refresh();
        $this->assertSame($service->title, 'Article service bis');
        $this->assertSame('978-KEEP', $service->isbn);
        $this->assertSame('BAR-KEEP', $service->barcode);
        $this->assertSame('SKU-KEEP', $service->sku);
        $this->assertSame('AUT-KEEP', $service->author);
    }

    public function test_a_hidden_isbn_survives_a_product_save_and_a_shown_field_can_change(): void
    {
        $this->mode('beauty');
        $product = $this->item('supply');

        $this->actingAs($this->owner)->put(route('catalog.items.update', $product), $this->payload($product, [
            'isbn' => '', 'barcode' => 'BAR-NEW', 'author' => 'Nouvelle marque',
        ]))->assertSessionHasNoErrors();

        $product->refresh();
        $this->assertSame('978-KEEP', $product->isbn);
        $this->assertSame('BAR-NEW', $product->barcode);
        $this->assertSame('Nouvelle marque', $product->author);
    }

    public function test_a_bookshop_can_still_clear_its_isbn(): void
    {
        $this->mode('library');
        $book = $this->item('book');

        $this->actingAs($this->owner)->put(route('catalog.items.update', $book), $this->payload($book, ['isbn' => '']))
            ->assertSessionHasNoErrors();

        $this->assertNull($book->fresh()->isbn);
    }
}
