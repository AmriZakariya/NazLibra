<?php

namespace App\Jobs;

use App\Models\TenantInstall;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

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
     * PHP file APIs, then let the client itself run Artisan through its signed
     * maintenance route.
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
            $maintenance = $this->runClientMaintenance($install, $root, 'clear-cache');

            return $maintenance['ok']
                ? ['status' => 'success', 'log' => $maintenance['log']]
                : ['status' => 'error', 'message' => $maintenance['message'], 'log' => $maintenance['log']];
        }

        $this->copyRelease(base_path(), $root);
        $this->clearCompiledCache($root);
        $maintenance = $this->runClientMaintenance($install, $root, 'migrate-and-clear');

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
    private function runClientMaintenance(TenantInstall $install, string $root, string $action): array
    {
        $key = $this->appKey($root.'/.env');
        if ($key === null) {
            return ['ok' => false, 'message' => 'Client APP_KEY is missing.', 'log' => ''];
        }
        $timestamp = (string) time();
        try {
            $response = Http::timeout(180)->acceptJson()->withHeaders([
                'X-Castlit-Timestamp' => $timestamp,
                'X-Castlit-Signature' => hash_hmac('sha256', $action.'|'.$timestamp, $key),
            ])->post('https://'.$install->domain.'/__castlit/maintenance', ['action' => $action]);
            $message = (string) ($response->json('message') ?: $response->body());

            return $response->successful() && $response->json('status') === 'success'
                ? ['ok' => true, 'message' => '', 'log' => $message]
                : ['ok' => false, 'message' => 'Client maintenance failed (HTTP '.$response->status().').', 'log' => $message];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Client maintenance request failed: '.$e->getMessage(), 'log' => ''];
        }
    }

    private function appKey(string $envPath): ?string
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
