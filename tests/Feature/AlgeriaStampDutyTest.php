<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\Sale;
use App\Models\SaleInvoice;
use App\Models\Tenant;
use App\Services\Documents\DocumentBranding;
use App\Services\Documents\InvoiceService;
use App\Support\I18n\HtmlTranslator;
use App\Support\StampDuty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Algerian « droit de timbre »: a percentage scale on the total TTC of an
 * invoice paid in cash, recomputed when the invoice is edited.
 */
class AlgeriaStampDutyTest extends TestCase
{
    use RefreshDatabase;

    private function shop(string $country = 'DZ', string $currency = 'DZD'): Tenant
    {
        $this->seed();
        $tenant = Tenant::firstOrFail();
        $settings = $tenant->settings ?? [];
        data_set($settings, 'company_profile.country_code', $country);
        data_forget($settings, StampDuty::SETTINGS_KEY);
        $tenant->forceFill(['currency' => $currency, 'settings' => $settings])->save();

        return $tenant->fresh();
    }

    private function invoice(Tenant $tenant, string $price, bool $cash): Invoice
    {
        return app(InvoiceService::class)->create($tenant, [
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(10)->toDateString(),
            'paid_in_cash' => $cash,
            'lines' => [['name' => 'Ligne libre', 'quantity' => 1, 'unit_price' => $price]],
        ]);
    }

    public function test_the_scale_boundaries(): void
    {
        foreach ([
            '0.00' => '0.00', '300.00' => '0.00', '301.00' => '5.00', '1000.00' => '10.00',
            '30000.00' => '300.00', '30001.00' => '451.00', '100000.00' => '1500.00', '100001.00' => '2001.00',
        ] as $total => $stamp) {
            $this->assertSame($stamp, StampDuty::scale($total), "total {$total}");
        }
    }

    public function test_a_cash_invoice_carries_the_stamp_and_a_card_invoice_does_not(): void
    {
        $tenant = $this->shop();

        $cash = $this->invoice($tenant, '1000.00', true);
        $this->assertSame('10.00', (string) $cash->stamp_duty);
        $this->assertSame('1010.00', (string) $cash->total);
        $this->assertSame('1010.00', (string) $cash->balance_due);
        $this->assertTrue((bool) data_get($cash->metadata, 'paid_in_cash'));

        $other = $this->invoice($tenant, '1000.00', false);
        $this->assertSame('0.00', (string) $other->stamp_duty);
        $this->assertSame('1000.00', (string) $other->total);

        $cash->loadMissing(['items', 'payments', 'customer', 'creator', 'tenant']);
        $html = HtmlTranslator::pdfHtml('librairepro.pdf.commercial-document', [
            'documentType' => 'invoice',
            'document' => $cash,
            'tenant' => $tenant,
            'company' => app(DocumentBranding::class)->companyProfile($tenant),
            'settings' => app(DocumentBranding::class)->settings($tenant),
        ], $tenant);
        $this->assertStringContainsString('Timbre fiscal', $html);
        $this->assertStringContainsString('paiement en espèces', $html);
    }

    public function test_the_form_asks_for_cash_and_stores_the_choice(): void
    {
        $tenant = $this->shop();

        $this->get(route('module', ['module' => 'invoices', 'section' => 'invoice-add']))
            ->assertOk()->assertSeeText('Réglé en espèces');

        $payload = [
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(5)->toDateString(),
            'lines' => [['name' => 'Ligne libre', 'quantity' => 1, 'unit_price' => '2000']],
        ];
        $this->post(route('documents.invoices.store'), $payload + ['paid_in_cash' => '1'])->assertRedirect();
        $this->assertSame('20.00', (string) Invoice::latest('id')->firstOrFail()->stamp_duty);

        $this->post(route('documents.invoices.store'), $payload)->assertRedirect();
        $this->assertSame('0.00', (string) Invoice::latest('id')->firstOrFail()->stamp_duty);
    }

    public function test_an_edit_recomputes_the_stamp_from_the_new_total_and_checkbox(): void
    {
        $tenant = $this->shop();
        $service = app(InvoiceService::class);
        $invoice = $this->invoice($tenant, '1000.00', true);

        $edited = $service->update($invoice->fresh(), ['lines' => [['name' => 'Ligne libre', 'quantity' => 1, 'unit_price' => '2000.00']]]);
        $this->assertSame('20.00', (string) $edited->stamp_duty, 'the stored choice is kept');
        $this->assertSame('2020.00', (string) $edited->total);
        $this->assertSame('2020.00', (string) $edited->balance_due);

        $edited = $service->update($edited->fresh(), [
            'paid_in_cash' => false,
            'lines' => [['name' => 'Ligne libre', 'quantity' => 1, 'unit_price' => '2000.00']],
        ]);
        $this->assertSame('0.00', (string) $edited->stamp_duty);
        $this->assertSame('2000.00', (string) $edited->total);
        $this->assertFalse((bool) data_get($edited->metadata, 'paid_in_cash'));
    }

    public function test_switching_the_stamp_off_stops_it(): void
    {
        $tenant = $this->shop();

        $this->post(route('settings.stamp-duty.update'), [])->assertRedirect();
        $this->assertSame(['enabled' => false], data_get($tenant->fresh()->settings, StampDuty::SETTINGS_KEY));

        $this->assertSame('0.00', (string) $this->invoice($tenant->fresh(), '1000.00', true)->stamp_duty);
        $this->get(route('module', ['module' => 'invoices', 'section' => 'invoice-add']))
            ->assertOk()->assertDontSeeText('Réglé en espèces');

        $this->post(route('settings.stamp-duty.update'), ['stamp_duty_enabled' => '1', 'stamp_duty_amount' => '9'])->assertRedirect();
        $this->assertNull(data_get($tenant->fresh()->settings, StampDuty::SETTINGS_KEY), 'default again: nothing stored');
    }

    public function test_the_settings_card_explains_the_scale_without_an_amount(): void
    {
        $this->shop();

        $this->get(route('module', ['module' => 'settings', 'section' => 'documents']))
            ->assertOk()
            ->assertSeeText('Timbre fiscal')
            ->assertSeeText('Appliquer le timbre fiscal')
            ->assertDontSeeText('Montant du timbre');
    }

    public function test_a_moroccan_shop_is_unaffected(): void
    {
        $tenant = $this->shop('MA', 'MAD');

        $invoice = $this->invoice($tenant, '1000.00', true);
        $this->assertSame('0.00', (string) $invoice->stamp_duty);
        $this->assertNull($invoice->metadata);
        $this->get(route('module', ['module' => 'invoices', 'section' => 'invoice-add']))
            ->assertOk()->assertDontSeeText('Réglé en espèces');
    }

    private function sale(array $payments): Sale
    {
        $item = Item::where('type', '!=', 'service')->firstOrFail();
        $item->forceFill(['sale_price' => 1000, 'stock_quantity' => 100])->save();
        $client = Contact::where('kind', 'client')->firstOrFail();

        $this->post(route('pos.store'), [
            'contact_id' => $client->id,
            'cart' => json_encode([['id' => $item->id, 'quantity' => 1]]),
            'discount_amount' => 0,
            'cash_amount' => $payments['cash'] ?? 0,
            'card_amount' => $payments['card'] ?? 0,
            'transfer_amount' => 0,
            'advance_amount' => 0,
        ])->assertRedirect();

        return Sale::latest('id')->firstOrFail();
    }

    public function test_a_sale_invoice_is_stamped_on_its_cash_portion_only(): void
    {
        $tenant = $this->shop();

        $cashSale = $this->sale(['cash' => 1000]);
        $this->post(route('sales.invoice.store', $cashSale))->assertRedirect();
        $invoice = SaleInvoice::latest('id')->firstOrFail();
        $this->assertSame('10.00', (string) $invoice->stamp_duty);
        $this->assertEqualsWithDelta((float) $cashSale->total_amount + 10, (float) $invoice->total_amount, 0.001);

        $cardSale = $this->sale(['card' => 1000]);
        $this->post(route('sales.invoice.store', $cardSale))->assertRedirect();
        $this->assertSame('0.00', (string) SaleInvoice::latest('id')->firstOrFail()->stamp_duty);

        // Split: 400 in cash + the rest by card → the scale applies to 400 (5 min).
        $splitSale = $this->sale(['cash' => 400, 'card' => 600]);
        $this->post(route('sales.invoice.store', $splitSale))->assertRedirect();
        $this->assertSame('5.00', (string) SaleInvoice::latest('id')->firstOrFail()->stamp_duty);

        // Raised again: it keeps its own stamp.
        $this->post(route('sales.invoice.store', $cashSale))->assertRedirect();
        $this->assertSame('10.00', (string) $invoice->fresh()->stamp_duty);
    }
}
