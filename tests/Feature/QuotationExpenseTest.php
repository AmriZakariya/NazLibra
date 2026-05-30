<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\CustomerAdvance;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Item;
use App\Models\Quotation;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuotationExpenseTest extends TestCase
{
    use RefreshDatabase;

    public function test_quotation_sections_render_and_create_quote(): void
    {
        $this->seed();

        $this->get(route('module', ['module' => 'sales', 'section' => 'quote-add']))
            ->assertOk()
            ->assertSee('Nouveau devis')
            ->assertSee('Enregistrer le devis');

        $item = Item::where('status', 'active')->firstOrFail();
        $client = Contact::where('kind', 'client')->firstOrFail();

        $response = $this->post(route('quotations.store'), [
            'contact_id' => $client->id,
            'quoted_at' => now()->format('Y-m-d H:i:s'),
            'expires_at' => now()->addDays(10)->toDateString(),
            'status' => 'sent',
            'reference' => 'ECOLE-TEST',
            'discount_amount' => 1,
            'items' => [
                ['item_id' => $item->id, 'quantity' => 2, 'unit_price' => 20],
            ],
        ]);

        $quote = Quotation::firstOrFail();
        $response->assertRedirect(route('module', ['module' => 'sales', 'section' => 'quotes']));
        $this->assertStringStartsWith('DEV', $quote->number);
        $this->assertSame('sent', $quote->status);
        $this->assertSame('39.00', number_format((float) $quote->total_amount, 2, '.', ''));

        $this->get(route('module', ['module' => 'sales', 'section' => 'quotes', 'q' => 'ECOLE-TEST']))
            ->assertOk()
            ->assertSee($quote->number)
            ->assertSee('ECOLE-TEST');
    }

    public function test_quotation_can_be_converted_to_unpaid_sale(): void
    {
        $this->seed();

        $item = Item::where('type', '!=', 'service')->where('stock_quantity', '>', 2)->firstOrFail();
        $initialStock = $item->stock_quantity;

        $this->post(route('quotations.store'), [
            'client_name' => 'Client devis test',
            'status' => 'draft',
            'items' => [
                ['item_id' => $item->id, 'quantity' => 2, 'unit_price' => 15],
            ],
        ])->assertRedirect();

        $quote = Quotation::firstOrFail();
        $this->post(route('quotations.convert', $quote))->assertRedirect(route('module', ['module' => 'sales', 'section' => 'list']));

        $sale = Sale::orderByDesc('id')->firstOrFail();
        $this->assertSame('unpaid', $sale->status);
        $this->assertSame($quote->fresh()->converted_sale_id, $sale->id);
        $this->assertSame($initialStock - 2, $item->fresh()->stock_quantity);
    }

    public function test_expense_sections_render_and_store_expense(): void
    {
        $this->seed();

        $this->get(route('module', ['module' => 'finance', 'section' => 'expense-add']))
            ->assertOk()
            ->assertSee('Ajouter une dépense');

        $categoryResponse = $this->post(route('expenses.categories.store'), [
            'name' => 'Transport test',
            'color' => '#0EA5E9',
            'description' => 'Frais de livraison et déplacement',
        ]);

        $categoryResponse->assertRedirect(route('module', ['module' => 'finance', 'section' => 'expense-categories']));
        $this->assertDatabaseHas('expense_categories', ['name' => 'Transport test']);

        $response = $this->post(route('expenses.store'), [
            'label' => 'Taxi fournisseur test',
            'category' => 'Transport test',
            'amount' => 42.50,
            'spent_at' => now()->toDateString(),
            'payment_method' => 'cash',
            'reference' => 'REC-42',
            'note' => 'Déplacement marché',
        ]);

        $expense = Expense::orderByDesc('id')->firstOrFail();
        $response->assertRedirect(route('module', ['module' => 'finance', 'section' => 'expenses']));
        $this->assertStringStartsWith('DEP', (string) $expense->number);
        $this->assertSame('Transport test', $expense->category);

        $this->get(route('module', ['module' => 'finance', 'section' => 'expenses', 'q' => 'REC-42']))
            ->assertOk()
            ->assertSee('Taxi fournisseur test')
            ->assertSee('REC-42');

        $this->assertSame(1, ExpenseCategory::where('name', 'Transport test')->count());
    }

    public function test_customer_advance_section_stores_lists_and_voids_advance(): void
    {
        $this->seed();

        $client = Contact::where('kind', 'client')->firstOrFail();
        $initialBalance = (float) $client->advance_balance;

        $this->get(route('module', ['module' => 'finance', 'section' => 'advance-add']))
            ->assertOk()
            ->assertSee('Nouvelle avance client')
            ->assertSee("Enregistrer l'avance", false);

        $response = $this->post(route('customer-advances.store'), [
            'contact_id' => $client->id,
            'amount' => 120,
            'payment_method' => 'cash',
            'paid_at' => now()->format('Y-m-d H:i:s'),
            'reference' => 'AV-REF-120',
            'note' => 'Avance rentrée scolaire',
        ]);

        $advance = CustomerAdvance::firstOrFail();
        $response->assertRedirect(route('module', ['module' => 'finance', 'section' => 'advances']));
        $this->assertStringStartsWith('AVC', $advance->number);
        $this->assertSame($initialBalance + 120, (float) $client->fresh()->advance_balance);

        $this->get(route('module', ['module' => 'finance', 'section' => 'advances']))
            ->assertOk()
            ->assertSee('Liste des paiements anticipés')
            ->assertSee('Solde avances client');

        $json = $this->getJson(route('customer-advances.data', ['search' => ['value' => 'AV-REF-120']]))
            ->assertOk()
            ->json();

        $this->assertSame(1, $json['recordsFiltered']);
        $this->assertStringContainsString($advance->number, $json['data'][0]['number']);
        $this->assertStringContainsString('120,00', $json['data'][0]['amount']);

        $this->delete(route('customer-advances.destroy', $advance))->assertRedirect();

        $this->assertSame('voided', $advance->fresh()->status);
        $this->assertSame($initialBalance, (float) $client->fresh()->advance_balance);
    }
}
