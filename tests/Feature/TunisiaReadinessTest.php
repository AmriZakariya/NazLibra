<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Support\AmountInWords;
use App\Support\BusinessIds;
use App\Support\Money;
use App\Support\Phone;
use App\Support\TaxPresets;
use Tests\TestCase;

/**
 * A shop in Tunisia: its dinar counted in millimes, its three VAT rates and
 * its matricule fiscal on its documents.
 */
class TunisiaReadinessTest extends TestCase
{
    private function tunisianShop(): Tenant
    {
        return new Tenant([
            'currency' => 'TND',
            'settings' => ['language_id' => 'fr', 'company_profile' => ['country_code' => 'TN']],
        ]);
    }

    public function test_the_dinar_keeps_its_millimes(): void
    {
        $money = new Money('TND', decimals: 3);

        app()->setLocale('fr');
        $this->assertSame('1 250,500 DT', $money->format(1250.5));

        app()->setLocale('ar');
        $this->assertSame('1 250,500 د.ت', $money->format(1250.5), 'Arabic reads the Arabic abbreviation');
    }

    public function test_the_total_in_words_counts_millimes_not_centimes(): void
    {
        $this->assertSame('Douze dinars et trois cent quarante-cinq millimes', AmountInWords::money('12.345', 'TND', 'fr'));
        $this->assertSame('Un dinar et cinq cents millimes', AmountInWords::money('1.5', 'TND', 'fr'));
        $this->assertSame('Twelve Tunisian dinars and three hundred forty-five millimes', AmountInWords::money('12.345', 'TND', 'en'));
        $this->assertStringContainsString('مليم', AmountInWords::money('12.345', 'TND', 'ar'));
        $this->assertSame('Cent quarante-cinq dirhams et trente centimes', AmountInWords::money('145.30', 'MAD', 'fr'), 'the dirham unchanged');
    }

    public function test_a_new_tunisian_shop_starts_with_tunisias_vat(): void
    {
        $this->assertSame(
            [['Sans TVA', 0, true], ['TVA 19%', 19, true], ['TVA 13%', 13, false], ['TVA 7%', 7, false]],
            TaxPresets::forCountry('TN'),
        );
    }

    public function test_the_shops_identifiers_are_named_as_tunisia_names_them(): void
    {
        $shop = $this->tunisianShop();

        $this->assertSame('TN', Phone::defaultCountry(new Tenant(['currency' => 'TND'])), 'a TND shop is Tunisian');
        $this->assertSame('Matricule fiscal (MF)', BusinessIds::settings($shop)['gst_no']);
        $this->assertSame(
            ['MF' => '1234567/A/M/000', 'RNE' => '1234567A', 'CNSS' => '987'],
            BusinessIds::lines($shop, ['gst_no' => '1234567/A/M/000', 'rc' => '1234567A', 'cnss' => '987', 'vat_no' => 'x']),
        );
        $this->assertSame('MF', BusinessIds::main($shop));
        $this->assertSame('Matricule fiscal', BusinessIds::partner($shop));
    }
}
