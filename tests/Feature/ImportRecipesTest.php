<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\ItemComponent;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Catalogue\ImportRecipes;
use App\Support\ContentUnits;
use App\Support\ImportExamples;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Product capacity (« Contenu / Unité de contenu / Contenant ») and service
 * recipes (« Produits utilisés ») in the catalogue import.
 */
class ImportRecipesTest extends TestCase
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

    private function importCsv(string $csv, string $kind)
    {
        $path = tempnam(sys_get_temp_dir(), 'rec-').'.csv';
        file_put_contents($path, $csv);

        return $this->actingAs($this->owner)->post(route('catalog.import'), [
            'kind' => $kind,
            'catalog_file' => new UploadedFile($path, 'fichier.csv', null, null, true),
        ]);
    }

    private function csv(array $headers, array $rows): string
    {
        $line = fn (array $cells) => implode(',', array_map(fn ($c) => '"'.str_replace('"', '""', (string) $c).'"', $cells))."\n";

        return $line($headers).implode('', array_map($line, $rows));
    }

    private function products(): void
    {
        $this->importCsv($this->csv(['Nom de l\'article', 'Prix de vente', 'Stock'], [
            ['Gel nettoyant visage', 50, 5], ['Lotion tonique', 40, 5], ['Masque hydratant', 80, 5],
            ['Masque purifiant (argile)', 80, 5], ['Gants jetables', 1, 100], ['Huile de massage', 90, 5],
        ]), 'items')->assertSessionHasNoErrors();
    }

    private function product(string $title): Item
    {
        return Item::where('title', $title)->sole();
    }

    private function services(array $rows)
    {
        return $this->importCsv($this->csv(['Nom de l\'article', 'Prix de vente', 'Variantes', 'Produits utilisés'], $rows), 'services');
    }

    private function recipe(string $service): array
    {
        $names = Item::pluck('title', 'id');

        return ItemComponent::where('item_id', Item::where('title', $service)->value('id'))
            ->orderBy('variant_id')->orderBy('sort_order')->get()
            ->map(fn ($c) => [
                $c->variant_id ? \App\Models\ItemVariant::find($c->variant_id)->name : null,
                $names[$c->component_item_id], (float) $c->quantity, $c->choice_group, (bool) $c->is_default,
            ])->all();
    }

    public function test_capacity_columns_accept_labels_and_keys(): void
    {
        $rows = [
            ['Crème A', 10, '200', 'ml', 'flacon'],
            ['Crème B', 10, '1,5', 'Litres', 'Bidon'],
            ['Crème C', 10, '3', 'PIÈCES', 'boîte'],
            ['Crème D', 10, '100', 'grams', 'jar'],
            ['Crème E', 10, '2', 'piece', 'piece'],
        ];
        $this->importCsv($this->csv(['Nom de l\'article', 'Prix de vente', 'Contenu', 'Unité de contenu', 'Contenant'], $rows), 'items')
            ->assertSessionHasNoErrors();

        $got = fn (string $t) => [(float) $this->product($t)->content_quantity, $this->product($t)->content_unit, $this->product($t)->content_container];
        $this->assertSame([200.0, 'ml', 'flacon'], $got('Crème A'));
        $this->assertSame([1.5, 'l', 'bidon'], $got('Crème B'));
        $this->assertSame([3.0, 'piece', 'boite'], $got('Crème C'));
        $this->assertSame([100.0, 'g', 'pot'], $got('Crème D'));
        $this->assertSame([2.0, 'piece', 'piece'], $got('Crème E'));
        $this->assertTrue($this->product('Crème A')->hasCapacity());
    }

    public function test_bad_capacity_cells_warn_and_never_fail_the_import(): void
    {
        $response = $this->importCsv($this->csv(['Nom de l\'article', 'Prix de vente', 'Contenu', 'Unité de contenu', 'Contenant'], [
            ['Bad A', 10, 'beaucoup', 'ml', 'flacon'],
            ['Bad B', 10, '50', 'furlong', 'amphore'],
            ['Bad C', 10, '50', '', ''],
            ['Bad D', 10, '', '', 'tube'],
        ]), 'items');
        $response->assertSessionHasNoErrors();

        $this->assertNull($this->product('Bad A')->content_quantity);
        $this->assertSame('flacon', $this->product('Bad A')->content_container);
        $this->assertNull($this->product('Bad B')->content_unit);
        $this->assertNull($this->product('Bad C')->content_unit);
        $this->assertSame('tube', $this->product('Bad D')->content_container);
        $this->assertCount(4, session('import_warnings'));
    }

    public function test_an_empty_capacity_cell_leaves_the_capacity_and_services_ignore_the_columns(): void
    {
        $headers = ['Nom de l\'article', 'Prix de vente', 'Contenu', 'Unité de contenu', 'Contenant'];
        $this->importCsv($this->csv($headers, [['Sérum', 10, 30, 'ml', 'flacon']]), 'items');
        $this->importCsv($this->csv(['Nom de l\'article', 'Prix de vente'], [['Sérum', 12]]), 'items');
        $this->assertSame([30.0, 'ml', 'flacon'], [(float) $this->product('Sérum')->content_quantity, $this->product('Sérum')->content_unit, $this->product('Sérum')->content_container]);

        $this->importCsv($this->csv($headers, [['Soin visage', 100, 30, 'ml', 'flacon']]), 'services')->assertSessionHasNoErrors();
        $service = $this->product('Soin visage');
        $this->assertNull($service->content_quantity);
        $this->assertNull($service->content_container);
    }

    public function test_the_recipe_cell_is_parsed(): void
    {
        $parsed = ImportRecipes::parse('Gel nettoyant visage=5; Lotion tonique=2,5; [Masque] Masque hydratant=10*; [Masque] Masque purifiant (argile)=10; 1 h > Huile de massage=30; Court > Gants jetables=0');
        $this->assertSame([], $parsed['errors']);
        $this->assertFalse($parsed['clear']);
        $this->assertSame([
            [null, null, 'Gel nettoyant visage', 5.0, false],
            [null, null, 'Lotion tonique', 2.5, false],
            [null, 'Masque', 'Masque hydratant', 10.0, true],
            [null, 'Masque', 'Masque purifiant (argile)', 10.0, false],
            ['1 h', null, 'Huile de massage', 30.0, false],
            ['Court', null, 'Gants jetables', 0.0, false],
        ], array_map(fn ($r) => [$r['variant'], $r['group'], $r['product'], $r['quantity'], $r['default']], $parsed['rows']));

        $this->assertTrue(ImportRecipes::parse(' - ')['clear']);

        $bad = ImportRecipes::parse('Sans quantité; A=abc; =4; B=0; C=1; C=2; [G] D=1*; [G] E=1*; F=2*');
        $this->assertSame(['C', 'D', 'E', 'F'], array_column($bad['rows'], 'product'));
        $this->assertSame([false, true, false, false], array_column($bad['rows'], 'default'));
        $this->assertCount(7, $bad['errors']);
    }

    public function test_services_import_builds_the_recipe_with_groups_defaults_and_variants(): void
    {
        $this->products();
        $cell = 'Gel nettoyant visage=5; Lotion tonique=2,5; [Masque] Masque hydratant=10*; [Masque] Masque purifiant (argile)=10; Gants jetables=2; 1 h > Huile de massage=30; 1 h > Gants jetables=0';
        $this->services([['Soin du visage', 200, '30 min=100@30; 1 h=200@60', $cell]])->assertSessionHasNoErrors();

        $this->assertSame([
            [null, 'Gel nettoyant visage', 5.0, null, false],
            [null, 'Lotion tonique', 2.5, null, false],
            [null, 'Masque hydratant', 10.0, 'Masque', true],
            [null, 'Masque purifiant (argile)', 10.0, 'Masque', false],
            [null, 'Gants jetables', 2.0, null, false],
            ['1 h', 'Huile de massage', 30.0, null, false],
            ['1 h', 'Gants jetables', 0.0, null, false],
        ], $this->recipe('Soin du visage'));
        $this->assertNull(session('import_warnings')[0] ?? null);
    }

    public function test_unknown_product_variant_and_malformed_pieces_warn_while_the_rest_imports(): void
    {
        $this->products();
        $this->services([['Soin', 100, 'Court=50', 'Gel nettoyant visage=5; Produit fantôme=3; Long > Lotion tonique=2; Lotion tonique; Gants jetables=1']])
            ->assertSessionHasNoErrors();

        $this->assertSame([[null, 'Gel nettoyant visage', 5.0, null, false], [null, 'Gants jetables', 1.0, null, false]], $this->recipe('Soin'));
        $warnings = implode(' | ', session('import_warnings'));
        $this->assertStringContainsString('Produit fantôme', $warnings);
        $this->assertStringContainsString('variante « Long »', $warnings);
        $this->assertStringContainsString('Lotion tonique', $warnings);
    }

    public function test_variants_and_products_match_case_and_accent_insensitively(): void
    {
        $this->importCsv($this->csv(['Nom de l\'article', 'Prix de vente'], [['Crème Épaisse', 10]]), 'items');
        $this->services([['Soin', 100, 'Court=50', 'court > crème épaisse=4']])->assertSessionHasNoErrors();

        $this->assertSame([['Court', 'Crème Épaisse', 4.0, null, false]], $this->recipe('Soin'));
    }

    public function test_the_cell_replaces_the_recipe_an_empty_cell_keeps_it_and_a_dash_clears_it(): void
    {
        $this->products();
        $this->services([['Soin', 100, '', 'Gel nettoyant visage=5; Lotion tonique=2']]);
        $this->assertCount(2, $this->recipe('Soin'));

        $this->services([['Soin', 100, '', 'Gants jetables=3']]);
        $this->assertSame([[null, 'Gants jetables', 3.0, null, false]], $this->recipe('Soin'));

        $this->services([['Soin', 100, '', '']]);
        $this->assertCount(1, $this->recipe('Soin'), 'an empty cell leaves the recipe untouched');

        $this->services([['Soin', 100, '', 'Produit fantôme=3']]);
        $this->assertCount(1, $this->recipe('Soin'), 'nothing usable leaves the recipe untouched');

        $this->services([['Soin', 100, '', '-']]);
        $this->assertSame([], $this->recipe('Soin'));
    }

    public function test_importing_the_same_files_twice_changes_nothing(): void
    {
        $this->products();
        $cell = '[Masque] Masque hydratant=10*; [Masque] Masque purifiant (argile)=10; Gants jetables=2; 1 h > Huile de massage=30';
        $this->services([['Soin', 200, '30 min=100@30; 1 h=200@60', $cell]]);
        $first = $this->recipe('Soin');
        $count = ItemComponent::count();
        $items = Item::count();

        $this->products();
        $this->services([['Soin', 200, '30 min=100@30; 1 h=200@60', $cell]]);

        $this->assertSame($first, $this->recipe('Soin'));
        $this->assertSame($count, ItemComponent::count());
        $this->assertSame($items, Item::count());
    }

    public function test_the_beauty_and_barber_examples_carry_the_new_columns_and_import_back(): void
    {
        foreach (['beauty', 'barber'] as $mode) {
            $settings = $this->tenant->settings;
            $settings['company_profile']['business_mode'] = $mode;
            $this->tenant->forceFill(['settings' => $settings])->save();
            $tenant = $this->tenant->fresh();

            $items = ImportExamples::sheet($tenant, 'items');
            foreach (['Contenu', 'Unité de contenu', 'Contenant'] as $header) {
                $this->assertContains($header, $items['headers'], "$mode items: $header");
            }
            $services = ImportExamples::sheet($tenant, 'services');
            $this->assertContains('Produits utilisés', $services['headers']);
            $col = array_search('Produits utilisés', $services['headers'], true);
            $this->assertNotSame('', $services['rows'][0][$col]);

            $this->importCsv($this->csv($items['headers'], $items['rows']), 'items')->assertSessionHasNoErrors();
            $this->importCsv($this->csv($services['headers'], $services['rows']), 'services')->assertSessionHasNoErrors();
            $this->assertEmpty(session('import_warnings'), "$mode example imports without a warning");
            $first = Item::where('title', $services['rows'][0][1])->sole();
            $this->assertGreaterThan(0, ItemComponent::where('item_id', $first->id)->count(), $mode);
        }

        $this->assertTrue(ContentUnits::parseUnit('Pièce') === 'piece');
        $tenant = $this->tenant->fresh();
        $settings = $tenant->settings;
        $settings['company_profile']['business_mode'] = 'butcher';
        $tenant->forceFill(['settings' => $settings])->save();
        $this->assertNotContains('Contenu', ImportExamples::sheet($tenant->fresh(), 'items')['headers']);
    }
}
