<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Item;
use App\Models\Tenant;
use App\Models\User;
use App\Support\RequestSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * An action from the app showed an empty detail in the activity journal: five
 * fields kept, the status under another name, and "Dart/3.13 (dart:io)" for
 * a device. It now keeps what the back office keeps, plus the app version.
 */
class ApiActionLogDetailTest extends TestCase
{
    use RefreshDatabase;

    private const APP_HEADERS = [
        'X-App-Version' => '1.0.13',
        'X-App-Platform' => 'android',
        'X-Device' => 'Android%208.1%20%C2%B7%20PAX%20A920', // as the app encodes it
        'X-Castlit-Install-Id' => 'a1b2c3d4-e5f6-4711-8899-aabbccddeeff',
        'User-Agent' => 'CastlitPOS/1.0.13 (Android 8.1 · PAX A920)',
    ];

    private function adjust(): AuditLog
    {
        $this->seed();
        $owner = User::first();
        $tenant = Tenant::first();
        $item = Item::create([
            'tenant_id' => $tenant->id, 'title' => 'Cahier 96p', 'type' => 'product',
            'status' => 'active', 'sale_price' => 10, 'purchase_price' => 5, 'stock_quantity' => 3,
        ]);
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $owner->email, 'password' => 'password', 'device_name' => 'till-'.Str::uuid(),
        ])->json('token');
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->withHeaders(self::APP_HEADERS)->postJson('/api/v1/inventory/adjustments', [
            'idempotency_key' => 'adj-1', 'item_id' => $item->id, 'mode' => 'delta', 'quantity' => 4,
            'reason' => 'Réception', 'note' => 'Carton abîmé',
        ])->assertSuccessful();

        return AuditLog::where('action', 'api.post')->latest('id')->firstOrFail();
    }

    public function test_an_app_action_keeps_its_full_detail_and_the_app_version(): void
    {
        $log = $this->adjust();
        $props = $log->properties;

        $this->assertSame('1.0.13', $log->app_version);
        $this->assertSame('a1b2c3d4-e5f6-4711-8899-aabbccddeeff', $log->app_install_id);
        $this->assertSame('Android 8.1 · PAX A920', $log->real_device_platform);
        $this->assertSame('Castl-it POS 1.0.13', $log->real_device_browser);

        $this->assertSame('POST', $props['method']);
        $this->assertSame('/api/v1/inventory/adjustments', $props['path']);
        $this->assertStringEndsWith('/api/v1/inventory/adjustments', $props['url']);
        $this->assertNotEmpty($props['route']);
        $this->assertStringContainsString('AdjustmentController', $props['route_action']);
        $this->assertContains($props['status_code'], [200, 201]);
        $this->assertNotEmpty($props['ip']);
        $this->assertNotEmpty($props['timezone']);
        $this->assertSame(4, $props['payload']['quantity']);
        $this->assertSame('Carton abîmé', $props['payload']['note']);
        $this->assertSame('1.0.13', $props['headers']['x-app-version']);
        $this->assertArrayNotHasKey('authorization', $props['headers'], 'the token never reaches the journal');
    }

    public function test_the_journal_detail_reads_status_ip_and_version(): void
    {
        $log = $this->adjust();
        $owner = User::first();

        $row = collect($this->actingAs($owner)->getJson(route('profile.activity.data', ['length' => 50]))->assertOk()->json('data'))
            ->firstWhere('id', $log->id);
        $detail = json_decode($row['properties_json'], true);

        $this->assertSame('1.0.13', $row['app_version']);
        $this->assertContains($detail['status_code'], [200, 201]);
        $this->assertSame($log->real_device_ip, $detail['ip']);
        $this->assertSame('1.0.13', $detail['app_version']);
        $this->assertStringContainsString('Carton abîmé', $detail['payload_json']);
    }

    public function test_each_detail_tab_has_its_panel(): void
    {
        $this->seed();
        $page = $this->actingAs(User::first())->get(route('profile.activity'))->assertOk()->getContent();

        foreach (['summary', 'device', 'technical'] as $tab) {
            $this->assertStringContainsString('id="tab-btn-'.$tab.'"', $page);
            $this->assertStringContainsString('id="tab-panel-'.$tab.'"', $page, "« {$tab} » opened onto nothing");
        }
    }

    public function test_an_older_app_log_still_shows_its_status_and_ip(): void
    {
        $this->seed();
        $owner = User::first();
        $log = AuditLog::create([
            'tenant_id' => Tenant::first()->id, 'user_id' => $owner->id, 'action' => 'api.post',
            'friendly_action' => 'POST /api/v1/inventory/adjustments',
            'properties' => ['path' => '/api/v1/inventory/adjustments', 'method' => 'POST', 'response_status' => 201],
            'real_device_ip' => '203.0.113.7', 'real_device_user_agent' => 'Dart/3.13 (dart:io)',
        ]);

        $row = collect($this->actingAs($owner)->getJson(route('profile.activity.data', ['length' => 50]))->json('data'))
            ->firstWhere('id', $log->id);
        $detail = json_decode($row['properties_json'], true);

        $this->assertSame(201, $detail['status_code']);
        $this->assertSame('203.0.113.7', $detail['ip']);
    }

    public function test_secrets_are_masked_at_any_depth_and_nothing_else_is(): void
    {
        $request = Request::create('/x', 'POST', [
            'name' => 'Sapin', 'password' => 'hunter2', 'shipping' => 'Express',
            'staff' => ['pin_code' => '1234', 'access_token' => 'abc', 'name' => 'Ali'],
            'lines' => range(1, 40),
            'photo' => 'data:image/png;base64,'.str_repeat('A', 4000),
        ]);
        $payload = RequestSnapshot::payload($request);

        $this->assertSame('Sapin', $payload['name']);
        $this->assertSame('Express', $payload['shipping'], '"shipping" holds "pin" but is no secret');
        $this->assertSame(RequestSnapshot::MASK, $payload['password']);
        $this->assertSame(RequestSnapshot::MASK, $payload['staff']['pin_code']);
        $this->assertSame(RequestSnapshot::MASK, $payload['staff']['access_token']);
        $this->assertSame('Ali', $payload['staff']['name']);
        $this->assertCount(26, $payload['lines'], '25 lines and a count of the rest');
        $this->assertStringStartsWith('[image', $payload['photo']);
    }

    public function test_the_version_is_read_from_the_user_agent_when_the_headers_are_gone(): void
    {
        $request = Request::create('/x', 'POST', server: ['HTTP_USER_AGENT' => 'CastlitPOS/1.0.14 (iOS 18.2 · iPhone15,2)']);
        $app = RequestSnapshot::appClient($request);

        $this->assertSame('1.0.14', $app['version']);
        $this->assertSame('iOS 18.2 · iPhone15,2', $app['device']);

        $browser = Request::create('/x', 'POST', server: ['HTTP_USER_AGENT' => 'Mozilla/5.0 Chrome/131']);
        $this->assertNull(RequestSnapshot::appClient($browser)['version']);
    }
}
