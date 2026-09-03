<?php

namespace App\Console\Commands;

use App\Jobs\ManageClientJob;
use App\Models\TenantInstall;
use Illuminate\Console\Command;

/**
 * Run a manage.sh action (update / clear-cache / enable / disable) against one
 * existing client install, addressed by subdomain rather than a guessed row id.
 *
 * This replaces hand-written `tinker --execute="...dispatch(1,'update')"` calls,
 * which were fragile: the class name broke whenever the shell wrapped the line,
 * and a numeric id could silently target the wrong client.
 *
 * Usage:
 *   php artisan castlit:client-update demo            # queue it (needs queue:work)
 *   php artisan castlit:client-update demo --now      # run inline, no worker needed
 *   php artisan castlit:client-update demo --action=clear-cache
 */
class ClientUpdateCommand extends Command
{
    protected $signature = 'castlit:client-update
        {subdomain : The client subdomain, e.g. "demo"}
        {--action=update : One of update, clear-cache, enable, disable}
        {--now : Run the action inline instead of queueing it (no queue:work needed)}';

    protected $description = 'Update (or clear-cache/enable/disable) one CastLit client install by subdomain.';

    public function handle(): int
    {
        $action = (string) $this->option('action');

        if (! in_array($action, ManageClientJob::ACTIONS, true)) {
            $this->error("Unknown --action \"{$action}\". Allowed: ".implode(', ', ManageClientJob::ACTIONS));

            return self::FAILURE;
        }

        $subdomain = trim((string) $this->argument('subdomain'));
        $install = TenantInstall::where('subdomain', $subdomain)->first();

        if (! $install) {
            $this->error("No client install found for subdomain \"{$subdomain}\".");
            $known = TenantInstall::orderBy('subdomain')->pluck('subdomain')->all();
            if ($known !== []) {
                $this->line('Known subdomains: '.implode(', ', $known));
            }

            return self::FAILURE;
        }

        if (! $install->isLive()) {
            $this->error("\"{$install->domain}\" is not live (status: {$install->status}) — nothing to do.");

            return self::FAILURE;
        }

        // Same bookkeeping as the admin "Mettre à jour" button, so the client
        // detail page reflects an in-flight action either way.
        $fields = [
            'last_action'  => $action,
            'current_step' => $action === 'update'
                ? 'Mise à jour du code — en attente du worker…'
                : 'Action planifiée — en attente du worker…',
        ];
        if ($action === 'update') {
            $fields['update_log'] = null;
            $fields['updated_log_at'] = now();
        }
        $install->forceFill($fields)->save();

        if ($this->option('now')) {
            $this->info("Running \"{$action}\" on {$install->domain} now — this can take a few minutes…");
            ManageClientJob::dispatchSync($install->id, $action);

            $install->refresh();
            $this->newLine();
            $this->line("Status : {$install->status}");
            $this->line('Step   : '.($install->current_step ?: '—'));
            if ($install->commit_sha) {
                $this->line("Commit : {$install->commit_sha}");
            }

            return self::SUCCESS;
        }

        ManageClientJob::dispatch($install->id, $action);
        $this->info("Queued \"{$action}\" for {$install->domain}.");
        $this->line('Run the worker to execute it:  php artisan queue:work --stop-when-empty');

        return self::SUCCESS;
    }
}
