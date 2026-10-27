<?php

namespace Tests\Feature;

use App\Console\Commands\RunClientSchedulesCommand;
use App\Models\Subscription;
use App\Models\TenantInstall;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use PDO;
use Tests\Concerns\ActsAsMasterInstall;
use Tests\TestCase;

/**
 * What the master now does for every client space: nightly database backups,
 * running the space's own scheduler (nothing did — no invoice ever turned
 * overdue), and a support login that is no longer "admin" everywhere.
 */
class ClientUpkeepTest extends TestCase
{
    use ActsAsMasterInstall {
        setUp as actsAsMasterSetUp;
        tearDown as actsAsMasterTearDown;
    }
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        $this->actsAsMasterSetUp();
        Queue::fake();
        Bus::fake();
        $this->root = sys_get_temp_dir().'/castlit-upkeep-'.uniqid();
        File::makeDirectory($this->root, 0755, true);
        config(['castlit.backups.path' => $this->root.'/backups', 'castlit.provision.php_bin' => PHP_BINARY]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        $this->actsAsMasterTearDown();
    }

    /** A client folder with a SQLite database and a stand-in `artisan` that reports what it was given. */
    private function space(string $sub, bool $live = true): TenantInstall
    {
        $docroot = $this->root.'/'.$sub.'.castlitpos.com';
        File::makeDirectory($docroot.'/database', 0755, true);
        $db = new PDO('sqlite:'.$docroot.'/database/database.sqlite');
        $db->exec('CREATE TABLE sales (id INTEGER PRIMARY KEY, total REAL); INSERT INTO sales (total) VALUES (120), (45.5);');
        File::put($docroot.'/artisan', <<<'PHP'
<?php
// Records each call, then answers like the real command would.
file_put_contents(__DIR__.'/calls.log', json_encode([
    'args' => array_slice($argv, 1),
    'master' => getenv('CASTLIT_MASTER'),
    'password' => getenv('CASTLIT_SUPPORT_PASSWORD'),
])."\n", FILE_APPEND);
if (($argv[1] ?? '') === 'castlit:support-password') { echo json_encode(['status' => 'success']), "\n"; }
exit(0);
PHP);

        $s = Subscription::create([
            'business_name' => ucfirst($sub), 'contact_name' => 'C', 'email' => $sub.'@example.com',
            'desired_subdomain' => $sub, 'currency' => 'MAD', 'status' => Subscription::STATUS_APPROVED,
        ]);

        return TenantInstall::create([
            'subscription_id' => $s->id, 'subdomain' => $sub, 'domain' => $sub.'.castlitpos.com', 'docroot' => $docroot,
            'status' => $live ? TenantInstall::STATUS_LIVE : TenantInstall::STATUS_QUEUED, 'access_code' => 'K7M2QX',
        ]);
    }

    /** @return list<array{args: list<string>, master: string|false, password: string|false}> */
    private function calls(TenantInstall $install): array
    {
        $file = $install->docroot.'/calls.log';

        return is_file($file) ? array_map(fn ($l) => json_decode($l, true), array_filter(explode("\n", File::get($file)))) : [];
    }

    public function test_a_space_database_is_backed_up_and_readable(): void
    {
        $install = $this->space('atlas');

        $this->artisan('castlit:backup-clients')->assertSuccessful();

        $files = glob($this->root.'/backups/atlas/*.sqlite.gz');
        $this->assertCount(1, $files);
        $restored = $this->root.'/restored.sqlite';
        File::put($restored, gzdecode(File::get($files[0])));
        $this->assertSame(2, (int) (new PDO('sqlite:'.$restored))->query('SELECT COUNT(*) FROM sales')->fetchColumn(),
            'the copy is a working database with the sales in it');

        $install->refresh();
        $this->assertNotNull($install->last_backup_at);
        $this->assertGreaterThan(0, $install->last_backup_bytes);
        $this->assertNull($install->last_backup_error);
    }

    public function test_old_backups_are_pruned_but_the_last_three_stay(): void
    {
        $install = $this->space('atlas');
        $dir = $this->root.'/backups/atlas';
        File::makeDirectory($dir, 0755, true);
        foreach (['20260101-023000', '20260102-023000', '20260103-023000', '20260104-023000'] as $old) {
            File::put("$dir/atlas-$old.sqlite.gz", 'x');
            touch("$dir/atlas-$old.sqlite.gz", now()->subDays(60)->getTimestamp());
        }

        $this->artisan('castlit:backup-clients')->assertSuccessful();

        $left = array_map('basename', glob("$dir/*.sqlite.gz"));
        $this->assertCount(3, $left, 'tonight copy plus the two newest old ones');
        $this->assertNotContains('atlas-20260101-023000.sqlite.gz', $left);
        $this->assertNotContains('atlas-20260102-023000.sqlite.gz', $left);
    }

    public function test_a_missing_database_is_reported_not_hidden(): void
    {
        $install = $this->space('atlas');
        File::delete($install->docroot.'/database/database.sqlite');

        $this->artisan('castlit:backup-clients')->assertFailed();

        $this->assertStringContainsString('introuvable', (string) $install->refresh()->last_backup_error);
    }

    public function test_each_space_runs_its_own_scheduler_without_the_masters_settings(): void
    {
        $live = $this->space('atlas');
        $notYet = $this->space('pending', live: false);

        $this->artisan('castlit:run-client-schedules')->assertSuccessful();

        $calls = $this->calls($live);
        $this->assertSame(['schedule:run', '--no-interaction'], $calls[0]['args']);
        $this->assertFalse($calls[0]['master'], 'CASTLIT_MASTER must not reach a client space');
        $this->assertNotNull($live->refresh()->schedule_ran_at);
        $this->assertSame([], $this->calls($notYet));
        $this->assertNotNull(Cache::get(RunClientSchedulesCommand::HEARTBEAT));
    }

    public function test_support_passwords_are_random_per_space_and_skip_the_demo(): void
    {
        $atlas = $this->space('atlas');
        $nour = $this->space('nour');
        $demo = $this->space('demo');

        $this->artisan('castlit:rotate-support-passwords --all')->assertSuccessful();

        $atlas->refresh();
        $nour->refresh();
        $this->assertSame('admin@atlas.com', $atlas->support_email);
        $this->assertSame(20, strlen($atlas->support_password));
        $this->assertNotSame($atlas->support_password, $nour->support_password);
        $this->assertSame($atlas->support_password, $this->calls($atlas)[0]['password'],
            'given to the space through the environment, not the command line');
        $this->assertNotContains($atlas->support_password, $this->calls($atlas)[0]['args']);
        $this->assertSame([], $this->calls($demo->refresh()), 'the public demo keeps its public login');
        $this->assertNotSame($atlas->support_password, $atlas->getRawOriginal('support_password'), 'stored encrypted');
    }

    public function test_a_new_space_never_gets_the_shared_admin_password(): void
    {
        config(['castlit.provision.default_admin.password' => null]);
        $command = new \App\Console\Commands\InstallTenantCommand;
        $method = new \ReflectionMethod($command, 'defaultAdmin');
        $command->setLaravel($this->app);
        $input = new \Symfony\Component\Console\Input\ArrayInput(['--subdomain' => 'atlas'], $command->getDefinition());
        $command->setInput($input);

        $one = $method->invoke($command, ['business_name' => 'Atlas']);
        $two = $method->invoke($command, ['business_name' => 'Atlas']);

        $this->assertNotSame('admin', $one['password']);
        $this->assertGreaterThanOrEqual(20, strlen($one['password']));
        $this->assertNotSame($one['password'], $two['password']);
    }

    public function test_the_admin_sees_backups_the_support_login_and_a_dead_cron(): void
    {
        $install = $this->space('atlas');
        $this->artisan('castlit:backup-clients')->assertSuccessful();
        $install->forceFill(['support_email' => 'admin@atlas.com', 'support_password' => 'S3cretSupportPass1234'])->save();
        $admin = User::factory()->create(['is_platform_admin' => true]);

        $page = $this->actingAs($admin)->get('/castlit-admin/clients')->assertOk()->getContent();
        $this->assertStringContainsString('data-upkeep="stale"', $page, 'no heartbeat yet: say the cron is not running');
        $this->assertStringContainsString('artisan schedule:run', $page);
        $this->assertStringContainsString('Sauvegarde il y a', $page);

        $this->actingAs($admin)->get(route('castlit.admin.clients.backup', $install))
            ->assertOk()->assertDownload();

        Cache::forever(RunClientSchedulesCommand::HEARTBEAT, now()->toIso8601String());
        $this->assertStringNotContainsString('data-upkeep="stale"', $this->actingAs($admin)->get('/castlit-admin/clients')->getContent());

        $show = $this->actingAs($admin)->get('/castlit-admin/'.$install->subscription_id)->assertOk()->getContent();
        $this->assertStringContainsString('admin@atlas.com', $show);
        $this->assertStringContainsString('S3cretSupportPass1234', $show);
    }

    public function test_client_tasks_fall_on_a_quarter_hour(): void
    {
        // The master runs a space's scheduler every 15 minutes: a task set for
        // 01:10 would never be due when it looks.
        foreach (app(Schedule::class)->events() as $event) {
            if (str_contains($event->command ?? '', 'castlit:')) {
                continue; // the master's own
            }
            [$minute] = explode(' ', $event->expression);
            foreach (explode(',', $minute) as $m) {
                $this->assertTrue($m === '*' || str_starts_with($m, '*/15') || ((int) $m % 15 === 0),
                    "\"{$event->command}\" runs at minute {$m}, which the master never hits");
            }
        }
    }
}
