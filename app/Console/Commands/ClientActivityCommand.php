<?php

namespace App\Console\Commands;

use App\Models\TenantInstall;
use App\Support\ClientActivity;
use Illuminate\Console\Command;

/**
 * MASTER, every 15 minutes: asks every live space what it last did, for the
 * « Dernière activité » column of the clients list.
 */
class ClientActivityCommand extends Command
{
    protected $signature = 'castlit:client-activity {--install= : One space only (its id)}';

    protected $description = 'Collect the latest activity of every live client space (master only).';

    public function handle(): int
    {
        if (! config('castlit.is_master')) {
            $this->error('Master only.');

            return self::FAILURE;
        }

        if ($id = $this->option('install')) {
            $install = TenantInstall::findOrFail((int) $id);
            $ok = ClientActivity::collect($install);
            $this->line($ok ? 'OK' : 'Échec : '.$install->fresh()->activity_error);

            return $ok ? self::SUCCESS : self::FAILURE;
        }

        $this->line(ClientActivity::collectAll().' espace(s) ont répondu.');

        return self::SUCCESS;
    }
}
