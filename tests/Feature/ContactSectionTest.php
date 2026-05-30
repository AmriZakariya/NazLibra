<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Item;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactSectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_sections_render_with_form_and_datatable(): void
    {
        $this->seed();

        $this->get(route('module', ['module' => 'contacts', 'section' => 'customer-add']))
            ->assertOk()
            ->assertSee('Ajouter un client')
            ->assertSee('Limite crédit')
            ->assertSee('Adresse livraison');

        $this->get(route('module', ['module' => 'contacts', 'section' => 'customers']))
            ->assertOk()
            ->assertSee('Liste des clients')
            ->assertSee('data-contact-table', false);
    }

    public function test_customer_can_be_created_updated_listed_and_deleted(): void
    {
        $this->seed();

        $create = $this->post(route('contacts.store'), [
            'kind' => 'client',
            'code' => 'CL-TEST-001',
            'name' => 'Client CRM Test',
            'client_type' => 'school',
            'status' => 'active',
            'phone' => '0600000000',
            'email' => 'client.crm@example.test',
            'ice' => 'ICE-123',
            'tax_number' => 'TAX-123',
            'credit_limit' => 1000,
            'opening_balance' => 50,
            'advance_balance' => 20,
            'outstanding_balance' => 75,
            'fine_balance' => 0,
            'country' => 'Maroc',
            'city' => 'Rabat',
            'address' => 'Rue test',
            'copy_address' => 1,
            'price_level_type' => 'increase',
            'price_level' => 5,
            'tags' => 'VIP, école',
        ]);

        $contact = Contact::where('code', 'CL-TEST-001')->firstOrFail();
        $create->assertRedirect(route('module', ['module' => 'contacts', 'section' => 'customers']));
        $this->assertSame('school', $contact->client_type);
        $this->assertSame('Rue test', $contact->shipping_address);

        $this->getJson(route('contacts.data', ['kind' => 'client', 'search' => ['value' => 'CRM Test']]))
            ->assertOk()
            ->assertJsonPath('recordsFiltered', 1);

        $update = $this->put(route('contacts.update', $contact), [
            'kind' => 'client',
            'code' => 'CL-TEST-001',
            'name' => 'Client CRM Test Updated',
            'client_type' => 'company',
            'status' => 'archived',
            'phone' => '0611111111',
            'email' => 'client.crm@example.test',
            'credit_limit' => 2000,
            'opening_balance' => 0,
            'advance_balance' => 0,
            'outstanding_balance' => 0,
            'fine_balance' => 0,
            'price_level_type' => 'decrease',
            'price_level' => 3,
        ]);

        $update->assertRedirect(route('module', ['module' => 'contacts', 'section' => 'customers']));
        $this->assertSame('archived', $contact->fresh()->status);

        $this->delete(route('contacts.destroy', $contact))->assertRedirect();
        $this->assertDatabaseMissing('contacts', ['id' => $contact->id]);
    }

    public function test_supplier_section_uses_supplier_fields_and_financial_columns(): void
    {
        $this->seed();

        $this->get(route('module', ['module' => 'contacts', 'section' => 'supplier-add']))
            ->assertOk()
            ->assertSee('Ajouter un fournisseur')
            ->assertSee('Solde précédent')
            ->assertSee("La fiche fournisseur reprend les champs", false);

        $response = $this->post(route('contacts.store'), [
            'kind' => 'supplier',
            'code' => 'FR-TEST-001',
            'name' => 'Fournisseur CRM Test',
            'client_type' => 'company',
            'status' => 'active',
            'phone' => '0522000000',
            'email' => 'supplier.crm@example.test',
            'ice' => 'ICE-FR-123',
            'tax_number' => 'TAX-FR-123',
            'credit_limit' => 0,
            'opening_balance' => 150,
            'advance_balance' => 0,
            'outstanding_balance' => 0,
            'fine_balance' => 0,
            'country' => 'Maroc',
            'city' => 'Casablanca',
            'address' => 'Zone fournisseur',
            'price_level_type' => 'increase',
            'price_level' => 0,
        ]);

        $supplier = Contact::where('code', 'FR-TEST-001')->firstOrFail();
        $response->assertRedirect(route('module', ['module' => 'contacts', 'section' => 'suppliers']));

        $item = Item::where('type', '!=', 'service')->firstOrFail();
        $purchase = Purchase::create([
            'tenant_id' => $supplier->tenant_id,
            'supplier_id' => $supplier->id,
            'number' => 'ACH-SUP-TEST',
            'status' => 'received',
            'total_amount' => 300,
            'ordered_at' => now()->toDateString(),
            'received_at' => now()->toDateString(),
        ]);
        $purchase->items()->create([
            'item_id' => $item->id,
            'quantity_ordered' => 3,
            'quantity_received' => 3,
            'unit_cost' => 100,
        ]);
        PurchaseReturn::create([
            'tenant_id' => $supplier->tenant_id,
            'purchase_id' => $purchase->id,
            'supplier_id' => $supplier->id,
            'number' => 'RAC-SUP-TEST',
            'status' => 'completed',
            'total_amount' => 80,
            'returned_at' => now(),
            'reason' => 'Retour test',
            'lines' => [],
        ]);

        $this->get(route('module', ['module' => 'contacts', 'section' => 'suppliers']))
            ->assertOk()
            ->assertSee('Liste des fournisseurs')
            ->assertSee('Achat dû')
            ->assertSee("Retour d'achat dû", false);

        $json = $this->getJson(route('contacts.data', ['kind' => 'supplier', 'search' => ['value' => 'Fournisseur CRM Test']]))
            ->assertOk()
            ->json();

        $this->assertSame(1, $json['recordsFiltered']);
        $this->assertStringContainsString('300,00', $json['data'][0]['purchase_due']);
        $this->assertStringContainsString('80,00', $json['data'][0]['purchase_return_due']);
        $this->assertStringContainsString('370,00', $json['data'][0]['supplier_total']);
    }
}
