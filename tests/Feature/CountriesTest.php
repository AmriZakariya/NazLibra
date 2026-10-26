<?php

namespace Tests\Feature;

use App\Console\Commands\GenerateCountriesCommand;
use App\Support\Countries;
use Tests\TestCase;

/**
 * Every country a shop can sign up from.
 *
 * It was 26 hand-picked markets; a shop anywhere else could not sign up at
 * all. Now it is the ISO 3166-1 list, generated, minus Countries::EXCLUDED.
 */
class CountriesTest extends TestCase
{
    public function test_every_officially_assigned_country_is_there(): void
    {
        $this->assertCount(249, GenerateCountriesCommand::ISO_3166_1);
        $this->assertSame(
            array_values(array_diff(GenerateCountriesCommand::ISO_3166_1, Countries::EXCLUDED)),
            Countries::codes(),
        );
        $this->assertCount(248, Countries::codes());
    }

    public function test_western_sahara_is_left_out_on_purpose(): void
    {
        $this->assertSame(['EH'], Countries::EXCLUDED);
        $this->assertNotContains('EH', Countries::codes());
    }

    public function test_no_code_that_is_not_a_country_slips_in(): void
    {
        foreach (['EU', 'UN', 'ZZ', 'QO', 'XK', 'CQ', 'AC', 'EZ'] as $code) {
            $this->assertNotContains($code, Countries::codes(), $code);
        }
    }

    public function test_every_country_is_named_in_every_site_language(): void
    {
        foreach (['fr', 'en', 'ar'] as $locale) {
            $names = Countries::localized($locale);
            $this->assertCount(248, $names, $locale);
            foreach ($names as $code => $name) {
                $this->assertNotSame($code, $name, "{$code} has no {$locale} name");
            }
        }
    }

    public function test_the_frequent_countries_come_first_morocco_at_the_top(): void
    {
        $order = array_keys(Countries::localized('fr'));

        $this->assertSame('MA', $order[0]);
        $this->assertSame(Countries::FREQUENT, array_slice($order, 0, count(Countries::FREQUENT)));
    }

    public function test_the_rest_is_alphabetical_as_a_french_reader_expects(): void
    {
        $others = array_values(Countries::grouped('fr')['others']);

        // "Égypte" sits with the Es; a byte sort would put it after Zimbabwe.
        $egypt = array_search('Égypte', $others, true);
        $this->assertStringStartsWith('D', $others[$egypt - 1]);
        $this->assertSame('Zimbabwe', end($others));
    }

    public function test_a_frequent_country_is_not_listed_twice(): void
    {
        $groups = Countries::grouped('fr');

        $this->assertSame([], array_intersect_key($groups['frequent'], $groups['others']));
    }

    public function test_a_currency_is_suggested_only_where_the_form_offers_it(): void
    {
        $this->assertSame('MAD', Countries::currency('MA'));
        $this->assertSame('EUR', Countries::currency('DE'));
        $this->assertSame('XOF', Countries::currency('SN'));
        $this->assertNull(Countries::currency('CA'));

        foreach (Countries::currencyMap() as $currency) {
            $this->assertContains($currency, Countries::OFFERED_CURRENCIES);
        }
    }

    public function test_regenerating_changes_nothing(): void
    {
        // The data file is generated; a hand edit that the generator would
        // undo is caught here rather than on the next regeneration.
        $before = file_get_contents(Countries::dataPath());

        $this->artisan('castlit:countries-generate', ['--dart' => sys_get_temp_dir().'/countries-test.dart'])
            ->assertSuccessful();

        $this->assertSame($before, file_get_contents(Countries::dataPath()));
    }
}
