<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Location;
use App\Models\OptionType;
use App\Models\OptionValue;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Tenant;
use App\Services\Catalogue\VariantService;
use App\Services\Inventory\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Selling a sub-product at the till.
 *
 * The till deducts the VARIANT's pile, not the article's, and records which
 * one went — the two halves that make an option trackable.
 */
class VariantCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Item $item;
    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->tenant = Tenant::firstOrFail();
        $this->location = Location::where('tenant_id', $this->tenant->id)
            ->where('is_default', true)->firstOrFail();
        $this->item = Item::where('tenant_id', $this->tenant->id)
            ->where('type', '!=', 'service')->firstOrFail();

        $size = OptionType::create(['tenant_id' => $this->tenant->id, 'name' => 'Taille']);
        foreach (['S', 'L'] as $index => $value) {
            OptionValue::create([
                'tenant_id' => $this->tenant->id, 'option_type_id' => $size->id,
                'value' => $value, 'sort_order' => $index,
            ]);
        }
        app(VariantService::class)->generateMatrix($this->item, [
            $size->id => $size->values()->pluck('id')->all(),
        ]);
    }

    private function stock(string $variantName, int $quantity): void
    {
        $variant = $this->item->variants()->where('name', $variantName)->firstOrFail();

        app(InventoryService::class)->move(new \App\Services\Inventory\MovementDTO(
            tenantId: $this->tenant->id,
            itemId: $this->item->id,
            variantId: $variant->id,
            locationId: $this->location->id,
            type: \App\Services\Inventory\InventoryMovementType::PURCHASE,
            quantityChanged: $quantity,
        ));
    }

    private function available(string $variantName): int
    {
        $variant = $this->item->variants()->where('name', $variantName)->firstOrFail();

        return app(InventoryService::class)->quantity(
            $this->tenant->id, $this->item->id, $variant->id, $this->location->id,
        );
    }

    private function checkout(array $line): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('pos.store'), [
            'cart' => json_encode([$line]),
            'cash_amount' => 1000,
        ]);
    }

    public function test_selling_a_variant_deducts_that_variants_pile(): void
    {
        $this->stock('L', 10);
        $this->stock('S', 10);
        $variant = $this->item->variants()->where('name', 'L')->firstOrFail();

        $this->checkout([
            'id' => $this->item->id, 'variant_id' => $variant->id,
            'quantity' => 3, 'price' => 100,
        ])->assertRedirect();

        $this->assertSame(7, $this->available('L'));
        $this->assertSame(10, $this->available('S'), 'the other size must be untouched');
    }

    public function test_the_sale_line_says_which_variant_went(): void
    {
        $this->stock('L', 5);
        $variant = $this->item->variants()->where('name', 'L')->firstOrFail();

        $this->checkout([
            'id' => $this->item->id, 'variant_id' => $variant->id,
            'quantity' => 1, 'price' => 100,
        ])->assertRedirect();

        $line = SaleItem::latest('id')->firstOrFail();
        $this->assertSame($variant->id, $line->variant_id);
        $this->assertStringContainsString('L', $line->name);
    }

    public function test_an_article_with_variants_cannot_be_sold_without_choosing_one(): void
    {
        // Otherwise the sale deducts a pile that does not exist and nobody can
        // say afterwards what left the shelf.
        $this->stock('L', 5);
        // Counted before and after: the seeder's own sales were created in
        // this same test run, so "sales made in the last minute" counts those.
        $before = Sale::where('tenant_id', $this->tenant->id)->count();

        $this->checkout(['id' => $this->item->id, 'quantity' => 1, 'price' => 100])
            ->assertSessionHasErrors();

        $this->assertSame($before, Sale::where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(5, $this->available('L'), 'no stock should have moved');
    }

    public function test_a_variant_of_another_article_is_refused(): void
    {
        $this->stock('L', 5);
        $other = Item::where('tenant_id', $this->tenant->id)
            ->where('id', '!=', $this->item->id)->where('type', '!=', 'service')->firstOrFail();
        $foreign = $this->item->variants()->where('name', 'S')->firstOrFail();

        $this->checkout([
            'id' => $other->id, 'variant_id' => $foreign->id,
            'quantity' => 1, 'price' => 100,
        ])->assertSessionHasErrors();
    }

    public function test_an_empty_size_is_refused_even_when_the_others_are_full(): void
    {
        // The whole reason variant stock is separate.
        $this->stock('S', 50);
        $variant = $this->item->variants()->where('name', 'L')->firstOrFail();

        $this->checkout([
            'id' => $this->item->id, 'variant_id' => $variant->id,
            'quantity' => 1, 'price' => 100,
        ])->assertSessionHasErrors();
    }

    public function test_a_return_puts_the_goods_back_on_the_pile_they_left(): void
    {
        // Restocking the article's own pile instead would leave the size still
        // missing AND invent stock that was never there — wrong twice over.
        $this->stock('L', 5);
        $variant = $this->item->variants()->where('name', 'L')->firstOrFail();
        $this->checkout([
            'id' => $this->item->id, 'variant_id' => $variant->id,
            'quantity' => 2, 'price' => 100,
        ])->assertRedirect();
        $this->assertSame(3, $this->available('L'));

        $sale = Sale::where('tenant_id', $this->tenant->id)->latest('id')->firstOrFail();
        $line = SaleItem::where('sale_id', $sale->id)->firstOrFail();
        // The article's OWN row, read directly. InventoryService::quantity()
        // with a null variant does not mean "the article's pile" — it omits
        // the variant filter and SUMS the article plus every size, which is a
        // different question and would hide the bug this asserts.
        $ownPile = fn (): int => (int) \App\Models\ItemLocationStock::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('item_id', $this->item->id)
            ->whereNull('variant_id')
            ->where('location_id', $this->location->id)
            ->value('quantity');
        $beforeArticle = $ownPile();

        $this->post(route('sales.refund', $sale), [
            'refund_method' => 'cash',
            'refund_reason' => 'Taille incorrecte',
            'return_lines' => [[
                'sale_item_id' => $line->id,
                'quantity' => 2,
                'stock_action' => 'restock',
            ]],
        ])->assertRedirect();

        $this->assertSame(5, $this->available('L'), 'the size should be back');
        $this->assertSame($beforeArticle, $ownPile(),
            "the article's own pile must not gain stock it never had");
    }

    public function test_the_manual_sale_form_refuses_an_article_sold_by_size(): void
    {
        // That form has no chooser, so it would deduct the article's own pile
        // — empty by design — and record a sale nobody could attribute.
        $this->stock('L', 5);

        $this->post(route('sales.store'), [
            'items' => [['item_id' => $this->item->id, 'quantity' => 1, 'unit_price' => 100]],
            'payment_method' => 'cash',
            'paid_amount' => 100,
        ])->assertSessionHasErrors();
    }

    public function test_the_stock_list_counts_the_sizes_not_the_empty_article(): void
    {
        // The joins filter `variant_id IS NULL`, which was right while only
        // the article had stock. An article with thirty units across three
        // sizes would otherwise read ZERO — out of stock everywhere the
        // catalogue, the transfer picker and the adjustment picker look.
        $ownPile = (int) \App\Models\ItemLocationStock::query()
            ->where('item_id', $this->item->id)
            ->whereNull('variant_id')
            ->where('location_id', $this->location->id)
            ->value('quantity');
        $this->stock('S', 12);
        $this->stock('L', 18);

        $html = $this->get(route('stock', ['panel' => 'stock-adjustment-add']))
            ->assertOk()->getContent();

        // Everything on the shelf: the article's own pile plus both sizes.
        $position = strpos($html, $this->item->title);
        $this->assertNotFalse($position);
        $this->assertStringContainsString(
            'data-stock="'.($ownPile + 30).'"',
            substr($html, $position, 200),
        );
    }

    public function test_the_variants_own_price_is_charged(): void
    {
        $this->stock('L', 5);
        $variant = $this->item->variants()->where('name', 'L')->firstOrFail();
        $variant->update(['sale_price_override' => 199]);

        // No price on the line: the till must take the variant's.
        $this->post(route('pos.store'), [
            'cart' => json_encode([[
                'id' => $this->item->id, 'variant_id' => $variant->id, 'quantity' => 1,
            ]]),
            'cash_amount' => 1000,
        ])->assertRedirect();

        $this->assertSame(199.0, (float) SaleItem::latest('id')->firstOrFail()->unit_price);
    }
}
