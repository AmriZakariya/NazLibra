<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Location;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Inventory\InventoryLedgerService;
use App\Services\Inventory\InventoryMovementType;
use App\Support\BusinessMode;
use App\Support\ItemTypes;
use App\Support\StaffReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A barber shop: the owner cutting alone, or a manager with barbers on
 * commission. The till names who did each cut, takes a tip for them, and
 * the Équipe report says what each one is owed.
 */
class BarberShopTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Location $location;
    private User $owner;
    private User $barber;
    private Item $cut;
    private Item $wax;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->tenant = Tenant::first();
        $this->location = Location::where('tenant_id', $this->tenant->id)->first();
        $this->owner = User::first();

        $this->barber = User::create([
            'name' => 'Barbier Test', 'email' => 'barbier@example.test',
            'password' => Hash::make('password'), 'commission_rate' => 40,
        ]);
        $this->tenant->users()->attach($this->barber->id, ['role' => 'cashier']);

        $this->cut = $this->item('Coupe homme', 'service', 60);
        $this->wax = $this->item('Cire coiffante', 'supply', 40);
        app(InventoryLedgerService::class)->createIncomingMovement([
            'tenantId' => $this->tenant->id, 'itemId' => $this->wax->id, 'variantId' => null,
            'locationId' => $this->location->id, 'type' => InventoryMovementType::INITIAL_STOCK,
            'quantity' => 10, 'unitCost' => 15.0, 'occurredAt' => now()->subMinutes(5),
            'syncedAt' => null, 'userId' => null, 'idempotencyKey' => 'setup-wax',
            'referenceType' => null, 'referenceId' => null, 'referenceNumber' => null,
            'reason' => null, 'note' => null, 'virtualDeviceId' => null,
            'actorNameSnapshot' => null, 'terminalNameSnapshot' => null,
        ]);
    }

    private function item(string $title, string $type, float $price): Item
    {
        return Item::create([
            'tenant_id' => $this->tenant->id, 'title' => $title, 'type' => $type,
            'status' => 'active', 'sale_price' => $price, 'purchase_price' => 0,
            'stock_quantity' => 0,
        ]);
    }

    private function token(): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $this->owner->email,
            'password' => 'password',
            'device_name' => 'till-'.Str::uuid(),
        ])->json('token');
    }

    /** @param array<int, array<string, mixed>> $items */
    private function sell(array $items, float $cash, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->withToken($this->token())->postJson('/api/v1/pos/sales', [
            'idempotency_key' => Str::uuid()->toString(),
            'location_id' => $this->location->id,
            'items' => $items,
            'payments' => ['cash' => $cash, 'card' => 0, 'transfer' => 0, 'advance' => 0],
        ] + $extra);
    }

    public function test_a_barber_shop_is_an_activity_of_its_own(): void
    {
        $this->assertSame('barber', BusinessMode::normalize('barbier'));
        $this->assertSame('barber', ItemTypes::activityFromBusinessMode('barber'));
        $this->assertSame(['supply', 'service'], ItemTypes::validTypes('barber'));
    }

    public function test_the_till_records_who_did_the_cut_and_their_tip(): void
    {
        $sale = $this->sell([
            ['item_id' => $this->cut->id, 'quantity' => 1, 'staff_user_id' => $this->barber->id],
        ], 80, ['tip' => 15])->assertCreated()->json('sale');

        $this->assertSame(60.0, (float) $sale['total_amount'], 'a tip is not revenue');
        $this->assertSame(15.0, (float) $sale['tip_amount']);
        $this->assertSame($this->barber->id, $sale['tip_user_id'], 'the tip goes to who did the cut');
        $this->assertSame(5.0, (float) data_get($sale, 'metadata.change_amount'), 'the tip is not given back as change');
        $this->assertSame($this->barber->id, $sale['items'][0]['staff_user_id']);
    }

    public function test_a_services_only_sale_answers_stock_after_as_an_object(): void
    {
        // An empty collection encodes as []; the till read it as a map and
        // re-sent the haircut forever.
        $body = $this->sell([['item_id' => $this->cut->id, 'quantity' => 1]], 60)
            ->assertCreated()->getContent();

        $this->assertStringContainsString('"stock_after":{}', $body);
    }

    public function test_a_tip_must_be_paid_too(): void
    {
        $this->sell([['item_id' => $this->cut->id, 'quantity' => 1]], 60, ['tip' => 10])
            ->assertStatus(422)->assertJsonPath('error', 'underpayment');
    }

    public function test_someone_from_another_shop_is_not_credited(): void
    {
        $stranger = User::create(['name' => 'Ailleurs', 'email' => 'x@example.test', 'password' => Hash::make('x')]);

        $sale = $this->sell([
            ['item_id' => $this->cut->id, 'quantity' => 1, 'staff_user_id' => $stranger->id],
        ], 60)->assertCreated()->json('sale');

        $this->assertNull($sale['items'][0]['staff_user_id']);
    }

    public function test_the_team_report_pays_commission_on_what_was_paid_and_tips_apart(): void
    {
        $this->sell([
            ['item_id' => $this->cut->id, 'quantity' => 2, 'staff_user_id' => $this->barber->id],
            ['item_id' => $this->wax->id, 'quantity' => 1],
        ], 200, ['tip' => 20])->assertCreated();

        $sales = Sale::with('items.item')->where('tenant_id', $this->tenant->id)->get();
        $rows = StaffReport::rows($sales)->keyBy('user_id');

        $barber = $rows[$this->barber->id];
        $this->assertSame(2, $barber['services']);
        $this->assertSame(120.0, $barber['service_revenue']);
        $this->assertSame(48.0, $barber['commission'], '40 % of 120, the tip excluded');
        $this->assertSame(20.0, $barber['tips']);

        // The line nobody was named on is the cashier's: the owner who sold it.
        $this->assertSame(40.0, $rows[$this->owner->id]['product_revenue']);
    }

    public function test_the_till_is_told_whether_to_ask_and_who_can_be_named(): void
    {
        $settings = $this->tenant->settings ?? [];
        $settings['company_profile']['business_mode'] = 'barber';
        $this->tenant->update(['settings' => $settings]);

        $json = $this->withToken($this->token())->getJson('/api/v1/sync/settings')->assertOk()->json();

        $this->assertTrue($json['ask_staff']);
        $this->assertContains('Barbier Test', array_column($json['staff'], 'name'));
    }

    public function test_the_team_report_page_renders(): void
    {
        $this->sell([['item_id' => $this->cut->id, 'quantity' => 1, 'staff_user_id' => $this->barber->id]], 60)
            ->assertCreated();

        $this->actingAs($this->owner)
            ->get(route('module', ['module' => 'reports', 'section' => 'team']))
            ->assertOk()
            ->assertSee('Barbier Test')
            ->assertSee('Pourboires');
    }
}
