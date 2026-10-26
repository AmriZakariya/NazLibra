<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ItemTypes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * The catalogue importer has to be able to produce every item type the item
 * FORM offers, or a shop can create a type by hand that it can never import.
 * Pharmacy and clothing were exactly that: their rows all landed as `supply`.
 */
class ImportItemTypeTest extends TestCase
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

    private function importRow(string $title, string $typeLabel, string $category = 'DIVERS[ITEM]'): Item
    {
        $csv = "Code de barre,Nom de l'article,Catégorie/Type d'élément,Unité,Stock,Prix de vente,Statut,Type d'élément\n"
            .',"'.$title.'","'.$category.'",Pièce,5,10.00,Active,"'.$typeLabel."\"\n";

        $path = tempnam(sys_get_temp_dir(), 'import-type-').'.csv';
        file_put_contents($path, $csv);

        $this->actingAs($this->owner)
            ->post(route('catalog.import'), [
                'kind' => 'items',
                'catalog_file' => new UploadedFile($path, 'catalogue.csv', null, null, true),
            ])
            ->assertRedirect();

        return Item::where('tenant_id', $this->tenant->id)->where('title', $title)->sole();
    }

    public function test_a_medication_imports_as_a_medication(): void
    {
        $this->assertSame('medication', $this->importRow('Paracétamol 500 mg', 'Médicament')->type);
    }

    public function test_a_garment_imports_as_clothing(): void
    {
        $this->assertSame('clothing', $this->importRow('Chemise coton homme', 'Vêtement')->type);
    }

    public function test_each_activity_can_re_import_its_own_export(): void
    {
        // `book` is the primary catalogue type and every activity labels it
        // differently. The catalogue EXPORT writes that label, so a
        // restaurant re-importing its own file turned every dish into
        // `supply` — a silent demotion nobody would spot until the menu
        // stopped behaving like a menu.
        foreach ([
            'Livre',            // librairie
            'Plat / menu',      // restaurant
            'Boisson / snack',  // café
        ] as $label) {
            $this->assertSame(
                'book',
                $this->importRow("Article $label", $label)->type,
                "the export label '$label' must come back as the primary type",
            );
        }
    }

    public function test_the_types_it_already_handled_are_unchanged(): void
    {
        $this->assertSame('book', $this->importRow('Roman quelconque', 'Livre')->type);
        $this->assertSame('supply', $this->importRow('Stylo quelconque', 'Article')->type);
    }

    public function test_a_service_still_wins_over_everything(): void
    {
        // "Service de livraison de médicaments" mentions a medication; the
        // row is still a service.
        $this->assertSame('service', $this->importRow('Livraison', 'Service médicament')->type);
    }

    public function test_a_pharmacy_label_is_a_known_divergence(): void
    {
        // BusinessMode labels a pharmacy's PRIMARY type "Produit santé" and
        // maps it to `book`; ItemTypes offers that shop `medication` and
        // never `book`. The two vocabularies disagree, so this label is not
        // reachable from the item form and importing it lands on `supply`.
        // Pinned as the current behaviour rather than asserted as correct —
        // reconciling the two is a product decision, not an import fix.
        $this->assertSame('supply', $this->importRow('Produit santé test', 'Produit santé')->type);
        $this->assertSame('medication', $this->importRow('Médicament test', 'Médicament')->type);
    }

    public function test_an_unknown_label_still_falls_back_rather_than_failing(): void
    {
        $this->assertSame('supply', $this->importRow('Objet non classé', 'Bidule')->type);
    }

    public function test_every_type_the_item_form_offers_can_be_imported(): void
    {
        // The contract this is really about: anything ItemTypes lists for an
        // activity must be reachable through the importer.
        $labels = [
            'medication' => 'Médicament',
            'clothing' => 'Vêtement',
            'book' => 'Livre',
            'supply' => 'Article',
        ];

        foreach (ItemTypes::activityKeys() as $activity) {
            foreach (array_keys(ItemTypes::physicalTypes($activity)) as $type) {
                $this->assertArrayHasKey($type, $labels,
                    "activity '$activity' offers '$type' and no import label covers it");

                $this->assertSame(
                    $type,
                    $this->importRow("Test $activity $type", $labels[$type])->type,
                );
            }
        }
    }

    /**
     * A spa imported 13 services and 12 products, saw 12 rows in Articles and
     * thought the rest were lost: services live on their own tab.
     */
    public function test_a_mixed_import_says_where_the_services_went(): void
    {
        $csv = "Code de barre,Nom de l'article,Catégorie/Type d'élément,Unité,Stock,Prix de vente,Statut,Type d'élément\n"
            .'9900000000001,"Massage test 60 min","SOINS[ITEM]",Service,0,350,Active,Service'."\n"
            .'9900000000002,"Soin visage test","SOINS[ITEM]",Service,0,300,Active,Service'."\n"
            .'9900000000003,"Shampooing test 250ml","PRODUITS[ITEM]",Pièce,24,280,Active,Article'."\n";
        $path = tempnam(sys_get_temp_dir(), 'import-mixed-').'.csv';
        file_put_contents($path, $csv);

        $this->actingAs($this->owner)
            ->post(route('catalog.import'), [
                'kind' => 'items',
                'catalog_file' => new UploadedFile($path, 'catalogue.csv', null, null, true),
            ])
            ->assertRedirect()
            ->assertSessionHas('status', fn (string $message): bool => str_contains($message, '3 créée(s)')
                && str_contains($message, "1 article(s) dans l'onglet Articles")
                && str_contains($message, "2 prestation(s) dans l'onglet Services"));

        $services = Item::where('tenant_id', $this->tenant->id)->where('type', 'service')->count();
        $this->assertGreaterThanOrEqual(2, $services);

        $this->get(route('catalog', ['panel' => 'articles']))
            ->assertOk()
            ->assertSee('data-services-pointer', false)
            ->assertSee("dans l'onglet Services", false);
    }
}
