<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantInstall;
use App\Models\User;
use App\Support\LogTail;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\ActsAsMasterInstall;
use Tests\TestCase;

/**
 * Support could not see what went wrong: Telescope is off in production and
 * the app showed "Erreur" with nothing behind it. The app now reports its
 * sync failures to the server, and the platform admin reads each client's
 * latest errors from its log.
 */
class SupportDiagnosticsTest extends TestCase
{
    use ActsAsMasterInstall {
        setUp as actsAsMasterSetUp;
        tearDown as actsAsMasterTearDown;
    }
    use RefreshDatabase;

    private string $base;

    protected function setUp(): void
    {
        $this->actsAsMasterSetUp();
        $this->base = sys_get_temp_dir().'/journal-'.bin2hex(random_bytes(4));
        mkdir($this->base.'/spa.castlitpos.com/storage/logs', 0755, true);
        config(['castlit.provision.public_html' => $this->base]);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->base);
        // The trait's own tearDown clears CASTLIT_MASTER; skipping it leaks
        // "master" into every test that runs after this one.
        $this->actsAsMasterTearDown();
    }

    private function log(): string
    {
        return "[2026-10-26 15:33:10] production.INFO: Something routine\n"
            ."[2026-10-26 15:33:11] production.ERROR: SQLSTATE[23000]: FOREIGN KEY constraint failed {\"exception\":\"[object]\"}\n"
            ."#0 /htdocs/app/Http/Controllers/Api/SyncController.php(325): query()\n"
            ."#1 {main}\n"
            ."[2026-10-26 16:10:02] production.WARNING: App sync failed: stock — HTTP 500 {\"section\":\"stock\"}\n";
    }

    private function install(string $docroot): TenantInstall
    {
        $sub = Subscription::create([
            'business_name' => 'Spa', 'contact_name' => 'Owner', 'email' => 'owner@example.com',
            'desired_subdomain' => 'spa', 'currency' => 'EUR', 'status' => Subscription::STATUS_APPROVED,
        ]);

        return TenantInstall::create([
            'subscription_id' => $sub->id, 'subdomain' => 'spa', 'domain' => 'spa.castlitpos.com',
            'docroot' => $docroot, 'status' => TenantInstall::STATUS_LIVE, 'owner_email' => 'owner@example.com',
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_platform_admin' => true]);
    }

    public function test_the_log_reads_as_errors_newest_first(): void
    {
        $file = $this->base.'/laravel.log';
        file_put_contents($file, $this->log());

        $entries = LogTail::entries($file);

        $this->assertCount(2, $entries, 'routine info is left out; app sync failures are kept');
        $this->assertSame('WARNING', $entries[0]['level']);
        $this->assertStringContainsString('App sync failed: stock', $entries[0]['message']);
        $this->assertSame('ERROR', $entries[1]['level']);
        $this->assertStringContainsString('SyncController.php(325)', $entries[1]['details']);

        $this->assertCount(3, LogTail::entries($file, 150, false));
    }

    public function test_only_the_end_of_a_big_log_is_read(): void
    {
        $file = $this->base.'/big.log';
        file_put_contents($file, str_repeat("[2026-10-01 10:00:00] production.INFO: filler line\n", 60000).$this->log());

        $tail = LogTail::tail($file, 4000);

        $this->assertLessThanOrEqual(4000, strlen($tail));
        $this->assertStringStartsWith('[', $tail, 'cut at a line boundary');
        $this->assertStringContainsString('App sync failed', $tail);
    }

    public function test_the_admin_reads_a_clients_errors(): void
    {
        $docroot = $this->base.'/spa.castlitpos.com';
        file_put_contents($docroot.'/storage/logs/laravel.log', $this->log());
        $install = $this->install($docroot);

        $this->actingAs($this->admin())
            ->get(route('castlit.admin.clients.journal', ['install' => $install->id]))
            ->assertOk()
            ->assertSee('FOREIGN KEY constraint failed')
            ->assertSee('App sync failed: stock')
            ->assertDontSee('Something routine');

        $download = $this->actingAs($this->admin())
            ->get(route('castlit.admin.clients.journal.download', ['install' => $install->id]))
            ->assertOk();
        $this->assertStringContainsString('attachment', (string) $download->headers->get('Content-Disposition'));
        $this->assertStringContainsString('Something routine', $download->getContent());
    }

    public function test_a_docroot_outside_the_hosting_folder_is_never_read(): void
    {
        $outside = sys_get_temp_dir().'/outside-'.bin2hex(random_bytes(4));
        mkdir($outside.'/storage/logs', 0755, true);
        file_put_contents($outside.'/storage/logs/laravel.log', $this->log());
        $install = $this->install($outside);

        $this->actingAs($this->admin())
            ->get(route('castlit.admin.clients.journal', ['install' => $install->id]))
            ->assertOk()
            ->assertSee('Aucun journal trouvé')
            ->assertDontSee('FOREIGN KEY');

        (new Filesystem)->deleteDirectory($outside);
    }

    public function test_the_journal_is_for_platform_admins_only(): void
    {
        $install = $this->install($this->base.'/spa.castlitpos.com');

        $this->actingAs(User::factory()->create(['is_platform_admin' => false]))
            ->get(route('castlit.admin.clients.journal', ['install' => $install->id]))
            ->assertForbidden();
    }

    public function test_the_app_reports_a_sync_failure_to_the_log(): void
    {
        $this->seed();
        $tenant = Tenant::firstOrFail();
        $user = User::where('email', 'amina@librairie-atlas.ma')->firstOrFail();
        Log::spy();

        $this->withToken($user->createToken('t')->plainTextToken)
            ->withHeader('X-Tenant-Slug', $tenant->slug)
            ->postJson('/api/v1/diagnostics/sync-error', [
                'section' => 'stock',
                'message' => 'HTTP 500 — Server Error',
                'status' => 500,
                'endpoint' => '/api/v1/sync/stock',
                'app_version' => '1.0.4',
                'platform' => 'android',
            ])
            ->assertOk();

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context): bool => str_contains($message, 'App sync failed: stock')
                && ($context['status'] ?? null) === 500
                && ($context['user_id'] ?? null) === $user->id,
        );
    }

    public function test_a_report_needs_a_signed_in_device(): void
    {
        $this->postJson('/api/v1/diagnostics/sync-error', ['section' => 'stock', 'message' => 'x'])
            ->assertUnauthorized();
    }
}
