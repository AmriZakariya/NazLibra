<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\User;
use App\Support\BusinessMode;
use App\Support\ImportExamples;
use App\Support\ItemTypes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * The activities a shop can pick, and the example sheet each one downloads
 * before importing its catalogue: its own goods, units, item types and taxes.
 */
class ShopActivitiesTest extends TestCase
{
    use RefreshDatabase;

    private const NEW = ['chocolate', 'wholesale', 'butcher', 'greengrocer', 'jewelry', 'optician', 'florist'];

    private function shopAs(string $mode): Tenant
    {
        $tenant = Tenant::firstOrFail();
        $settings = $tenant->settings;
        $settings['company_profile']['business_mode'] = $mode;
        $tenant->forceFill(['settings' => $settings])->save();

        return $tenant->fresh();
    }

    public function test_the_new_activities_are_offered_with_their_own_words(): void
    {
        foreach (self::NEW as $key) {
            $this->assertArrayHasKey($key, BusinessMode::all());
            $this->assertSame($key, BusinessMode::normalize($key), "$key does not fall back to the bookshop");
            $this->assertSame('general', ItemTypes::activityFromBusinessMode($key), "$key: Produit + Service");
        }
        $this->assertSame('chocolate', BusinessMode::normalize('Chocolaterie'));
        $this->assertSame('wholesale', BusinessMode::normalize('grossiste'));
        $this->assertSame('greengrocer', BusinessMode::normalize('primeur'));
    }

    public function test_every_activity_has_its_own_example_sheet(): void
    {
        $this->seed();
        $bookshopRows = ImportExamples::sheet($this->shopAs('library'), 'items')['rows'];

        foreach (array_keys(BusinessMode::all()) as $mode) {
            $sheet = ImportExamples::sheet($this->shopAs($mode), 'items');
            if ($mode !== 'library') {
                $this->assertNotContains('ISBN', $sheet['headers'], "$mode: no ISBN column");
                $this->assertNotEquals($bookshopRows, $sheet['rows'], "$mode: not the bookshop's rows");
            }
            foreach (['categories', 'brands', 'variants', 'services'] as $kind) {
                // The salon trades show more rows: one per way of pricing (length, duration, options, « à partir de »).
                $this->assertGreaterThanOrEqual(2, count(ImportExamples::sheet($this->shopAs($mode), $kind)['rows']), "$mode $kind");
            }
        }

        $butcher = ImportExamples::sheet($this->shopAs('butcher'), 'items');
        $this->assertSame('Kg', $butcher['rows'][0][array_search('Unité', $butcher['headers'], true)]);
    }

    public function test_the_example_uses_the_shops_type_names_and_imports_back_as_them(): void
    {
        $this->seed();
        $tenant = $this->shopAs('bakery');
        $owner = User::where('email', 'amina@librairie-atlas.ma')->firstOrFail();

        $sheet = ImportExamples::sheet($tenant, 'items');
        $typeColumn = array_search("Type d'élément", $sheet['headers'], true);
        $this->assertSame(ItemTypes::label($tenant, 'book'), $sheet['rows'][0][$typeColumn], 'the bakery’s « Pain / pâtisserie »');

        // The downloaded sheet goes straight back in: the main type survives.
        $response = $this->actingAs($owner)->get(route('catalog.import.example', 'items'))->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'ex').'.xlsx';
        file_put_contents($path, file_get_contents($response->baseResponse->getFile()->getPathname()));

        $this->actingAs($owner)->post(route('catalog.import'), [
            'kind' => 'items',
            'catalog_file' => new UploadedFile($path, 'exemple.xlsx', null, null, true),
        ])->assertRedirect();

        $this->assertSame('book', Item::where('tenant_id', $tenant->id)->where('title', $sheet['rows'][0][1])->value('type'));
    }

    public function test_the_example_uses_the_shops_own_taxes(): void
    {
        $this->seed();
        $tenant = $this->shopAs('grocery');
        Tax::where('tenant_id', $tenant->id)->update(['is_active' => false]);
        Tax::create(['tenant_id' => $tenant->id, 'name' => 'TVA 14%', 'rate' => 14, 'is_active' => true]);

        $sheet = ImportExamples::sheet($tenant, 'items');
        $this->assertContains('TVA 14%(14.00%)', array_merge(...$sheet['rows']));
    }

    public function test_a_cleared_catalogue_imports_again_without_a_code_clash(): void
    {
        // Deleted items keep their item_code, and the unique (tenant,
        // item_code) key counts them: the demo shop, cleared then
        // re-imported, got a 500 on the first new row.
        $this->seed();
        $tenant = $this->shopAs('chocolate');
        $owner = User::where('email', 'amina@librairie-atlas.ma')->firstOrFail();
        Item::where('tenant_id', $tenant->id)->get()->each->delete();
        $this->assertSame(0, Item::where('tenant_id', $tenant->id)->count());

        $sheet = ImportExamples::sheet($tenant, 'items');
        $path = \App\Support\SimpleXlsx::write($sheet['title'], $sheet['headers'], $sheet['rows']);

        $this->actingAs($owner)->post(route('catalog.import'), [
            'kind' => 'items',
            'catalog_file' => new UploadedFile($path, 'articles.xlsx', null, null, true),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2, Item::where('tenant_id', $tenant->id)->count());
        $codes = Item::withTrashed()->where('tenant_id', $tenant->id)->pluck('item_code');
        $this->assertSame($codes->count(), $codes->unique()->count());
    }
}
