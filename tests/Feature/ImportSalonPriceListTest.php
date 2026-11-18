<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\ItemVariant;
use App\Models\Modifier;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Catalogue\ImportOffers;
use App\Support\ImportExamples;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * A salon's price list in one sheet: several prices per service, price by
 * duration, add-ons, a duration and « à partir de ». Parsing is pure; the
 * import turns it into real variants and options, and doing it twice changes
 * nothing.
 */
class ImportSalonPriceListTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->tenant = Tenant::firstOrFail();
        $this->owner = User::where('email', 'amina@librairie-atlas.ma')->firstOrFail();
    }

    private function importCsv(string $csv, string $kind = 'services')
    {
        $path = tempnam(sys_get_temp_dir(), 'salon-').'.csv';
        file_put_contents($path, $csv);

        return $this->actingAs($this->owner)->post(route('catalog.import'), [
            'kind' => $kind,
            'catalog_file' => new UploadedFile($path, 'prestations.csv', null, null, true),
        ]);
    }

    private const HEAD = "Nom de l'article,Prix de vente,Durée (min),Prix à partir de,Variantes,Options\n";

    public function test_a_variants_cell_is_parsed_into_names_prices_and_durations(): void
    {
        $parsed = ImportOffers::parseVariants('Court=20; Mi-long=40,5; Long=60');
        $this->assertSame([], $parsed['errors']);
        $this->assertSame([['Court', 20.0, null], ['Mi-long', 40.5, null], ['Long', 60.0, null]],
            array_map(fn ($v) => [$v['name'], $v['price'], $v['duration']], $parsed['variants']));

        $timed = ImportOffers::parseVariants('30 min=200@30; 1 h=400@1h');
        $this->assertSame([30, 60], array_column($timed['variants'], 'duration'));
        $this->assertSame([200.0, 400.0], array_column($timed['variants'], 'price'));
    }

    public function test_malformed_pieces_are_reported_and_the_good_ones_kept(): void
    {
        $parsed = ImportOffers::parseVariants('Court=20; sans prix; Long=abc; Court=25; Mi=30@2');
        $this->assertSame(['Court', 'Mi'], array_column($parsed['variants'], 'name'));
        $this->assertNull($parsed['variants'][1]['duration'], 'a duration under 5 minutes is dropped, the price kept');
        $this->assertCount(4, $parsed['errors']);

        $options = ImportOffers::parseOptions('Paraffine=+50; Vernis=+20; Rien; Gel=x');
        $this->assertSame([['Paraffine', 50.0], ['Vernis', 20.0]], array_map(fn ($o) => [$o['name'], $o['delta']], $options['options']));
        $this->assertCount(2, $options['errors']);
    }

    public function test_flags_and_durations_accept_the_way_a_shop_writes_them(): void
    {
        foreach (['oui', 'Oui', 'yes', '1', 'x', 'X'] as $yes) {
            $this->assertTrue(ImportOffers::flag($yes), $yes);
        }
        foreach (['non', 'no', '0'] as $no) {
            $this->assertFalse(ImportOffers::flag($no), $no);
        }
        $this->assertNull(ImportOffers::flag(''));
        $this->assertNull(ImportOffers::flag('peut-être'));

        $this->assertSame(45, ImportOffers::duration('45'));
        $this->assertSame(90, ImportOffers::duration('1h30'));
        $this->assertSame(60, ImportOffers::duration('1 h'));
        $this->assertSame(75, ImportOffers::duration('75 min'));
        $this->assertNull(ImportOffers::duration('2'));
        $this->assertNull(ImportOffers::duration('9999'));
    }

    public function test_the_import_creates_variants_and_the_cheapest_becomes_the_price(): void
    {
        $this->importCsv(self::HEAD.'Shampoing,,20,,Court=20; Mi-long=40; Long=60,'."\n")
            ->assertRedirect()->assertSessionHasNoErrors();

        $item = Item::where('tenant_id', $this->tenant->id)->where('title', 'Shampoing')->sole();
        $this->assertSame('service', $item->type);
        $this->assertSame(20, $item->duration_minutes);
        $this->assertEquals(20.0, (float) $item->sale_price, 'the lowest variant price when the cell is empty');
        $this->assertSame(3, $item->variant_count);
        $this->assertTrue($item->hasVariants());

        $variants = $item->variants()->orderBy('sort_order')->get();
        $this->assertSame(['Court', 'Mi-long', 'Long'], $variants->pluck('name')->all());
        $this->assertEquals([20.0, 40.0, 60.0], $variants->map->price()->all(), 'price() is what the till sells');
        $this->assertNotNull($variants->first()->combination_key);
    }

    public function test_a_price_in_the_row_wins_over_the_cheapest_variant(): void
    {
        $this->importCsv(self::HEAD.'Coloration,300,90,,Court=300; Long=450,'."\n");

        $this->assertEquals(300.0, (float) Item::where('title', 'Coloration')->value('sale_price'));
    }

    public function test_importing_the_same_file_twice_changes_nothing_and_a_new_price_updates(): void
    {
        $csv = self::HEAD.'Massage,,,,30 min=200@30; 1 h=400@60,'."\n"
            .'Pédicure,150,45,,,Paraffine=+50; Vernis=+20'."\n";

        $this->importCsv($csv);
        $this->importCsv($csv);

        $this->assertSame(1, Item::where('tenant_id', $this->tenant->id)->where('title', 'Massage')->count(), 'the item is recognised by its name');
        $massage = Item::where('title', 'Massage')->sole();
        $this->assertSame(2, ItemVariant::where('item_id', $massage->id)->count());
        $this->assertSame(2, $massage->fresh()->variant_count);

        $pedicure = Item::where('title', 'Pédicure')->sole();
        $groups = $pedicure->modifierGroups()->get();
        $this->assertCount(1, $groups);
        $this->assertSame(['Paraffine', 'Vernis'], $groups->first()->modifiers->pluck('name')->all());
        $this->assertEquals([50.0, 20.0], $groups->first()->modifiers->map(fn ($m) => (float) $m->price_delta)->all());

        $this->importCsv(self::HEAD.'Massage,,,,30 min=220@30; 1 h=400@60; 90 min=550@90,'."\n"
            .'Pédicure,150,45,,,Paraffine=+60'."\n");

        $variants = ItemVariant::where('item_id', $massage->id)->orderBy('sort_order')->get();
        $this->assertSame(['30 min', '1 h', '90 min'], $variants->pluck('name')->all());
        $this->assertEquals([220.0, 400.0, 550.0], $variants->map->price()->all());
        $this->assertSame(1, Modifier::where('name', 'Paraffine')->count(), 'updated, never duplicated');
        $this->assertEquals(60.0, (float) Modifier::where('name', 'Paraffine')->value('price_delta'));
        $this->assertSame(1, Modifier::where('name', 'Vernis')->count(), 'the option left out of the cell is left alone');
    }

    /** SQLite's LOWER() leaves « É » alone, so this one was duplicated on every re-import. */
    public function test_a_name_starting_with_an_accented_capital_is_recognised_on_reimport(): void
    {
        $csv = self::HEAD."Épilation totale,300,90,,,\n";

        $this->importCsv($csv);
        $this->importCsv($csv);

        $this->assertSame(1, Item::where('tenant_id', $this->tenant->id)->where('title', 'Épilation totale')->count());
    }

    /** A salon service has no code: its photo in the ZIP is named after it, accents spelled the Mac way. */
    public function test_a_zip_photo_named_after_the_service_is_attached(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $dir = sys_get_temp_dir().'/salon-zip-'.uniqid();
        mkdir($dir);
        file_put_contents("{$dir}/prestations.csv", self::HEAD."Épilation totale,300,90,,,\nMassage,,,,30 min=200@30,\n");
        $jpeg = imagecreatetruecolor(20, 20);
        imagejpeg($jpeg, "{$dir}/photo.jpg");
        $zipPath = "{$dir}/import.zip";
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFile("{$dir}/prestations.csv", 'prestations.csv');
        $zip->addFile("{$dir}/photo.jpg", \Normalizer::normalize('Épilation totale.jpg', \Normalizer::FORM_D));
        $zip->addFile("{$dir}/photo.jpg", 'massage.JPG');
        $zip->close();

        $this->actingAs($this->owner)->post(route('catalog.import'), [
            'kind' => 'services',
            'catalog_file' => new UploadedFile($zipPath, 'import.zip', null, null, true),
        ])->assertSessionHasNoErrors();

        foreach (['Épilation totale', 'Massage'] as $title) {
            $images = Item::where('tenant_id', $this->tenant->id)->where('title', $title)->sole()->images;
            $this->assertNotEmpty($images, "{$title} got its photo");
            \Illuminate\Support\Facades\Storage::disk('public')->assertExists($images[0]);
        }
    }

    public function test_bad_cells_are_warnings_not_a_failed_import(): void
    {
        $response = $this->importCsv(self::HEAD
            .'Brushing,80,2,peut-être,,'."\n"
            .'Soin visage,250,45,,Court=abc,Gel=?'."\n")
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2, Item::where('tenant_id', $this->tenant->id)->whereIn('title', ['Brushing', 'Soin visage'])->count(), 'both rows are imported');
        $this->assertNull(Item::where('title', 'Brushing')->value('duration_minutes'));
        $warnings = session('import_warnings');
        $this->assertGreaterThanOrEqual(4, count($warnings));
        $this->assertStringContainsString('Brushing', $warnings[0]);
        $this->assertStringContainsString('avertissement', session('status'));
    }

    public function test_existing_columns_still_import_and_a_product_can_carry_variants(): void
    {
        $csv = "Code de barre,Nom de l'article,Stock,Prix de vente,Variantes\n"
            .'6110000000999,Huile,5,30,Petit=30; Grand=55'."\n";
        $this->importCsv($csv, 'items')->assertRedirect();

        $item = Item::where('barcode', '6110000000999')->sole();
        $this->assertEquals(30.0, (float) $item->sale_price);
        $this->assertSame(2, $item->variant_count);
    }

    private function sheetFor(string $mode): array
    {
        $settings = $this->tenant->settings;
        $settings['company_profile']['business_mode'] = $mode;
        $this->tenant->forceFill(['settings' => $settings])->save();

        return ImportExamples::sheet($this->tenant->fresh(), 'services');
    }

    public function test_the_beauty_and_barber_example_sheets_show_the_new_columns(): void
    {
        foreach (['beauty', 'barber'] as $mode) {
            $sheet = $this->sheetFor($mode);
            foreach (['Durée (min)', 'Prix à partir de', 'Variantes', 'Options'] as $header) {
                $this->assertContains($header, $sheet['headers'], "$mode: $header");
            }
            $this->assertCount(count($sheet['headers']), $sheet['rows'][0]);
            $filled = collect($sheet['rows'])->flatMap(fn ($r) => array_slice($r, -4))->filter(fn ($c) => $c !== '')->count();
            $this->assertGreaterThan(2, $filled, "$mode demonstrates the columns");
        }

        $this->assertSame('', $this->sheetFor('butcher')['rows'][0][array_search('Variantes', $this->sheetFor('butcher')['headers'], true)]);
    }

    public function test_the_beauty_example_imports_back_into_a_working_price_list(): void
    {
        $sheet = $this->sheetFor('beauty');
        $csv = implode(',', array_map(fn ($h) => '"'.$h.'"', $sheet['headers']))."\n";
        foreach ($sheet['rows'] as $row) {
            $csv .= implode(',', array_map(fn ($c) => '"'.str_replace('"', '""', (string) $c).'"', $row))."\n";
        }

        $this->importCsv($csv)->assertSessionHasNoErrors();
        $this->importCsv($csv);

        $this->assertSame(3, Item::where('title', 'Shampoing')->sole()->variant_count);
        $this->assertSame(2, Item::where('title', 'Massage')->sole()->variant_count);
        $this->assertCount(1, Item::where('title', 'Pédicure')->sole()->modifierGroups()->get());
        $this->assertEquals(300.0, (float) Item::where('title', 'Coloration')->value('sale_price'));
        $this->assertSame(90, Item::where('title', 'Coloration')->value('duration_minutes'));
    }
}
