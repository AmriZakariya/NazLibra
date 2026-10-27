<?php

namespace Tests\Feature;

use App\Models\ClientAccessEvent;
use App\Models\Subscription;
use App\Models\TenantInstall;
use App\Models\User;
use App\Support\DeviceLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ActsAsMasterInstall;
use Tests\TestCase;

/**
 * Has a client reached their space? Each entry of the verification code — in
 * the app or on the web gate, right or wrong — is journaled on the master and
 * shown to the platform admin, with the device and the date.
 */
class ClientAccessJournalTest extends TestCase
{
    use ActsAsMasterInstall {
        setUp as actsAsMasterSetUp;
    }
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->actsAsMasterSetUp();
        Queue::fake();
        Bus::fake();
    }

    private function client(string $subdomain = 'atlas', bool $live = true): TenantInstall
    {
        $sub = Subscription::create([
            'business_name' => 'Librairie '.ucfirst($subdomain), 'contact_name' => 'Contact', 'email' => $subdomain.'@example.com',
            'desired_subdomain' => $subdomain, 'currency' => 'MAD', 'status' => Subscription::STATUS_APPROVED,
        ]);

        return TenantInstall::create([
            'subscription_id' => $sub->id, 'subdomain' => $subdomain, 'domain' => $subdomain.'.castlitpos.com',
            'status' => $live ? TenantInstall::STATUS_LIVE : TenantInstall::STATUS_QUEUED, 'access_code' => 'K7M2QX',
        ]);
    }

    private function enter(string $subdomain, string $code, array $device = []): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('User-Agent', 'Dart/3.10 (dart:io)')
            ->postJson('/api/v1/public/client-access', ['subdomain' => $subdomain, 'code' => $code] + $device);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_platform_admin' => true]);
    }

    public function test_each_valid_entry_from_the_app_is_journaled_with_its_device(): void
    {
        $install = $this->client();
        $tablet = ['channel' => 'app', 'platform' => 'android', 'os' => 'Android 8.1 · PAX A920', 'app_version' => '1.0.6', 'device_id' => 'install-1'];

        $this->enter('atlas', 'k7m2qx', $tablet)->assertOk()->assertJson(['ok' => true]);
        $this->travel(2)->days();
        $this->enter('atlas', 'K7M2QX', $tablet)->assertOk();

        $events = ClientAccessEvent::where('tenant_install_id', $install->id)->get();
        $this->assertCount(2, $events, 'every entry, not only the first');
        $this->assertSame('ok', $events[1]->outcome);
        $this->assertSame('app', $events[1]->channel);
        $this->assertSame('Android 8.1 · PAX A920 · app 1.0.6', $events[1]->device_label);
        $this->assertSame('install-1', $events[1]->device_id);
    }

    public function test_wrong_codes_and_unknown_clients_are_journaled_apart(): void
    {
        $install = $this->client();

        $this->enter('atlas', 'WRONG1')->assertStatus(422);
        $this->enter('nobody', 'K7M2QX')->assertNotFound();

        $this->assertSame(ClientAccessEvent::OUTCOME_INVALID, ClientAccessEvent::where('tenant_install_id', $install->id)->value('outcome'));
        $this->assertSame(ClientAccessEvent::OUTCOME_UNKNOWN, ClientAccessEvent::where('subdomain', 'nobody')->value('outcome'));
        $this->assertSame('Application (ancienne version)', ClientAccessEvent::where('subdomain', 'nobody')->value('device_label'),
            'an app that sends nothing is still recorded');
    }

    public function test_the_web_gate_passes_on_the_visitor_not_the_server(): void
    {
        Http::fake(['*/api/v1/public/client-access' => Http::response(['ok' => true])]);

        $this->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130.0 Safari/537.36')
            ->withServerVariables(['HTTP_HOST' => 'atlas.castlitpos.com', 'REMOTE_ADDR' => '41.250.10.20'])
            ->post('http://atlas.castlitpos.com/acces', ['code' => 'k7m2qx'])
            ->assertRedirect()
            ->assertCookie('castlit_browser');

        Http::assertSent(fn ($request) => $request['channel'] === 'web'
            && $request['client_ip'] === '41.250.10.20'
            && str_contains($request['client_user_agent'], 'Chrome/130')
            && preg_match('/^[a-f0-9]{32}$/', $request['device_id']));
    }

    public function test_the_master_records_a_web_entry_as_the_visitor_saw_it(): void
    {
        $install = $this->client();

        $this->enter('atlas', 'K7M2QX', [
            'channel' => 'web', 'client_ip' => '41.250.10.20', 'device_id' => str_repeat('a', 32),
            'client_user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1',
        ])->assertOk();

        $event = ClientAccessEvent::where('tenant_install_id', $install->id)->firstOrFail();
        $this->assertSame('web', $event->channel);
        $this->assertSame('41.250.10.20', $event->ip);
        $this->assertSame('Safari · iPhone', $event->device_label);
    }

    public function test_the_approved_list_says_who_has_connected_and_from_what(): void
    {
        $connected = $this->client('atlas');
        $this->client('silent');
        foreach (['install-1', 'install-1', 'install-2'] as $device) {
            $this->enter('atlas', 'K7M2QX', ['channel' => 'app', 'platform' => 'android', 'os' => 'Android 13', 'app_version' => '1.0.6', 'device_id' => $device]);
        }
        $this->enter('atlas', 'BADBAD');

        $page = $this->actingAs($this->admin())->get('/castlit-admin?status=approved')->assertOk()->getContent();

        $this->assertStringContainsString('<th>Connexions</th>', $page);
        $this->assertStringContainsString('Jamais connecté', $page, 'the client who never entered the code');
        $this->assertStringContainsString('3 accès · 2 appareils', $page);
        $this->assertStringContainsString('Android 13 · app 1.0.6', $page);
        $this->assertStringContainsString('1 code erroné (30 j)', $page);
        $this->assertSame(3, ClientAccessEvent::where('tenant_install_id', $connected->id)->where('outcome', 'ok')->count());
    }

    public function test_the_client_page_lists_every_entry(): void
    {
        $install = $this->client();
        $this->enter('atlas', 'K7M2QX', ['channel' => 'app', 'platform' => 'windows', 'app_version' => '1.0.6', 'device_id' => 'till-9']);
        $this->enter('atlas', 'NOPE00');

        $page = $this->actingAs($this->admin())->get('/castlit-admin/'.$install->subscription_id)->assertOk()->getContent();

        $this->assertStringContainsString('Connexions à l\'espace', $page);
        $this->assertStringContainsString('Windows · app 1.0.6', $page);
        $this->assertStringContainsString('data-outcome="invalid"', $page);
        $this->assertStringContainsString('Code erroné', $page);
    }

    public function test_browsers_are_named_the_way_people_say_them(): void
    {
        $this->assertSame('Edge · Windows', DeviceLabel::fromUserAgent('Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/130.0 Safari/537.36 Edg/130.0'));
        $this->assertSame('Chrome · Android', DeviceLabel::fromUserAgent('Mozilla/5.0 (Linux; Android 13; SM-X200) AppleWebKit/537.36 Chrome/130.0 Mobile Safari/537.36'));
        $this->assertSame('Firefox · macOS', DeviceLabel::fromUserAgent('Mozilla/5.0 (Macintosh; Intel Mac OS X 14.0; rv:131.0) Gecko/20100101 Firefox/131.0'));
        $this->assertNull(DeviceLabel::fromUserAgent(null));
    }
}
