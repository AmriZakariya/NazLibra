<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\ItemLocationStock;
use App\Models\CashRegisterMovement;
use App\Models\Location;
use App\Models\Printer;
use App\Models\PrinterGroup;
use App\Models\PrinterGroupPrinter;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VirtualDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class ApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Tenant $tenant;
    private Location $location;
    private ?Item $item;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->user     = User::first();
        $this->tenant   = Tenant::first();
        $this->location = Location::where('tenant_id', $this->tenant->id)->first();

        // Pick any non-service active item (the seeder uses type book/supply).
        $candidate = Item::where('tenant_id', $this->tenant->id)
            ->where('status', 'active')
            ->whereNotIn('type', ['service'])
            ->first();

        if ($candidate) {
            $this->item = $candidate;
            $this->item->update(['stock_quantity' => 0, 'sale_price' => 80.00, 'purchase_price' => 50.00]);

            // Seed stock through the LIFO ledger so inventory_layers are populated
            // and availableQuantity() returns the correct value during tests.
            $ledger = app(\App\Services\Inventory\InventoryLedgerService::class);
            $ledger->createIncomingMovement([
                'tenantId'             => $this->tenant->id,
                'itemId'               => $this->item->id,
                'variantId'            => null,
                'locationId'           => $this->location->id,
                'type'                 => \App\Services\Inventory\InventoryMovementType::INITIAL_STOCK,
                'quantity'             => 100,
                'unitCost'             => 50.00,
                'occurredAt'           => now()->subMinutes(5),
                'syncedAt'             => null,
                'userId'               => null,
                'idempotencyKey'       => 'test-setup-'.$this->item->id,
                'referenceType'        => null,
                'referenceId'          => null,
                'referenceNumber'      => null,
                'reason'               => null,
                'note'                 => null,
                'virtualDeviceId'      => null,
                'actorNameSnapshot'    => null,
                'terminalNameSnapshot' => null,
            ]);
        } else {
            // @phpstan-ignore-next-line
            $this->item = null;
        }
    }

    // ── Auth ──────────────────────────────────────────────────────────────────

    public function test_login_returns_token(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email'       => $this->user->email,
            'password'    => 'password',
            'device_name' => 'test-device',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['ok', 'token', 'user', 'tenant', 'location'])
            ->assertJsonPath('ok', true);

        $this->token = $response->json('token');
    }

    public function test_login_with_wrong_password_fails(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email'       => $this->user->email,
            'password'    => 'wrong-password',
            'device_name' => 'test-device',
        ])->assertStatus(422);
    }

    public function test_me_returns_user_and_tenant(): void
    {
        $token = $this->apiToken();

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonStructure(['ok', 'user', 'tenant', 'abilities'])
            ->assertJsonPath('ok', true);
    }

    public function test_users_list_keeps_owner_wildcard_permissions(): void
    {
        Role::where('tenant_id', $this->tenant->id)
            ->where('key', 'owner')
            ->update(['permissions' => []]);

        $owner = $this->tenant->users()->wherePivot('role', 'owner')->firstOrFail();
        $token = $this->apiToken($owner);

        $response = $this->withToken($token)->getJson('/api/v1/users');

        $response->assertOk();
        $listedOwner = collect($response->json('users'))
            ->firstWhere('id', $owner->id);

        $this->assertSame('owner', data_get($listedOwner, 'role'));
        $this->assertSame(['*'], data_get($listedOwner, 'abilities'));
    }

    public function test_mobile_user_and_role_management_api_is_read_only(): void
    {
        $token = $this->apiToken();
        $role = $this->tenant->roles()->create([
            'name' => 'API read-only test',
            'key' => 'api_read_only_test',
            'permissions' => ['sales.view'],
            'is_system' => false,
        ]);

        $this->withToken($token)
            ->putJson('/api/v1/users/'.$this->user->id, ['role' => 'cashier'])
            ->assertStatus(405)
            ->assertJsonPath('error', 'mobile_user_management_read_only');

        $this->withToken($token)
            ->postJson('/api/v1/users/set-pin', [
                'pin' => '1234',
                'pin_confirmation' => '1234',
            ])
            ->assertStatus(405)
            ->assertJsonPath('error', 'mobile_user_management_read_only');

        $this->withToken($token)
            ->deleteJson('/api/v1/users/pin')
            ->assertStatus(405)
            ->assertJsonPath('error', 'mobile_user_management_read_only');

        $this->withToken($token)
            ->postJson('/api/v1/roles', [
                'name' => 'Mobile role',
                'key' => 'mobile_role',
                'permissions' => ['sales.view'],
            ])
            ->assertStatus(405)
            ->assertJsonPath('error', 'mobile_user_management_read_only');

        $this->withToken($token)
            ->putJson('/api/v1/roles/'.$role->id, ['name' => 'Changed'])
            ->assertStatus(405)
            ->assertJsonPath('error', 'mobile_user_management_read_only');

        $this->withToken($token)
            ->deleteJson('/api/v1/roles/'.$role->id)
            ->assertStatus(405)
            ->assertJsonPath('error', 'mobile_user_management_read_only');
    }

    public function test_printers_are_scoped_to_selected_virtual_device(): void
    {
        $token = $this->apiToken();
        $firstDevice = VirtualDevice::create([
            'tenant_id' => $this->tenant->id,
            'location_id' => $this->location->id,
            'name' => 'Printer terminal A',
            'code' => 'PRN-A',
            'type' => 'mobile',
            'is_active' => true,
        ]);
        $secondDevice = VirtualDevice::create([
            'tenant_id' => $this->tenant->id,
            'location_id' => $this->location->id,
            'name' => 'Printer terminal B',
            'code' => 'PRN-B',
            'type' => 'mobile',
            'is_active' => true,
        ]);

        $printerId = \Illuminate\Support\Str::uuid()->toString();

        $this->withToken($token)
            ->postJson('/api/v1/printers', [
                'id' => $printerId,
                'name' => 'Kitchen A',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'virtual_device_required');

        $this->withToken($token)
            ->getJson('/api/v1/sync/printers')
            ->assertStatus(422)
            ->assertJsonPath('error', 'virtual_device_required');

        $this->withToken($token)
            ->withHeader('X-Virtual-Device-Id', (string) $firstDevice->id)
            ->postJson('/api/v1/printers', [
                'id' => $printerId,
                'name' => 'Kitchen A',
                'connection_type' => 'tcp',
            ])
            ->assertCreated()
            ->assertJsonPath('printer.virtual_device_id', $firstDevice->id);

        $this->assertDatabaseHas('printers', [
            'id' => $printerId,
            'tenant_id' => $this->tenant->id,
            'virtual_device_id' => $firstDevice->id,
        ]);

        $group = PrinterGroup::create([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'virtual_device_id' => null,
            'name' => 'Independent routing group',
        ]);
        PrinterGroupPrinter::create([
            'group_id' => $group->id,
            'printer_id' => $printerId,
            'priority' => 1,
        ]);

        $this->withToken($token)
            ->withHeader('X-Virtual-Device-Id', (string) $firstDevice->id)
            ->getJson('/api/v1/printers')
            ->assertOk()
            ->assertJsonCount(1, 'printers')
            ->assertJsonPath('printers.0.id', $printerId)
            ->assertJsonPath('printer_groups.0.id', $group->id)
            ->assertJsonPath('printer_group_printers.0.printer_id', $printerId);

        $this->withToken($token)
            ->withHeader('X-Virtual-Device-Id', (string) $secondDevice->id)
            ->getJson('/api/v1/printers')
            ->assertOk()
            ->assertJsonCount(0, 'printers')
            ->assertJsonCount(1, 'printer_groups')
            ->assertJsonPath('printer_groups.0.id', $group->id)
            ->assertJsonPath('printer_group_printers.0.printer_id', $printerId);

        $this->withToken($token)
            ->withHeader('X-Virtual-Device-Id', (string) $secondDevice->id)
            ->putJson('/api/v1/printers/'.$printerId, ['name' => 'Wrong terminal'])
            ->assertNotFound();

        $this->withToken($token)
            ->withHeader('X-Virtual-Device-Id', (string) $secondDevice->id)
            ->deleteJson('/api/v1/printers/'.$printerId)
            ->assertNotFound();
    }

    public function test_mobile_printer_group_management_api_is_read_only(): void
    {
        $token = $this->apiToken();

        $this->withToken($token)
            ->postJson('/api/v1/printer-groups', [
                'id' => \Illuminate\Support\Str::uuid()->toString(),
                'name' => 'Mobile group',
            ])
            ->assertStatus(405)
            ->assertJsonPath('error', 'mobile_printer_groups_read_only');

        $this->withToken($token)
            ->putJson('/api/v1/printer-groups/'.\Illuminate\Support\Str::uuid()->toString(), [
                'name' => 'Changed',
            ])
            ->assertStatus(405)
            ->assertJsonPath('error', 'mobile_printer_groups_read_only');

        $this->withToken($token)
            ->deleteJson('/api/v1/printer-groups/'.\Illuminate\Support\Str::uuid()->toString())
            ->assertStatus(405)
            ->assertJsonPath('error', 'mobile_printer_groups_read_only');
    }

    public function test_printer_config_push_replaces_only_terminal_printers_and_keeps_web_groups(): void
    {
        $token = $this->apiToken();
        $device = VirtualDevice::create([
            'tenant_id' => $this->tenant->id,
            'location_id' => $this->location->id,
            'name' => 'Printer terminal',
            'code' => 'PRN-C',
            'type' => 'mobile',
            'is_active' => true,
        ]);

        $oldPrinter = Printer::create([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'virtual_device_id' => $device->id,
            'name' => 'Old printer',
        ]);
        $group = PrinterGroup::create([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'virtual_device_id' => null,
            'name' => 'Web-managed group',
            'is_receipt_group' => true,
        ]);
        $newPrinterId = \Illuminate\Support\Str::uuid()->toString();

        $this->withToken($token)
            ->withHeader('X-Virtual-Device-Id', (string) $device->id)
            ->postJson('/api/v1/printers/push-config', [
                'printers' => [
                    ['id' => $newPrinterId, 'name' => 'New terminal printer'],
                ],
                'printer_groups' => [
                    ['id' => \Illuminate\Support\Str::uuid()->toString(), 'name' => 'Ignored mobile group'],
                ],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'printers')
            ->assertJsonPath('printers.0.id', $newPrinterId)
            ->assertJsonFragment(['id' => $group->id, 'name' => $group->name]);

        $this->assertDatabaseMissing('printers', ['id' => $oldPrinter->id]);
        $this->assertDatabaseHas('printers', [
            'id' => $newPrinterId,
            'tenant_id' => $this->tenant->id,
            'virtual_device_id' => $device->id,
        ]);
        $this->assertDatabaseHas('printer_groups', [
            'id' => $group->id,
            'tenant_id' => $this->tenant->id,
            'virtual_device_id' => null,
        ]);
    }

    public function test_mobile_printer_can_be_linked_to_global_printer_groups(): void
    {
        $token = $this->apiToken();
        $device = VirtualDevice::create([
            'tenant_id' => $this->tenant->id,
            'location_id' => $this->location->id,
            'name' => 'Printer terminal with groups',
            'code' => 'PRN-D',
            'type' => 'mobile',
            'is_active' => true,
        ]);
        $printerId = \Illuminate\Support\Str::uuid()->toString();
        $group = PrinterGroup::create([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'virtual_device_id' => null,
            'name' => 'Shared receipts',
            'is_receipt_group' => true,
        ]);

        $this->withToken($token)
            ->withHeader('X-Virtual-Device-Id', (string) $device->id)
            ->postJson('/api/v1/printers', [
                'id' => $printerId,
                'name' => 'Counter printer',
                'group_ids' => [$group->id],
            ])
            ->assertCreated()
            ->assertJsonPath('printer.id', $printerId);

        $this->assertDatabaseHas('printer_group_printers', [
            'group_id' => $group->id,
            'printer_id' => $printerId,
            'priority' => 0,
        ]);

        $this->withToken($token)
            ->withHeader('X-Virtual-Device-Id', (string) $device->id)
            ->putJson('/api/v1/printers/'.$printerId, [
                'name' => 'Counter printer updated',
                'group_ids' => [],
            ])
            ->assertOk()
            ->assertJsonPath('printer.name', 'Counter printer updated');

        $this->assertDatabaseMissing('printer_group_printers', [
            'group_id' => $group->id,
            'printer_id' => $printerId,
        ]);
    }

    public function test_logout_returns_ok(): void
    {
        $token = $this->apiToken();

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
    }

    // ── Locations ─────────────────────────────────────────────────────────────

    public function test_locations_returns_list(): void
    {
        $this->withToken($this->apiToken())
            ->getJson('/api/v1/locations')
            ->assertOk()
            ->assertJsonStructure(['ok', 'locations'])
            ->assertJsonPath('ok', true);
    }

    // ── Virtual devices ──────────────────────────────────────────────────────

    public function test_virtual_devices_returns_empty_list_when_feature_is_disabled(): void
    {
        $settings = $this->tenant->settings ?? [];
        data_set($settings, 'features.virtual_devices', false);
        $this->tenant->update(['settings' => $settings]);

        $this->withToken($this->apiToken())
            ->getJson('/api/v1/virtual-devices')
            ->assertOk()
            ->assertExactJson(['ok' => true, 'devices' => []]);
    }

    public function test_virtual_devices_returns_only_active_devices_for_tenant(): void
    {
        $settings = $this->tenant->settings ?? [];
        data_set($settings, 'features.virtual_devices', true);
        $this->tenant->update(['settings' => $settings]);

        VirtualDevice::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Caisse principale',
            'code' => 'POS-01',
            'type' => 'pos',
            'description' => 'Comptoir',
            'is_active' => true,
        ]);

        VirtualDevice::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Ancienne caisse',
            'code' => 'POS-OLD',
            'type' => 'pos',
            'is_active' => false,
        ]);

        $this->withToken($this->apiToken())
            ->getJson('/api/v1/virtual-devices')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonCount(1, 'devices')
            ->assertJsonPath('devices.0.code', 'POS-01');
    }

    // ── Sync ──────────────────────────────────────────────────────────────────

    public function test_sync_items_returns_paginated_items(): void
    {
        $response = $this->withToken($this->apiToken())
            ->getJson('/api/v1/sync/items');

        $response->assertOk()
            ->assertJsonStructure(['ok', 'sync_at', 'has_more', 'page', 'per_page', 'total', 'items'])
            ->assertJsonPath('ok', true);
    }

    public function test_sync_meta_returns_categories_brands_units_taxes(): void
    {
        $response = $this->withToken($this->apiToken())
            ->getJson('/api/v1/sync/meta');

        $response->assertOk()
            ->assertJsonStructure(['ok', 'sync_at', 'categories', 'brands', 'units', 'taxes'])
            ->assertJsonPath('ok', true);
    }

    public function test_sync_catalog_legacy_alias_works(): void
    {
        // /sync/catalog is a backward-compat alias for /sync/items.
        $response = $this->withToken($this->apiToken())
            ->getJson('/api/v1/sync/catalog');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonStructure(['ok', 'sync_at', 'has_more', 'items']);
    }

    public function test_sync_items_delta_only_returns_recent_items(): void
    {
        $futureDate = now()->addDay()->toISOString();

        $response = $this->withToken($this->apiToken())
            ->getJson('/api/v1/sync/items?since='.$futureDate);

        $response->assertOk();
        $this->assertCount(0, $response->json('items'));
    }

    public function test_sync_stock_returns_snapshot(): void
    {
        $this->withToken($this->apiToken())
            ->getJson("/api/v1/sync/stock?location_id={$this->location->id}")
            ->assertOk()
            ->assertJsonStructure(['ok', 'sync_at', 'location_id', 'stock'])
            ->assertJsonPath('ok', true);
    }

    public function test_sync_contacts_returns_customers(): void
    {
        $this->withToken($this->apiToken())
            ->getJson('/api/v1/sync/contacts')
            ->assertOk()
            ->assertJsonStructure(['ok', 'sync_at', 'contacts'])
            ->assertJsonPath('ok', true);
    }

    public function test_contacts_list_returns_paginated_contacts(): void
    {
        $this->withToken($this->apiToken())
            ->getJson('/api/v1/contacts/list?kind=client&page=1&per_page=200')
            ->assertOk()
            ->assertJsonStructure(['ok', 'contacts', 'total', 'page', 'has_more'])
            ->assertJsonPath('ok', true)
            ->assertJsonPath('page', 1);
    }

    // ── Sales ─────────────────────────────────────────────────────────────────

    public function test_sale_submission_creates_sale_and_moves_stock(): void
    {
        if (! $this->item) {
            $this->markTestSkipped('No active product item available in seed data.');
        }

        $key = \Illuminate\Support\Str::uuid()->toString();

        $response = $this->withToken($this->apiToken())
            ->postJson('/api/v1/pos/sales', [
                'idempotency_key' => $key,
                'location_id'     => $this->location->id,
                'items'           => [
                    ['item_id' => $this->item->id, 'quantity' => 2, 'unit_price' => 80.00],
                ],
                'payments'        => ['cash' => 160.00, 'card' => 0, 'transfer' => 0, 'advance' => 0],
            ]);

        $response->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonStructure(['ok', 'sale', 'stock_after'])
            ->assertJsonPath('already_existed', false);

        // Sale created.
        $this->assertDatabaseHas('sales', ['tenant_id' => $this->tenant->id, 'total_amount' => 160.00]);

        // Stock was decremented.
        $stock = ItemLocationStock::where('tenant_id', $this->tenant->id)
            ->where('item_id', $this->item->id)
            ->where('location_id', $this->location->id)
            ->value('quantity');
        $this->assertEquals(98, $stock);

        // SaleItem captures cost.
        $saleId = $response->json('sale.id');
        $this->assertDatabaseHas('sale_items', [
            'sale_id'   => $saleId,
            'item_id'   => $this->item->id,
            'unit_cost' => 50.00,
            'total_cost' => 100.00,
        ]);

        // Inventory movement recorded with cost.
        $this->assertDatabaseHas('stock_movements', [
            'tenant_id'  => $this->tenant->id,
            'item_id'    => $this->item->id,
            'unit_cost'  => 50.00,
        ]);
    }

    public function test_sale_submission_is_idempotent(): void
    {
        if (! $this->item) {
            $this->markTestSkipped('No active product item available in seed data.');
        }

        $key = \Illuminate\Support\Str::uuid()->toString();
        $payload = [
            'idempotency_key' => $key,
            'location_id'     => $this->location->id,
            'items'           => [
                ['item_id' => $this->item->id, 'quantity' => 1, 'unit_price' => 80.00],
            ],
            'payments'        => ['cash' => 80.00, 'card' => 0, 'transfer' => 0, 'advance' => 0],
        ];

        $r1 = $this->withToken($this->apiToken())->postJson('/api/v1/pos/sales', $payload);
        $r2 = $this->withToken($this->apiToken())->postJson('/api/v1/pos/sales', $payload);

        $r1->assertCreated();
        $r2->assertOk()->assertJsonPath('already_existed', true);

        // Only one sale record created.
        $this->assertEquals(1, Sale::where('idempotency_key', $key)->count());

        // Stock decremented only once.
        $stock = ItemLocationStock::where('tenant_id', $this->tenant->id)
            ->where('item_id', $this->item->id)
            ->where('location_id', $this->location->id)
            ->value('quantity');
        $this->assertEquals(99, $stock);
    }

    public function test_zero_amount_sale_can_be_returned_for_stock_without_cash_movement(): void
    {
        if (! $this->item) {
            $this->markTestSkipped('No active product item available in seed data.');
        }

        $startingStock = ItemLocationStock::where('tenant_id', $this->tenant->id)
            ->where('item_id', $this->item->id)
            ->where('location_id', $this->location->id)
            ->value('quantity');

        $saleResponse = $this->withToken($this->apiToken())
            ->postJson('/api/v1/pos/sales', [
                'idempotency_key' => \Illuminate\Support\Str::uuid()->toString(),
                'location_id'     => $this->location->id,
                'items'           => [
                    ['item_id' => $this->item->id, 'quantity' => 2, 'unit_price' => 0],
                ],
                'payments'        => ['cash' => 0, 'card' => 0, 'transfer' => 0, 'advance' => 0],
            ]);

        $saleResponse->assertCreated()
            ->assertJsonPath('sale.total_amount', 0);

        $saleId = $saleResponse->json('sale.id');
        $lineId = $saleResponse->json('sale.items.0.id');

        $this->assertEquals($startingStock - 2, ItemLocationStock::where('tenant_id', $this->tenant->id)
            ->where('item_id', $this->item->id)
            ->where('location_id', $this->location->id)
            ->value('quantity'));

        $cashRefundsBefore = CashRegisterMovement::where('tenant_id', $this->tenant->id)
            ->where('type', 'sale_refund_cash')
            ->count();

        $returnResponse = $this->withToken($this->apiToken())
            ->postJson("/api/v1/pos/sales/{$saleId}/returns", [
                'idempotency_key' => \Illuminate\Support\Str::uuid()->toString(),
                'refund_method' => 'cash',
                'return_lines' => [[
                    'sale_item_id' => $lineId,
                    'quantity' => 1,
                    'stock_action' => 'restock',
                ]],
            ]);

        $returnResponse->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('return.total_amount', 0)
            ->assertJsonPath('return.refund_scope', 'partial');

        $this->assertDatabaseHas('sales', [
            'id' => $saleId,
            'status' => 'partial_refund',
        ]);
        $this->assertSame(1, SaleReturn::where('sale_id', $saleId)->count());
        $this->assertEquals($startingStock - 1, ItemLocationStock::where('tenant_id', $this->tenant->id)
            ->where('item_id', $this->item->id)
            ->where('location_id', $this->location->id)
            ->value('quantity'));
        $this->assertSame($cashRefundsBefore, CashRegisterMovement::where('tenant_id', $this->tenant->id)
            ->where('type', 'sale_refund_cash')
            ->count());

        $finalReturnResponse = $this->withToken($this->apiToken())
            ->postJson("/api/v1/pos/sales/{$saleId}/returns", [
                'idempotency_key' => \Illuminate\Support\Str::uuid()->toString(),
                'refund_method' => 'cash',
                'return_lines' => [[
                    'sale_item_id' => $lineId,
                    'quantity' => 1,
                    'stock_action' => 'restock',
                ]],
            ]);

        $finalReturnResponse->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('return.total_amount', 0)
            ->assertJsonPath('return.refund_scope', 'full');

        $this->assertDatabaseHas('sales', [
            'id' => $saleId,
            'status' => 'refunded',
        ]);
        $this->assertSame(2, SaleReturn::where('sale_id', $saleId)->count());
        $this->assertEquals($startingStock, ItemLocationStock::where('tenant_id', $this->tenant->id)
            ->where('item_id', $this->item->id)
            ->where('location_id', $this->location->id)
            ->value('quantity'));
        $this->assertSame($cashRefundsBefore, CashRegisterMovement::where('tenant_id', $this->tenant->id)
            ->where('type', 'sale_refund_cash')
            ->count());
    }

    public function test_enabled_virtual_devices_require_a_valid_terminal_and_reject_client_actor(): void
    {
        if (! $this->item) {
            $this->markTestSkipped('No active product item available in seed data.');
        }

        $settings = $this->tenant->settings ?? [];
        data_set($settings, 'features.virtual_devices', true);
        $this->tenant->update(['settings' => $settings]);

        $payload = $this->salePayload();
        $token = $this->apiToken();

        $this->withToken($token)
            ->postJson('/api/v1/pos/sales', $payload)
            ->assertStatus(422)
            ->assertJsonPath('error', 'virtual_device_required');

        $device = VirtualDevice::create([
            'tenant_id' => $this->tenant->id,
            'location_id' => $this->location->id,
            'name' => 'Terminal API',
            'code' => 'API-POS-01',
            'type' => 'mobile',
            'is_active' => true,
        ]);

        $this->withToken($token)
            ->withHeader('X-Virtual-Device-Id', (string) $device->id)
            ->postJson('/api/v1/pos/sales', [...$payload, 'user_id' => 999999])
            ->assertStatus(422)
            ->assertJsonPath('error', 'client_actor_forbidden');
    }

    public function test_idempotent_replay_preserves_original_server_attribution(): void
    {
        if (! $this->item) {
            $this->markTestSkipped('No active product item available in seed data.');
        }

        $settings = $this->tenant->settings ?? [];
        data_set($settings, 'features.virtual_devices', true);
        $this->tenant->update(['settings' => $settings]);

        $firstDevice = VirtualDevice::create(['tenant_id' => $this->tenant->id, 'location_id' => $this->location->id, 'name' => 'Terminal A', 'code' => 'TERM-A', 'type' => 'mobile', 'is_active' => true]);
        $secondDevice = VirtualDevice::create(['tenant_id' => $this->tenant->id, 'location_id' => $this->location->id, 'name' => 'Terminal B', 'code' => 'TERM-B', 'type' => 'mobile', 'is_active' => true]);
        $payload = $this->salePayload();
        $token = $this->apiToken();

        $this->withToken($token)->withHeader('X-Virtual-Device-Id', (string) $firstDevice->id)
            ->postJson('/api/v1/pos/sales', $payload)->assertCreated();

        $this->withToken($token)->withHeader('X-Virtual-Device-Id', (string) $secondDevice->id)
            ->postJson('/api/v1/pos/sales', $payload)
            ->assertOk()
            ->assertJsonPath('already_existed', true)
            ->assertJsonPath('sale.virtual_device.id', $firstDevice->id)
            ->assertJsonPath('sale.virtual_device.name', $firstDevice->name);

        $this->assertSame(1, Sale::where('idempotency_key', $payload['idempotency_key'])->count());
    }

    public function test_pin_switch_replaces_credential_and_sale_uses_switched_operator_and_terminal(): void
    {
        if (! $this->item) {
            $this->markTestSkipped('No active product item available in seed data.');
        }

        $settings = $this->tenant->settings ?? [];
        data_set($settings, 'features.virtual_devices', true);
        $this->tenant->update(['settings' => $settings]);

        $device = VirtualDevice::create([
            'tenant_id' => $this->tenant->id,
            'location_id' => $this->location->id,
            'name' => 'Tablette comptoir',
            'code' => 'TABLET-01',
            'type' => 'mobile',
            'is_active' => true,
        ]);

        $cashier = User::where('email', 'caisse@librairie-atlas.ma')->firstOrFail();
        $cashier->forceFill(['pin_hash' => Hash::make('2468')])->save();

        $ownerToken = $this->apiToken();
        $switch = $this->withToken($ownerToken)->postJson('/api/v1/auth/pin-verify', [
            'user_id' => $cashier->id,
            'pin' => '2468',
        ]);

        $switch->assertOk()
            ->assertJsonPath('user.id', $cashier->id)
            ->assertJsonPath('previous_token_revoked', true)
            ->assertJsonStructure(['token', 'token_type', 'abilities']);

        $operatorToken = $switch->json('token');

        $this->assertNull(PersonalAccessToken::findToken($ownerToken));
        $this->app['auth']->forgetGuards();

        $response = $this->withToken($operatorToken)
            ->withHeader('X-Virtual-Device-Id', (string) $device->id)
            ->postJson('/api/v1/pos/sales', $this->salePayload());

        $response->assertCreated()
            ->assertJsonPath('sale.created_by.id', $cashier->id)
            ->assertJsonPath('sale.created_by.name', $cashier->name)
            ->assertJsonPath('sale.virtual_device.id', $device->id)
            ->assertJsonPath('sale.virtual_device.name', $device->name);

        $this->assertDatabaseHas('sales', [
            'id' => $response->json('sale.id'),
            'user_id' => $cashier->id,
            'virtual_device_id' => $device->id,
            'actor_name_snapshot' => $cashier->name,
            'terminal_name_snapshot' => $device->name,
        ]);

        // Sync windows close on the previous complete DB timestamp second.
        $this->travel(2)->seconds();

        $listedSales = $this->withToken($operatorToken)
            ->withHeader('X-Location-Id', (string) $this->location->id)
            ->getJson('/api/v1/pos/sales')
            ->assertOk();
        $listedSale = collect($listedSales->json('sales'))->firstWhere('id', $response->json('sale.id'));
        $this->assertSame($cashier->id, data_get($listedSale, 'created_by.id'));
        $this->assertSame($device->id, data_get($listedSale, 'virtual_device.id'));

        $syncedSales = $this->withToken($operatorToken)
            ->withHeader('X-Location-Id', (string) $this->location->id)
            ->getJson('/api/v1/sync/sales')
            ->assertOk();
        $syncedSale = collect($syncedSales->json('sales'))->firstWhere('id', $response->json('sale.id'));
        $this->assertSame($cashier->name, data_get($syncedSale, 'created_by.name'));
        $this->assertSame($device->name, data_get($syncedSale, 'virtual_device.name'));

        // Mobile POS user management is read-only regardless of the switched
        // operator's abilities. Management is done through the web back-office.
        $this->withToken($operatorToken)
            ->putJson('/api/v1/users/'.$this->user->id, ['role' => 'cashier'])
            ->assertStatus(405)
            ->assertJsonPath('error', 'mobile_user_management_read_only');
    }

    public function test_sale_rejected_when_insufficient_stock(): void
    {
        if (! $this->item) {
            $this->markTestSkipped('No active product item available in seed data.');
        }

        // Set stock to 1 — must update both the cache AND the layer ledger so
        // availableQuantity() (which reads from inventory_layers) also returns 1.
        ItemLocationStock::where('tenant_id', $this->tenant->id)
            ->where('item_id', $this->item->id)
            ->where('location_id', $this->location->id)
            ->update(['quantity' => 1]);

        \App\Models\InventoryLayer::where('tenant_id', $this->tenant->id)
            ->where('item_id', $this->item->id)
            ->where('location_id', $this->location->id)
            ->update(['remaining_quantity' => 1]);

        $salesBefore = Sale::where('tenant_id', $this->tenant->id)->count();

        $this->withToken($this->apiToken())
            ->postJson('/api/v1/pos/sales', [
                'idempotency_key' => \Illuminate\Support\Str::uuid()->toString(),
                'location_id'     => $this->location->id,
                'items'           => [
                    ['item_id' => $this->item->id, 'quantity' => 5, 'unit_price' => 80.00],
                ],
                'payments' => ['cash' => 400.00, 'card' => 0, 'transfer' => 0, 'advance' => 0],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'insufficient_stock')
            ->assertJsonStructure(['conflicts']);

        // No new sale created.
        $this->assertEquals($salesBefore, Sale::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_sale_reconciles_positive_stock_cache_when_layers_are_missing(): void
    {
        if (! $this->item) {
            $this->markTestSkipped('No active product item available in seed data.');
        }

        ItemLocationStock::where('tenant_id', $this->tenant->id)
            ->where('item_id', $this->item->id)
            ->where('location_id', $this->location->id)
            ->update(['quantity' => 10, 'average_cost' => 1.5]);

        \App\Models\InventoryLayer::where('tenant_id', $this->tenant->id)
            ->where('item_id', $this->item->id)
            ->where('location_id', $this->location->id)
            ->update(['remaining_quantity' => 0, 'exhausted_at' => now()]);

        $ledger = app(\App\Services\Inventory\InventoryLedgerService::class);

        $this->assertSame(0.0, $ledger->availableQuantity(
            $this->tenant->id,
            $this->item->id,
            null,
            $this->location->id,
        ));

        $response = $this->withToken($this->apiToken())
            ->postJson('/api/v1/pos/sales', [
                'idempotency_key' => \Illuminate\Support\Str::uuid()->toString(),
                'location_id'     => $this->location->id,
                'items'           => [
                    ['item_id' => $this->item->id, 'quantity' => 10, 'unit_price' => 2],
                ],
                'payments' => ['cash' => 20, 'card' => 0, 'transfer' => 0, 'advance' => 0],
            ]);

        $response->assertCreated()
            ->assertJsonPath('sale.total_amount', 20);

        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $this->tenant->id,
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'type' => \App\Services\Inventory\InventoryMovementType::CORRECTION,
            'reason' => 'Synchronisation cache stock / couches LIFO',
        ]);

        $this->assertSame(0.0, $ledger->availableQuantity(
            $this->tenant->id,
            $this->item->id,
            null,
            $this->location->id,
        ));
        $this->assertEquals(0, ItemLocationStock::where('tenant_id', $this->tenant->id)
            ->where('item_id', $this->item->id)
            ->where('location_id', $this->location->id)
            ->value('quantity'));
    }

    // ── Dashboard ─────────────────────────────────────────────────────────────

    public function test_dashboard_returns_kpis(): void
    {
        $this->withToken($this->apiToken())
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonStructure(['ok', 'kpis', 'stock_health', 'payment_breakdown', 'top_items'])
            ->assertJsonPath('ok', true);
    }

    public function test_dashboard_cogs_uses_snapshot_cost_not_current_price(): void
    {
        if (! $this->item) {
            $this->markTestSkipped('No active product item available in seed data.');
        }

        // Delete any seed sales so we start with a known baseline.
        Sale::where('tenant_id', $this->tenant->id)->delete();

        // Submit a sale at avg_cost=50.
        $this->withToken($this->apiToken())
            ->postJson('/api/v1/pos/sales', [
                'idempotency_key' => \Illuminate\Support\Str::uuid()->toString(),
                'location_id'     => $this->location->id,
                'items'           => [
                    ['item_id' => $this->item->id, 'quantity' => 1, 'unit_price' => 80.00],
                ],
                'payments' => ['cash' => 80.00, 'card' => 0, 'transfer' => 0, 'advance' => 0],
            ])->assertCreated();

        // Now change purchase_price to something very different.
        $this->item->update(['purchase_price' => 999.00]);

        $response = $this->withToken($this->apiToken())
            ->getJson('/api/v1/dashboard')
            ->assertOk();

        // COGS should still be 50 (the snapshot), not 999.
        $this->assertEquals(50.0, $response->json('kpis.cogs'));
        $this->assertEquals(13.33, $response->json('kpis.tax_total')); // 80 TTC at 20%
        $this->assertEquals(16.67, $response->json('kpis.gross_profit')); // 66.67 HT - 50

        $stats = $this->withToken($this->apiToken())
            ->getJson('/api/v1/statistics/overview')
            ->assertOk();

        $this->assertEquals(13.33, $stats->json('kpis.tax_total'));
        $this->assertEquals(16.67, $stats->json('kpis.gross_profit'));
    }

    // ── Sales list ────────────────────────────────────────────────────────────

    public function test_sales_list_is_paginated(): void
    {
        $this->withToken($this->apiToken())
            ->getJson('/api/v1/pos/sales')
            ->assertOk()
            ->assertJsonStructure(['ok', 'has_more', 'page', 'sales']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function apiToken(?User $user = null): string
    {
        $user ??= $this->user;

        $response = $this->postJson('/api/v1/auth/login', [
            'email'       => $user->email,
            'password'    => 'password',
            'device_name' => 'phpunit-'.microtime(true),
        ]);

        return $response->json('token');
    }

    private function salePayload(?string $key = null): array
    {
        return [
            'idempotency_key' => $key ?? \Illuminate\Support\Str::uuid()->toString(),
            'location_id' => $this->location->id,
            'items' => [['item_id' => $this->item->id, 'quantity' => 1, 'unit_price' => 80.00]],
            'payments' => ['cash' => 80.00, 'card' => 0, 'transfer' => 0, 'advance' => 0],
        ];
    }
}
