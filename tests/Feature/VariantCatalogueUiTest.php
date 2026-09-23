<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\ItemVariantValue;
use App\Models\OptionType;
use App\Models\OptionValue;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Creating the axes, and building an article's matrix from them. */
class VariantCatalogueUiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->tenant = Tenant::firstOrFail();
        $this->item = Item::where('tenant_id', $this->tenant->id)
            ->where('type', '!=', 'service')->firstOrFail();
    }

    private function axis(string $name, array $values): OptionType
    {
        $this->post(route('catalog.options.store'), ['name' => $name])->assertRedirect();
        $type = OptionType::where('tenant_id', $this->tenant->id)->where('name', $name)->firstOrFail();

        foreach ($values as $value) {
            $this->post(route('catalog.option-values.store', $type), ['value' => $value])->assertRedirect();
        }

        return $type->fresh('values');
    }

    public function test_an_option_and_its_values_can_be_created(): void
    {
        $size = $this->axis('Taille', ['S', 'M', 'L']);

        $this->assertSame(3, $size->values()->count());
    }

    public function test_values_keep_the_order_they_were_entered(): void
    {
        // S, M, L is the order a shop means and it is not alphabetical, so
        // the list must not be sorted for it.
        $size = $this->axis('Taille', ['S', 'M', 'L']);

        $this->assertSame(['S', 'M', 'L'], $size->values()->pluck('value')->all());
    }

    public function test_an_option_name_cannot_be_used_twice(): void
    {
        $this->axis('Taille', []);

        $this->post(route('catalog.options.store'), ['name' => 'Taille'])
            ->assertSessionHasErrors('name');
    }

    public function test_a_value_cannot_repeat_within_its_option(): void
    {
        $size = $this->axis('Taille', ['S']);

        $this->post(route('catalog.option-values.store', $size), ['value' => 'S'])
            ->assertSessionHasErrors('value');
    }

    public function test_the_same_value_may_exist_under_a_different_option(): void
    {
        // "M" is a size and could equally be a format.
        $this->axis('Taille', ['M']);
        $format = $this->axis('Format', ['M']);

        $this->assertSame(1, $format->values()->count());
    }

    public function test_the_matrix_is_generated_from_the_ticked_values(): void
    {
        $size = $this->axis('Taille', ['S', 'M']);
        $colour = $this->axis('Couleur', ['Rouge', 'Bleu']);

        $this->post(route('catalog.variants.generate', $this->item), [
            'options' => [
                $size->id => $size->values()->pluck('id')->all(),
                $colour->id => $colour->values()->pluck('id')->all(),
            ],
        ])->assertRedirect();

        $this->assertSame(4, $this->item->variants()->count());
        $this->assertSame(4, (int) $this->item->fresh()->variant_count);
    }

    public function test_generating_without_a_selection_is_refused(): void
    {
        $this->post(route('catalog.variants.generate', $this->item), [])
            ->assertSessionHasErrors('options');
    }

    public function test_one_tenant_cannot_build_anothers_article(): void
    {
        $size = $this->axis('Taille', ['S']);
        $other = Tenant::create(['name' => 'Autre', 'slug' => 'autre-'.uniqid()]);
        $this->item->update(['tenant_id' => $other->id]);

        $this->post(route('catalog.variants.generate', $this->item), [
            'options' => [$size->id => $size->values()->pluck('id')->all()],
        ])->assertNotFound();
    }

    public function test_a_value_still_in_use_is_retired_rather_than_deleted(): void
    {
        // Deleting it would take the meaning off every variant built on it and
        // leave the sales report unable to say what was sold.
        $size = $this->axis('Taille', ['S']);
        $value = $size->values()->firstOrFail();
        $this->post(route('catalog.variants.generate', $this->item), [
            'options' => [$size->id => [$value->id]],
        ])->assertRedirect();

        $this->delete(route('catalog.option-values.destroy', $value))->assertRedirect();

        $this->assertDatabaseHas('option_values', ['id' => $value->id, 'is_active' => false]);
        $this->assertSame(1, ItemVariantValue::where('option_value_id', $value->id)->count());
    }

    public function test_an_unused_value_is_deleted_outright(): void
    {
        $size = $this->axis('Taille', ['S']);
        $value = $size->values()->firstOrFail();

        $this->delete(route('catalog.option-values.destroy', $value))->assertRedirect();

        $this->assertDatabaseMissing('option_values', ['id' => $value->id]);
    }

    public function test_an_option_in_use_is_retired_rather_than_deleted(): void
    {
        $size = $this->axis('Taille', ['S']);
        $this->post(route('catalog.variants.generate', $this->item), [
            'options' => [$size->id => $size->values()->pluck('id')->all()],
        ])->assertRedirect();

        $this->delete(route('catalog.options.destroy', $size))->assertRedirect();

        $this->assertDatabaseHas('option_types', ['id' => $size->id, 'is_active' => false]);
    }

    public function test_a_colour_can_carry_a_swatch(): void
    {
        $colour = $this->axis('Couleur', []);

        $this->post(route('catalog.option-values.store', $colour), [
            'value' => 'Rouge', 'swatch' => '#FF0000',
        ])->assertRedirect();

        $this->assertSame('#FF0000', OptionValue::where('value', 'Rouge')->firstOrFail()->swatch);
    }

    public function test_a_swatch_must_look_like_a_colour(): void
    {
        $colour = $this->axis('Couleur', []);

        $this->post(route('catalog.option-values.store', $colour), [
            'value' => 'Rouge', 'swatch' => 'rouge vif',
        ])->assertSessionHasErrors('swatch');
    }

    public function test_the_panel_offers_the_option_builder(): void
    {
        $this->axis('Taille', ['S']);

        $this->get(route('catalog', ['panel' => 'variantes']))
            ->assertOk()
            ->assertSee('Générer les déclinaisons')
            ->assertSee('Options disponibles')
            ->assertSee('Taille');
    }
}
