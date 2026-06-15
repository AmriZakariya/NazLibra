<?php

namespace Tests\Feature;

use App\Models\DocumentStatusHistory;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\Tenant;
use App\Services\Documents\EstimateService;
use App\Services\Documents\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CommercialDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_creation_uses_snapshots_decimal_calculation_and_unique_numbering(): void
    {
        $this->seed();
        $tenant = Tenant::firstOrFail();
        $item = Item::where('tenant_id', $tenant->id)->firstOrFail();

        $invoice = app(InvoiceService::class)->create($tenant, [
            'customer_name' => 'Client test',
            'issue_date' => '2026-06-15',
            'due_date' => '2026-06-30',
            'document_discount_type' => 'fixed',
            'document_discount_value' => '5.00',
            'lines' => [
                [
                    'item_id' => $item->id,
                    'quantity' => 2,
                    'unit_price' => '100.00',
                    'discount_type' => 'percentage',
                    'discount_value' => '10',
                    'tax_rate' => '20',
                    'tax_inclusive' => false,
                ],
            ],
        ]);

        $this->assertStringStartsWith('FAC', $invoice->number);
        $this->assertSame('draft', $invoice->status);
        $this->assertSame('175.00', (string) $invoice->subtotal);
        $this->assertSame('35.00', (string) $invoice->tax_total);
        $this->assertSame('210.00', (string) $invoice->total);
        $this->assertSame('210.00', (string) $invoice->balance_due);
        $this->assertSame($item->title, $invoice->items->first()->item_snapshot['title']);

        $second = app(InvoiceService::class)->create($tenant, [
            'issue_date' => '2026-06-15',
            'lines' => [['name' => 'Ligne libre', 'quantity' => 1, 'unit_price' => '1.00']],
        ]);
        $this->assertNotSame($invoice->number, $second->number);
        $this->assertDatabaseHas('document_status_histories', [
            'document_type' => 'invoice',
            'document_id' => $invoice->id,
            'action' => 'created',
        ]);
    }

    public function test_tax_inclusive_invoice_extracts_tax_instead_of_adding_it(): void
    {
        $this->seed();
        $tenant = Tenant::firstOrFail();

        $invoice = app(InvoiceService::class)->create($tenant, [
            'issue_date' => '2026-06-15',
            'lines' => [
                ['name' => 'Prix TTC', 'quantity' => 1, 'unit_price' => '120.00', 'tax_rate' => '20', 'tax_inclusive' => true],
            ],
        ]);

        $this->assertSame('100.00', (string) $invoice->subtotal);
        $this->assertSame('20.00', (string) $invoice->tax_total);
        $this->assertSame('120.00', (string) $invoice->total);
    }

    public function test_invoice_payments_are_validated_and_update_status(): void
    {
        $this->seed();
        $tenant = Tenant::firstOrFail();
        $service = app(InvoiceService::class);
        $invoice = $service->create($tenant, [
            'status' => 'sent',
            'issue_date' => '2026-06-15',
            'lines' => [['name' => 'Service', 'quantity' => 1, 'unit_price' => '100.00']],
        ]);

        $service->recordPayment($invoice, ['amount' => '40.00', 'method' => 'cash', 'idempotency_key' => 'pay-1']);
        $invoice->refresh();
        $this->assertSame('partially_paid', $invoice->status);
        $this->assertSame('60.00', (string) $invoice->balance_due);

        $same = $service->recordPayment($invoice, ['amount' => '40.00', 'method' => 'cash', 'idempotency_key' => 'pay-1']);
        $this->assertSame('IPAY00001', $same->number);
        $this->assertSame(1, $invoice->payments()->count());

        $this->expectException(ValidationException::class);
        $service->recordPayment($invoice->fresh(), ['amount' => '61.00', 'method' => 'cash']);
    }

    public function test_paid_invoice_cannot_be_edited_or_cancelled(): void
    {
        $this->seed();
        $tenant = Tenant::firstOrFail();
        $service = app(InvoiceService::class);
        $invoice = $service->create($tenant, [
            'status' => 'sent',
            'issue_date' => '2026-06-15',
            'lines' => [['name' => 'Service', 'quantity' => 1, 'unit_price' => '50.00']],
        ]);
        $service->recordPayment($invoice, ['amount' => '50.00', 'method' => 'cash']);
        $this->assertSame('paid', $invoice->fresh()->status);

        try {
            $service->update($invoice->fresh(), [
                'issue_date' => '2026-06-15',
                'lines' => [['name' => 'Service', 'quantity' => 1, 'unit_price' => '60.00']],
            ]);
            $this->fail('Paid invoice edit should fail.');
        } catch (ValidationException $exception) {
            $this->assertTrue($exception->validator->errors()->has('invoice'));
        }

        $this->expectException(ValidationException::class);
        $service->cancel($invoice->fresh(), 'Erreur');
    }

    public function test_estimate_conversion_creates_invoice_without_stock_movement_and_is_idempotent(): void
    {
        $this->seed();
        $tenant = Tenant::firstOrFail();
        $item = Item::where('tenant_id', $tenant->id)->where('type', '!=', 'service')->firstOrFail();
        $stock = $item->stock_quantity;
        $service = app(EstimateService::class);

        $estimate = $service->create($tenant, [
            'status' => 'accepted',
            'issue_date' => '2026-06-15',
            'expiration_date' => '2026-06-30',
            'lines' => [['item_id' => $item->id, 'quantity' => 2, 'unit_price' => '20.00']],
        ]);

        $invoice = $service->convertToInvoice($estimate, ['due_date' => '2026-07-15']);
        $again = $service->convertToInvoice($estimate->fresh(), ['due_date' => '2026-07-15']);

        $this->assertSame($invoice->id, $again->id);
        $this->assertSame('converted', $estimate->fresh()->status);
        $this->assertSame($invoice->id, $estimate->fresh()->converted_invoice_id);
        $this->assertSame($stock, $item->fresh()->stock_quantity);
        $this->assertSame(0, DB::table('stock_movements')->where('reference_type', Estimate::class)->count());
        $this->assertSame(1, Invoice::where('source_estimate_id', $estimate->id)->count());
    }

    public function test_estimate_duplicate_resets_conversion_and_payment_context(): void
    {
        $this->seed();
        $tenant = Tenant::firstOrFail();
        $service = app(EstimateService::class);
        $estimate = $service->create($tenant, [
            'status' => 'sent',
            'issue_date' => '2026-06-15',
            'lines' => [['name' => 'Prestation', 'quantity' => 1, 'unit_price' => '200.00']],
        ]);

        $copy = $service->duplicate($estimate);

        $this->assertNotSame($estimate->number, $copy->number);
        $this->assertSame('draft', $copy->status);
        $this->assertSame($estimate->id, $copy->duplicated_from_id);
        $this->assertNull($copy->converted_invoice_id);
        $this->assertSame('200.00', (string) $copy->total);
    }

    public function test_document_activity_history_is_written_for_material_actions(): void
    {
        $this->seed();
        $tenant = Tenant::firstOrFail();
        $service = app(InvoiceService::class);
        $invoice = $service->create($tenant, [
            'status' => 'draft',
            'issue_date' => '2026-06-15',
            'lines' => [['name' => 'Service', 'quantity' => 1, 'unit_price' => '80.00']],
        ]);
        $service->send($invoice);

        $actions = DocumentStatusHistory::where('document_type', 'invoice')
            ->where('document_id', $invoice->id)
            ->pluck('action')
            ->all();

        $this->assertContains('created', $actions);
        $this->assertContains('sent', $actions);
    }
}
