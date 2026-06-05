<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Item;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleInvoice;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PdfDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_settings_screen_and_update_work(): void
    {
        $this->seed();

        $this->get(route('module', ['module' => 'settings', 'section' => 'documents']))
            ->assertOk()
            ->assertSeeText('Modèles PDF commerciaux')
            ->assertSeeText('{{document_number}}');

        $this->post(route('settings.documents.update'), [
            'sale_title' => 'Bon de vente test',
            'invoice_title' => 'Facture test',
            'purchase_title' => 'Bon achat test',
            'primary_color' => '#123456',
            'accent_color' => '#0F9F8A',
            'header_text' => 'Document {{document_number}}',
            'sale_note_template' => 'Vente {{document_number}} pour {{client_name}}',
            'invoice_note_template' => 'Facture {{document_number}} total {{total}}',
            'purchase_note_template' => 'Achat {{document_number}} fournisseur {{supplier_name}}',
            'footer_text' => 'Merci {{store_name}}',
            'terms' => 'Conditions {{today}}',
            'show_logo' => '1',
            'show_signature' => '1',
            'show_bank_details' => '1',
        ])->assertRedirect();

        $tenant = Tenant::firstOrFail()->fresh();

        $this->assertSame('Facture test', data_get($tenant->settings, 'documents.invoice_title'));
        $this->assertSame('#123456', data_get($tenant->settings, 'documents.primary_color'));
        $this->assertTrue(data_get($tenant->settings, 'documents.show_signature'));
    }

    public function test_sales_invoices_and_purchases_can_download_pdf(): void
    {
        $this->seed();

        $item = Item::where('type', '!=', 'service')->where('stock_quantity', '>', 1)->firstOrFail();
        $client = Contact::where('kind', 'client')->firstOrFail();
        $supplier = Contact::where('kind', 'supplier')->firstOrFail();

        $this->post(route('pos.store'), [
            'contact_id' => $client->id,
            'cart' => json_encode([
                ['id' => $item->id, 'quantity' => 1],
            ]),
            'discount_amount' => 0,
            'cash_amount' => (float) $item->sale_price,
            'card_amount' => 0,
            'transfer_amount' => 0,
            'advance_amount' => 0,
        ])->assertRedirect();

        $sale = Sale::latest()->firstOrFail();
        $this->post(route('sales.invoice.store', $sale))->assertRedirect();
        $invoice = SaleInvoice::latest()->firstOrFail();

        $this->assertPdfResponse($this->get(route('sales.pdf', $sale)));
        $this->assertPdfResponse($this->get(route('sales.invoices.pdf', $invoice)));

        $this->post(route('purchases.store'), [
            'supplier_id' => $supplier->id,
            'ordered_at' => now()->toDateString(),
            'supplier_invoice' => 'PDF-PO-001',
            'status' => 'ordered',
            'items' => [
                ['item_id' => $item->id, 'quantity' => 3, 'unit_cost' => 12.5],
            ],
        ])->assertRedirect();

        $purchase = Purchase::latest()->firstOrFail();
        $this->assertPdfResponse($this->get(route('purchases.pdf', $purchase)));
    }

    private function assertPdfResponse($response): void
    {
        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->baseResponse->getContent());
    }
}
