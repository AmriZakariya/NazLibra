<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Documents\DocumentBranding;
use App\Services\Documents\InvoiceService;
use App\Support\AmountInWords;
use App\Support\BusinessIds;
use App\Support\I18n\ArabicShaper;
use App\Support\I18n\HtmlTranslator;
use App\Support\Money;
use App\Support\Phone;
use App\Support\TaxPresets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A shop in Egypt: its pound written as Egyptians write it, its own VAT and
 * identifiers on its documents, and invoices it can read in Arabic.
 */
class EgyptReadinessTest extends TestCase
{
    use RefreshDatabase;

    private function egyptianShop(): Tenant
    {
        return new Tenant([
            'currency' => 'EGP',
            'settings' => ['language_id' => 'ar', 'company_profile' => ['country_code' => 'EG']],
        ]);
    }

    public function test_the_pound_reads_like_an_egyptian_receipt(): void
    {
        $money = new Money('EGP');

        app()->setLocale('ar');
        $this->assertSame('1,250.50 ج.م', $money->format(1250.5));

        app()->setLocale('en');
        $this->assertSame('1,250.50 EGP', $money->format(1250.5));
        $this->assertSame(['.', ','], [$money->toJs()['decimal_separator'], $money->toJs()['thousands_separator']], 'the browser formats it the same way');

        $this->assertSame('1 250,50 DH', (new Money('MAD'))->format(1250.5), 'Moroccan shops unchanged');
    }

    public function test_a_new_egyptian_shop_starts_with_egypts_vat(): void
    {
        $this->assertSame([['Sans TVA', 0, true], ['TVA 14%', 14, true], ['TVA 5%', 5, false]], TaxPresets::forCountry('EG'));
        $this->assertSame([['Sans TVA', 0, true], ['TVA 20%', 20, true], ['TVA 7%', 7, false]], TaxPresets::forCountry('MA'));
        $this->assertSame(TaxPresets::forCountry('MA'), TaxPresets::forCountry(null), 'unknown country: as before');
    }

    public function test_the_shops_identifiers_are_named_as_its_country_names_them(): void
    {
        $egypt = $this->egyptianShop();
        $morocco = new Tenant(['currency' => 'MAD', 'settings' => ['company_profile' => ['country_code' => 'MA']]]);

        $this->assertSame('EG', Phone::defaultCountry(new Tenant(['currency' => 'EGP'])), 'an EGP shop is Egyptian');
        $this->assertSame(['N° d’enregistrement fiscal' => '100-200-300', 'Registre de commerce' => '4521'], BusinessIds::lines($egypt, ['gst_no' => '100-200-300', 'rc' => '4521', 'cnss' => 'x']));
        $this->assertSame(['ICE' => '0012', 'RC' => '4521', 'CNSS' => 'x'], BusinessIds::lines($morocco, ['gst_no' => '0012', 'rc' => '4521', 'cnss' => 'x']));
        $this->assertSame('ICE', BusinessIds::partner($morocco));
    }

    public function test_the_total_in_words_follows_the_documents_language(): void
    {
        $this->assertSame('فقط ألف ومائتان وخمسون جنيه مصري وخمسون قرش لا غير', AmountInWords::money(1250.5, 'EGP', 'ar'));
        $this->assertSame('One thousand two hundred fifty Egyptian pounds and fifty piastres', AmountInWords::money(1250.5, 'EGP', 'en'));
        $this->assertSame(AmountInWords::money(1250.5, 'MAD'), AmountInWords::money(1250.5, 'MAD', 'fr'), 'French unchanged');
    }

    public function test_arabic_is_joined_and_put_in_visual_order_for_dompdf(): void
    {
        // « لا » is one ligature; « فاتورة » keeps its letters joined.
        $this->assertSame("\u{FEFB}", ArabicShaper::shape('لا'));
        $this->assertSame("\u{FED3}\u{FE8E}\u{FE97}\u{FEEE}\u{FEAD}\u{FE93}", ArabicShaper::shape('فاتورة'));

        // Reversed for a left-to-right engine, numbers keeping their order.
        $visual = ArabicShaper::visual('فاتورة FAC-2026-0001');
        $this->assertStringStartsWith('FAC-2026-0001 ', $visual);
        $this->assertStringEndsWith("\u{FED3}", $visual, 'the first letter read is the rightmost');

        $this->assertSame('Total 12,00 DH', ArabicShaper::visual('Total 12,00 DH'), 'no Arabic, nothing changes');
    }

    public function test_an_arabic_shop_gets_a_real_pdf_in_arabic(): void
    {
        $this->seed();
        $tenant = Tenant::firstOrFail();
        $settings = $tenant->settings;
        $settings['language_id'] = 'ar';
        $settings['company_profile']['country_code'] = 'EG';
        $tenant->forceFill(['currency' => 'EGP', 'settings' => $settings])->save();
        Money::forget();

        $service = app(InvoiceService::class);
        $invoice = $service->send($service->create($tenant, [
            'customer_name' => 'أحمد محمود',
            'issue_date' => '2026-11-14',
            'due_date' => '2026-11-30',
            'lines' => [['name' => 'كتاب', 'quantity' => 2, 'unit_price' => '125.50', 'tax_rate' => '14']],
        ]));

        $html = HtmlTranslator::pdfHtml('librairepro.pdf.commercial-document', [
            'documentType' => 'invoice',
            'document' => $invoice->fresh(['items', 'payments', 'creator']),
            'tenant' => $tenant,
            'company' => app(DocumentBranding::class)->companyProfile($tenant),
            'settings' => app(DocumentBranding::class)->settings($tenant),
        ], $tenant);

        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringContainsString(ArabicShaper::visual('ج.م'), $html, 'the pound in Arabic, shaped');
        $this->assertStringContainsString(ArabicShaper::visual('أحمد محمود'), $html, 'a name typed in Arabic is joined too');
        $this->assertStringNotContainsString('Arrêtée la présente facture', $html);

        $owner = User::where('email', 'amina@librairie-atlas.ma')->firstOrFail();
        $response = $this->actingAs($owner)->get(route('documents.invoices.pdf', $invoice));
        $response->assertOk();
        $this->assertStringStartsWith('%PDF', $response->baseResponse->getContent());
    }
}
