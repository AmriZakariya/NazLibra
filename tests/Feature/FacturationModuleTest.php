<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Tenant;
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
}
