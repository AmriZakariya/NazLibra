<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Item;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_sections_render_add_list_and_returns(): void
    {
        $this->seed();

        $this->get(route('module', ['module' => 'purchases', 'section' => 'add']))
            ->assertOk()
            ->assertSee('Ajouter un achat')
            ->assertSee('Fournisseur')
            ->assertSee('Raccourcis achat')
            ->assertSee('purchase-supplier-dialog', false)
            ->assertSee('Ajouter article')
            ->assertSee("Enregistrer l'achat", false);

        $this->get(route('module', ['module' => 'purchases', 'section' => 'list']))
            ->assertOk()
            ->assertSee("Liste d'achat")
            ->assertSee('Afficher/recherche Achat')
            ->assertSee('Recevoir');

        $this->get(route('module', ['module' => 'purchases', 'section' => 'returns']))
            ->assertOk()
            ->assertSee("Liste des retours d'achat")
            ->assertSee('Créer un retour fournisseur');
    }

    public function test_purchase_can_be_created_and_received_into_stock(): void
    {
        $this->seed();

        $supplier = Contact::where('kind', 'supplier')->firstOrFail();
        $item = Item::where('type', '!=', 'service')->firstOrFail();
        $initialStock = $item->stock_quantity;

        $response = $this->post(route('purchases.store'), [
            'supplier_id' => $supplier->id,
            'ordered_at' => now()->toDateString(),
            'expected_at' => now()->addDays(3)->toDateString(),
            'supplier_invoice' => 'FA-TEST-001',
            'status' => 'ordered',
            'items' => [
                ['item_id' => $item->id, 'quantity' => 5, 'unit_cost' => 12.5],
            ],
        ]);

        $purchase = Purchase::orderByDesc('id')->firstOrFail();
        $response->assertRedirect(route('module', ['module' => 'purchases', 'section' => 'list']));
        $this->assertStringStartsWith('ACH', $purchase->number);
        $this->assertSame('ordered', $purchase->status);
        $this->assertSame($initialStock, $item->fresh()->stock_quantity);

        $this->post(route('purchases.receive', $purchase))->assertRedirect();

        $this->assertSame('received', $purchase->fresh()->status);
        $this->assertSame($initialStock + 5, $item->fresh()->stock_quantity);
        $this->assertSame(5, $purchase->items()->firstOrFail()->fresh()->quantity_received);
        $this->assertDatabaseHas('stock_movements', [
            'item_id' => $item->id,
            'type' => 'purchase',
            'quantity_delta' => 5,
            'quantity_after' => $initialStock + 5,
            'reference_type' => Purchase::class,
            'reference_id' => $purchase->id,
        ]);
    }

    public function test_purchase_return_decrements_stock_and_is_searchable(): void
    {
        $this->seed();

        $supplier = Contact::where('kind', 'supplier')->firstOrFail();
        $item = Item::where('type', '!=', 'service')->firstOrFail();
        $initialStock = $item->stock_quantity;

        $purchaseResponse = $this->post(route('purchases.store'), [
            'supplier_id' => $supplier->id,
            'ordered_at' => now()->toDateString(),
            'supplier_invoice' => 'FA-RET-001',
            'status' => 'received',
            'items' => [
                ['item_id' => $item->id, 'quantity' => 4, 'unit_cost' => 20],
            ],
        ]);

        $purchase = Purchase::orderByDesc('id')->firstOrFail();
        $purchaseResponse->assertRedirect();
        $this->assertSame($initialStock + 4, $item->fresh()->stock_quantity);

        $returnResponse = $this->post(route('purchases.returns.store'), [
            'purchase_id' => $purchase->id,
            'returned_at' => now()->toDateString(),
            'reason' => 'Articles abîmés test',
            'items' => [
                ['item_id' => $item->id, 'quantity' => 2, 'unit_cost' => 20],
            ],
        ]);

        $return = PurchaseReturn::firstOrFail();
        $returnResponse->assertRedirect(route('module', ['module' => 'purchases', 'section' => 'returns']));
        $this->assertStringStartsWith('RAC', $return->number);
        $this->assertSame($initialStock + 2, $item->fresh()->stock_quantity);
        $this->assertDatabaseHas('stock_movements', [
            'item_id' => $item->id,
            'type' => 'purchase_return',
            'quantity_delta' => -2,
            'quantity_after' => $initialStock + 2,
            'reference_type' => PurchaseReturn::class,
            'reference_id' => $return->id,
        ]);

        $this->get(route('module', ['module' => 'purchases', 'section' => 'returns', 'q' => 'abîmés']))
            ->assertOk()
            ->assertSee($return->number)
            ->assertSee('Articles abîmés test');
    }
}
