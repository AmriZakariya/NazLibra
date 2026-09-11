<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Item;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * The demo catalogue is only worth having if it actually imports. Generating a
 * plausible-looking file proves nothing — every column here is parsed by the
 * real importer, and a category, tax or unit spelled the wrong way is silently
 * dropped rather than reported.
 */
class DemoCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->tenant = Tenant::firstOrFail();
        $this->owner = User::where('email', 'amina@librairie-atlas.ma')->firstOrFail();
        $this->directory = storage_path('framework/testing/demo-catalogue');

        $this->artisan('demo:catalogue', ['--dir' => $this->directory])->assertSuccessful();
    }

    private function import(string $file, string $kind): void
    {
        $this->actingAs($this->owner)
            ->post(route('catalog.import'), [
                'kind' => $kind,
                'catalog_file' => new UploadedFile(
                    $this->directory."/$file", $file, null, null, true,
                ),
            ])
            ->assertRedirect();
    }

    /** @return array{items: array<int, array<string, mixed>>, services: array<int, array<string, mixed>>} */
    private function catalogue(): array
    {
        return require database_path('data/demo_catalogue.php');
    }

    private function items(): \Illuminate\Database\Eloquent\Builder
    {
        return Item::where('tenant_id', $this->tenant->id);
    }

    /** One row of the imported catalogue, by the title in the data file. */
    private function item(string $title): Item
    {
        return $this->items()->where('title', $title)->sole();
    }

    public function test_every_article_row_is_shelved(): void
    {
        // The base seeder already stocks a token five items, so this counts
        // the titles the file carries rather than the whole shop.
        $titles = array_column($this->catalogue()['items'], 'name');

        $this->import('catalogue-librairie-articles.xlsx', 'items');

        $this->assertSame(
            count($titles),
            $this->items()->whereIn('title', $titles)->count(),
            'every article row should have produced exactly one item',
        );
    }

    public function test_every_service_row_is_shelved(): void
    {
        $titles = array_column($this->catalogue()['services'], 'name');

        $this->import('catalogue-librairie-services.xlsx', 'services');

        $this->assertSame(
            count($titles),
            $this->items()->whereIn('title', $titles)->where('type', 'service')->count(),
        );
    }

    public function test_a_title_the_seeder_already_stocks_is_updated_not_duplicated(): void
    {
        // Le Petit Prince and the Bescherelle are in the base seeder. They
        // carry its ISBN so the importer recognises them; without that the
        // demo shop would show the same book twice.
        $this->import('catalogue-librairie-articles.xlsx', 'items');

        foreach (['Le Petit Prince', 'Bescherelle - La conjugaison pour tous'] as $title) {
            $this->assertSame(1, $this->items()->where('title', $title)->count(), $title);
        }
    }

    public function test_categories_are_created_from_the_file(): void
    {
        $this->import('catalogue-librairie-articles.xlsx', 'items');

        // Matched on slug, so the seeder's existing "Romans" is reused rather
        // than a second category appearing beside it.
        $slugs = Category::where('tenant_id', $this->tenant->id)
            ->pluck('name')->map(fn (string $name) => \Illuminate\Support\Str::slug($name));

        foreach (['FOURNITURE SCOLAIRE', 'ROMANS', 'PAPETERIE', 'LIVRES SCOLAIRES'] as $category) {
            $this->assertContains(\Illuminate\Support\Str::slug($category), $slugs->all(), $category);
        }
    }

    public function test_a_book_is_exempt_and_stationery_is_taxed(): void
    {
        // Moroccan practice, and the reason the tax column is not one value:
        // getting it uniform would make every demo total wrong.
        $this->import('catalogue-librairie-articles.xlsx', 'items');

        $this->assertSame(0.0, (float) $this->item('La Boîte à merveilles')->tax?->rate);
        $this->assertSame(20.0, (float) $this->item('Stylo bille bleu pointe moyenne')->tax?->rate);
    }

    public function test_a_book_carries_its_isbn_and_its_author(): void
    {
        $this->import('catalogue-librairie-articles.xlsx', 'items');

        $book = $this->item('La Boîte à merveilles');

        $this->assertNotEmpty($book->isbn);
        $this->assertSame($book->barcode, $book->isbn, 'a bookseller scans the ISBN');
        $this->assertSame('Ahmed Sefrioui', $book->author);
    }

    public function test_prices_and_stock_survive_the_round_trip(): void
    {
        $this->import('catalogue-librairie-articles.xlsx', 'items');

        $ream = $this->item('Ramette papier A4 80 g 500 feuilles');

        $this->assertSame(62.0, (float) $ream->sale_price);
        $this->assertSame(42.0, (float) $ream->purchase_price);
        $this->assertSame(150, (int) $ream->stock_quantity);
        $this->assertSame(25, (int) $ream->min_stock_threshold);
    }

    public function test_every_barcode_is_a_valid_ean13(): void
    {
        // These get scanned. A wrong check digit reads as a broken scanner.
        $this->import('catalogue-librairie-articles.xlsx', 'items');

        // Only the rows this file shelved: the base seeder uses internal
        // codes like ROM-PP-001, which are not barcodes and never scanned.
        $barcodes = $this->items()
            ->whereIn('title', array_column($this->catalogue()['items'], 'name'))
            ->whereNotNull('barcode')->pluck('barcode');

        $this->assertGreaterThan(50, $barcodes->count());

        foreach ($barcodes as $barcode) {
            $this->assertMatchesRegularExpression('/\A\d{13}\z/', $barcode);

            $sum = 0;
            foreach (str_split(substr($barcode, 0, 12)) as $position => $digit) {
                $sum += (int) $digit * ($position % 2 === 0 ? 1 : 3);
            }
            $this->assertSame(
                (int) substr($barcode, 12),
                (10 - $sum % 10) % 10,
                "check digit is wrong for $barcode",
            );
        }
    }

    public function test_barcodes_are_unique(): void
    {
        // A duplicate would make the importer update a row instead of creating
        // one, and the shortfall would be silent.
        $this->import('catalogue-librairie-articles.xlsx', 'items');

        $barcodes = $this->items()
            ->whereIn('title', array_column($this->catalogue()['items'], 'name'))
            ->pluck('barcode')->filter();

        $this->assertSame($barcodes->count(), $barcodes->unique()->count());
    }

    public function test_importing_twice_updates_rather_than_duplicates(): void
    {
        // Re-running the demo import is normal; it must not double the shop.
        $this->import('catalogue-librairie-articles.xlsx', 'items');
        $first = $this->items()->count();

        $this->import('catalogue-librairie-articles.xlsx', 'items');

        $this->assertSame($first, $this->items()->count());
    }
}
