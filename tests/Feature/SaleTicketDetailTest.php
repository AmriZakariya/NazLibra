<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Location;
use App\Models\Sale;
use App\Models\SaleInvoice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The ticket detail said « Facture créée par : Utilisateur inconnu » for an
 * invoice made from the app, and put the sale an hour off: its header, note
 * and printed ticket read the stored UTC time instead of the shop's clock.
 */
class SaleTicketDetailTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->tenant = Tenant::first();
        $this->tenant->update(['timezone' => 'Europe/Paris']);
        $this->user = User::first();
    }

    private function sale(array $attributes = []): Sale
    {
        return Sale::create(array_merge([
            'tenant_id' => $this->tenant->id, 'number' => 'BL-03-000001', 'status' => 'paid',
            'payment_method' => 'cash', 'total_amount' => 4, 'sold_at' => Carbon::parse('2026-11-08 11:19:00', 'UTC'),
        ], $attributes));
    }

    private function detail(Sale $sale): string
    {
        return $this->actingAs($this->user)
            ->get(route('module', ['module' => 'sales', 'section' => 'list', 'detail_sale' => $sale->id]))
            ->assertOk()->getContent();
    }

    public function test_an_invoice_generated_from_the_app_says_who_made_it(): void
    {
        $item = Item::create([
            'tenant_id' => $this->tenant->id, 'title' => 'Coupe', 'type' => 'service',
            'status' => 'active', 'sale_price' => 1, 'purchase_price' => 0, 'stock_quantity' => 10,
        ]);
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $this->user->email, 'password' => 'password', 'device_name' => 'till-'.Str::uuid(),
        ])->json('token');
        $saleId = $this->withToken($token)->postJson('/api/v1/pos/sales', [
            'idempotency_key' => (string) Str::uuid(),
            'location_id' => Location::where('tenant_id', $this->tenant->id)->value('id'),
            'items' => [['item_id' => $item->id, 'quantity' => 4]],
            'payments' => ['cash' => 4, 'card' => 0, 'transfer' => 0, 'advance' => 0],
        ])->assertCreated()->json('sale.id');

        $this->withToken($token)->postJson("/api/v1/sales/{$saleId}/invoice")->assertSuccessful();

        $invoice = SaleInvoice::where('sale_id', $saleId)->sole();
        $this->assertSame($this->user->id, $invoice->user_id);
        $this->assertSame($this->user->name, $invoice->metadata['created_by_name']);
        $this->assertSame('app', $invoice->metadata['source']);

        // Generated again: the creator stays, the update is recorded.
        $this->withToken($token)->postJson("/api/v1/sales/{$saleId}/invoice")->assertSuccessful();
        $invoice->refresh();
        $this->assertSame($this->user->name, $invoice->metadata['created_by_name']);
        $this->assertSame($this->user->name, $invoice->metadata['updated_by_name']);

        $page = $this->detail(Sale::findOrFail($saleId));
        $this->assertMatchesRegularExpression('/Facture créée par<\/span><strong>'.preg_quote(e($this->user->name), '/').'<\/strong>/', $page);
    }

    public function test_an_older_app_invoice_names_the_sales_cashier_instead_of_unknown(): void
    {
        $sale = $this->sale(['actor_name_snapshot' => 'Ahmed']);
        SaleInvoice::create([
            'tenant_id' => $this->tenant->id, 'sale_id' => $sale->id, 'number' => 'FAC00001',
            'status' => 'issued', 'issued_at' => now(), 'total_amount' => 4,
        ]);

        $page = $this->detail($sale);

        $this->assertStringContainsString('Facture créée par</span><strong>Ahmed (vendeur)</strong>', $page);
        $this->assertStringNotContainsString('Facture créée par</span><strong>Utilisateur inconnu', $page);
    }

    public function test_the_sale_reads_the_shops_clock_everywhere_in_its_detail(): void
    {
        // 11:19 UTC is 12:19 in Paris in November.
        $page = $this->detail($this->sale());

        $this->assertStringContainsString('08/11/2026 12:19', $page);
        $this->assertStringNotContainsString('08/11/2026 11:19', $page, 'header, note, list and printed ticket alike');
    }
}
