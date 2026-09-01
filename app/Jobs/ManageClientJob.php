<?php

namespace App\Jobs;

use App\Models\TenantInstall;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Updates, suspends or reactivates one existing client install by driving
 * deploy/manage.sh — the SAME Process-based pattern as ProvisionTenantJob (the
 * first deploy), which is proven to work on this host. Records the outcome on
 * the TenantInstall row; update logs are kept separate from the first-deploy
 * (provision) log.
 */
class ManageClientJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const ACTIONS = ['update', 'clear-cache', 'enable', 'disable'];

    public int $timeout = 900;
    public int $tries = 1;

    public function __construct(public int $installId, public string $action)
    {
    }

    public function handle(): void
    {
        if (! in_array($this->action, self::ACTIONS, true)) {
            return;
        }

        $install = TenantInstall::find($this->installId);
        if (! $install || ! $install->isLive()) {
            return;
        }

        $cfg = config('castlit.provision');
        $script = $cfg['manage_script'] ?? base_path('deploy/manage.sh');
        if (! is_file($script)) {
            $this->fail($install, "Manage script not found: {$script}");
            return;
        }

        $install->forceFill([
            'last_action'  => $this->action,
            'current_step' => $this->stepLabel($this->action).'…',
        ])->save();

        $process = new Process(
            ['bash', $script, $this->action, $install->subdomain],
            null,
            $this->scriptEnv($cfg),
            null,
            $cfg['timeout'] ?? 900,
        );

        $log = '';
        try {
            $process->run(function ($type, $buffer) use (&$log, $install): void {
                $log .= $buffer;
                $this->persistProgress($install, $log, $buffer);
            });
        } catch (\Throwable $e) {
            $this->fail($install, 'Manage process error: '.$e->getMessage(), $log);
            return;
        }

        $result = $this->parseFinalJson($process->getOutput());
        if (($result['status'] ?? '') !== 'success') {
            $this->fail($install, $result['message'] ?? 'Action échouée (voir le journal).', $log);
            return;
        }

        $this->applySuccess($install, $result, $log);
    }

    public function failed(\Throwable $e): void
    {
        $install = TenantInstall::find($this->installId);
        if ($install) {
            $this->fail($install, 'Job crashed: '.$e->getMessage());
        }
    }

    private function applySuccess(TenantInstall $install, array $result, string $log): void
    {
        $attrs = ['current_step' => $this->stepLabel($this->action).' ✓'];

        if ($this->action === 'update') {
            $attrs['update_log'] = trim($log) ?: $install->update_log;
            $attrs['updated_log_at'] = now();
            $attrs['is_enabled'] = true; // an update always brings the client back up
            $attrs['commit_sha'] = $result['commit'] ?? $install->commit_sha;
            $attrs['updated_version_at'] = now();
        } else {
            $attrs['provision_log'] = trim($log) ?: $install->provision_log;
            if ($this->action === 'disable') {
                $attrs['is_enabled'] = false;
            } elseif ($this->action === 'enable') {
                $attrs['is_enabled'] = true;
            }
        }

        $install->forceFill($attrs)->save();
    }

    private function scriptEnv(array $cfg): array
    {
        return [
            'MAIN_DOMAIN' => config('castlit.main_domain'),
            'BASE_DIR'    => $cfg['public_html'],
            'SOURCE_DIR'  => base_path(),
            'PHP_BIN'     => $cfg['php_bin'],
            'PATH'        => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
        ];
    }

    /** Persist the growing log + latest "▸ step" so the admin sees live progress. */
    private function persistProgress(TenantInstall $install, string $log, string $buffer): void
    {
        $field = $this->action === 'update' ? 'update_log' : 'provision_log';

        if (! str_contains($buffer, '▸')) {
            $install->forceFill([$field => trim($log)])->save();
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
            $field         => trim($log),
            'current_step' => $step ?: $install->current_step,
        ])->save();
    }

    private function parseFinalJson(string $stdout): array
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", trim($stdout)))));
        $data = json_decode(end($lines) ?: '', true);

        return is_array($data) ? $data : ['status' => 'error', 'message' => 'No JSON result from manage script.'];
    }

    private function stepLabel(string $action): string
    {
        return match ($action) {
            'update'      => 'Mise à jour du code',
            'clear-cache' => 'Vidage du cache',
            'disable'     => 'Suspension',
            'enable'      => 'Réactivation',
            default       => 'Action',
        };
    }

    private function fail(TenantInstall $install, string $message, ?string $log = null): void
    {
        $field = $this->action === 'update' ? 'update_log' : 'provision_log';
        $attrs = ['current_step' => $this->stepLabel($this->action).' — échec : '.Str::limit($message, 120)];
        if ($log !== null) {
            $attrs[$field] = trim($log);
        }
        if ($this->action === 'update') {
            $attrs['updated_log_at'] = now();
        }
        $install->forceFill($attrs)->save();

        Log::error('CastLit manage action failed', [
            'install' => $install->id, 'action' => $this->action, 'message' => $message,
        ]);
    }
}
