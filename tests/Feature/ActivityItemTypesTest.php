<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Support\ItemTypes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityItemTypesTest extends TestCase
{
    use RefreshDatabase;

    public function test_restaurant_business_mode_relabels_catalog_item_types_without_book_fields(): void
    {
        $this->seed();

        $tenant = Tenant::firstOrFail();
        $settings = $tenant->settings ?? [];
        data_set($settings, 'company_profile.business_mode', 'restaurant');
        data_set($settings, 'store.business_activity', 'bookstore');

        $tenant->update([
            'business_mode' => 'restaurant',
            'mode' => 'restaurant',
            'settings' => $settings,
        ]);

        $this->assertSame('restaurant', ItemTypes::activityForTenant($tenant->fresh()));

        $this->get(route('catalog', ['panel' => 'ajouter']))
            ->assertOk()
            ->assertSee('Plat / menu')
            ->assertSee('Produit cuisine')
            ->assertDontSee('ISBN, auteur, édition')
            ->assertDontSee('Fiche livre');
    }
}
