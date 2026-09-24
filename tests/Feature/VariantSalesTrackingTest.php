<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\OptionType;
use App\Models\OptionValue;
use App\Models\Sale;
use App\Models\Tenant;
use App\Services\Catalogue\VariantSalesReport;
use App\Services\Catalogue\VariantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The question the whole feature exists to answer: how many L did we sell?
 *
 * Stock, movements and stocktakes already carried a variant. `sale_items` did
 * not, so a shop could hold three separate piles and never learn which one
 * moved.
 */
class VariantSalesTrackingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Item $shirt;
    private Item $mug;
    private OptionType $size;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->tenant = Tenant::firstOrFail();

        // The seeder ships sales of its own. This file is about what the
        // report counts, so it owns every sale in the database — otherwise
        // each assertion is really about the seeder's fixtures.
        \App\Models\SaleItem::query()->forceDelete();
        Sale::query()->forceDelete();

        $items = Item::where('tenant_id', $this->tenant->id)->where('type', '!=', 'service')->take(2)->get();
        [$this->shirt, $this->mug] = [$items[0], $items[1]];

        $this->size = OptionType::create(['tenant_id' => $this->tenant->id, 'name' => 'Taille']);
        foreach (['S', 'M', 'L'] as $index => $value) {
            OptionValue::create([
                'tenant_id' => $this->tenant->id, 'option_type_id' => $this->size->id,
                'value' => $value, 'sort_order' => $index,
            ]);
        }

        foreach ([$this->shirt, $this->mug] as $item) {
            app(VariantService::class)->generateMatrix($item, [
                $this->size->id => $this->size->values()->pluck('id')->all(),
            ]);
        }
    }

    private function sell(Item $item, string $variantName, int $quantity, float $price = 100): void
    {
        $variant = $item->variants()->where('name', $variantName)->firstOrFail();

        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'number' => 'BL'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'status' => 'paid',
            'payment_method' => 'cash',
            'subtotal_amount' => $price * $quantity,
            'total_amount' => $price * $quantity,
            'sold_at' => now(),
        ]);

        $sale->items()->create([
            'item_id' => $item->id,
            'variant_id' => $variant->id,
            'name' => $item->title.' — '.$variant->name,
            'quantity' => $quantity,
            'unit_price' => $price,
            'total_price' => $price * $quantity,
        ]);
    }

    public function test_a_sale_line_records_which_variant_was_sold(): void
    {
        $this->sell($this->shirt, 'L', 2);

        $line = \App\Models\SaleItem::where('item_id', $this->shirt->id)->latest('id')->firstOrFail();

        $this->assertNotNull($line->variant_id);
        $this->assertSame('L', $line->variant->name);
    }

    public function test_sales_are_counted_per_variant(): void
    {
        // The stated requirement, in one assertion.
        $this->sell($this->shirt, 'L', 3);
        $this->sell($this->shirt, 'S', 1);
        $this->sell($this->shirt, 'L', 2);

        $rows = app(VariantSalesReport::class)->byVariant($this->tenant, $this->shirt->id)
            ->keyBy('variant_name');

        $this->assertSame(5, (int) $rows['L']->quantity);
        $this->assertSame(1, (int) $rows['S']->quantity);
        $this->assertArrayNotHasKey('M', $rows->all(), 'a size that never sold should not appear');
    }

    public function test_the_report_totals_the_money_as_well_as_the_count(): void
    {
        $this->sell($this->shirt, 'M', 2, 150);

        $row = app(VariantSalesReport::class)->byVariant($this->tenant, $this->shirt->id)->firstWhere('variant_name', 'M');

        $this->assertSame(300.0, (float) $row->revenue);
    }

    public function test_a_line_with_no_variant_is_still_counted(): void
    {
        // Lines predating the options, or plain articles. Dropping them would
        // make this report disagree with the sales report.
        $sale = Sale::create([
            'tenant_id' => $this->tenant->id, 'number' => 'BL90001', 'status' => 'paid',
            'payment_method' => 'cash', 'subtotal_amount' => 50, 'total_amount' => 50,
            'sold_at' => now(),
        ]);
        $sale->items()->create([
            'item_id' => $this->shirt->id, 'name' => 'Sans option',
            'quantity' => 4, 'unit_price' => 12.5, 'total_price' => 50,
        ]);

        $rows = app(VariantSalesReport::class)->byVariant($this->tenant, $this->shirt->id);

        $this->assertSame(4, (int) $rows->firstWhere('variant_name', 'Sans déclinaison')->quantity);
    }

    public function test_sizes_are_counted_across_every_article(): void
    {
        // "How many L did the shop sell" — the join the pivot table exists
        // for, and why the options are not left in a free-form JSON blob.
        $this->sell($this->shirt, 'L', 3);
        $this->sell($this->mug, 'L', 4);
        $this->sell($this->mug, 'S', 1);

        $rows = app(VariantSalesReport::class)->byOptionValue($this->tenant)->keyBy('option_value');

        $this->assertSame(7, (int) $rows['L']->quantity);
        $this->assertSame(1, (int) $rows['S']->quantity);
        $this->assertSame('Taille', $rows['L']->option_type);
    }

    public function test_the_cross_article_report_can_be_narrowed_to_one_axis(): void
    {
        $colour = OptionType::create(['tenant_id' => $this->tenant->id, 'name' => 'Couleur']);
        OptionValue::create([
            'tenant_id' => $this->tenant->id, 'option_type_id' => $colour->id, 'value' => 'Rouge',
        ]);
        $this->sell($this->shirt, 'L', 2);

        $rows = app(VariantSalesReport::class)->byOptionValue($this->tenant, $colour->id);

        $this->assertCount(0, $rows, 'nothing was sold on the colour axis');
    }

    public function test_a_cancelled_sale_is_not_counted(): void
    {
        // Cancelling soft-deletes the sale rather than re-statusing it, so
        // that is what has to exclude it here too.
        $this->sell($this->shirt, 'L', 5);
        Sale::where('tenant_id', $this->tenant->id)->latest('id')->firstOrFail()->delete();

        $rows = app(VariantSalesReport::class)->byVariant($this->tenant, $this->shirt->id);

        $this->assertCount(0, $rows);
    }

    public function test_one_tenant_cannot_see_anothers_sales(): void
    {
        $this->sell($this->shirt, 'L', 2);
        $other = Tenant::create(['name' => 'Autre', 'slug' => 'autre-'.uniqid()]);

        $this->assertCount(0, app(VariantSalesReport::class)->byVariant($other, $this->shirt->id));
        $this->assertCount(0, app(VariantSalesReport::class)->byOptionValue($other));
    }

    public function test_the_sales_pull_selects_the_size_column(): void
    {
        // That endpoint names its columns explicitly, so `variant_id` drops
        // off silently — and every screen still reads right, because the line
        // NAME carries "— L" while the column behind it is null.
        $this->sell($this->shirt, 'L', 2);

        $controller = file_get_contents(app_path('Http/Controllers/Api/SyncController.php'));
        // The SALE's items, specifically: other relations on that controller
        // are named `items` too, and matching the first one would pass or
        // fail on where an unrelated eager load happens to sit in the file.
        $matched = preg_match_all('/\x27items:([a-z_,]+)\x27/', $controller, $all);
        $this->assertGreaterThan(0, $matched, 'the sales pull should eager-load named columns');
        $lists = array_values(array_filter(
            $all[1],
            fn (string $list): bool => str_contains($list, 'sale_id'),
        ));
        $this->assertCount(1, $lists, 'exactly one eager load should name the sale line columns');
        $columns = [1 => $lists[0]];
        $this->assertStringContainsString('variant_id', $columns[1]);

        // And the column list actually hydrates it.
        $sale = Sale::with(['items:'.$columns[1]])->latest('id')->firstOrFail();
        $this->assertSame(
            $this->shirt->variants()->where('name', 'L')->value('id'),
            $sale->items->first()->variant_id,
        );
    }

    public function test_the_report_can_be_bounded_by_date(): void
    {
        $this->sell($this->shirt, 'L', 2);
        Sale::where('tenant_id', $this->tenant->id)->latest('id')->firstOrFail()
            ->update(['sold_at' => now()->subMonth()]);
        $this->sell($this->shirt, 'S', 1);

        $rows = app(VariantSalesReport::class)
            ->byVariant($this->tenant, $this->shirt->id, now()->subDays(2)->toDateString());

        $this->assertCount(1, $rows);
        $this->assertSame('S', $rows->first()->variant_name);
    }
}
