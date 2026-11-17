<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\Sale;
use App\Models\SaleInvoice;
use App\Models\Tenant;
use App\Services\Documents\DocumentBranding;
use App\Services\Documents\EstimateService;
use App\Services\Documents\InvoiceService;
use App\Support\I18n\HtmlTranslator;
use App\Support\StampDuty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Tunisian « droit de timbre »: added to every new invoice of a Tunisian
 * shop, fixed when the invoice is created, and invisible everywhere else.
 */
class TunisiaStampDutyTest extends TestCase
{
    use RefreshDatabase;

    private function shop(string $country, string $currency): Tenant
    {
        $this->seed();
        $tenant = Tenant::firstOrFail();
        $settings = $tenant->settings ?? [];
        data_set($settings, 'company_profile.country_code', $country);
        data_forget($settings, StampDuty::SETTINGS_KEY);
        $tenant->forceFill(['currency' => $currency, 'settings' => $settings])->save();

        return $tenant->fresh();
    }

    private function invoice(Tenant $tenant): Invoice
    {
        return app(InvoiceService::class)->create($tenant, [
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(10)->toDateString(),
            'lines' => [['name' => 'Ligne libre', 'quantity' => 1, 'unit_price' => '100.00']],
        ]);
    }

    private function printed(Invoice $invoice, string $type = 'invoice'): string
    {
        $invoice->loadMissing(['items', 'payments', 'customer', 'creator', 'tenant']);
        $branding = app(DocumentBranding::class);

        return HtmlTranslator::pdfHtml('librairepro.pdf.commercial-document', [
            'documentType' => $type,
            'document' => $invoice,
            'tenant' => $invoice->tenant,
            'company' => $branding->companyProfile($invoice->tenant),
            'settings' => $branding->settings($invoice->tenant),
        ], $invoice->tenant);
    }

    public function test_a_tunisian_invoice_carries_the_stamp_in_its_net_to_pay_and_on_paper(): void
    {
        $tenant = $this->shop('TN', 'TND');

        $invoice = $this->invoice($tenant);

        $this->assertSame('1.00', (string) $invoice->stamp_duty);
        $this->assertSame('101.00', (string) $invoice->total);
        $this->assertSame('101.00', (string) $invoice->balance_due);
        $this->assertStringContainsString('Timbre fiscal', $this->printed($invoice));

        // The stamp is owed like the rest: paying 100 leaves 1.
        $service = app(InvoiceService::class);
        $service->send($invoice);
        $service->recordPayment($invoice->fresh(), ['amount' => '100.00']);
        $this->assertSame('1.00', (string) $invoice->fresh()->balance_due);
    }

    public function test_a_moroccan_shop_has_no_stamp_and_never_sees_the_setting(): void
    {
        $tenant = $this->shop('MA', 'MAD');

        $invoice = $this->invoice($tenant);

        $this->assertSame('0.00', (string) $invoice->stamp_duty);
        $this->assertSame('100.00', (string) $invoice->total);
        $this->assertStringNotContainsString('Timbre fiscal', $this->printed($invoice));
        $this->get(route('module', ['module' => 'settings', 'section' => 'documents']))
            ->assertOk()
            ->assertDontSeeText('Timbre fiscal');
        $this->post(route('settings.stamp-duty.update'), ['stamp_duty_enabled' => '1', 'stamp_duty_amount' => '1'])->assertNotFound();
    }

    public function test_a_tunisian_shop_sees_the_setting_and_disabling_it_stops_the_stamp(): void
    {
        $tenant = $this->shop('TN', 'TND');

        $this->get(route('module', ['module' => 'settings', 'section' => 'documents']))
            ->assertOk()
            ->assertSeeText('Timbre fiscal');

        // Only differences from the default are stored.
        $this->post(route('settings.stamp-duty.update'), ['stamp_duty_amount' => '1.00'])->assertRedirect();
        $this->assertSame(['enabled' => false], data_get($tenant->fresh()->settings, StampDuty::SETTINGS_KEY));

        $invoice = $this->invoice($tenant->fresh());
        $this->assertSame('0.00', (string) $invoice->stamp_duty);
        $this->assertSame('100.00', (string) $invoice->total);
        $this->assertStringNotContainsString('Timbre fiscal', $this->printed($invoice));

        $this->post(route('settings.stamp-duty.update'), ['stamp_duty_enabled' => '1', 'stamp_duty_amount' => '1.00'])->assertRedirect();
        $this->assertNull(data_get($tenant->fresh()->settings, StampDuty::SETTINGS_KEY), 'back to the default: nothing stored');
    }

    public function test_changing_the_amount_never_alters_an_invoice_already_issued(): void
    {
        $tenant = $this->shop('TN', 'TND');
        $old = $this->invoice($tenant);

        $this->post(route('settings.stamp-duty.update'), ['stamp_duty_enabled' => '1', 'stamp_duty_amount' => '2.50'])->assertRedirect();
        $this->assertSame(['amount' => '2.50'], data_get($tenant->fresh()->settings, StampDuty::SETTINGS_KEY));

        $this->assertSame('1.00', (string) $old->fresh()->stamp_duty);
        $this->assertSame('101.00', (string) $old->fresh()->total);

        $new = $this->invoice($tenant->fresh());
        $this->assertSame('2.50', (string) $new->stamp_duty);
        $this->assertSame('102.50', (string) $new->total);

        // Editing an invoice keeps the stamp it was issued with.
        $edited = app(InvoiceService::class)->update($old->fresh(), [
            'lines' => [['name' => 'Ligne libre', 'quantity' => 2, 'unit_price' => '100.00']],
        ]);
        $this->assertSame('1.00', (string) $edited->stamp_duty);
        $this->assertSame('201.00', (string) $edited->total);
    }

    public function test_an_estimate_has_no_stamp_but_the_invoice_it_becomes_has(): void
    {
        $tenant = $this->shop('TN', 'TND');
        $service = app(EstimateService::class);

        $estimate = $service->create($tenant, [
            'status' => 'accepted',
            'issue_date' => now()->toDateString(),
            'expiration_date' => now()->addDays(10)->toDateString(),
            'lines' => [['name' => 'Ligne libre', 'quantity' => 1, 'unit_price' => '100.00']],
        ]);

        $this->assertSame('100.00', (string) $estimate->total);
        $estimate->loadMissing(['items', 'customer', 'creator', 'tenant']);
        $html = HtmlTranslator::pdfHtml('librairepro.pdf.commercial-document', [
            'documentType' => 'estimate',
            'document' => $estimate,
            'tenant' => $tenant,
            'company' => app(DocumentBranding::class)->companyProfile($tenant),
            'settings' => app(DocumentBranding::class)->settings($tenant),
        ], $tenant);
        $this->assertStringNotContainsString('Timbre fiscal', $html);

        $invoice = $service->convertToInvoice($estimate, ['due_date' => now()->addDays(20)->toDateString()]);
        $this->assertSame('1.00', (string) $invoice->stamp_duty);
        $this->assertSame('101.00', (string) $invoice->total);
    }

    public function test_an_invoice_raised_from_a_sale_carries_the_stamp(): void
    {
        $tenant = $this->shop('TN', 'TND');
        $item = Item::where('type', '!=', 'service')->where('stock_quantity', '>', 1)->firstOrFail();
        $client = Contact::where('kind', 'client')->firstOrFail();

        $this->post(route('pos.store'), [
            'contact_id' => $client->id,
            'cart' => json_encode([['id' => $item->id, 'quantity' => 1]]),
            'discount_amount' => 0,
            'cash_amount' => (float) $item->sale_price,
            'card_amount' => 0,
            'transfer_amount' => 0,
            'advance_amount' => 0,
        ])->assertRedirect();
        $sale = Sale::latest()->firstOrFail();

        $this->post(route('sales.invoice.store', $sale))->assertRedirect();
        $invoice = SaleInvoice::latest()->firstOrFail();

        $this->assertSame('1.00', (string) $invoice->stamp_duty);
        $this->assertEqualsWithDelta((float) $sale->total_amount + 1, (float) $invoice->total_amount, 0.001);

        // Raised again after the setting changed: it keeps its own stamp.
        $this->post(route('settings.stamp-duty.update'), ['stamp_duty_enabled' => '1', 'stamp_duty_amount' => '3'])->assertRedirect();
        $this->post(route('sales.invoice.store', $sale))->assertRedirect();
        $this->assertSame('1.00', (string) $invoice->fresh()->stamp_duty);

        $response = $this->get(route('sales.invoices.pdf', $invoice));
        $response->assertOk();
        $this->assertStringStartsWith('%PDF', $response->baseResponse->getContent());
    }
}
