<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\ItemVariant;
use App\Models\OptionType;
use App\Models\OptionValue;
use App\Models\Tenant;
use App\Services\Catalogue\VariantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Sub-products: one article sold in several concrete forms, each holding its
 * own stock.
 *
 * The rule the whole model rests on: a variant HAS ITS OWN STOCK. An option
 * that does not ("sans glace", "emballage cadeau") is a modifier and must
 * never become a row here — otherwise a burger with eight toppings is 256
 * sub-products.
 */
class ProductVariantTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Item $item;
    private OptionType $size;
    private OptionType $colour;
    private VariantService $variants;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->tenant = Tenant::firstOrFail();
        $this->item = Item::where('tenant_id', $this->tenant->id)
            ->where('type', '!=', 'service')->firstOrFail();
        $this->variants = app(VariantService::class);

        $this->size = OptionType::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Taille', 'sort_order' => 0,
        ]);
        foreach (['S', 'M', 'L'] as $index => $value) {
            OptionValue::create([
                'tenant_id' => $this->tenant->id, 'option_type_id' => $this->size->id,
                'value' => $value, 'sort_order' => $index,
            ]);
        }

        $this->colour = OptionType::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Couleur', 'sort_order' => 1,
        ]);
        foreach (['Rouge', 'Bleu'] as $index => $value) {
            OptionValue::create([
                'tenant_id' => $this->tenant->id, 'option_type_id' => $this->colour->id,
                'value' => $value, 'sort_order' => $index,
            ]);
        }
    }

    private function valueIds(OptionType $type, array $values = []): array
    {
        return $type->values()
            ->when($values !== [], fn ($q) => $q->whereIn('value', $values))
            ->pluck('id')->all();
    }

    public function test_one_axis_makes_one_variant_per_value(): void
    {
        $result = $this->variants->generateMatrix($this->item, [
            $this->size->id => $this->valueIds($this->size),
        ]);

        $this->assertCount(3, $result);
        $this->assertEqualsCanonicalizing(['S', 'M', 'L'], $result->pluck('name')->all());
    }

    public function test_two_axes_make_every_combination(): void
    {
        // Three sizes and two colours is six things to stock, count and sell.
        $result = $this->variants->generateMatrix($this->item, [
            $this->size->id => $this->valueIds($this->size),
            $this->colour->id => $this->valueIds($this->colour),
        ]);

        $this->assertCount(6, $result);
        $this->assertContains('M / Rouge', $result->pluck('name')->all());
    }

    public function test_regenerating_adds_only_what_is_new(): void
    {
        // The reason this is not "delete and rebuild": the existing rows carry
        // the stock, the barcodes and the prices a shop has already entered.
        $this->variants->generateMatrix($this->item, [
            $this->size->id => $this->valueIds($this->size, ['S', 'M']),
        ]);
        $small = $this->item->variants()->where('name', 'S')->firstOrFail();
        $small->update(['stock_quantity' => 12, 'barcode' => '5900000000001']);

        $result = $this->variants->generateMatrix($this->item, [
            $this->size->id => $this->valueIds($this->size),
        ]);

        $this->assertCount(3, $result);
        $small->refresh();
        $this->assertSame(12, (int) $small->stock_quantity);
        $this->assertSame('5900000000001', $small->barcode);
    }

    public function test_the_same_combination_cannot_exist_twice(): void
    {
        $this->variants->generateMatrix($this->item, [
            $this->size->id => $this->valueIds($this->size, ['S']),
        ]);
        $this->variants->generateMatrix($this->item, [
            $this->size->id => $this->valueIds($this->size, ['S']),
        ]);

        $this->assertSame(1, $this->item->variants()->count());
    }

    public function test_a_combination_is_the_same_whichever_order_it_was_entered(): void
    {
        // "Rouge / L" and "L / Rouge" are one thing. The key is sorted, so the
        // unique index recognises them as such.
        $first = $this->variants->generateMatrix($this->item, [
            $this->size->id => $this->valueIds($this->size, ['L']),
            $this->colour->id => $this->valueIds($this->colour, ['Rouge']),
        ]);
        $second = $this->variants->generateMatrix($this->item, [
            $this->colour->id => $this->valueIds($this->colour, ['Rouge']),
            $this->size->id => $this->valueIds($this->size, ['L']),
        ]);

        $this->assertCount(1, $first);
        $this->assertCount(1, $second);
        $this->assertSame(1, $this->item->variants()->count());
    }

    public function test_a_till_finds_the_variant_even_when_the_axes_read_backwards(): void
    {
        // The combination key is SORTED by value id, and this is why. Put the
        // colour axis first and the generator builds the pair as
        // (colour, size); a till looking one up gets its values back in id
        // order, (size, colour). Unsorted, those two produce different keys
        // and the variant that plainly exists is never found.
        $this->colour->update(['sort_order' => 0]);
        $this->size->update(['sort_order' => 1]);

        $this->variants->generateMatrix($this->item, [
            $this->size->id => $this->valueIds($this->size, ['L']),
            $this->colour->id => $this->valueIds($this->colour, ['Rouge']),
        ]);

        $found = $this->variants->findByValues($this->item, [
            ...$this->valueIds($this->size, ['L']),
            ...$this->valueIds($this->colour, ['Rouge']),
        ]);

        $this->assertNotNull($found, 'the generated variant should be findable');
        $this->assertSame('Rouge / L', $found->name);
    }

    public function test_an_absurd_matrix_is_refused_rather_than_created(): void
    {
        // 200 rows a shop cannot easily undo is worse than an error it can.
        $many = OptionType::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Pointure', 'sort_order' => 2,
        ]);
        foreach (range(1, 40) as $n) {
            OptionValue::create([
                'tenant_id' => $this->tenant->id, 'option_type_id' => $many->id,
                'value' => 'P'.$n, 'sort_order' => $n,
            ]);
        }

        $this->expectException(ValidationException::class);

        $this->variants->generateMatrix($this->item, [
            $many->id => $this->valueIds($many),
            $this->size->id => $this->valueIds($this->size),
            $this->colour->id => $this->valueIds($this->colour),
        ]);
    }

    public function test_no_axis_at_all_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->variants->generateMatrix($this->item, [$this->size->id => []]);
    }

    public function test_another_tenants_option_cannot_be_used(): void
    {
        $other = Tenant::create(['name' => 'Autre', 'slug' => 'autre-'.uniqid()]);
        $foreign = OptionType::create(['tenant_id' => $other->id, 'name' => 'Taille']);
        OptionValue::create([
            'tenant_id' => $other->id, 'option_type_id' => $foreign->id, 'value' => 'XL',
        ]);

        $this->expectException(ValidationException::class);

        $this->variants->generateMatrix($this->item, [
            $foreign->id => $foreign->values()->pluck('id')->all(),
        ]);
    }

    public function test_an_inactive_value_is_not_generated(): void
    {
        // Retiring a colour should stop new combinations without touching the
        // ones already stocked.
        OptionValue::where('option_type_id', $this->colour->id)
            ->where('value', 'Bleu')->update(['is_active' => false]);

        $result = $this->variants->generateMatrix($this->item, [
            $this->colour->id => $this->valueIds($this->colour),
        ]);

        $this->assertCount(1, $result);
        $this->assertSame('Rouge', $result->first()->name);
    }

    public function test_the_article_records_which_axes_it_varies_on(): void
    {
        $this->variants->generateMatrix($this->item, [
            $this->size->id => $this->valueIds($this->size),
            $this->colour->id => $this->valueIds($this->colour),
        ]);

        $this->assertEqualsCanonicalizing(
            ['Taille', 'Couleur'],
            $this->item->optionTypes()->pluck('name')->all(),
        );
    }

    public function test_the_variant_count_on_the_article_stays_true(): void
    {
        // Stored so the till can ask "does this need a chooser?" without a
        // join on every catalogue row.
        $this->variants->generateMatrix($this->item, [
            $this->size->id => $this->valueIds($this->size),
        ]);

        $this->assertSame(3, (int) $this->item->fresh()->variant_count);
        $this->assertTrue($this->item->fresh()->hasVariants());
    }

    public function test_a_price_left_alone_follows_the_article(): void
    {
        // A shirt priced once should not need the same number typed into
        // every size, nor a price rise repeated across them.
        $this->variants->generateMatrix($this->item, [
            $this->size->id => $this->valueIds($this->size, ['S']),
        ]);
        $variant = $this->item->variants()->firstOrFail();
        $this->item->update(['sale_price' => 249.50]);

        $this->assertSame(249.50, $variant->fresh()->price());
    }

    public function test_a_variant_can_cost_more_than_the_article(): void
    {
        // XL costs more to make; that is the whole reason for an override.
        $this->variants->generateMatrix($this->item, [
            $this->size->id => $this->valueIds($this->size, ['L']),
        ]);
        $variant = $this->item->variants()->firstOrFail();
        $variant->update(['sale_price_override' => 299]);

        $this->assertSame(299.0, $variant->fresh()->price());
    }

    public function test_a_till_can_find_the_variant_from_the_chosen_values(): void
    {
        $this->variants->generateMatrix($this->item, [
            $this->size->id => $this->valueIds($this->size),
            $this->colour->id => $this->valueIds($this->colour),
        ]);

        $found = $this->variants->findByValues($this->item, [
            ...$this->valueIds($this->size, ['M']),
            ...$this->valueIds($this->colour, ['Bleu']),
        ]);

        $this->assertNotNull($found);
        $this->assertSame('M / Bleu', $found->name);
    }

    public function test_a_combination_that_was_never_generated_is_not_found(): void
    {
        $this->variants->generateMatrix($this->item, [
            $this->size->id => $this->valueIds($this->size, ['S']),
        ]);

        $this->assertNull($this->variants->findByValues($this->item, [
            ...$this->valueIds($this->size, ['L']),
        ]));
    }

    public function test_a_variant_reads_as_its_options_not_as_a_code(): void
    {
        // What goes on the ticket and in the report.
        $this->variants->generateMatrix($this->item, [
            $this->size->id => $this->valueIds($this->size, ['L']),
            $this->colour->id => $this->valueIds($this->colour, ['Rouge']),
        ]);

        // Scoped to THIS article: the seeder ships variants of its own, and
        // an unscoped first() picks one of those.
        $variant = $this->item->variants()
            ->with(['values.optionValue', 'values.optionType'])->firstOrFail();

        $this->assertSame('L / Rouge', $variant->optionLabel());
    }
}
