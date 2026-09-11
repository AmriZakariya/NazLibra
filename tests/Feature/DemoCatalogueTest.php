<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Item;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ItemTypes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The demo catalogues are only worth having if they actually import. A
 * plausible-looking file proves nothing: every column is parsed by the real
 * importer, and a category, tax or type spelled the wrong way is silently
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
    }

    /** @return array<int, string> */
    public static function activities(): array
    {
        return array_map(fn (string $a) => [$a], [
            'bookstore', 'restaurant', 'cafe', 'pharmacy', 'clothing', 'general',
        ]);
    }

    /** @return array<string, mixed> */
    private function catalogue(string $activity): array
    {
        return require database_path("data/demo_catalogues/$activity.php");
    }

    private function generate(string $activity): void
    {
        $this->artisan('demo:catalogue', [
            '--activity' => [$activity],
            '--dir' => $this->directory,
        ])->assertSuccessful();
    }

    private function import(string $activity, string $kind): void
    {
        $suffix = $kind === 'services' ? 'services' : 'articles';
        $file = "catalogue-$activity-$suffix.xlsx";

        $this->actingAs($this->owner)
            ->post(route('catalog.import'), [
                'kind' => $kind,
                'catalog_file' => new UploadedFile(
                    $this->directory."/$file", $file, null, null, true,
                ),
            ])
            ->assertRedirect();
    }

    private function items(): Builder
    {
        return Item::where('tenant_id', $this->tenant->id);
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function titles(array $rows): array
    {
        return array_column($rows, 'name');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('activities')]
    public function test_every_article_row_is_shelved(string $activity): void
    {
        // The base seeder already stocks a token five items, so this counts
        // the titles the file carries rather than the whole shop.
        $titles = $this->titles($this->catalogue($activity)['items']);

        $this->generate($activity);
        $this->import($activity, 'items');

        $this->assertSame(
            count($titles),
            $this->items()->whereIn('title', $titles)->count(),
            "$activity: every article row should have produced exactly one item",
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('activities')]
    public function test_every_service_row_is_shelved(string $activity): void
    {
        $titles = $this->titles($this->catalogue($activity)['services']);

        $this->generate($activity);
        $this->import($activity, 'services');

        $this->assertSame(
            count($titles),
            $this->items()->whereIn('title', $titles)->where('type', 'service')->count(),
            $activity,
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('activities')]
    public function test_each_row_lands_on_the_type_its_file_declares(string $activity): void
    {
        // The whole point of splitting the catalogues. A pharmacy row that
        // arrives as `supply` has silently become parapharmacy.
        $this->generate($activity);
        $this->import($activity, 'items');

        foreach ($this->catalogue($activity)['items'] as $row) {
            $this->assertSame(
                $row['kind'],
                $this->items()->where('title', $row['name'])->sole()->type,
                "$activity: {$row['name']}",
            );
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('activities')]
    public function test_the_types_used_are_types_the_activity_offers(string $activity): void
    {
        // A file may only use types this activity's item form would offer,
        // or the demo shop shows rows it could not have created itself.
        $allowed = ItemTypes::validTypes($activity);

        foreach ($this->catalogue($activity)['items'] as $row) {
            $this->assertContains($row['kind'], $allowed, "$activity: {$row['name']}");
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('activities')]
    public function test_barcodes_are_valid_and_unique(string $activity): void
    {
        // These get scanned. A wrong check digit reads as a broken scanner,
        // and a duplicate makes the importer update a row instead of adding
        // one — a shortfall nobody would notice.
        $this->generate($activity);
        $this->import($activity, 'items');

        $barcodes = $this->items()
            ->whereIn('title', $this->titles($this->catalogue($activity)['items']))
            ->whereNotNull('barcode')->pluck('barcode');

        $this->assertGreaterThan(20, $barcodes->count());
        $this->assertSame($barcodes->count(), $barcodes->unique()->count(), "$activity: duplicate barcode");

        foreach ($barcodes as $barcode) {
            $this->assertMatchesRegularExpression('/\A\d{13}\z/', $barcode);

            $sum = 0;
            foreach (str_split(substr($barcode, 0, 12)) as $position => $digit) {
                $sum += (int) $digit * ($position % 2 === 0 ? 1 : 3);
            }
            $this->assertSame((int) substr($barcode, 12), (10 - $sum % 10) % 10, "check digit: $barcode");
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('activities')]
    public function test_importing_twice_updates_rather_than_duplicates(string $activity): void
    {
        $this->generate($activity);
        $this->import($activity, 'items');
        $first = $this->items()->count();

        $this->import($activity, 'items');

        $this->assertSame($first, $this->items()->count(), $activity);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('activities')]
    public function test_the_vat_each_row_declares_is_the_vat_it_gets(string $activity): void
    {
        // Rates differ by trade — books exempt, restauration at 10%, medicines
        // at 7% — and a uniform rate would make every demo total wrong.
        $this->generate($activity);
        $this->import($activity, 'items');

        foreach ($this->catalogue($activity)['items'] as $row) {
            $this->assertSame(
                (float) $row['vat'],
                (float) $this->items()->where('title', $row['name'])->sole()->tax?->rate,
                "$activity: {$row['name']}",
            );
        }
    }

    public function test_a_bookstore_book_carries_its_isbn_and_author(): void
    {
        // Only the bookstore file has those columns: an ISBN on a tajine
        // would be noise on every other trade's import screen.
        $this->generate('bookstore');
        $this->import('bookstore', 'items');

        $book = $this->items()->where('title', 'La Boîte à merveilles')->sole();

        $this->assertSame($book->barcode, $book->isbn, 'a bookseller scans the ISBN');
        $this->assertSame('Ahmed Sefrioui', $book->author);
    }

    public function test_only_the_bookstore_file_carries_book_columns(): void
    {
        // Asserted on the GENERATED header row, not on the flag in the data
        // file: a generator that ignored the flag would still satisfy the
        // flag. An ISBN column on a tajine is noise on the import screen.
        $this->artisan('demo:catalogue', ['--dir' => $this->directory, '--csv' => true])
            ->assertSuccessful();

        foreach (['bookstore', 'restaurant', 'cafe', 'pharmacy', 'clothing', 'general'] as $activity) {
            $header = fgetcsv(fopen($this->directory."/catalogue-$activity-articles.csv", 'r'));

            if ($activity === 'bookstore') {
                $this->assertContains('ISBN', $header);
                $this->assertContains('Auteur', $header);

                continue;
            }

            $this->assertNotContains('ISBN', $header, $activity);
            $this->assertNotContains('Auteur', $header, $activity);
        }
    }

    public function test_a_title_the_seeder_already_stocks_is_updated_not_duplicated(): void
    {
        // Le Petit Prince and the Bescherelle are in the base seeder. They
        // carry its ISBN so the importer recognises them; without that the
        // demo shop would show the same book twice.
        $this->generate('bookstore');
        $this->import('bookstore', 'items');

        foreach (['Le Petit Prince', 'Bescherelle - La conjugaison pour tous'] as $title) {
            $this->assertSame(1, $this->items()->where('title', $title)->count(), $title);
        }
    }

    public function test_categories_are_created_from_the_file(): void
    {
        $this->generate('restaurant');
        $this->import('restaurant', 'items');

        $slugs = Category::where('tenant_id', $this->tenant->id)
            ->pluck('name')->map(fn (string $name) => Str::slug($name));

        foreach (['TAJINES', 'COUSCOUS', 'BOISSONS'] as $category) {
            $this->assertContains(Str::slug($category), $slugs->all(), $category);
        }
    }

    public function test_prices_and_stock_survive_the_round_trip(): void
    {
        $this->generate('bookstore');
        $this->import('bookstore', 'items');

        $ream = $this->items()->where('title', 'Ramette papier A4 80 g 500 feuilles')->sole();

        $this->assertSame(62.0, (float) $ream->sale_price);
        $this->assertSame(42.0, (float) $ream->purchase_price);
        $this->assertSame(150, (int) $ream->stock_quantity);
        $this->assertSame(25, (int) $ream->min_stock_threshold);
    }
}
