<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Item;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\VariantOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
use ZipArchive;

class CatalogueTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalogue_can_create_item_category_brand_and_variant(): void
    {
        $this->seed();

        $categoryResponse = $this->post(route('catalog.categories.store'), [
            'name' => 'Parascolaire',
            'icon' => 'book-copy',
            'color' => '#0EA5E9',
            'loan_duration_days' => 14,
            'daily_fine_amount' => 2,
        ]);

        $categoryResponse->assertRedirect();
        $category = Category::where('name', 'Parascolaire')->firstOrFail();
        $unit = Unit::where('name', 'Pièce')->firstOrFail();
        $tax = Tax::where('name', 'TVA 20%')->firstOrFail();

        $brandResponse = $this->post(route('catalog.brands.store'), [
            'name' => 'Éditions Test',
            'type' => 'publisher',
            'email' => 'edition@example.ma',
        ]);

        $brandResponse->assertRedirect();

        $itemResponse = $this->post(route('catalog.items.store'), [
            'type' => 'book',
            'title' => 'Nouveau manuel test',
            'author' => 'Auteur Test',
            'isbn' => '9780000000001',
            'barcode' => 'TEST-BOOK-001',
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'tax_id' => $tax->id,
            'purchase_price' => 50,
            'sale_price' => 85,
            'stock_quantity' => 12,
            'min_stock_threshold' => 3,
            'location' => 'Rayon T-01',
            'status' => 'active',
        ]);

        $itemResponse->assertRedirect();
        $item = Item::where('barcode', 'TEST-BOOK-001')->firstOrFail();

        $variantResponse = $this->post(route('catalog.variants.store'), [
            'item_id' => $item->id,
            'name' => 'Relié',
            'format' => 'Relié',
            'barcode' => 'TEST-BOOK-001-R',
            'purchase_price' => 60,
            'sale_price' => 95,
            'stock_quantity' => 4,
        ]);

        $variantResponse->assertRedirect();
        $this->assertDatabaseHas('item_variants', ['item_id' => $item->id, 'name' => 'Relié']);
    }

    public function test_catalogue_import_and_labels_work(): void
    {
        $this->seed();

        $file = UploadedFile::fake()->createWithContent('catalogue.csv', implode("\n", [
            'title,isbn,barcode,category,brand,purchase_price,sale_price,stock,location',
            'Stylo bleu import,,IMP-STYLO-001,Papeterie,Bic,1.5,3,100,Comptoir',
        ]));

        $response = $this->post(route('catalog.import'), [
            'kind' => 'items',
            'catalog_file' => $file,
        ]);

        $response->assertRedirect();
        $item = Item::where('barcode', 'IMP-STYLO-001')->firstOrFail();

        $this->get(route('catalog.labels', ['items' => $item->id, 'template' => 'medium']))
            ->assertOk()
            ->assertSee('Stylo bleu import')
            ->assertSee('IMP-STYLO-001');
    }

    public function test_catalogue_import_accepts_mylibrairie_sample_headers(): void
    {
        $this->seed();

        $file = UploadedFile::fake()->createWithContent('legacy-items.csv', implode("\n", [
            'ITEM NAME,CATEGORY NAME,SKU,HSN,UNIT NAME,ALERT QTY,BRAND NAME,LOT NUMBER,PRICE BEFORE TAX,TAX NAME,TAX VALUE,TAX TYPE,SALES PRICE,OPENING STOCK,CUSTOM BARCODE,SELLER POINTS,ITEM DESCIPTION,DISCOUNT TYPE,DISCOUNT,MRP',
            'Item Name 1,Category 1,1111,HSN1,BOX,5,Brand 1,11,100,Tax 5%,5,Inclusive,120,10,BARCODE123,10,Description 1,Percentage,10,150',
        ]));

        $response = $this->post(route('catalog.import'), [
            'kind' => 'items',
            'catalog_file' => $file,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('items', [
            'title' => 'Item Name 1',
            'barcode' => 'BARCODE123',
            'sku' => '1111',
            'hsn' => 'HSN1',
            'min_stock_threshold' => 5,
            'sale_price' => 120,
            'stock_quantity' => 10,
            'tax_type' => 'Inclusive',
        ]);
        $this->assertDatabaseHas('categories', ['name' => 'Category 1']);
        $this->assertDatabaseHas('brands', ['name' => 'Brand 1']);
        $this->assertDatabaseHas('units', ['name' => 'BOX']);
        $this->assertDatabaseHas('taxes', ['name' => 'Tax 5%']);
    }

    public function test_catalogue_imports_legacy_mylibrairie_xlsx_exports(): void
    {
        $this->seed();

        $this->post(route('catalog.import'), [
            'kind' => 'categories',
            'catalog_file' => $this->legacyXlsx('Liste des catégories', ['Nom de catégorie', 'La description', 'Statut'], [
                ['DICTIONNAIRE TEST', 'Livres de référence', 'Active'],
            ], 'categories.xlsx'),
        ])->assertRedirect();

        $this->post(route('catalog.import'), [
            'kind' => 'brands',
            'catalog_file' => $this->legacyXlsx('Liste des marques', ['Marque', 'La description', 'Statut'], [
                ['CONSON TEST', 'Marque exportée', 'Active'],
            ], 'brands.xlsx'),
        ])->assertRedirect();

        $this->post(route('catalog.import'), [
            'kind' => 'variants',
            'catalog_file' => $this->legacyXlsx('Liste des variantes', ['Nom de la variante', 'La description', 'Statut'], [
                ['BLEU MARINE TEST', 'Couleur importée', 'Active'],
            ], 'variants.xlsx'),
        ])->assertRedirect();

        $this->post(route('catalog.import'), [
            'kind' => 'items',
            'catalog_file' => $this->legacyXlsx("Liste d'articles", ["Code de barre", "Nom de l'article", "Catégorie/Type d'élément", 'Unité', 'Stock', "Quantité d'alerte", 'Prix de vente', 'Impôt', 'Statut', 'Action'], [
                ['BAR-LEGACY-XLSX-001', 'RECHARGE MARQUEUR TEST', 'FOURNITURE SCOLAIRE[ITEM]', 'Pièce', '7.0', '2', '3.5', 'Sans TVA(0.00%)', 'Active', 'Modifier'],
            ], 'items.xlsx'),
        ])->assertRedirect();

        $this->assertDatabaseHas('categories', ['name' => 'DICTIONNAIRE TEST']);
        $this->assertDatabaseHas('brands', ['name' => 'CONSON TEST', 'type' => 'brand']);
        $this->assertDatabaseHas('variant_options', ['name' => 'BLEU MARINE TEST', 'is_active' => true]);
        $this->assertDatabaseHas('categories', ['name' => 'FOURNITURE SCOLAIRE']);
        $this->assertDatabaseHas('items', [
            'barcode' => 'BAR-LEGACY-XLSX-001',
            'title' => 'RECHARGE MARQUEUR TEST',
            'stock_quantity' => 7,
            'min_stock_threshold' => 2,
            'sale_price' => 3.5,
        ]);
    }

    public function test_catalogue_list_uses_legacy_columns_and_exports_filtered_csv(): void
    {
        $this->seed();

        $item = Item::where('type', '!=', 'service')->firstOrFail();

        $this->get(route('catalog', ['panel' => 'articles', 'q' => $item->barcode ?? $item->title]))
            ->assertOk()
            ->assertSee('Code de barre')
            ->assertSee("Nom de l'article", false)
            ->assertSee("Catégorie/")
            ->assertSee("Type d'élément", false)
            ->assertSee("Quantité d'alerte", false)
            ->assertSee('Prix de vente')
            ->assertSee('Impôt')
            ->assertSee('Exporter CSV');

        $response = $this->get(route('catalog.export', ['panel' => 'articles', 'q' => $item->barcode ?? $item->title]));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $csv = $response->streamedContent();
        $this->assertStringContainsString('Code de barre', $csv);
        $this->assertStringContainsString($item->title, $csv);
    }

    public function test_catalogue_accepts_legacy_item_and_service_fields(): void
    {
        $this->seed();

        $category = Category::where('name', 'Services')->firstOrFail();
        $unit = Unit::where('name', 'Service')->firstOrFail();
        $tax = Tax::where('name', 'TVA 20%')->firstOrFail();

        $articleResponse = $this->post(route('catalog.items.store'), [
            'type' => 'book',
            'item_code' => 'IT-LEGACY-001',
            'item_group' => 'Pack',
            'nb_item' => 4,
            'title' => 'Livre legacy complet',
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'tax_id' => $tax->id,
            'sku' => 'SKU-LEGACY',
            'hsn' => 'HSN-01',
            'author' => 'Auteur Legacy',
            'editor' => 'Éditeur texte',
            'edition_year' => '2026',
            'barcode' => 'BAR-LEGACY-001',
            'discount_type' => 'Percentage',
            'discount' => 5,
            'price' => 100,
            'purchase_price' => 100,
            'tax_type' => 'Inclusive',
            'profit_margin' => 25,
            'sale_price' => 125,
            'reseller_sale_price' => 115,
            'mrp' => 130,
            'opening_stock' => 8,
            'stock_quantity' => 8,
            'min_stock_threshold' => 2,
            'warehouse' => 'Oubra store',
            'location' => 'A-01',
        ]);

        $articleResponse->assertRedirect();
        $this->assertDatabaseHas('items', [
            'item_code' => 'IT-LEGACY-001',
            'barcode' => 'BAR-LEGACY-001',
            'item_group' => 'Pack',
            'nb_item' => 4,
            'tax_type' => 'Inclusive',
        ]);

        $serviceResponse = $this->post(route('catalog.items.store'), [
            'type' => 'service',
            'item_code' => 'SRV-LEGACY-001',
            'title' => 'Photocopie couleur legacy',
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'tax_id' => $tax->id,
            'barcode' => 'SRV-BAR-001',
            'sac' => 'SAC-01',
            'hsn' => 'HSN-SRV',
            'seller_points' => 1,
            'discount_type' => 'Fixed',
            'discount' => 0,
            'price' => 2,
            'purchase_price' => 2,
            'tax_type' => 'Exclusive',
            'sale_price' => 3,
            'stock_quantity' => 9999,
            'min_stock_threshold' => 0,
        ]);

        $serviceResponse->assertRedirect();
        $this->assertDatabaseHas('items', [
            'item_code' => 'SRV-LEGACY-001',
            'type' => 'service',
            'stock_quantity' => 9999,
            'min_stock_threshold' => 0,
        ]);
    }

    public function test_add_item_reference_shortcuts_return_json_options(): void
    {
        $this->seed();

        $this->postJson(route('catalog.brands.store'), [
            'name' => 'Marque rapide',
            'type' => 'brand',
        ])->assertOk()->assertJsonPath('label', 'Marque rapide');

        $this->postJson(route('catalog.categories.store'), [
            'name' => 'Catégorie rapide',
            'loan_duration_days' => 14,
            'daily_fine_amount' => 2,
        ])->assertOk()->assertJsonPath('label', 'Catégorie rapide');

        $this->postJson(route('catalog.units.store'), [
            'name' => 'Boîte',
        ])->assertOk()->assertJsonPath('label', 'Boîte');

        $this->postJson(route('catalog.taxes.store'), [
            'name' => 'TVA test',
            'rate' => 7,
        ])->assertOk()->assertJsonStructure(['id', 'label']);
    }


    public function test_catalogue_shows_validation_error_for_duplicate_isbn_on_update(): void
    {
        $this->seed();

        $existing = Item::whereNotNull('isbn')->firstOrFail();
        $target = Item::where('id', '!=', $existing->id)->firstOrFail();

        $response = $this->from(route('catalog'))->put(route('catalog.items.update', $target), [
            'type' => $target->type,
            'title' => $target->title,
            'author' => $target->author,
            'isbn' => $existing->isbn,
            'barcode' => $target->barcode,
            'category_id' => $target->category_id,
            'brand_id' => $target->brand_id,
            'unit_id' => $target->unit_id,
            'tax_id' => $target->tax_id,
            'purchase_price' => $target->purchase_price,
            'sale_price' => $target->sale_price,
            'stock_quantity' => $target->stock_quantity,
            'min_stock_threshold' => $target->min_stock_threshold,
            'location' => $target->location,
            'status' => $target->status,
            'description' => $target->description,
        ]);

        $response->assertRedirect(route('catalog'));
        $response->assertSessionHasErrors('isbn');
        $this->assertNotSame($existing->isbn, $target->fresh()->isbn);
    }

    public function test_catalogue_detail_edit_prefills_item_service_and_variant_data(): void
    {
        $this->seed();

        $item = Item::where('type', '!=', 'service')->whereHas('variants')->firstOrFail();
        $service = Item::where('type', 'service')->firstOrFail();

        $this->get(route('catalog', ['panel' => 'articles', 'edit' => $item->id]))
            ->assertOk()
            ->assertSee('Détail / modifier: '.$item->title)
            ->assertSee('value="'.$item->title.'"', false)
            ->assertSee($item->variants->first()->name);

        $this->get(route('catalog', ['panel' => 'services', 'edit' => $service->id]))
            ->assertOk()
            ->assertSee('Détail / modifier: '.$service->title)
            ->assertSee('value="'.$service->title.'"', false);

        $this->get(route('catalog', ['panel' => 'variantes']))
            ->assertOk()
            ->assertSee('Liste des variantes')
            ->assertSee($item->title)
            ->assertSee($item->variants->first()->name);
    }

    public function test_catalogue_brand_panel_and_sidebar_subsection_are_visible(): void
    {
        $this->seed();

        $this->get(route('catalog', ['panel' => 'marques']))
            ->assertOk()
            ->assertSee('Marques / éditeurs')
            ->assertSee('Liste des marques');
    }

    public function test_settings_can_persist_tenant_theme(): void
    {
        $this->seed();

        $response = $this->post(route('settings.theme.update'), [
            'primary' => '#0EA5E9',
            'accent' => '#DB2777',
            'success' => '#16A34A',
            'background' => '#F8FAFC',
            'surface_color' => '#FFFFFF',
            'surface_muted' => '#F1F5F9',
            'text' => '#0F172A',
            'muted' => '#64748B',
            'border' => '#E2E8F0',
            'font_scale' => '1',
            'density' => 'compact',
            'radius' => '8',
        ]);

        $response->assertRedirect();

        $theme = Tenant::firstOrFail()->fresh()->settings['theme'];

        $this->assertSame('#0EA5E9', $theme['primary']);
        $this->assertSame('compact', $theme['density']);
        $this->assertSame('8', $theme['radius']);
    }

    public function test_settings_default_preset_uses_refined_palette(): void
    {
        $this->seed();

        $response = $this->post(route('settings.theme.update'), [
            'preset' => 'default',
        ]);

        $response->assertRedirect();

        $theme = Tenant::firstOrFail()->fresh()->settings['theme'];

        $this->assertSame('#2563EB', $theme['primary']);
        $this->assertSame('#0D9488', $theme['accent']);
        $this->assertSame('#F6F8FB', $theme['background']);
        $this->assertSame('#D8E1EE', $theme['border']);
    }

    public function test_legacy_mylibrairie_catalogue_urls_redirect_to_new_catalogue(): void
    {
        $this->seed();

        $this->get('/items/add')->assertRedirect('/catalogue?panel=ajouter');
        $this->get('/services/add')->assertRedirect('/catalogue?panel=ajouter-service');
        $this->get('/items')->assertRedirect('/catalogue?panel=articles');
        $this->get('/category/view')->assertRedirect('/catalogue?panel=categories');
        $this->get('/brands/view')->assertRedirect('/catalogue?panel=marques');
        $this->get('/variants/view')->assertRedirect('/catalogue?panel=variantes');
        $this->get('/items/labels')->assertRedirect('/catalogue/etiquettes');
        $this->get('/import/items')->assertRedirect('/catalogue?panel=import&kind=items');
        $this->get('/import/services')->assertRedirect('/catalogue?panel=import&kind=services');
    }

    private function legacyXlsx(string $title, array $headers, array $rows, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'legacy-xlsx-').'.xlsx';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');

        $sheetRows = [[$title], $headers, ...$rows];
        $xmlRows = '';
        foreach ($sheetRows as $rowIndex => $row) {
            $xmlRows .= '<row r="'.($rowIndex + 1).'">';
            foreach ($row as $columnIndex => $value) {
                $reference = $this->xlsxColumn($columnIndex + 1).($rowIndex + 1);
                $xmlRows .= '<c r="'.$reference.'" t="inlineStr"><is><t>'.htmlspecialchars((string) $value, ENT_XML1).'</t></is></c>';
            }
            $xmlRows .= '</row>';
        }

        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$xmlRows.'</sheetData></worksheet>');
        $zip->close();

        return new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function xlsxColumn(int $number): string
    {
        $column = '';
        while ($number > 0) {
            $number--;
            $column = chr(65 + ($number % 26)).$column;
            $number = intdiv($number, 26);
        }

        return $column;
    }
}
