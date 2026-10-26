<?php

namespace Tests\Feature;

use App\Support\BusinessMode;
use App\Support\ItemTypes;
use Tests\TestCase;

/**
 * The activities a shop can choose at signup, and what each one switches on.
 *
 * An activity is more than a label: it decides which kinds of article the
 * shop can create (ItemTypes), and the categories and units it starts with.
 * Two lists have to agree, and the way they disagree is silent — a mode with
 * no mapping falls back to the bookshop, so a spa would be offered "Livre".
 */
class BusinessModeTest extends TestCase
{
    public function test_the_activities_shops_asked_for_are_offered(): void
    {
        foreach (['beauty', 'clothing', 'grocery', 'bakery', 'cosmetics', 'electronics'] as $key) {
            $this->assertArrayHasKey($key, BusinessMode::all(), $key);
        }
        $this->assertSame('Autre commerce', BusinessMode::all()['retail']['label']);
    }

    public function test_no_activity_falls_back_to_the_bookshop_by_accident(): void
    {
        foreach (array_keys(BusinessMode::all()) as $key) {
            if ($key === 'library') {
                continue;
            }

            $this->assertNotSame(
                'bookstore',
                ItemTypes::activityFromBusinessMode($key),
                "« {$key} » has no article types of its own and would be offered « Livre »",
            );
        }
    }

    public function test_every_activity_maps_to_article_types_that_exist(): void
    {
        foreach (array_keys(BusinessMode::all()) as $key) {
            $activity = ItemTypes::activityFromBusinessMode($key);

            $this->assertContains($activity, ItemTypes::activityKeys(), $key);
            $this->assertNotEmpty(ItemTypes::physicalTypes($activity), $key);
            // A service is always possible — a haircut is one.
            $this->assertContains('service', ItemTypes::validTypes($activity), $key);
        }
    }

    public function test_every_activity_is_fully_described(): void
    {
        $required = ['label', 'short_label', 'subtitle', 'hero_title', 'hero_text',
            'catalog_label', 'primary_item', 'type_labels', 'search_placeholder', 'keywords'];

        foreach (BusinessMode::all() as $key => $mode) {
            foreach ($required as $field) {
                $this->assertNotEmpty($mode[$field] ?? null, "{$key}.{$field}");
            }
            foreach (['book', 'supply', 'service'] as $type) {
                $this->assertArrayHasKey($type, $mode['type_labels'], "{$key}.type_labels.{$type}");
            }
        }
    }

    public function test_a_spa_gets_care_products_and_services_not_books(): void
    {
        $activity = ItemTypes::activityFromBusinessMode('beauty');

        $this->assertSame(['supply', 'service'], ItemTypes::validTypes($activity));
        $this->assertSame('Produit de soin', ItemTypes::physicalTypes($activity)['supply']['label']);
    }

    public function test_a_clothing_shop_gets_the_clothing_type_it_already_had(): void
    {
        // ItemTypes knew about clothing all along; the signup did not offer it.
        $this->assertSame('clothing', ItemTypes::activityFromBusinessMode('clothing'));
        $this->assertArrayHasKey('clothing', ItemTypes::physicalTypes('clothing'));
    }

    public function test_the_words_people_type_reach_the_right_activity(): void
    {
        foreach ([
            'spa' => 'beauty', 'coiffure' => 'beauty', 'Beauté' => 'beauty',
            'épicerie' => 'grocery', 'superette' => 'grocery',
            'boulangerie' => 'bakery', 'Pâtisserie' => 'bakery',
            'parfumerie' => 'cosmetics', 'téléphonie' => 'electronics',
            'vêtements' => 'clothing', 'mode' => 'clothing',
        ] as $typed => $expected) {
            $this->assertSame($expected, BusinessMode::normalize($typed), $typed);
        }
    }

}
