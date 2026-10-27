<?php

namespace App\Console\Commands;

use App\Models\TenantInstall;
use App\Support\ClientArtisan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * MASTER, every 15 minutes: runs each client space's own scheduler
 * (`schedule:run`), which nothing else did — a space never marked an invoice
 * overdue nor pruned its error log. One cron line on the master covers every
 * space. Client tasks must therefore fall on a quarter hour (a test checks).
 */
class RunClientSchedulesCommand extends Command
{
    public const HEARTBEAT = 'castlit:client-schedules:ran-at';

    protected $signature = 'castlit:run-client-schedules';

    protected $description = 'Run every live client space scheduler (master only).';

    public function handle(): int
    {
        if (! config('castlit.is_master')) {
            $this->error('Master only.');

            return self::FAILURE;
        }

        $installs = TenantInstall::query()
            ->where('status', TenantInstall::STATUS_LIVE)
            ->where('is_enabled', true)
            ->get();

        foreach ($installs as $install) {
            if (! ClientArtisan::reachable($install)) {
                $install->forceFill(['schedule_error' => 'Dossier introuvable'])->save();
                continue;
            }

            $process = ClientArtisan::process($install, ['schedule:run', '--no-interaction'], [], 300);
            $process->run();
            $install->forceFill([
                'schedule_ran_at' => now(),
                'schedule_error'  => $process->isSuccessful() ? null : ClientArtisan::failure($process),
            ])->save();
        }

        // What castlit-admin reads to say whether the master's cron runs at all.
        Cache::forever(self::HEARTBEAT, now()->toIso8601String());

        return self::SUCCESS;
    }
}
