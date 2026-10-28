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

    public function test_every_country_suggests_its_own_currency_and_the_forms_offer_it(): void
    {
        $this->assertSame('MAD', Countries::currency('MA'));
        $this->assertSame('EUR', Countries::currency('DE'));
        $this->assertSame('XOF', Countries::currency('SN'));
        // A shop in Toronto or Riyadh used to be left in dirhams.
        $this->assertSame('CAD', Countries::currency('CA'));
        $this->assertSame('SAR', Countries::currency('SA'));

        foreach (Countries::currencyMap() as $country => $currency) {
            $this->assertContains($currency, Countries::currencyCodes(), $country);
        }
        $this->assertSame(Countries::FREQUENT_CURRENCIES, array_slice(Countries::currencyCodes(), 0, count(Countries::FREQUENT_CURRENCIES)));
    }

    public function test_each_currency_has_its_name_and_its_own_decimals(): void
    {
        $this->assertSame('Dinar tunisien', Countries::currencyName('TND'));
        $this->assertSame('Saudi Riyal', Countries::currencyName('SAR', 'en'));
        $this->assertSame(3, Countries::currencyDigits('TND'));
        $this->assertSame(0, Countries::currencyDigits('XOF'));
        $this->assertSame(2, Countries::currencyDigits('MAD'));
        $this->assertSame(2, Countries::currencyDigits('???'), 'unknown: the usual two');
    }

    public function test_a_new_shop_runs_on_its_own_clock(): void
    {
        $this->assertSame('Europe/Paris', Countries::timezone('FR'));
        $this->assertSame('America/Toronto', Countries::timezone('CA'), 'the country\'s main zone');
        $this->assertSame('America/Vancouver', Countries::timezone('CA', 'America/Vancouver'),
            'its own zone when it lies in that country');
        $this->assertSame('America/Toronto', Countries::timezone('CA', 'Europe/Paris'), 'a zone abroad says nothing');
        $this->assertSame('America/Toronto', Countries::timezone('CA', 'Not/AZone'));
        $this->assertSame('Africa/El_Aaiun', Countries::timezone('MA', 'Africa/El_Aaiun'), 'Laâyoune signs up under Maroc');
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
