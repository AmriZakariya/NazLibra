<?php

namespace App\Jobs;

use App\Models\TenantInstall;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;

/**
 * Updates, suspends or reactivates an existing client install without spawning
 * a local shell process. Records the outcome on the TenantInstall row.
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
        $attrs = ['current_step' => $this->stepLabel($this->action).' ✓'];

        // Keep the first-deploy (provision) log intact; updates log separately.
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

    /**
     * Shared hosts often disable proc_open/system/etc.  Manage clients with
     * PHP file APIs, then boot the client application in-process to run its
     * Artisan commands. This avoids both proc_open and outbound HTTP/DNS.
     */
    private function manageWithoutProcess(TenantInstall $install, array $cfg): array
    {
        $root = $this->clientRoot($install, $cfg);
        $this->ensureClientRootExists($root);

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
            $sync = $this->syncClientRelease($root, forceCopy: false);
            $maintenance = $this->runClientMaintenance($install, $root, 'clear-cache');

            return $maintenance['ok']
                ? ['status' => 'success', 'log' => ($sync['restored'] ? "Client code restored.\n" : '').$maintenance['log']]
                : ['status' => 'error', 'message' => $maintenance['message'], 'log' => $maintenance['log']];
        }

        $sync = $this->syncClientRelease($root, forceCopy: true);
        $maintenance = $this->runClientMaintenance($install, $root, 'migrate');

        if (! $maintenance['ok']) {
            return ['status' => 'error', 'message' => $maintenance['message'], 'log' => $maintenance['log']];
        }

        // Stamp the deployed sha into the client so its web UI shows the exact
        // running version (the client has no .git checkout of its own).
        $version = $this->sourceVersion();
        @file_put_contents($root.'/.version', $version);

        return [
            'status' => 'success',
            'commit' => $version,
            'log' => ($sync['restored'] ? "Client code restored.\n" : "Code copied with PHP.\n").$maintenance['log'],
        ];
    }

    /**
     * Copy the master release into the client tree, ensure writable runtime
     * paths exist, and wipe stale compiled caches before booting the client.
     *
     * @return array{restored:bool}
     */
    private function syncClientRelease(string $root, bool $forceCopy): array
    {
        $restored = false;
        $needsCode = ! is_file($root.'/bootstrap/app.php') || ! is_file($root.'/public/index.php');

        if ($needsCode || $forceCopy) {
            $this->copyRelease(base_path(), $root);
            $restored = $needsCode;
        }

        $this->ensureRuntimeDirectories($root);
        $this->clearCompiledCache($root);

        if (! is_file($root.'/bootstrap/app.php')) {
            throw new \RuntimeException('Client application bootstrap is still missing after release sync.');
        }

        return ['restored' => $restored];
    }

    private function ensureClientRootExists(string $root): void
    {
        if (is_dir($root)) {
            return;
        }

        if (! @mkdir($root, 0755, true) && ! is_dir($root)) {
            throw new \RuntimeException("Cannot create client directory: {$root}");
        }
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

    /** Resolve the client folder the wildcard dispatcher actually serves. */
    private function clientRoot(TenantInstall $install, array $cfg): string
    {
        $base = rtrim((string) ($cfg['public_html'] ?? base_path()), '/');
        $domain = $install->domain ?: ($install->subdomain.'.'.config('castlit.main_domain'));
        $canonical = $base.'/'.$domain;
        $recorded = rtrim((string) $install->docroot, '/');

        $candidates = array_values(array_unique(array_filter([$canonical, $recorded])));

        foreach ($candidates as $path) {
            if (is_file($path.'/public/index.php')) {
                return $path;
            }
        }

        return $canonical;
    }

    /** @return array{ok:bool,message:string,log:string} */
    private function runClientMaintenance(TenantInstall $install, string $root, string $action): array
    {
        if (! is_file($root.'/bootstrap/app.php')) {
            return ['ok' => false, 'message' => 'Client application bootstrap is missing.', 'log' => ''];
        }

        $inProcess = $this->runClientMaintenanceInProcess($root, $action);

        // The in-process run clears the client's file caches but executes in the
        // MASTER's PHP worker, so it cannot reset the CLIENT web workers' OPcache
        // (shared hosts run opcache.validate_timestamps=0, so freshly copied code
        // keeps serving stale bytecode). Poke the client's own signed endpoint —
        // it runs in the client pool and resets that pool's OPcache. Best-effort:
        // ignored when outbound HTTP is unavailable; in-process stays authoritative.
        $httpAction = $action === 'migrate' ? 'migrate-and-clear' : $action;
        if (in_array($httpAction, ['migrate-and-clear', 'clear-cache'], true)) {
            $http = $this->runClientMaintenanceViaHttp($install, $root, $httpAction);
            if (! $inProcess['ok'] && $http['ok']) {
                return $http;
            }
        }

        return $inProcess;
    }

    /** @return array{ok:bool,message:string,log:string} */
    private function runClientMaintenanceInProcess(string $root, string $action): array
    {
        $originalApp = Container::getInstance();
        $originalCwd = getcwd();
        try {
            chdir($root);
            $client = require $root.'/bootstrap/app.php';
            $kernel = $client->make(\Illuminate\Contracts\Console\Kernel::class);
            $kernel->bootstrap();

            $log = '';
            if ($action === 'migrate') {
                $kernel->call('config:clear');
                $log = trim($kernel->output());
                $exit = $kernel->call('migrate', ['--force' => true]);
                $log = trim($log."\n".$kernel->output());
                if ($exit !== 0) {
                    return ['ok' => false, 'message' => 'Client migration failed.', 'log' => $log];
                }
                $exit = $kernel->call('optimize:clear');
                $log = trim($log."\n".$kernel->output());
                if ($exit !== 0) {
                    return ['ok' => false, 'message' => 'Client cache clear failed after migration.', 'log' => $log];
                }
                // May clear the client's bytecode too if OPcache SHM is shared
                // across the account's sites (best-effort).
                if (function_exists('opcache_reset')) {
                    @opcache_reset();
                }
            }

            if ($action === 'clear-cache') {
                $exit = $kernel->call('optimize:clear');
                $log = trim($kernel->output());
                if (function_exists('opcache_reset')) {
                    @opcache_reset();
                }

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

    /** @return array{ok:bool,message:string,log:string} */
    private function runClientMaintenanceViaHttp(TenantInstall $install, string $root, string $action): array
    {
        $key = $this->clientAppKey($root.'/.env');
        if ($key === null) {
            return ['ok' => false, 'message' => 'Client APP_KEY is missing.', 'log' => ''];
        }

        $timestamp = (string) time();
        try {
            $response = Http::timeout(180)->acceptJson()->withHeaders([
                'X-Castlit-Timestamp' => $timestamp,
                'X-Castlit-Signature' => hash_hmac('sha256', $action.'|'.$timestamp, $key),
            ])->post('https://'.$install->domain.'/__castlit/maintenance', ['action' => $action]);
            $message = trim((string) ($response->json('message') ?: $response->body()));

            return $response->successful() && $response->json('status') === 'success'
                ? ['ok' => true, 'message' => '', 'log' => $message ?: 'Maintenance complete via HTTP.']
                : ['ok' => false, 'message' => 'Client maintenance failed (HTTP '.$response->status().').', 'log' => $message];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Client maintenance request failed: '.$e->getMessage(), 'log' => ''];
        }
    }

    private function clientAppKey(string $envPath): ?string
    {
        if (! is_file($envPath)) {
            return null;
        }

        foreach (file($envPath, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (str_starts_with($line, 'APP_KEY=')) {
                return trim(substr($line, 8), " \t\"'") ?: null;
            }
        }

        return null;
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
                @chmod($directory, 0777);
            }
            if (! is_writable($directory)) {
                throw new \RuntimeException("Required runtime directory is not writable: {$directory}");
            }
            if (str_ends_with($directory, '/bootstrap/cache') && ! is_file($directory.'/.gitignore')) {
                @file_put_contents($directory.'/.gitignore', "*\n!.gitignore\n");
            }
        }
    }

    private function sourceVersion(): string
    {
        // Runs on the master, which has a .git checkout, so this resolves the
        // real current sha (git → .version → marker).
        return \App\Support\AppVersion::shortSha() ?? 'master';
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
        $attrs = ['current_step' => $this->stepLabel($this->action).' — échec : '.Str::limit($message, 120)];
        if ($this->action === 'update') {
            $attrs['update_log'] = $log !== null ? trim($log) : $install->update_log;
            $attrs['updated_log_at'] = now();
        } else {
            $attrs['provision_log'] = $log !== null ? trim($log) : $install->provision_log;
        }
        $install->forceFill($attrs)->save();
        Log::error('CastLit manage action failed', [
            'install' => $install->id, 'action' => $this->action, 'message' => $message,
        ]);
    }
}
