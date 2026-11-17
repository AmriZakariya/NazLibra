<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Support\AmountInWords;
use App\Support\BusinessIds;
use App\Support\Countries;
use App\Support\Money;
use App\Support\Phone;
use App\Support\TaxPresets;
use Tests\TestCase;

/**
 * A shop in Algeria: its dinar, its 19 % and 9 % VAT, and the RC, NIF, NIS
 * and AI an Algerian invoice must carry.
 */
class AlgeriaReadinessTest extends TestCase
{
    private function algerianShop(): Tenant
    {
        return new Tenant([
            'currency' => 'DZD',
            'settings' => ['language_id' => 'fr', 'company_profile' => ['country_code' => 'DZ']],
        ]);
    }

    public function test_the_dinar_reads_like_an_algerian_invoice(): void
    {
        $money = new Money('DZD');

        app()->setLocale('fr');
        $this->assertSame('1 250,50 DA', $money->format(1250.5));

        app()->setLocale('ar');
        $this->assertSame('1 250,50 د.ج', $money->format(1250.5));

        $this->assertSame(2, Countries::currencyDigits('DZD'));
        $this->assertSame('Africa/Algiers', Countries::timezone('DZ'));
    }

    public function test_the_total_in_words_names_the_algerian_dinar(): void
    {
        $this->assertSame('Mille deux cent cinquante dinars et cinquante centimes', AmountInWords::money(1250.5, 'DZD', 'fr'));
        $this->assertStringContainsString('دينار جزائري', AmountInWords::money(1250.5, 'DZD', 'ar'));
    }

    public function test_a_new_algerian_shop_starts_with_algerias_vat(): void
    {
        $this->assertSame([['Sans TVA', 0, true], ['TVA 19%', 19, true], ['TVA 9%', 9, false]], TaxPresets::forCountry('DZ'));
    }

    public function test_an_invoice_carries_the_rc_nif_nis_and_ai(): void
    {
        $shop = $this->algerianShop();

        $this->assertSame('DZ', Phone::defaultCountry(new Tenant(['currency' => 'DZD'])), 'a DZD shop is Algerian');
        $this->assertSame(
            ['NIF' => '000016001234567', 'RC' => '16/00-1234567B21', 'NIS' => '000116001234567', 'AI' => '16012345678'],
            BusinessIds::lines($shop, [
                'gst_no' => '000016001234567', 'rc' => '16/00-1234567B21', 'vat_no' => '000116001234567',
                'pan_no' => '16012345678', 'cnss' => 'x',
            ]),
        );
        $this->assertSame('Article d’imposition (AI)', BusinessIds::settings($shop)['pan_no']);
        $this->assertSame('NIF', BusinessIds::partner($shop));
    }
}
