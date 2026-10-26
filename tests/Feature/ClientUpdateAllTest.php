<?php

namespace Tests\Feature;

use App\Jobs\ManageClientJob;
use App\Models\Subscription;
use App\Models\TenantInstall;
use App\Support\MasterVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * `castlit:client-update --all` — one command that brings every client up to
 * the master version.
 *
 * The real job is run against a stand-in manage.sh that records what it was
 * asked to do and answers the way the real script does, so these tests cover
 * the command, the job and their bookkeeping without touching a client.
 */
class ClientUpdateAllTest extends TestCase
{
    use RefreshDatabase;

    private string $calls;

    private string $script;

    protected function setUp(): void
    {
        parent::setUp();

        $dir = sys_get_temp_dir().'/castlit-manage-'.uniqid();
        mkdir($dir);
        $this->calls = $dir.'/calls.log';
        $this->script = $dir.'/manage.sh';

        // Fails for the subdomain "broken"; otherwise reports success at the
        // master version, exactly as deploy/manage.sh prints its last line.
        file_put_contents($this->script, <<<SH
            #!/bin/bash
            echo "\$1 \$2" >> "{$this->calls}"
            if [ "\$2" = "broken" ]; then
              echo '{"status":"error","message":"migrate failed"}'
              exit 1
            fi
            echo '{"status":"success","commit":"newsha1"}'
            SH);

        config(['castlit.provision.manage_script' => $this->script]);
        MasterVersion::$resolveUsing = fn (): ?string => 'newsha1';
    }

    protected function tearDown(): void
    {
        MasterVersion::$resolveUsing = null;
        parent::tearDown();
    }

    private function client(string $subdomain, array $attributes = []): TenantInstall
    {
        $subscription = Subscription::create([
            'business_name' => 'Boutique '.$subdomain,
            'contact_name' => 'Contact',
            'email' => $subdomain.'@example.com',
            'desired_subdomain' => $subdomain,
            'currency' => 'MAD',
            'status' => Subscription::STATUS_APPROVED,
        ]);

        return TenantInstall::create(array_merge([
            'subscription_id' => $subscription->id,
            'subdomain' => $subdomain,
            'domain' => $subdomain.'.castlitpos.com',
            'status' => TenantInstall::STATUS_LIVE,
            'is_enabled' => true,
            'commit_sha' => 'oldsha1',
        ], $attributes));
    }

    /** @return list<string> "action subdomain", in the order the script was run */
    private function calls(): array
    {
        return is_file($this->calls) ? file($this->calls, FILE_IGNORE_NEW_LINES) : [];
    }

    public function test_it_updates_every_live_client(): void
    {
        $this->client('alpha');
        $this->client('beta');

        $this->artisan('castlit:client-update --all --now')
            ->expectsOutputToContain('2/2 client(s) à jour')
            ->assertSuccessful();

        $this->assertSame(['update alpha', 'update beta'], $this->calls());
        $this->assertSame('newsha1', TenantInstall::where('subdomain', 'alpha')->value('commit_sha'));
    }

    public function test_a_client_that_is_not_live_is_left_alone(): void
    {
        $this->client('alpha');
        $this->client('pending', ['status' => TenantInstall::STATUS_FAILED]);

        $this->artisan('castlit:client-update --all --now')->assertSuccessful();

        $this->assertSame(['update alpha'], $this->calls());
    }

    public function test_a_client_already_on_the_master_version_is_skipped(): void
    {
        $this->client('alpha');
        $this->client('current', ['commit_sha' => 'newsha1']);

        // Running the command twice in a row costs nothing the second time.
        $this->artisan('castlit:client-update --all --now')
            ->expectsOutputToContain('déjà à jour')
            ->assertSuccessful();

        $this->assertSame(['update alpha'], $this->calls());
    }

    public function test_force_updates_them_anyway(): void
    {
        $this->client('current', ['commit_sha' => 'newsha1']);

        $this->artisan('castlit:client-update --all --now --force')->assertSuccessful();

        $this->assertSame(['update current'], $this->calls());
    }

    public function test_an_unknown_master_version_skips_nobody(): void
    {
        MasterVersion::$resolveUsing = fn (): ?string => null;
        $this->client('current', ['commit_sha' => 'newsha1']);

        // With no version to compare against, "up to date" cannot be known,
        // so the safe answer is to update rather than to assume.
        $this->artisan('castlit:client-update --all --now')->assertSuccessful();

        $this->assertSame(['update current'], $this->calls());
    }

    public function test_a_client_with_no_recorded_version_is_updated(): void
    {
        // Never stamped (provisioned before versions were recorded). Unknown
        // equals unknown is not "up to date".
        MasterVersion::$resolveUsing = fn (): ?string => null;
        $this->client('unstamped', ['commit_sha' => null]);

        $this->artisan('castlit:client-update --all --now')->assertSuccessful();

        $this->assertSame(['update unstamped'], $this->calls());
    }

    public function test_a_blocked_client_is_not_unblocked_by_an_update(): void
    {
        // The job re-enables a client on update. Without this, "update all"
        // would silently reopen every shop blocked for non-payment.
        $this->client('alpha');
        $this->client('blocked', ['is_enabled' => false]);

        $this->artisan('castlit:client-update --all --now')
            ->expectsOutputToContain('bloqué')
            ->assertSuccessful();

        $this->assertSame(['update alpha'], $this->calls());
        $this->assertFalse((bool) TenantInstall::where('subdomain', 'blocked')->value('is_enabled'));
    }

    public function test_include_blocked_updates_them_and_blocks_them_again(): void
    {
        $this->client('blocked', ['is_enabled' => false]);

        $this->artisan('castlit:client-update --all --now --include-blocked')
            ->expectsOutputToContain('toujours bloqué')
            ->assertSuccessful();

        $this->assertSame(['update blocked', 'disable blocked'], $this->calls());
        $install = TenantInstall::where('subdomain', 'blocked')->firstOrFail();
        $this->assertFalse((bool) $install->is_enabled);
        $this->assertSame('newsha1', $install->commit_sha);
    }

    public function test_one_failure_does_not_stop_the_others(): void
    {
        $this->client('alpha');
        $this->client('broken');
        $this->client('gamma');

        $this->artisan('castlit:client-update --all --now')
            ->expectsOutputToContain('Échec : broken.castlitpos.com')
            ->assertFailed();

        // Every client was tried; the broken one did not leave gamma behind.
        $this->assertSame(['update alpha', 'update broken', 'update gamma'], $this->calls());
        $this->assertSame('newsha1', TenantInstall::where('subdomain', 'gamma')->value('commit_sha'));
        $this->assertSame('oldsha1', TenantInstall::where('subdomain', 'broken')->value('commit_sha'));
    }

    public function test_all_refuses_to_block_or_unblock_everyone(): void
    {
        $this->client('alpha');

        // One mistyped option should not close every shop.
        $this->artisan('castlit:client-update --all --now --action=disable')->assertFailed();
        $this->artisan('castlit:client-update --all --now --action=enable')->assertFailed();

        $this->assertSame([], $this->calls());
    }

    public function test_all_can_clear_every_cache(): void
    {
        $this->client('alpha', ['commit_sha' => 'newsha1']);

        // Not skipped for being up to date: that only applies to updates.
        $this->artisan('castlit:client-update --all --now --action=clear-cache')->assertSuccessful();

        $this->assertSame(['clear-cache alpha'], $this->calls());
    }

    public function test_a_subdomain_and_all_together_is_refused(): void
    {
        $this->client('alpha');

        $this->artisan('castlit:client-update alpha --all --now')->assertFailed();
        $this->artisan('castlit:client-update --now')->assertFailed();

        $this->assertSame([], $this->calls());
    }

    public function test_without_now_every_update_is_queued(): void
    {
        Bus::fake();
        $this->client('alpha');
        $this->client('blocked', ['is_enabled' => false]);

        $this->artisan('castlit:client-update --all --include-blocked')->assertSuccessful();

        Bus::assertDispatched(ManageClientJob::class, 3); // two updates + re-block
        $this->assertSame([], $this->calls());
    }

    public function test_a_single_client_failure_now_exits_non_zero(): void
    {
        // It always said success before, even when the update had failed, so a
        // script chaining it could not tell.
        $this->client('broken');

        $this->artisan('castlit:client-update broken --now')->assertFailed();
    }

    public function test_a_single_client_still_updates(): void
    {
        $this->client('demo');

        $this->artisan('castlit:client-update demo --now')->assertSuccessful();

        $this->assertSame(['update demo'], $this->calls());
    }

}
