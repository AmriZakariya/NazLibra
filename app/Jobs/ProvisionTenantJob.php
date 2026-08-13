<?php

namespace App\Jobs;

use App\Models\Subscription;
use App\Models\TenantInstall;
use App\Notifications\TenantProvisionedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Process\Process;

/**
 * Runs the cPanel provisioning script for one approved subscription and records
 * the outcome on its TenantInstall row. Triggered when a platform admin
 * approves a subscription.
 *
 * The heavy lifting lives in deploy/provision.sh (subdomain, DB, code, .env,
 * migrate, tenant seed). This job just drives it, captures the log, parses the
 * final JSON line and notifies the new owner on success.
 */
class ProvisionTenantJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;
    public int $tries = 1;

    public function __construct(public int $installId)
    {
    }

    public function handle(): void
    {
        $install = TenantInstall::with('subscription')->find($this->installId);
        if (! $install) {
            return;
        }

        $subscription = $install->subscription;
        if (! $subscription) {
            $this->fail($install, 'Subscription missing for this install.');
            return;
        }

        $install->update([
            'status'        => TenantInstall::STATUS_RUNNING,
            'current_step'  => 'Démarrage du provisioning…',
            'provision_log' => null,
        ]);

        $cfg = config('castlit.provision');
        $script = $cfg['script'];
        if (! is_file($script)) {
            $this->fail($install, "Provisioning script not found: {$script}");
            return;
        }

        $payload = base64_encode(json_encode([
            'business_name' => $subscription->business_name,
            'activity'      => $subscription->activity,
            'currency'      => $subscription->currency,
            'contact_name'  => $subscription->contact_name,
            'email'         => $subscription->email,
            'phone'         => $subscription->phone,
        ], JSON_UNESCAPED_UNICODE));

        $process = new Process(
            ['bash', $script, $install->subdomain, $payload],
            null,
            $this->scriptEnv($cfg),
            null,
            $cfg['timeout'] ?? 600,
        );

        $log = '';
        try {
            // Persist the log + current step live as the script emits "▸ …" lines,
            // so the admin can follow progress (and see where it stopped on error).
            $process->run(function ($type, $buffer) use (&$log, $install): void {
                $log .= $buffer;
                $this->persistProgress($install, $log, $buffer);
            });
        } catch (\Throwable $e) {
            $install->update(['provision_log' => $log]);
            $this->fail($install, 'Provisioning process error: '.$e->getMessage());
            return;
        }

        $result = $this->parseFinalJson($process->getOutput());
        $install->provision_log = trim($log);

        if (($result['status'] ?? '') !== 'success') {
            $install->save();
            $this->fail($install, $result['message'] ?? 'Provisioning failed (see log).');
            return;
        }

        $install->fill([
            'status'         => TenantInstall::STATUS_LIVE,
            'current_step'   => 'Espace en ligne ✓',
            'db_name'        => $result['db'] ?? $install->db_name,
            'db_user'        => $result['db_user'] ?? $install->db_user,
            'docroot'        => $result['docroot'] ?? $install->docroot,
            'commit_sha'     => $result['commit'] ?? null,
            'owner_email'    => $result['owner_email'] ?: $subscription->email,
            'provisioned_at' => now(),
        ])->save();

        $this->notifyOwner($install, $subscription, $result['owner_password'] ?? null);
    }

    public function failed(\Throwable $e): void
    {
        $install = TenantInstall::find($this->installId);
        if ($install && $install->status !== TenantInstall::STATUS_LIVE) {
            $this->fail($install, 'Job crashed: '.$e->getMessage());
        }
    }

    private function scriptEnv(array $cfg): array
    {
        return [
            'MAIN_DOMAIN' => config('castlit.main_domain'),
            'BASE_DIR'    => $cfg['public_html'],
            'DB_DRIVER'   => $cfg['db_driver'] ?? 'sqlite',
            'DB_PREFIX'   => $cfg['db_prefix'],
            'REPO_DIR'    => $cfg['repo_dir'],
            'BRANCH'      => $cfg['repo_branch'],
            'REPO_OWNER'  => $cfg['repo_owner'],
            'REPO_NAME'   => $cfg['repo_name'],
            'GH_TOKEN'    => $cfg['github_token'],
            'PHP_BIN'     => $cfg['php_bin'],
            'PATH'        => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
        ];
    }

    /**
     * On each chunk that contains a "▸ step" line, save the growing log and the
     * latest step so a polling admin sees live progress.
     */
    private function persistProgress(TenantInstall $install, string $log, string $buffer): void
    {
        if (! str_contains($buffer, '▸')) {
            $install->forceFill(['provision_log' => trim($log)])->save();
            return;
        }

        $step = null;
        foreach (preg_split('/\r?\n/', trim($buffer)) as $line) {
            $line = trim($line);
            if (str_starts_with($line, '▸')) {
                $step = trim(ltrim($line, "▸ \t"));
            }
        }

        $install->forceFill([
            'provision_log' => trim($log),
            'current_step'  => $step ?: $install->current_step,
        ])->save();
    }

    private function parseFinalJson(string $stdout): array
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", trim($stdout)))));
        $last = end($lines) ?: '';
        $data = json_decode($last, true);

        return is_array($data) ? $data : ['status' => 'error', 'message' => 'No JSON result from provisioning script.'];
    }

    private function notifyOwner(TenantInstall $install, Subscription $subscription, ?string $password): void
    {
        try {
            Notification::route('mail', $subscription->email)
                ->notify(new TenantProvisionedNotification($install, $subscription, $password));
        } catch (\Throwable $e) {
            Log::warning('CastLit: welcome email failed', ['install' => $install->id, 'error' => $e->getMessage()]);
        }
    }

    private function fail(TenantInstall $install, string $message): void
    {
        $install->status = TenantInstall::STATUS_FAILED;
        $install->current_step = 'Échec : '.\Illuminate\Support\Str::limit($message, 140);
        $install->appendLog('✗ '.$message);
        Log::error('CastLit provisioning failed', ['install' => $install->id, 'message' => $message]);
    }
}
