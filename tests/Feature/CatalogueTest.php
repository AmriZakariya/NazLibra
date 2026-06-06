<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Item;
use App\Models\StockAdjustment;
use App\Models\StockTransfer;
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
        $this->assertTrue($item->is_enabled);
        $this->assertTrue($item->checkout_visible);
        $this->assertDatabaseHas('stock_movements', [
            'item_id' => $item->id,
            'type' => 'opening_stock',
            'quantity_delta' => 12,
            'quantity_after' => 12,
            'reference_type' => Item::class,
            'reference_id' => $item->id,
        ]);

        $this->put(route('catalog.items.update', $item), [
            'type' => 'book',
            'title' => $item->title,
            'author' => $item->author,
            'isbn' => $item->isbn,
            'barcode' => $item->barcode,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'tax_id' => $tax->id,
            'purchase_price' => 50,
            'sale_price' => 85,
            'stock_quantity' => 15,
            'min_stock_threshold' => 3,
            'location' => 'Rayon T-01',
            'status' => 'active',
            'is_enabled' => 1,
            'checkout_visible' => 1,
        ])->assertRedirect();

        $this->assertDatabaseHas('stock_movements', [
            'item_id' => $item->id,
            'type' => 'item_update',
            'quantity_delta' => 3,
            'quantity_after' => 15,
            'reference_type' => Item::class,
            'reference_id' => $item->id,
        ]);

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

    public function test_catalogue_import_examples_are_downloadable_xlsx_files(): void
    {
        $this->seed();

        foreach (['items', 'services', 'categories', 'brands', 'variants'] as $kind) {
            $response = $this->get(route('catalog.import.example', $kind));

            $response->assertOk();
            $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            $zip = new ZipArchive();
            $this->assertTrue($zip->open($response->baseResponse->getFile()->getPathname()));
            $this->assertNotFalse($zip->getFromName('xl/worksheets/sheet1.xml'));
            $zip->close();
        }
    }

    public function test_label_search_matches_split_keywords_and_keeps_selection_active(): void
    {
        $this->seed();

        $tenant = Tenant::firstOrFail();
        $category = Category::firstOrFail();
        $brand = Brand::firstOrFail();
        $unit = Unit::firstOrFail();
        $tax = Tax::firstOrFail();

        $item = Item::create([
            'tenant_id' => $tenant->id,
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'unit_id' => $unit->id,
            'tax_id' => $tax->id,
            'type' => 'supply',
            'title' => 'Cahier pique rouge 192 pages',
            'barcode' => 'LBL-SEARCH-001',
            'purchase_price' => 7,
            'sale_price' => 12,
            'stock_quantity' => 30,
            'min_stock_threshold' => 4,
            'status' => 'active',
        ]);

        $this->get(route('catalog.labels', ['q' => 'cahier 192']))
            ->assertOk()
            ->assertSee('Cahier pique rouge 192 pages')
            ->assertSee('Imprimer des étiquettes')
            ->assertSee('sidebar-child is-active', false);

        $this->get(route('catalog.labels', [
            'q' => 'terme introuvable',
            'selected_items' => [$item->id],
            'quantities' => [$item->id => 2],
        ]))
            ->assertOk()
            ->assertSee('Cahier pique rouge 192 pages')
            ->assertSee('checked', false)
            ->assertSee('2 étiquette');
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

    public function test_catalogue_import_reads_legacy_item_type_from_last_column(): void
    {
        $this->seed();

        $file = UploadedFile::fake()->createWithContent('legacy-types.csv', implode("\n", [
            "Code de barre,Nom de l'article,Catégorie/Type d'élément,Unité,Stock,Quantité d'alerte,Prix de vente,Impôt,Statut,Action,Type d'élément",
            'TYPE-BOOK-001,Livre importé test,ROMANS[ITEM],Pièce,4,1,80,Sans TVA(0.00%),Active,,Livre',
            'TYPE-PRODUCT-001,Produit importé test,FOURNITURE SCOLAIRE[ITEM],Pièce,20,5,6,Sans TVA(0.00%),Active,,Article',
            'TYPE-SERVICE-001,Service importé test,Services[SERVICE],Service,0,0,2,Sans TVA(0.00%),Active,,Service',
        ]));

        $response = $this->post(route('catalog.import'), [
            'kind' => 'items',
            'catalog_file' => $file,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('items', ['barcode' => 'TYPE-BOOK-001', 'type' => 'book', 'stock_quantity' => 4]);
        $this->assertDatabaseHas('items', ['barcode' => 'TYPE-PRODUCT-001', 'type' => 'supply', 'stock_quantity' => 20]);
        $this->assertDatabaseHas('items', ['barcode' => 'TYPE-SERVICE-001', 'type' => 'service', 'stock_quantity' => 9999, 'min_stock_threshold' => 0]);
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
        $hiddenItem = Item::where('type', '!=', 'service')->where('id', '!=', $item->id)->firstOrFail();
        $hiddenItem->update(['checkout_visible' => false]);

        $this->get(route('catalog', ['panel' => 'articles', 'q' => $item->barcode ?? $item->title]))
            ->assertOk()
            ->assertSee('Code de barre')
            ->assertSee("Nom de l'article", false)
            ->assertSee("Catégorie/")
            ->assertSee("Type d'élément", false)
            ->assertSee("Quantité d'alerte", false)
            ->assertSee('Prix de vente')
            ->assertSee('Impôt')
            ->assertSee('Exporter vue')
            ->assertSee('Exporter tout');

        $this->get(route('catalog', ['panel' => 'services']))
            ->assertOk()
            ->assertSee('Liste des services')
            ->assertDontSee("Quantité d'alerte", false);

        $servicesData = $this->getJson(route('catalog.data', [
            'panel' => 'services',
            'draw' => 1,
            'start' => 0,
            'length' => 10,
        ]))->assertOk()
            ->assertJsonStructure(['draw', 'recordsTotal', 'recordsFiltered', 'data']);
        $this->assertStringContainsString('#edit-item', data_get($servicesData->json(), 'data.0.row_url', ''));
        $this->assertStringContainsString('&edit=', data_get($servicesData->json(), 'data.0.row_url', ''));
        $this->assertStringNotContainsString('&amp;edit=', data_get($servicesData->json(), 'data.0.row_url', ''));
        $this->assertStringContainsString('Historique', data_get($servicesData->json(), 'data.0.action', ''));
        $this->assertStringContainsString('inventory_item=', data_get($servicesData->json(), 'data.0.action', ''));

        $hiddenStatusResponse = $this->getJson(route('catalog.data', [
            'panel' => 'articles',
            'draw' => 1,
            'start' => 0,
            'length' => 25,
            'search' => ['value' => $hiddenItem->title],
        ]))->assertOk();

        $hiddenStatusHtml = data_get($hiddenStatusResponse->json(), 'data.0.status');
        $this->assertStringContainsString('Activé', $hiddenStatusHtml);
        $this->assertStringContainsString('Caché caisse', $hiddenStatusHtml);

        $this->get(route('pos', ['q' => $hiddenItem->title]))
            ->assertOk()
            ->assertDontSee('data-id="'.$hiddenItem->id.'"', false);

        $response = $this->get(route('catalog.export', ['panel' => 'articles', 'q' => $item->barcode ?? $item->title]));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $csv = $response->streamedContent();
        $this->assertStringContainsString('Code de barre', $csv);
        $this->assertStringContainsString($item->title, $csv);

        $allResponse = $this->get(route('catalog.export', ['all' => 1]));

        $allResponse->assertOk();
        $allResponse->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('catalogue-complet-', $allResponse->headers->get('content-disposition'));
    }

    public function test_catalogue_and_pos_search_find_services_from_article_search(): void
    {
        $this->seed();

        $tenant = Tenant::firstOrFail();
        $category = Category::where('name', 'Services')->firstOrFail();
        $unit = Unit::where('name', 'Service')->firstOrFail();
        $tax = Tax::firstOrFail();

        $service = Item::create([
            'tenant_id' => $tenant->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'tax_id' => $tax->id,
            'type' => 'service',
            'status' => 'active',
            'is_enabled' => true,
            'checkout_visible' => true,
            'item_code' => 'SRV-PASSEPORT-TEST',
            'title' => 'Photo passeport express',
            'barcode' => 'SRV-PASSEPORT-BAR',
            'purchase_price' => 0,
            'sale_price' => 30,
            'stock_quantity' => 9999,
            'min_stock_threshold' => 0,
        ]);

        $catalogResponse = $this->getJson(route('catalog.data', [
            'panel' => 'articles',
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => 'passeport'],
        ]));

        $catalogResponse->assertOk();
        $this->assertStringContainsString('Photo passeport express', data_get($catalogResponse->json(), 'data.0.title'));

        $this->get(route('pos', ['q' => 'passeport']))
            ->assertOk()
            ->assertSee('data-id="'.$service->id.'"', false)
            ->assertSee('Photo passeport express');
    }

    public function test_navbar_product_search_opens_articles_and_services_from_catalogue(): void
    {
        $this->seed();

        $tenant = Tenant::firstOrFail();
        $category = Category::where('name', 'Services')->firstOrFail();
        $unit = Unit::where('name', 'Service')->firstOrFail();
        $tax = Tax::firstOrFail();
        $article = Item::where('type', '!=', 'service')->firstOrFail();

        $service = Item::create([
            'tenant_id' => $tenant->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'tax_id' => $tax->id,
            'type' => 'service',
            'status' => 'active',
            'is_enabled' => true,
            'checkout_visible' => true,
            'item_code' => 'SRV-NAVBAR-001',
            'title' => 'Service recherche navbar',
            'barcode' => 'SRV-NAVBAR-BAR',
            'purchase_price' => 0,
            'sale_price' => 25,
            'stock_quantity' => 0,
            'min_stock_threshold' => 0,
        ]);

        $articleResponse = $this->getJson(route('catalog.quick-search', ['q' => $article->title]))->assertOk();
        $this->assertEquals(
            route('catalog', ['panel' => 'articles', 'edit' => $article->id]).'#edit-item',
            collect($articleResponse->json('items'))->firstWhere('id', $article->id)['url'] ?? null
        );

        $serviceResponse = $this->getJson(route('catalog.quick-search', ['q' => 'navbar']))->assertOk();
        $this->assertEquals(
            route('catalog', ['panel' => 'services', 'edit' => $service->id]).'#edit-item',
            collect($serviceResponse->json('items'))->firstWhere('id', $service->id)['url'] ?? null
        );
        $this->assertNull(collect($serviceResponse->json('items'))->firstWhere('id', $service->id)['stock'] ?? null);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-product-search', false)
            ->assertSee(route('catalog.quick-search'), false);
    }

    public function test_item_detail_exposes_quick_actions_with_prefilled_flows(): void
    {
        $this->seed();

        $item = Item::where('type', '!=', 'service')->where('status', 'active')->firstOrFail();
        $searchCode = $item->barcode ?: ($item->isbn ?: ($item->sku ?: $item->item_code));

        $this->get(route('catalog', ['panel' => 'articles', 'edit' => $item->id]))
            ->assertOk()
            ->assertSee('Vendre en caisse')
            ->assertSee('Créer un achat')
            ->assertSee('Ajuster le stock')
            ->assertSee('Historique stock')
            ->assertSee(route('pos', ['q' => $searchCode ?: $item->title, 'stock' => 'all']))
            ->assertSee(route('module', ['module' => 'purchases', 'section' => 'add', 'item' => $item->id]))
            ->assertSee(route('stock', ['panel' => 'stock-adjustment-add', 'item' => $item->id, 'stock_q' => $searchCode ?: $item->title]));

        $this->get(route('module', ['module' => 'purchases', 'section' => 'add', 'item' => $item->id]))
            ->assertOk()
            ->assertSee('value="'.$item->id.'"', false)
            ->assertSee($item->title);

        $this->get(route('stock', ['panel' => 'stock-adjustment-add', 'item' => $item->id, 'stock_q' => $searchCode ?: $item->title]))
            ->assertOk()
            ->assertSee('value="'.$item->id.'"', false)
            ->assertSee($item->title);
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
        $service = Item::where('item_code', 'SRV-LEGACY-001')->firstOrFail();
        $this->assertTrue($service->is_enabled);
        $this->assertTrue($service->checkout_visible);
        $this->assertDatabaseHas('items', [
            'item_code' => 'SRV-LEGACY-001',
            'type' => 'service',
            'status' => 'active',
            'stock_quantity' => 9999,
            'min_stock_threshold' => 0,
        ]);
    }

    public function test_catalogue_requires_sale_price_but_allows_explicit_zero(): void
    {
        $this->seed();

        $category = Category::where('name', 'Services')->firstOrFail();
        $unit = Unit::where('name', 'Service')->firstOrFail();
        $tax = Tax::where('name', 'Sans TVA')->firstOrFail();

        $basePayload = [
            'type' => 'service',
            'title' => 'Service prix obligatoire',
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'tax_id' => $tax->id,
            'price' => 0,
            'purchase_price' => 0,
            'stock_quantity' => 9999,
            'min_stock_threshold' => 0,
        ];

        $this->from(route('catalog', ['panel' => 'ajouter-service']))
            ->post(route('catalog.items.store'), $basePayload)
            ->assertRedirect(route('catalog', ['panel' => 'ajouter-service']))
            ->assertSessionHasErrors('sale_price');

        $this->post(route('catalog.items.store'), $basePayload + ['sale_price' => 0])
            ->assertRedirect();

        $service = Item::where('title', 'Service prix obligatoire')->firstOrFail();
        $this->assertSame('0.00', $service->sale_price);
    }

    public function test_catalogue_normalizes_money_fields_without_precision_drift(): void
    {
        $this->seed();

        $category = Category::where('name', 'Papeterie')->firstOrFail();
        $unit = Unit::where('name', 'Pièce')->firstOrFail();
        $tax = Tax::where('name', 'Sans TVA')->firstOrFail();

        $this->post(route('catalog.items.store'), [
            'type' => 'supply',
            'title' => 'Stylo prix exact',
            'barcode' => 'PRICE-EXACT-001',
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'tax_id' => $tax->id,
            'price' => '20',
            'purchase_price' => '12,50',
            'sale_price' => '20',
            'reseller_sale_price' => '18,25',
            'stock_quantity' => 5,
            'min_stock_threshold' => 1,
        ])->assertRedirect();

        $item = Item::where('barcode', 'PRICE-EXACT-001')->firstOrFail();

        $this->assertSame('20.00', $item->price);
        $this->assertSame('12.50', $item->purchase_price);
        $this->assertSame('20.00', $item->sale_price);
        $this->assertSame('18.25', $item->reseller_sale_price);

        $this->get(route('catalog', ['panel' => 'articles', 'edit' => $item->id]))
            ->assertOk()
            ->assertSee('name="sale_price" required type="number" step="0.01" min="0" inputmode="decimal" value="20.00"', false)
            ->assertSee('name="purchase_price" required type="number" step="0.01" min="0" inputmode="decimal" value="12.50"', false);
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
            ->assertSee('marques / éditeurs', false)
            ->assertSee('Liste des marques');

        $this->get(route('catalog', ['panel' => 'unites']))
            ->assertOk()
            ->assertSee('Liste des unités');

        $this->get(route('catalog', ['panel' => 'impots']))
            ->assertOk()
            ->assertSee('Liste des impôts');
    }

    public function test_catalogue_reference_lists_are_searchable_and_editable(): void
    {
        $this->seed();

        $category = Category::create([
            'tenant_id' => Tenant::firstOrFail()->id,
            'name' => 'Recherche Catégorie Test',
            'slug' => 'recherche-categorie-test',
            'icon' => 'book',
            'color' => '#2563EB',
            'loan_duration_days' => 14,
            'daily_fine_amount' => 2,
        ]);

        $brand = Brand::create([
            'tenant_id' => Tenant::firstOrFail()->id,
            'name' => 'Recherche Éditeur Test',
            'type' => 'publisher',
        ]);

        $unit = Unit::create([
            'tenant_id' => Tenant::firstOrFail()->id,
            'name' => 'Palette Test',
        ]);

        $tax = Tax::create([
            'tenant_id' => Tenant::firstOrFail()->id,
            'name' => 'TVA Recherche',
            'rate' => 13,
        ]);

        $this->get(route('catalog', ['panel' => 'categories', 'reference_q' => 'Recherche Catégorie']))
            ->assertOk()
            ->assertSee('Recherche Catégorie Test')
            ->assertSee('Modifier')
            ->assertSee('Supprimer');

        $this->put(route('catalog.categories.update', $category), [
            'name' => 'Catégorie Modifiée Test',
            'parent_id' => null,
            'icon' => 'book-open',
            'color' => '#0D9488',
            'description' => 'Mise à jour',
            'loan_duration_days' => 14,
            'daily_fine_amount' => 2,
        ])->assertRedirect();
        $this->assertDatabaseHas('categories', ['id' => $category->id, 'name' => 'Catégorie Modifiée Test']);

        $this->get(route('catalog', ['panel' => 'marques', 'reference_q' => 'Éditeur Test']))
            ->assertOk()
            ->assertSee('Recherche Éditeur Test');
        $this->put(route('catalog.brands.update', $brand), [
            'name' => 'Éditeur Modifié Test',
            'type' => 'publisher',
        ])->assertRedirect();
        $this->assertDatabaseHas('brands', ['id' => $brand->id, 'name' => 'Éditeur Modifié Test']);

        $this->get(route('catalog', ['panel' => 'unites', 'reference_q' => 'Palette']))
            ->assertOk()
            ->assertSee('Palette Test');
        $this->put(route('catalog.units.update', $unit), [
            'name' => 'Carton Test',
            'description' => 'Unité modifiée',
        ])->assertRedirect();
        $this->assertDatabaseHas('units', ['id' => $unit->id, 'name' => 'Carton Test']);

        $this->get(route('catalog', ['panel' => 'impots', 'reference_q' => 'TVA Recherche']))
            ->assertOk()
            ->assertSee('TVA Recherche');
        $this->put(route('catalog.taxes.update', $tax), [
            'name' => 'TVA Modifiée',
            'rate' => 14,
            'description' => 'Impôt modifié',
        ])->assertRedirect();
        $this->assertDatabaseHas('taxes', ['id' => $tax->id, 'name' => 'TVA Modifiée', 'rate' => 14]);

        $this->delete(route('catalog.categories.destroy', $category->fresh()))->assertRedirect();
        $this->delete(route('catalog.brands.destroy', $brand->fresh()))->assertRedirect();
        $this->delete(route('catalog.units.destroy', $unit->fresh()))->assertRedirect();
        $this->delete(route('catalog.taxes.destroy', $tax->fresh()))->assertRedirect();

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
        $this->assertDatabaseMissing('brands', ['id' => $brand->id]);
        $this->assertDatabaseMissing('units', ['id' => $unit->id]);
        $this->assertDatabaseMissing('taxes', ['id' => $tax->id]);
    }

    public function test_stock_route_opens_stock_workspace(): void
    {
        $this->seed();

        $this->get(route('stock'))
            ->assertOk()
            ->assertSee('Stock opérationnel')
            ->assertSee('Liste d&#039;ajustement', false)
            ->assertSee('Sections stock')
            ->assertSee('data-nav-key="stock"', false)
            ->assertSee('data-command-title="stock"', false)
            ->assertDontSee('href="http://localhost/catalogue?panel=ajouter" class="sidebar-child is-active"', false);

        $this->get(route('stock', ['panel' => 'stock-transfer-add']))
            ->assertOk()
            ->assertSee('Transfert de stock')
            ->assertSee('Liste de transfert');
    }

    public function test_stock_adjustment_changes_stock_and_is_listed(): void
    {
        $this->seed();

        $item = Item::where('type', '!=', 'service')->where('stock_quantity', '>', 5)->firstOrFail();
        $initialStock = (int) $item->stock_quantity;

        $this->get(route('stock', ['panel' => 'stock-adjustment-add']))
            ->assertOk()
            ->assertSee('Ajustement des stocks')
            ->assertSee("Liste d&#039;ajustement", false);

        $response = $this->post(route('catalog.stock-adjustments.store'), [
            'adjusted_at' => now()->format('Y-m-d H:i:s'),
            'warehouse' => 'Dépôt test',
            'reason' => 'Inventaire test',
            'items' => [
                ['item_id' => $item->id, 'direction' => 'remove', 'quantity' => 2, 'note' => 'Casse test'],
            ],
        ]);

        $adjustment = StockAdjustment::firstOrFail();
        $response->assertRedirect(route('stock', ['panel' => 'stock-adjustments']));
        $this->assertStringStartsWith('AJS', $adjustment->number);
        $this->assertSame($initialStock - 2, (int) $item->fresh()->stock_quantity);

        $this->assertDatabaseHas('stock_movements', [
            'item_id' => $item->id,
            'reference_id' => $adjustment->id,
            'quantity_delta' => -2,
            'quantity_after' => $initialStock - 2,
        ]);

        $this->get(route('stock', ['panel' => 'stock-adjustments', 'q' => 'Inventaire test']))
            ->assertOk()
            ->assertSee($adjustment->number)
            ->assertSee('Inventaire test')
            ->assertSee('Stock par article')
            ->assertSee('Historique inventaire par article')
            ->assertSee('Valeur achat')
            ->assertSee('Valeur vente')
            ->assertSee('inventory_item='.$item->id, false)
            ->assertSee('-2');

        $this->get(route('stock', ['panel' => 'stock-adjustments', 'inventory_item' => $item->id]))
            ->assertOk()
            ->assertSee($item->title)
            ->assertSee('1 mouvement(s) enregistré')
            ->assertSee('data-inventory-item-picker', false)
            ->assertSee(route('catalog.stock-items.search'), false)
            ->assertSee('Voir ajustement')
            ->assertSee('detail_adjustment='.$adjustment->id, false)
            ->assertSee('Ajuster maintenant')
            ->assertSee('Tous les articles');
    }

    public function test_stock_item_search_returns_combobox_options(): void
    {
        $this->seed();

        $tenant = Tenant::firstOrFail();
        $item = Item::create([
            'tenant_id' => $tenant->id,
            'type' => 'book',
            'status' => 'active',
            'is_enabled' => true,
            'checkout_visible' => true,
            'item_code' => 'STOCK-SEARCH-001',
            'title' => 'Manuel stock recherche avancée',
            'barcode' => 'STOCK-BAR-001',
            'purchase_price' => 10,
            'sale_price' => 20,
            'stock_quantity' => 6,
            'min_stock_threshold' => 2,
        ]);

        $this->getJson(route('catalog.stock-items.search', ['q' => 'stock recherche']))
            ->assertOk()
            ->assertJsonFragment([
                'value' => (string) $item->id,
                'title' => 'Manuel stock recherche avancée',
                'stock' => 6,
                'code' => 'STOCK-BAR-001',
            ]);
    }

    public function test_stock_transfer_records_transfer_without_changing_global_stock(): void
    {
        $this->seed();

        $item = Item::where('type', '!=', 'service')->where('stock_quantity', '>', 5)->firstOrFail();
        $initialStock = (int) $item->stock_quantity;

        $this->get(route('stock', ['panel' => 'stock-transfer-add']))
            ->assertOk()
            ->assertSee('Transfert de stock')
            ->assertSee('Liste de transfert');

        $response = $this->post(route('catalog.stock-transfers.store'), [
            'transferred_at' => now()->format('Y-m-d H:i:s'),
            'store_from' => 'Magasin A',
            'warehouse_from' => 'Dépôt',
            'store_to' => 'Magasin B',
            'warehouse_to' => 'Rayon scolaire',
            'items' => [
                ['item_id' => $item->id, 'quantity' => 3, 'note' => 'Carton test'],
            ],
        ]);

        $transfer = StockTransfer::firstOrFail();
        $response->assertRedirect(route('stock', ['panel' => 'stock-transfers']));
        $this->assertStringStartsWith('TRS', $transfer->number);
        $this->assertSame($initialStock, (int) $item->fresh()->stock_quantity);
        $this->assertSame(3, (int) $transfer->total_quantity);

        $this->get(route('stock', ['panel' => 'stock-transfers', 'q' => 'Magasin B']))
            ->assertOk()
            ->assertSee($transfer->number)
            ->assertSee('Magasin B');
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

        $this->assertSame('#3157D5', $theme['primary']);
        $this->assertSame('#0F9F8A', $theme['accent']);
        $this->assertSame('#F4F7FB', $theme['background']);
        $this->assertSame('#D7DEE9', $theme['border']);
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
        $this->get('/units')->assertRedirect('/catalogue?panel=unites');
        $this->get('/tax')->assertRedirect('/catalogue?panel=impots');
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
