<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Sale;
use App\Models\Tenant;
use App\Services\Documents\InvoiceService;
use App\Support\AppModules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FacturationModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoices_module_page_loads_and_shows_facturation(): void
    {
        $this->seed();

        $this->get(route('module', ['module' => 'invoices']))
            ->assertOk()
            ->assertSee('Facturation')
            ->assertSee('Liste des factures');
    }

    public function test_invoices_list_section_loads(): void
    {
        $this->seed();

        $this->get(route('module', ['module' => 'invoices', 'section' => 'invoices']))
            ->assertOk()
            ->assertSee('Factures commerciales')
            ->assertSee('Factures issues des ventes POS');
    }

    public function test_estimate_add_section_loads(): void
    {
        $this->seed();

        $this->get(route('module', ['module' => 'invoices', 'section' => 'estimate-add']))
            ->assertOk()
            ->assertSee('Nouveau devis')
            ->assertSee('Lignes devis');
    }

    public function test_invoice_add_section_loads(): void
    {
        $this->seed();

        $this->get(route('module', ['module' => 'invoices', 'section' => 'invoice-add']))
            ->assertOk()
            ->assertSee('Nouvelle facture')
            ->assertSee('Lignes facture');
    }

    public function test_invoices_datatable_endpoint_returns_searchable_rows(): void
    {
        $this->seed();
        $tenant = Tenant::firstOrFail();
        $item = Item::where('tenant_id', $tenant->id)->firstOrFail();

        $invoice = app(InvoiceService::class)->create($tenant, [
            'customer_name' => 'Client DataTable',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(15)->toDateString(),
            'lines' => [[
                'item_id' => $item->id,
                'quantity' => 1,
                'unit_price' => '25.00',
                'discount_type' => 'fixed',
                'discount_value' => '0.00',
                'tax_rate' => '0',
            ]],
        ]);

        $this->getJson(route('documents.invoices.data', [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => $invoice->number],
        ]))
            ->assertOk()
            ->assertJsonPath('draw', 1)
            ->assertJsonFragment(['number' => $invoice->number]);
    }

    public function test_disabling_invoices_module_returns_404(): void
    {
        $this->seed();
        $tenant = Tenant::firstOrFail();

        $this->post(route('settings.modules.update'), [
            'enabled' => ['dashboard', 'catalog', 'sales', 'settings'],
            'order' => 'dashboard,sales,invoices,catalog,settings',
        ])->assertRedirect();

        $tenant->refresh();
        $this->assertFalse(AppModules::enabled($tenant, 'invoices'));

        $this->get(route('module', ['module' => 'invoices']))
            ->assertNotFound();
    }

    public function test_legacy_invoice_redirect_points_to_invoices_module(): void
    {
        $this->seed();

        $this->get(route('legacy.invoice'))
            ->assertRedirect('/modules/invoices?section=invoices');
    }

    public function test_legacy_quotation_redirect_points_to_invoices_estimates(): void
    {
        $this->seed();

        $this->get(route('legacy.quotation'))
            ->assertRedirect('/modules/invoices?section=estimates');
    }

    public function test_creating_invoice_from_new_form_redirects_to_invoice_detail(): void
    {
        $this->seed();
        $tenant = Tenant::firstOrFail();
        $item = Item::where('tenant_id', $tenant->id)->firstOrFail();

        $response = $this->post(route('documents.invoices.store'), [
            'customer_name' => 'Client facturation',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(15)->toDateString(),
            'status' => 'draft',
            'lines' => [
                [
                    'item_id' => $item->id,
                    'quantity' => 2,
                    'unit_price' => '50.00',
                    'discount_type' => 'fixed',
                    'discount_value' => '5.00',
                    'tax_rate' => '20',
                    'tax_inclusive' => false,
                ],
            ],
        ]);

        $invoice = $tenant->invoices()->latest('id')->firstOrFail();

        $response->assertRedirect(route('module', [
            'module' => 'invoices',
            'section' => 'invoices',
            'invoice' => $invoice->id,
        ]));

        $this->assertSame('Client facturation', $invoice->customer_snapshot['name']);
        $this->assertSame('95.00', (string) $invoice->subtotal);
    }

    public function test_invoice_can_create_only_one_sale_and_then_redirects_to_existing_sale(): void
    {
        $this->seed();
        $tenant = Tenant::firstOrFail();
        $item = Item::where('tenant_id', $tenant->id)
            ->where('type', '!=', 'service')
            ->where('stock_quantity', '>', 2)
            ->firstOrFail();

        $invoice = app(InvoiceService::class)->create($tenant, [
            'customer_name' => 'Client conversion facture',
            'status' => 'sent',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(15)->toDateString(),
            'lines' => [[
                'item_id' => $item->id,
                'quantity' => 1,
                'unit_price' => (string) $item->sale_price,
                'discount_type' => 'fixed',
                'discount_value' => '0.00',
                'tax_rate' => '0',
            ]],
        ]);

        $this->get(route('module', ['module' => 'sales', 'section' => 'add', 'from_invoice' => $invoice->id]))
            ->assertRedirect(route('pos', ['source_invoice' => $invoice->id]));

        $this->get(route('pos', ['source_invoice' => $invoice->id]))
            ->assertOk()
            ->assertSee('name="source_invoice_id" type="hidden" value="'.$invoice->id.'"', false)
            ->assertSee($item->title);

        $payload = [
            '_idempotency_key' => 'sale-from-invoice-1',
            'source_invoice_id' => $invoice->id,
            'cash_amount' => (float) $item->sale_price,
            'cart' => json_encode([['id' => $item->id, 'quantity' => 1, 'price' => (float) $item->sale_price]]),
        ];

        $this->post(route('pos.store'), $payload)->assertRedirect();
        $sale = Sale::where('source_invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame($invoice->id, $sale->source_invoice_id);
        $this->assertSame($invoice->number, $sale->metadata['source_invoice_number']);
        $this->assertSame('invoice_then_sale', $sale->metadata['document_flow']);

        $this->get(route('module', ['module' => 'sales', 'section' => 'list', 'detail_sale' => $sale->id]))
            ->assertOk()
            ->assertSee('Facture source')
            ->assertSee($invoice->number)
            ->assertSee('Facture créée avant la vente');

        $payload['_idempotency_key'] = 'sale-from-invoice-2';
        $this->post(route('pos.store'), $payload)
            ->assertRedirect(route('pos', ['sale' => $sale->id]));

        $this->assertSame(1, Sale::where('source_invoice_id', $invoice->id)->count());

        $this->get(route('module', ['module' => 'sales', 'section' => 'add', 'from_invoice' => $invoice->id]))
            ->assertRedirect(route('pos', ['source_invoice' => $invoice->id]));
    }

    public function test_draft_invoice_cannot_create_sale(): void
    {
        $this->seed();
        $tenant = Tenant::firstOrFail();
        $item = Item::where('tenant_id', $tenant->id)
            ->where('type', '!=', 'service')
            ->where('stock_quantity', '>', 2)
            ->firstOrFail();

        $invoice = app(InvoiceService::class)->create($tenant, [
            'customer_name' => 'Client brouillon',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(15)->toDateString(),
            'lines' => [[
                'item_id' => $item->id,
                'quantity' => 1,
                'unit_price' => (string) $item->sale_price,
                'discount_type' => 'fixed',
                'discount_value' => '0.00',
                'tax_rate' => '0',
            ]],
        ]);

        $this->get(route('module', ['module' => 'sales', 'section' => 'add', 'from_invoice' => $invoice->id]))
            ->assertRedirect(route('pos', ['source_invoice' => $invoice->id]));

        $this->get(route('pos', ['source_invoice' => $invoice->id]))
            ->assertRedirect(route('module', ['module' => 'invoices', 'section' => 'invoices', 'invoice' => $invoice->id]))
            ->assertSessionHasErrors('invoice');

        $this->post(route('sales.store'), [
            '_idempotency_key' => 'sale-from-draft-invoice',
            'source_invoice_id' => $invoice->id,
            'sale_status' => 'paid',
            'cash_amount' => (float) $item->sale_price,
            'items' => [
                ['item_id' => $item->id, 'quantity' => 1, 'unit_price' => (float) $item->sale_price, 'discount_amount' => 0, 'tax_rate' => 0],
            ],
        ])->assertSessionHasErrors('sale');

        $this->assertSame(0, Sale::where('source_invoice_id', $invoice->id)->count());
    }
}
