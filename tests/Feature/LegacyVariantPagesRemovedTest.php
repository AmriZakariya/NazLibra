<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The old /variantes pages answered every request with a 500 (their
 * controller called a tenant() helper it never had) and nothing linked to
 * them. Variants are edited from the catalogue, through VariantService; the
 * pages that bypassed it are gone rather than repaired.
 */
class LegacyVariantPagesRemovedTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_legacy_variant_routes_no_longer_exist(): void
    {
        foreach (['index', 'data', 'create', 'store', 'show', 'edit', 'update', 'duplicate', 'toggle', 'destroy'] as $name) {
            $this->assertFalse(Route::has('variants.'.$name), "variants.{$name} should be gone");
        }

        $this->seed();
        $this->actingAs(User::first())->get('/variantes')->assertNotFound();
    }

    public function test_the_catalogue_still_edits_variants(): void
    {
        $this->assertTrue(Route::has('catalog.variants.store'));
        $this->assertTrue(Route::has('catalog.variants.update'));
        $this->assertTrue(Route::has('legacy.variants.view'), 'the old bookmark still lands in the catalogue');
    }
}
