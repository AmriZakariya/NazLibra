<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Catalogue\ItemPhotos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Product photos in bulk: dropped by file name, inside an import ZIP, or as a
 * link in the import sheet. A client asked how to import a catalogue with its
 * photos; until now each photo was added by hand, one product at a time.
 */
class ItemPhotosTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    /** @var list<string> */
    private array $temp = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('public');
        $this->tenant = Tenant::firstOrFail();
        $this->owner = User::where('email', 'amina@librairie-atlas.ma')->firstOrFail();
    }

    protected function tearDown(): void
    {
        ItemPhotos::$resolveUsing = null;
        foreach ($this->temp as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    private function photos(): ItemPhotos
    {
        return app(ItemPhotos::class);
    }

    private function png(int $w, int $h, bool $transparent = false): string
    {
        $image = imagecreatetruecolor($w, $h);
        imagesavealpha($image, true);
        imagealphablending($image, false);
        imagefill($image, 0, 0, $transparent ? imagecolorallocatealpha($image, 0, 0, 0, 127) : imagecolorallocate($image, 200, 40, 40));
        $path = tempnam(sys_get_temp_dir(), 'photo-test-').'.png';
        imagepng($image, $path);
        $this->temp[] = $path;

        return $path;
    }

    private function item(array $attributes): Item
    {
        $template = Item::where('tenant_id', $this->tenant->id)->firstOrFail();

        return Item::create(array_merge($template->only(['tenant_id', 'category_id', 'unit_id', 'tax_id']), [
            'type' => 'item',
            'title' => 'Produit photo',
            'item_code' => 'PH'.random_int(10000, 99999),
            'sale_price' => 10,
            'purchase_price' => 5,
            'price' => 5,
            'stock_quantity' => 0,
            'min_stock_threshold' => 0,
            'images' => [],
        ], $attributes));
    }

    // ── Processing ────────────────────────────────────────────────────────────

    public function test_a_big_photo_is_shrunk_to_a_jpeg(): void
    {
        $path = $this->photos()->store($this->png(3000, 1500));

        $this->assertStringStartsWith('catalogue/items/', $path);
        $this->assertStringEndsWith('.jpg', $path);
        [$w, $h, , ] = getimagesizefromstring(Storage::disk('public')->get($path));
        $this->assertSame([1000, 500], [$w, $h]);
    }

    public function test_a_transparent_photo_sits_on_white(): void
    {
        $path = $this->photos()->store($this->png(200, 200, transparent: true));

        $image = imagecreatefromstring(Storage::disk('public')->get($path));
        $rgb = imagecolorat($image, 2, 2);
        $this->assertGreaterThan(240, ($rgb >> 16) & 0xFF);
    }

    public function test_a_file_that_is_not_a_photo_is_refused(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'not-photo-');
        file_put_contents($path, 'hello');
        $this->temp[] = $path;

        $this->expectException(\InvalidArgumentException::class);
        $this->photos()->store($path);
    }

    // ── Matching ──────────────────────────────────────────────────────────────

    public function test_file_names_are_read_like_a_person_names_photos(): void
    {
        $this->assertSame(['6111 (2)', '6111'], ItemPhotos::keysFor('dossier/6111 (2).JPG'));
        $this->assertSame(['6111_2', '6111'], ItemPhotos::keysFor('6111_2.jpg'));
        $this->assertSame(['Massage relaxant'], ItemPhotos::keysFor('Massage relaxant.jpeg'));
    }

    public function test_a_photo_finds_its_product_by_code_or_by_name(): void
    {
        $byBarcode = $this->item(['barcode' => '6111000000101', 'title' => 'Massage A']);
        $byCode = $this->item(['item_code' => 'IT9999', 'title' => 'Massage B']);
        $byName = $this->item(['title' => 'Soin Visage Éclat']);
        $this->item(['title' => 'Doublon']);
        $this->item(['title' => 'Doublon']);

        $match = $this->photos()->matcher($this->tenant);

        $this->assertSame($byBarcode->id, $match('6111000000101.jpg')['item']->id);
        $this->assertSame($byBarcode->id, $match('6111000000101 (3).jpg')['item']->id);
        $this->assertSame($byCode->id, $match('it9999.png')['item']->id);
        $this->assertSame($byName->id, $match('soin visage eclat.jpg')['item']->id);
        $this->assertNull($match('Doublon.jpg'), 'two products share the name: the photo cannot choose');
        $this->assertNull($match('inconnu.jpg'));
    }

    // ── Photos en masse ───────────────────────────────────────────────────────

    public function test_the_screen_previews_where_each_photo_goes(): void
    {
        $item = $this->item(['barcode' => '6111000000101', 'images' => ['catalogue/items/old.jpg']]);

        $this->actingAs($this->owner)
            ->postJson(route('catalog.photos.match'), ['names' => ['6111000000101.jpg', 'inconnu.jpg']])
            ->assertOk()
            ->assertJsonPath('matches.0.item_id', $item->id)
            ->assertJsonPath('matches.0.has_photo', true)
            ->assertJsonPath('matches.1.item_id', null);
    }

    public function test_dropped_photos_become_each_products_main_photo(): void
    {
        Storage::disk('public')->put('catalogue/items/old.jpg', 'old');
        $replaced = $this->item(['barcode' => '6111000000101', 'images' => ['catalogue/items/old.jpg']]);
        $fresh = $this->item(['title' => 'Coupe homme']);

        $this->actingAs($this->owner)
            ->post(route('catalog.photos.store'), [
                'photos' => [
                    UploadedFile::fake()->image('6111000000101.jpg', 1200, 900),
                    UploadedFile::fake()->image('Coupe homme.jpg', 400, 400),
                    UploadedFile::fake()->image('inconnu.jpg', 100, 100),
                ],
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('results.0.ok', true)
            ->assertJsonPath('results.1.ok', true)
            ->assertJsonPath('results.2.ok', false);

        $main = $replaced->fresh()->images[0];
        $this->assertStringStartsWith('catalogue/items/', $main);
        Storage::disk('public')->assertExists($main);
        Storage::disk('public')->assertMissing('catalogue/items/old.jpg');
        $this->assertCount(1, $replaced->fresh()->images);
        $this->assertNotEmpty($fresh->fresh()->images);
    }

    public function test_existing_photos_can_be_left_alone(): void
    {
        $item = $this->item(['barcode' => '6111000000101', 'images' => ['catalogue/items/old.jpg']]);

        $this->actingAs($this->owner)
            ->post(route('catalog.photos.store'), [
                'photos' => [UploadedFile::fake()->image('6111000000101.jpg')],
                'skip_existing' => '1',
            ], ['Accept' => 'application/json'])
            ->assertJsonPath('results.0.skipped', true);

        $this->assertSame(['catalogue/items/old.jpg'], $item->fresh()->images);
    }

    public function test_the_browser_may_rename_a_shrunk_photo(): void
    {
        $item = $this->item(['barcode' => '6111000000101']);

        $this->actingAs($this->owner)
            ->post(route('catalog.photos.store'), [
                'photos' => [UploadedFile::fake()->image('blob.jpg')],
                'names' => ['6111000000101.jpeg'],
            ], ['Accept' => 'application/json'])
            ->assertJsonPath('results.0.ok', true);

        $this->assertNotEmpty($item->fresh()->images);
    }

    public function test_the_panel_is_reachable_from_the_catalogue(): void
    {
        $this->actingAs($this->owner)
            ->get(route('catalog', ['panel' => 'photos']))
            ->assertOk()
            ->assertSee('data-bulk-photos', false)
            ->assertSee('Photos en masse');
    }

    // ── Import with photos ────────────────────────────────────────────────────

    private function zip(array $files): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'import-zip-').'.zip';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();
        $this->temp[] = $path;

        return new UploadedFile($path, 'catalogue.zip', 'application/zip', null, true);
    }

    public function test_a_zip_imports_the_sheet_and_its_photos(): void
    {
        $csv = "Code de barre,Nom de l'article,Catégorie/Type d'élément,Unité,Stock,Prix de vente,Statut,Type d'élément,Image\n"
            ."9900000000011,Massage zip,SOINS[ITEM],Service,0,350,Active,Service,massage.jpg\n"
            ."9900000000012,Shampooing zip,PRODUITS[ITEM],Pièce,5,280,Active,Article,\n"
            ."9900000000013,Sans photo,PRODUITS[ITEM],Pièce,5,10,Active,Article,\n";
        $photo = file_get_contents($this->png(300, 300));

        $this->actingAs($this->owner)
            ->post(route('catalog.import'), [
                'kind' => 'items',
                'catalog_file' => $this->zip([
                    'catalogue.csv' => $csv,
                    'photos/massage.jpg' => $photo,          // named in the Image column
                    'photos/9900000000012.png' => $photo,     // named after the barcode
                    '__MACOSX/._massage.jpg' => 'junk',
                ]),
            ])
            ->assertRedirect()
            ->assertSessionHas('status', fn (string $m): bool => str_contains($m, '3 créée(s)') && str_contains($m, 'Photos : 2 ajoutée(s)'));

        $find = fn (string $title) => Item::where('tenant_id', $this->tenant->id)->where('title', $title)->sole();
        $this->assertNotEmpty($find('Massage zip')->images);
        $this->assertNotEmpty($find('Shampooing zip')->images);
        $this->assertEmpty($find('Sans photo')->images);
    }

    public function test_a_photo_missing_from_the_zip_is_reported_not_fatal(): void
    {
        $csv = "Nom de l'article,Prix de vente,Image\nArticle sans fichier,10,absente.jpg\n";

        $this->actingAs($this->owner)
            ->post(route('catalog.import'), ['kind' => 'items', 'catalog_file' => $this->zip(['a.csv' => $csv])])
            ->assertSessionHas('status', fn (string $m): bool => str_contains($m, '1 créée(s)') && str_contains($m, 'absente du ZIP'));
    }

    public function test_a_zip_without_a_sheet_is_refused_clearly(): void
    {
        $this->actingAs($this->owner)
            ->post(route('catalog.import'), ['kind' => 'items', 'catalog_file' => $this->zip(['x.jpg' => 'x'])])
            ->assertSessionHasErrors('catalog_file');
    }

    public function test_a_zip_cannot_write_outside_its_folder(): void
    {
        $unzipped = $this->photos()->unzip($this->zip([
            'a.csv' => "Nom de l'article\nx\n",
            '../../../evil.jpg' => 'x',
        ])->getRealPath());

        foreach ($unzipped['photos'] as $path) {
            $this->assertStringStartsWith($unzipped['dir'].'/', $path);
        }
        $this->assertArrayHasKey('evil.jpg', $unzipped['photos']);
        ItemPhotos::cleanup($unzipped['dir']);
        $this->assertDirectoryDoesNotExist($unzipped['dir']);
    }

    public function test_a_link_in_the_sheet_is_downloaded(): void
    {
        ItemPhotos::$resolveUsing = fn (string $host): array => ['93.184.216.34'];
        Http::fake(['photos.example.com/*' => Http::response(file_get_contents($this->png(200, 200)), 200)]);
        $csv = "Nom de l'article,Prix de vente,Image\nArticle lien,10,https://photos.example.com/a.png\n";
        $path = tempnam(sys_get_temp_dir(), 'import-link-').'.csv';
        file_put_contents($path, $csv);
        $this->temp[] = $path;

        $this->actingAs($this->owner)
            ->post(route('catalog.import'), ['kind' => 'items', 'catalog_file' => new UploadedFile($path, 'a.csv', null, null, true)])
            ->assertSessionHas('status', fn (string $m): bool => str_contains($m, 'Photos : 1 ajoutée(s)'));

        $this->assertNotEmpty(Item::where('title', 'Article lien')->sole()->images);
    }

    public function test_a_link_to_the_servers_own_network_is_refused(): void
    {
        Http::fake();

        foreach (['127.0.0.1', '10.0.0.5', '169.254.169.254', '192.168.1.1'] as $ip) {
            ItemPhotos::$resolveUsing = fn (string $host): array => [$ip];
            try {
                $this->photos()->download('http://internal.example/a.jpg');
                $this->fail("{$ip} was fetched");
            } catch (\InvalidArgumentException $e) {
                $this->assertSame('adresse non autorisée', $e->getMessage());
            }
        }
        Http::assertNothingSent();
    }

    public function test_share_links_point_at_the_file(): void
    {
        $this->assertSame(
            'https://drive.google.com/uc?export=download&id=1AbC_d-E',
            $this->photos()->directLink('https://drive.google.com/file/d/1AbC_d-E/view?usp=sharing'),
        );
        $this->assertSame(
            'https://www.dropbox.com/s/x/a.jpg?dl=1',
            $this->photos()->directLink('https://www.dropbox.com/s/x/a.jpg?dl=0'),
        );
    }

    public function test_the_example_sheet_has_an_image_column(): void
    {
        $response = $this->actingAs($this->owner)->get(route('catalog.import.example', 'items'))->assertOk();
        $file = $response->baseResponse->getFile()->getPathname();

        $zip = new \ZipArchive;
        $zip->open($file);
        $sheet = (string) $zip->getFromName('xl/sharedStrings.xml').(string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        $this->assertStringContainsString('Image', $sheet);
    }
}
