<?php

namespace Tests\Feature;

use App\Models\DeviceReport;
use App\Models\Subscription;
use App\Models\SupportConversation;
use App\Models\TenantInstall;
use App\Models\User;
use App\Support\DeviceReports;
use App\Support\SupportChat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActsAsMasterInstall;
use Tests\TestCase;

/**
 * « Envoyer les journaux au support »: a phone sends its logs, the team
 * reads them in castlit-admin, and the support thread says they arrived.
 */
class DeviceReportTest extends TestCase
{
    use ActsAsMasterInstall;
    use RefreshDatabase;

    private const LOG = <<<'LOG'
===== Castl-it-POS 1.0.10 · android · PAX A920 =====
2026-11-03T10:00:00.000Z [I] auth: verify access code → ok (spaparis)
2026-11-03T10:00:01.000Z [W] http: POST /api/v1/auth/login → 422 (412 ms)
2026-11-03T10:00:02.000Z [E] auth: login failed: Identifiants incorrects
===== logcat =====
11-03 10:00:03.100  4321  4350 E PaxPrinter: printer out of paper
11-03 10:00:03.200  4321  4350 D Flutter: frame
LOG;

    protected function setUp(): void
    {
        putenv('CASTLIT_MASTER=true');
        $_ENV['CASTLIT_MASTER'] = 'true';
        $_SERVER['CASTLIT_MASTER'] = 'true';
        \Tests\TestCase::setUp();
        Storage::fake('local');
    }

    private function log(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('app.log.gz', gzencode(self::LOG));
    }

    public function test_a_phone_sends_its_logs_before_login_and_gets_a_reference(): void
    {
        $reference = $this->post('/api/v1/support/logs', [
            'log' => $this->log(), 'note' => 'Je ne peux pas me connecter',
            'platform' => 'android', 'device' => 'PAX A920 · Android 8.1', 'app_version' => '1.0.10',
        ], ['Accept' => 'application/json'])->assertCreated()->json('reference');

        $this->assertMatchesRegularExpression('/^LOG-[2-9A-Z]{4}$/', $reference);
        $report = DeviceReport::sole();
        $this->assertNull($report->tenant_install_id);
        $this->assertSame('PAX A920 · Android 8.1', $report->device);
        Storage::disk('local')->assertExists($report->file_path);
    }

    public function test_a_logged_in_shop_is_known_and_the_thread_says_the_logs_arrived(): void
    {
        $s = Subscription::create([
            'business_name' => 'Spa', 'contact_name' => 'C', 'email' => 'spa@example.com',
            'desired_subdomain' => 'spaparis', 'currency' => 'EUR', 'status' => Subscription::STATUS_APPROVED,
        ]);
        $install = TenantInstall::create([
            'subscription_id' => $s->id, 'subdomain' => 'spaparis', 'domain' => 'spaparis.castlitpos.com',
            'status' => TenantInstall::STATUS_LIVE, 'access_code' => 'KNS32K',
        ]);
        SupportChat::$keyResolver = fn () => 'base64:client-key';
        config(['app.url' => 'https://spaparis.castlitpos.com', 'app.key' => 'base64:client-key']);
        $identity = SupportChat::signIdentity('Spa', 'Majda', null);
        [$conversation, $secret] = SupportChat::start(['name' => 'Spa', 'channel' => 'app'], $install);

        $reference = $this->post('/api/v1/support/logs', [
            'log' => $this->log(), 'note' => 'Plus d’impression', 'identity' => $identity,
            'conversation_id' => $conversation->public_id, 'conversation_token' => $secret,
        ], ['Accept' => 'application/json'])->assertCreated()->json('reference');

        $report = DeviceReport::sole();
        $this->assertSame($install->id, $report->tenant_install_id);
        $this->assertSame('Majda', $report->user_name);
        $this->assertStringContainsString($reference, $conversation->messages()->sole()->body);
        SupportChat::$keyResolver = null;
    }

    public function test_a_wrong_conversation_secret_links_nothing(): void
    {
        [$conversation] = SupportChat::start(['name' => 'X', 'phone' => '+212600000000', 'channel' => 'app']);

        $this->post('/api/v1/support/logs', [
            'log' => $this->log(), 'conversation_id' => $conversation->public_id, 'conversation_token' => 'guess',
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertNull(DeviceReport::sole()->support_conversation_id);
        $this->assertSame(0, $conversation->messages()->count());
    }

    public function test_the_team_reads_errors_from_the_app_and_from_logcat(): void
    {
        $this->post('/api/v1/support/logs', ['log' => $this->log()], ['Accept' => 'application/json'])->assertCreated();
        $report = DeviceReport::sole();

        $errors = DeviceReports::lines($report, 'error');
        $this->assertCount(2, $errors);
        $this->assertStringContainsString('login failed', $errors[0]);
        $this->assertStringContainsString('out of paper', $errors[1]);
        $this->assertCount(3, DeviceReports::lines($report, 'warning'));
        $this->assertCount(1, DeviceReports::lines($report, 'all', 'PaxPrinter'));

        $admin = User::factory()->create(['is_platform_admin' => true]);
        $this->actingAs($admin)->get(route('castlit.admin.logs'))->assertOk()->assertSee($report->reference);
        $this->actingAs($admin)->get(route('castlit.admin.logs.show', [$report, 'niveau' => 'error']))
            ->assertOk()->assertSee('login failed')->assertDontSee('frame');
        $this->actingAs($admin)->get(route('castlit.admin.logs.download', $report))->assertOk();
    }

    public function test_reports_older_than_thirty_days_are_deleted(): void
    {
        $this->post('/api/v1/support/logs', ['log' => $this->log()], ['Accept' => 'application/json'])->assertCreated();
        $old = DeviceReport::sole();
        $this->travel(31)->days();
        $this->post('/api/v1/support/logs', ['log' => $this->log()], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame(1, DeviceReports::prune());
        $this->assertNull(DeviceReport::find($old->id));
        Storage::disk('local')->assertMissing($old->file_path);
        $this->assertSame(1, DeviceReport::count());
    }

    public function test_the_admin_pages_are_the_platform_admins_only(): void
    {
        $this->actingAs(User::factory()->create())->get(route('castlit.admin.logs'))->assertForbidden();
    }
}
