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
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;

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
        $install->forceFill([
            'last_action'  => $this->action,
            'current_step' => $this->stepLabel($this->action).'…',
        ])->save();

        try {
            $result = $this->manageWithoutProcess($install, $cfg);
        } catch (\Throwable $e) {
            $this->fail($install, 'Manage action error: '.$e->getMessage());
            return;
        }

        if (($result['status'] ?? '') !== 'success') {
            $this->fail($install, $result['message'] ?? 'Action failed (see log).', $result['log'] ?? null);
            return;
        }

        $this->applySuccess($install, $result, $result['log'] ?? '');
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

    /**
     * Shared hosts often disable proc_open/system/etc.  Manage clients with
     * PHP file APIs, then boot the client application in-process to run its
     * Artisan commands. This avoids both proc_open and outbound HTTP/DNS.
     */
    private function manageWithoutProcess(TenantInstall $install, array $cfg): array
    {
        $root = rtrim((string) ($cfg['public_html'] ?? base_path()), '/').'/'.$install->domain;
        if (! is_dir($root)) {
            return ['status' => 'error', 'message' => "Client dir not found: {$root}"];
        }

        if ($this->action === 'disable') {
            if (@file_put_contents($root.'/.suspended', '') === false) {
                return ['status' => 'error', 'message' => 'Unable to suspend this client directory.'];
            }
            return ['status' => 'success', 'log' => 'Client suspended.'];
        }

        if ($this->action === 'enable') {
            if (is_file($root.'/.suspended') && ! @unlink($root.'/.suspended')) {
                return ['status' => 'error', 'message' => 'Unable to reactivate this client directory.'];
            }
            return ['status' => 'success', 'log' => 'Client reactivated.'];
        }

        if ($this->action === 'clear-cache') {
            $recovered = false;
            if (! is_file($root.'/bootstrap/app.php')) {
                // A host-side `git clean` can remove the client's untracked
                // release files while keeping its ignored .env/database/data.
                // Rehydrate code first; copyRelease deliberately preserves that
                // tenant-specific data.
                $this->copyRelease(base_path(), $root);
                $recovered = true;
            }
            $this->ensureRuntimeDirectories($root);
            $this->clearCompiledCache($root);
            $maintenance = $this->runClientMaintenance($root, 'clear-cache');

            return $maintenance['ok']
                ? ['status' => 'success', 'log' => ($recovered ? "Client code restored.\n" : '').$maintenance['log']]
                : ['status' => 'error', 'message' => $maintenance['message'], 'log' => $maintenance['log']];
        }

        $this->copyRelease(base_path(), $root);
        $this->ensureRuntimeDirectories($root);
        $maintenance = $this->runClientMaintenance($root, 'migrate');

        if (! $maintenance['ok']) {
            return ['status' => 'error', 'message' => $maintenance['message'], 'log' => $maintenance['log']];
        }

        return [
            'status' => 'success',
            'commit' => $this->sourceVersion(),
            'log' => "Code copied with PHP.\n".$maintenance['log'],
        ];
    }

    private function copyRelease(string $source, string $destination, string $relative = ''): void
    {
        $skipDirs = ['.git', 'node_modules', 'storage'];
        foreach (scandir($source) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = ltrim($relative.'/'.$entry, '/');
            if (in_array($path, ['.env', '.htaccess', '.suspended', '.version', 'suspended.html'], true)
                || in_array($path, ['bootstrap/cache', 'public/storage'], true)
                || ($relative === '' && in_array($entry, $skipDirs, true))
                || ($relative === '' && str_ends_with($entry, '.'.config('castlit.main_domain')))
                || ($relative === 'database' && str_ends_with($entry, '.sqlite'))) {
                continue;
            }

            $from = $source.'/'.$entry;
            $to = $destination.'/'.$entry;
            if (is_dir($from)) {
                if (! is_dir($to) && ! @mkdir($to, 0755, true) && ! is_dir($to)) {
                    throw new \RuntimeException("Cannot create {$to}");
                }
                $this->copyRelease($from, $to, $path);
            } elseif (! @copy($from, $to)) {
                throw new \RuntimeException("Cannot copy {$path}");
            }
        }
    }

    /** @return array{ok:bool,message:string,log:string} */
    private function runClientMaintenance(string $root, string $action): array
    {
        if (! is_file($root.'/bootstrap/app.php')) {
            return ['ok' => false, 'message' => 'Client application bootstrap is missing.', 'log' => ''];
        }

        $originalApp = Container::getInstance();
        $originalCwd = getcwd();
        try {
            chdir($root);
            $client = require $root.'/bootstrap/app.php';
            $kernel = $client->make(\Illuminate\Contracts\Console\Kernel::class);
            $kernel->bootstrap();

            $log = '';
            if ($action === 'migrate') {
                $exit = $kernel->call('migrate', ['--force' => true]);
                $log .= trim($kernel->output());
                if ($exit !== 0) {
                    return ['ok' => false, 'message' => 'Client migration failed.', 'log' => $log];
                }
            }

            if ($action === 'clear-cache') {
                $exit = $kernel->call('optimize:clear');
                $log = trim($log."\n".$kernel->output());

                return $exit === 0
                    ? ['ok' => true, 'message' => '', 'log' => $log ?: 'Cache cleared.']
                    : ['ok' => false, 'message' => 'Client cache clear failed.', 'log' => $log];
            }

            return ['ok' => true, 'message' => '', 'log' => $log ?: 'Migration complete.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Client maintenance failed: '.$e->getMessage(), 'log' => ''];
        } finally {
            if ($originalCwd !== false) {
                chdir($originalCwd);
            }
            Container::setInstance($originalApp);
            Facade::setFacadeApplication($originalApp);
        }
    }

    /** Remove only Laravel's generated cache files, preserving .gitignore. */
    private function clearCompiledCache(string $root): void
    {
        $cache = $root.'/bootstrap/cache';
        foreach (glob($cache.'/*.php') ?: [] as $file) {
            if (! @unlink($file)) {
                throw new \RuntimeException("Cannot clear compiled cache file {$file}");
            }
        }
    }

    /** Ensure older client installs have Laravel's writable runtime paths. */
    private function ensureRuntimeDirectories(string $root): void
    {
        $directories = [
            $root.'/bootstrap/cache',
            $root.'/storage/framework/cache/data',
            $root.'/storage/framework/sessions',
            $root.'/storage/framework/views',
            $root.'/storage/logs',
        ];

        foreach ($directories as $directory) {
            if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
                throw new \RuntimeException("Cannot create required runtime directory: {$directory}");
            }
            @chmod($directory, 0775);
            if (! is_writable($directory)) {
                throw new \RuntimeException("Required runtime directory is not writable: {$directory}");
            }
        }
    }

    private function sourceVersion(): string
    {
        $marker = storage_path('app/castlit_deployed.sha');
        return is_file($marker) ? (trim((string) file_get_contents($marker)) ?: 'master') : 'master';
    }

    private function stepLabel(string $action): string
    {
        return match ($action) {
            'update'  => 'Mise à jour du code',
            'clear-cache' => 'Vidage du cache',
            'disable' => 'Suspension',
            'enable'  => 'Réactivation',
            default   => 'Action',
        };
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
