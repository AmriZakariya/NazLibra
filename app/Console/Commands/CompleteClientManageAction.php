<?php

namespace App\Console\Commands;

use App\Models\TenantInstall;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** Finalizes one shell-run client action and persists its result. */
class CompleteClientManageAction extends Command
{
    protected $signature = 'castlit:complete-manage
        {request : Claimed .running request file}
        {stdout : File containing the script stdout}
        {stderr : File containing the script stderr/progress}
        {exitCode : Shell exit code}';

    protected $description = 'Persist the result of one cron-run CastLit client action.';

    public function handle(): int
    {
        $requestPath = (string) $this->argument('request');
        $request = json_decode((string) @file_get_contents($requestPath), true);
        if (! is_array($request) || empty($request['install_id']) || empty($request['action'])) {
            $this->error('Invalid manage request.');
            return self::FAILURE;
        }

        $install = TenantInstall::find((int) $request['install_id']);
        if (! $install) {
            $this->error('Install not found.');
            return self::FAILURE;
        }

        $stdout = (string) @file_get_contents((string) $this->argument('stdout'));
        $stderr = (string) @file_get_contents((string) $this->argument('stderr'));
        $result = $this->finalJson($stdout);
        $action = (string) $request['action'];
        $log = trim($stderr."\n".$stdout);

        if ((int) $this->argument('exitCode') !== 0 || ($result['status'] ?? '') !== 'success') {
            $message = $result['message'] ?? 'Action shell échouée (voir le journal).';
            $install->forceFill([
                'current_step' => $this->label($action).' — échec : '.Str::limit($message, 120),
                'provision_log' => $log ?: $install->provision_log,
            ])->save();
            $this->error($message);
            return self::FAILURE;
        }

        $attrs = [
            'current_step' => $this->label($action).' ✓',
            'provision_log' => $log ?: $install->provision_log,
        ];
        if ($action === 'update') {
            $attrs['is_enabled'] = true;
            $attrs['commit_sha'] = $result['commit'] ?? $install->commit_sha;
            $attrs['updated_version_at'] = now();
        } elseif ($action === 'disable') {
            $attrs['is_enabled'] = false;
        } elseif ($action === 'enable') {
            $attrs['is_enabled'] = true;
        }
        $install->forceFill($attrs)->save();
        $this->info('Client action completed.');

        return self::SUCCESS;
    }

    private function finalJson(string $stdout): array
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $stdout))));
        $data = json_decode((string) end($lines), true);

        return is_array($data) ? $data : [];
    }

    private function label(string $action): string
    {
        return match ($action) {
            'update' => 'Mise à jour du code',
            'disable' => 'Suspension',
            'enable' => 'Réactivation',
            default => 'Action',
        };
    }
}
