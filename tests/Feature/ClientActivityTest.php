<?php

namespace Tests\Feature;

use App\Jobs\CollectClientActivityJob;
use App\Models\Subscription;
use App\Models\TenantInstall;
use App\Models\User;
use App\Support\ClientActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ActsAsMasterInstall;
use Tests\TestCase;

/**
 * castlit-admin says who uses the product: the master asks each space what it
 * last did, keeps the answer, and the clients list shows it.
 */
class ClientActivityTest extends TestCase
{
    use ActsAsMasterInstall {
        tearDown as actsAsMasterTearDown;
    }
    use RefreshDatabase;

    private ?string $root = null;

    protected function tearDown(): void
    {
        if ($this->root) {
            File::deleteDirectory($this->root);
        }
        $this->actsAsMasterTearDown();
    }

    // ── On the master ───────────────────────────────────────────────────────

    /** A space whose stand-in `artisan` records its arguments and prints [$output]. */
    private function space(string $output, int $exit = 0, array $attributes = []): TenantInstall
    {
        config(['castlit.provision.php_bin' => PHP_BINARY]);
        $this->root ??= sys_get_temp_dir().'/castlit-activity-'.uniqid();
        $docroot = $this->root.'/'.uniqid();
        File::makeDirectory($docroot, 0755, true);
        File::put($docroot.'/artisan', '<?php file_put_contents(__DIR__."/args.json", json_encode(array_slice($argv, 1)));'
            .' echo '.var_export($output, true).'; exit('.$exit.');');

        $s = Subscription::create([
            'business_name' => 'Spa '.uniqid(), 'contact_name' => 'A', 'email' => uniqid().'@example.com',
            'desired_subdomain' => 'spa'.uniqid(), 'currency' => 'MAD', 'status' => Subscription::STATUS_APPROVED,
        ]);

        $install = TenantInstall::create([
            'subscription_id' => $s->id, 'subdomain' => $s->desired_subdomain, 'domain' => $s->desired_subdomain.'.castlitpos.com',
            'docroot' => $docroot, 'status' => TenantInstall::STATUS_LIVE, 'support_email' => 'support@spa.castlitpos.com',
        ]);
        // The activity columns are written by the collector only, never mass-assigned.
        $install->forceFill($attributes)->save();

        return $install;
    }

    public function test_the_master_keeps_what_a_space_answered(): void
    {
        $this->travelTo(Carbon::parse('2026-11-09 12:00:00', 'UTC'));
        $install = $this->space("Some notice\n".json_encode([
            'status' => 'success', 'at' => '2026-11-09T11:00:00Z',
            'last' => ['kind' => 'app', 'at' => '2026-11-09T11:00:00Z', 'user' => 'Ahmed', 'device' => 'PAX A920'],
            'sales_today' => 4, 'sales_7d' => 31,
        ])."\n");

        $this->artisan('castlit:client-activity')->assertSuccessful();

        $install->refresh();
        $this->assertSame(['castlit:activity', '--no-interaction', '--ignore=support@spa.castlitpos.com'],
            json_decode(File::get($install->docroot.'/args.json'), true));
        $this->assertTrue($install->activity_at->eq(Carbon::parse('2026-11-09 11:00:00', 'UTC')));
        $this->assertSame(31, $install->activity['sales_7d']);
        $this->assertNull($install->activity_error);
        $this->assertSame(ClientActivity::FRESH, ClientActivity::level($install->activity_at));
        $this->assertSame('Application · PAX A920 · Ahmed', ClientActivity::describe($install->activity));
    }

    public function test_a_space_not_yet_updated_keeps_what_it_said_last(): void
    {
        $install = $this->space('Command "castlit:activity" is not defined.', 1, [
            'activity_at' => '2026-11-01 10:00:00', 'activity' => ['status' => 'success', 'last' => null],
        ]);

        $this->assertFalse(ClientActivity::collect($install));

        $install->refresh();
        $this->assertSame('Espace pas encore mis à jour', $install->activity_error);
        $this->assertSame('2026-11-01 10:00:00', $install->activity_at->format('Y-m-d H:i:s'));
        $this->assertNotNull($install->activity_checked_at);
    }

    public function test_the_list_shows_the_activity_coloured_and_sorts_the_quiet_first(): void
    {
        $this->travelTo(Carbon::parse('2026-11-09 12:00:00', 'UTC'));
        $fresh = $this->space('', 0, ['activity_at' => now()->subHours(2), 'activity' => [
            'status' => 'success', 'last' => ['kind' => 'sale', 'number' => 'BL00042', 'total' => 120.5, 'currency' => 'MAD', 'user' => 'Ahmed'],
            'sales_today' => 4, 'sales_7d' => 31,
        ], 'activity_checked_at' => now()]);
        $idle = $this->space('', 0, ['activity_at' => now()->subDays(3)]);
        $gone = $this->space('', 0, ['activity_at' => now()->subDays(20)]);
        $never = $this->space('');

        $admin = User::factory()->create(['is_platform_admin' => true]);
        $page = $this->actingAs($admin)->get('/castlit-admin/clients')->assertOk();

        $page->assertSee('Dernière activité')
            ->assertSee('data-activity="fresh"', false)
            ->assertSee('data-activity="idle"', false)
            ->assertSee('data-activity="gone"', false)
            ->assertSee('Vente BL00042 · 120,50 MAD · Ahmed')
            ->assertSee('Ventes : 4 aujourd’hui · 31 sur 7 j', false);

        $sorted = $this->actingAs($admin)->get('/castlit-admin/clients?tri=activite')->assertOk()->getContent();
        $positions = array_map(fn (TenantInstall $i) => strpos($sorted, $i->domain), [$never, $gone, $idle, $fresh]);
        $sortedPositions = $positions;
        sort($sortedPositions);
        $this->assertSame($sortedPositions, $positions, 'never seen, then the longest quiet, first');
    }

    public function test_the_client_page_lists_each_device_version_against_the_update_policy(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 12:00:00', 'UTC'));
        \App\Models\AppUpdatePolicy::create(['platform' => 'android', 'latest_version' => '1.0.14', 'min_version' => '1.0.12']);
        $install = $this->space('', 0, ['activity_at' => now(), 'activity' => [
            'status' => 'success', 'last' => null, 'devices' => [
                ['version' => '1.0.14', 'platform' => 'android', 'device' => 'Android 8.1 · PAX A920', 'terminal' => 'Caisse 1', 'user' => 'Ahmed', 'at' => '2026-11-10T11:00:00Z'],
                ['version' => '1.0.13', 'platform' => 'android', 'device' => 'Android 14 · Samsung SM-X200', 'terminal' => null, 'user' => 'Sara', 'at' => '2026-11-09T09:00:00Z'],
                ['version' => '1.0.11', 'platform' => 'android', 'device' => 'Android 11 · Sunmi V2', 'terminal' => null, 'user' => null, 'at' => '2026-10-01T09:00:00Z'],
            ],
        ]]);

        $admin = User::factory()->create(['is_platform_admin' => true]);
        $page = $this->actingAs($admin)->get('/castlit-admin/'.$install->subscription_id)->assertOk();

        $page->assertSee('Versions de l\'application', false)
            ->assertSee('Android 8.1 · PAX A920')
            ->assertSee('data-version-status="ok"', false)
            ->assertSee('data-version-status="soft"', false)
            ->assertSee('data-version-status="force"', false)
            ->assertSee('v1.0.11');
    }

    public function test_a_client_without_versions_says_so(): void
    {
        $install = $this->space('');
        $admin = User::factory()->create(['is_platform_admin' => true]);

        $this->actingAs($admin)->get('/castlit-admin/'.$install->subscription_id)->assertOk()
            ->assertSee('Aucun appareil n\'a encore envoyé sa version', false);
    }

    public function test_refresh_goes_through_the_worker_since_the_web_process_cannot_run_a_space(): void
    {
        $install = $this->space(json_encode(['status' => 'success', 'at' => null, 'last' => null]));
        $admin = User::factory()->create(['is_platform_admin' => true]);
        Queue::fake();

        // The host's web PHP has no proc_open: the request itself must not run the space.
        $this->actingAs($admin)
            ->post('/castlit-admin/clients/op', ['install' => $install->id, 'do' => 'activity'])
            ->assertSessionHas('success');
        $this->actingAs($admin)
            ->post('/castlit-admin/clients/op', ['install' => 0, 'do' => 'activity'])
            ->assertSessionHas('success');

        $this->assertNull($install->fresh()->activity_checked_at);
        Queue::assertPushed(CollectClientActivityJob::class, fn ($job) => $job->installId === $install->id);
        Queue::assertPushed(CollectClientActivityJob::class, fn ($job) => $job->installId === null);

        // And the worker, which can, does.
        (new CollectClientActivityJob($install->id))->handle();
        $this->assertNotNull($install->fresh()->activity_checked_at);
        $this->assertNull($install->fresh()->activity_error);
    }

    public function test_the_per_client_buttons_reach_their_action_and_a_stray_get_still_lands_on_the_list(): void
    {
        $install = $this->space('');
        $admin = User::factory()->create(['is_platform_admin' => true]);

        $this->actingAs($admin)
            ->post('/castlit-admin/clients/op', ['install' => $install->id, 'do' => 'paid'])
            ->assertSessionHas('success');
        $this->assertNotNull($install->fresh()->paid_at);

        $this->actingAs($admin)->get('/castlit-admin/clients/op')->assertRedirect('/castlit-admin/clients');
    }
}
