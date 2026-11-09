<?php

namespace App\Jobs;

use App\Models\TenantInstall;
use App\Support\ClientActivity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * « Actualiser » on the clients list. Asking a space runs its artisan, which
 * needs proc_open — off for the web process on the shared host — so the
 * worker does it, like « Mettre à jour ». No id: every live space.
 */
class CollectClientActivityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public ?int $installId = null) {}

    public function handle(): void
    {
        if ($this->installId === null) {
            ClientActivity::collectAll();

            return;
        }

        if ($install = TenantInstall::find($this->installId)) {
            ClientActivity::collect($install);
        }
    }
}
