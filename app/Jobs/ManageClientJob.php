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
 * Runs deploy/manage.sh against one existing client install to update its code,
 * suspend it or reactivate it. Records the outcome on the TenantInstall row.
 *
 * Fast actions (enable/disable) are dispatched synchronously from the admin for
 * instant feedback; the heavy `update` (code copy + migrate) is queued.
 */
class ManageClientJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const ACTIONS = ['update', 'enable', 'disable'];

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
            $cfg['timeout'] ?? 600,
        );

        $log = '';
        try {
            $process->run(function ($type, $buffer) use (&$log): void {
                $log .= $buffer;
            });
        } catch (\Throwable $e) {
            $this->fail($install, 'Manage process error: '.$e->getMessage(), $log);
            return;
        }

        $result = $this->parseFinalJson($process->getOutput());

        if (($result['status'] ?? '') !== 'success') {
            $this->fail($install, $result['message'] ?? 'Action failed (see log).', $log);
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
        $attrs = [
            'current_step'  => $this->stepLabel($this->action).' ✓',
            'provision_log' => trim($log) ?: $install->provision_log,
        ];

        if ($this->action === 'disable') {
            $attrs['is_enabled'] = false;
        } elseif ($this->action === 'enable') {
            $attrs['is_enabled'] = true;
        } elseif ($this->action === 'update') {
            $attrs['is_enabled'] = true; // an update always brings the client back up
            $attrs['commit_sha'] = $result['commit'] ?? $install->commit_sha;
            $attrs['updated_version_at'] = now();
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

    private function stepLabel(string $action): string
    {
        return match ($action) {
            'update'  => 'Mise à jour du code',
            'disable' => 'Suspension',
            'enable'  => 'Réactivation',
            default   => 'Action',
        };
    }

    private function parseFinalJson(string $stdout): array
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", trim($stdout)))));
        $last = end($lines) ?: '';
        $data = json_decode($last, true);

        return is_array($data) ? $data : ['status' => 'error', 'message' => 'No JSON result from manage script.'];
    }

    private function fail(TenantInstall $install, string $message, ?string $log = null): void
    {
        $install->forceFill([
            'current_step'  => $this->stepLabel($this->action).' — échec : '.Str::limit($message, 120),
            'provision_log' => $log !== null ? trim($log) : $install->provision_log,
        ])->save();
        Log::error('CastLit manage action failed', [
            'install' => $install->id, 'action' => $this->action, 'message' => $message,
        ]);
    }
}
