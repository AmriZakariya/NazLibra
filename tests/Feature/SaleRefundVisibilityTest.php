<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Location;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A partial refund from the app showed as « payé » on the web, with no trace
 * of it in the ticket or its PDF: the two sides spelled the status
 * differently, and nothing showed returns. One spelling now, and the refunds
 * are shown wherever the sale is.
 */
class SaleRefundVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Location $location;
    private Item $sapin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->tenant = Tenant::first();
        $this->user = User::first();
        $this->location = Location::where('tenant_id', $this->tenant->id)->firstOrFail();
        $this->sapin = Item::create([
            'tenant_id' => $this->tenant->id, 'title' => 'Sapin en resine epoxy', 'type' => 'service',
            'status' => 'active', 'sale_price' => 7, 'purchase_price' => 0, 'stock_quantity' => 0,
        ]);
    }

    private function token(): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $this->user->email, 'password' => 'password', 'device_name' => 'till-'.Str::uuid(),
        ])->json('token');
    }

    /** Four sapins at 7 € from the app, then two of them refunded from the app. */
    private function appSaleWithPartialRefund(): Sale
    {
        $token = $this->token();
        $saleId = $this->withToken($token)->postJson('/api/v1/pos/sales', [
            'idempotency_key' => (string) Str::uuid(),
            'location_id' => $this->location->id,
            'items' => [['item_id' => $this->sapin->id, 'quantity' => 4]],
            'payments' => ['cash' => 28, 'card' => 0, 'transfer' => 0, 'advance' => 0],
        ])->assertCreated()->json('sale.id');
        $lineId = Sale::findOrFail($saleId)->items()->value('id');

        $this->withToken($token)->postJson("/api/v1/pos/sales/{$saleId}/returns", [
            'idempotency_key' => (string) Str::uuid(),
            'refund_method' => 'cash',
            'refund_reason' => 'Client a changé d’avis',
            'return_lines' => [['sale_item_id' => $lineId, 'quantity' => 2, 'stock_action' => 'restock']],
        ])->assertCreated();

        return Sale::findOrFail($saleId);
    }

    private function detail(Sale $sale): string
    {
        return $this->actingAs($this->user)
            ->get(route('module', ['module' => 'sales', 'section' => 'list', 'detail_sale' => $sale->id]))
            ->assertOk()->getContent();
    }

    public function test_an_app_partial_refund_shows_on_the_web_ticket(): void
    {
        $sale = $this->appSaleWithPartialRefund();
        $this->assertSame('partial_refund', $sale->status);
        $this->assertSame(14.0, $sale->refundedAmount());
        $this->assertSame(14.0, $sale->netAmount());

        $page = $this->detail($sale);
        $this->assertStringContainsString('Retour partiel', $page);
        $this->assertStringContainsString('data-sale-returns', $page);
        $this->assertStringContainsString('Sapin en resine epoxy × 2', $page);
        $this->assertStringContainsString('Client a changé d’avis', html_entity_decode($page));
        $this->assertMatchesRegularExpression('/data-sale-refunded>.*?14,00/s', $page);
        $this->assertMatchesRegularExpression('/data-sale-net>.*?14,00/s', $page);
    }

    public function test_the_sale_pdf_lists_the_refund_and_the_net(): void
    {
        $sale = $this->appSaleWithPartialRefund();
        $captured = null;
        View::creator('librairepro.pdf.document', function ($view) use (&$captured) {
            $captured = $view->getData()['document'];
        });

        $this->actingAs($this->user)->get(route('sales.pdf', $sale))->assertOk();

        $this->assertSame('Remboursement partiel', $captured['status_label']);
        $this->assertSame(14.0, $captured['totals']['refunded']);
        $this->assertSame(14.0, $captured['totals']['net']);
        $this->assertSame([['name' => 'Sapin en resine epoxy', 'quantity' => 2]], $captured['returns'][0]['lines']);
        $this->assertSame('Espèces', $captured['returns'][0]['method']);
    }

    public function test_a_web_partial_refund_can_be_finished_from_the_app(): void
    {
        $token = $this->token();
        $saleId = $this->withToken($token)->postJson('/api/v1/pos/sales', [
            'idempotency_key' => (string) Str::uuid(),
            'location_id' => $this->location->id,
            'items' => [['item_id' => $this->sapin->id, 'quantity' => 4]],
            'payments' => ['cash' => 28, 'card' => 0, 'transfer' => 0, 'advance' => 0],
        ])->json('sale.id');
        $lineId = Sale::findOrFail($saleId)->items()->value('id');

        $this->actingAs($this->user)->post(route('sales.refund', $saleId), [
            'refund_method' => 'cash', 'refund_reason' => 'Un abîmé',
            'return_lines' => [['sale_item_id' => $lineId, 'quantity' => 1, 'stock_action' => 'restock']],
        ])->assertSessionHasNoErrors();
        $this->assertSame('partial_refund', Sale::findOrFail($saleId)->status, 'the spelling the app understands');

        // The till, as itself: not the back office's signed-in user.
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->postJson("/api/v1/pos/sales/{$saleId}/returns", [
            'idempotency_key' => (string) Str::uuid(), 'refund_method' => 'cash',
        ])->assertCreated()->assertJsonPath('return.lines.0.quantity', 3);
        $this->assertSame('refunded', Sale::findOrFail($saleId)->status);
    }

    public function test_every_till_gets_the_refunds_with_the_sale(): void
    {
        $sale = $this->appSaleWithPartialRefund();
        $this->travel(2)->seconds();

        $synced = collect($this->withToken($this->token())
            ->withHeader('X-Location-Id', (string) $this->location->id)
            ->getJson('/api/v1/sync/sales')->assertOk()->json('sales'))
            ->firstWhere('id', $sale->id);

        $this->assertSame('partial_refund', $synced['status']);
        $this->assertSame(14, (int) $synced['refunded_amount']);
        $this->assertCount(1, $synced['returns']);
        $this->assertSame(2, $synced['returns'][0]['lines'][0]['quantity']);
        $this->assertSame('cash', $synced['returns'][0]['refund_method']);
        $this->assertStringEndsWith('Z', $synced['returns'][0]['returned_at']);
    }

    public function test_sales_spelled_the_old_way_are_converted(): void
    {
        $sale = $this->appSaleWithPartialRefund();
        $sale->forceFill(['status' => 'partially_refunded'])->save();

        (require database_path('migrations/2026_11_09_000003_one_partial_refund_status.php'))->up();

        $this->assertSame('partial_refund', $sale->fresh()->status);
        $this->assertSame(1, SaleReturn::count());
    }
}
