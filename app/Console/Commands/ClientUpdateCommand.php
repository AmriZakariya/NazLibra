<?php

namespace App\Console\Commands;

use App\Jobs\ManageClientJob;
use App\Models\TenantInstall;
use App\Support\MasterVersion;
use Illuminate\Console\Command;

/**
 * Run a manage.sh action (update / clear-cache / enable / disable) against one
 * client install, addressed by subdomain — or update every client at once.
 *
 * This replaces hand-written `tinker --execute="...dispatch(1,'update')"` calls,
 * which were fragile: the class name broke whenever the shell wrapped the line,
 * and a numeric id could silently target the wrong client.
 *
 * Usage:
 *   php artisan castlit:client-update demo            # queue it (needs queue:work)
 *   php artisan castlit:client-update demo --now      # run inline, no worker needed
 *   php artisan castlit:client-update demo --action=clear-cache
 *
 *   php artisan castlit:client-update --all --now     # every client, one by one
 *   php artisan castlit:client-update --all --now --force             # even those already up to date
 *   php artisan castlit:client-update --all --now --include-blocked   # blocked ones too, and they stay blocked
 */
class ClientUpdateCommand extends Command
{
    protected $signature = 'castlit:client-update
        {subdomain? : The client subdomain, e.g. "demo" (or use --all)}
        {--all : Every live client, one after the other}
        {--action=update : One of update, clear-cache, enable, disable}
        {--now : Run inline instead of queueing (no queue:work needed)}
        {--force : With --all, also update clients already on the master version}
        {--include-blocked : With --all, also update blocked clients — they are blocked again afterwards}';

    protected $description = 'Update (or clear-cache/enable/disable) one CastLit client by subdomain, or update them all with --all.';

    /**
     * What --all may do. Not enable/disable: one mistyped option would then
     * block, or unblock, every shop at once.
     */
    private const BULK_ACTIONS = ['update', 'clear-cache'];

    public function handle(): int
    {
        $action = (string) $this->option('action');

        if (! in_array($action, ManageClientJob::ACTIONS, true)) {
            $this->error("Unknown --action \"{$action}\". Allowed: ".implode(', ', ManageClientJob::ACTIONS));

            return self::FAILURE;
        }

        $subdomain = trim((string) $this->argument('subdomain'));
        $all = (bool) $this->option('all');

        if ($all === ($subdomain !== '')) {
            $this->error('Give one subdomain, or --all — not both, not neither.');

            return self::FAILURE;
        }

        return $all ? $this->updateAll($action) : $this->updateOne($subdomain, $action);
    }

    private function updateOne(string $subdomain, string $action): int
    {
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

        $this->markPending($install, $action);

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

            return ManageClientJob::succeeded($install, $action) ? self::SUCCESS : self::FAILURE;
        }

        ManageClientJob::dispatch($install->id, $action);
        $this->info("Queued \"{$action}\" for {$install->domain}.");
        $this->line('Run the worker to execute it:  php artisan queue:work --stop-when-empty');

        return self::SUCCESS;
    }

    /**
     * Every live client, one at a time.
     *
     * Sequential on purpose: each update copies the whole code base and
     * migrates a database, and a shared host does not thank you for doing
     * five of those at once. A failure does not stop the run — one broken
     * client must not leave the others behind — but it does make the
     * command exit non-zero, so a script or a person cannot miss it.
     */
    private function updateAll(string $action): int
    {
        if (! in_array($action, self::BULK_ACTIONS, true)) {
            $this->error("--all only runs ".implode(' or ', self::BULK_ACTIONS)
                .". Run \"{$action}\" one client at a time.");

            return self::FAILURE;
        }

        $master = MasterVersion::sha();
        $force = (bool) $this->option('force');
        $includeBlocked = (bool) $this->option('include-blocked');

        $installs = TenantInstall::where('status', TenantInstall::STATUS_LIVE)
            ->orderBy('subdomain')
            ->get();

        $notLive = TenantInstall::where('status', '!=', TenantInstall::STATUS_LIVE)->count();

        $todo = [];
        $skipped = [];

        foreach ($installs as $install) {
            if ($install->isBlocked() && ! $includeBlocked) {
                // An update brings a client back online (the job re-enables
                // it), so updating a blocked shop would silently unblock it.
                $skipped[] = [$install->domain, 'bloqué — ajoutez --include-blocked (il restera bloqué)'];

                continue;
            }

            if ($action === 'update' && ! $force && $master !== null
                && $install->commit_sha !== null && $install->commit_sha === $master) {
                $skipped[] = [$install->domain, "déjà à jour ({$master})"];

                continue;
            }

            $todo[] = $install;
        }

        $this->info(sprintf(
            'Master %s · %d client(s) à traiter · %d ignoré(s)%s',
            $master ?? '(version inconnue)',
            count($todo),
            count($skipped),
            $notLive > 0 ? " · {$notLive} non en ligne (non concernés)" : '',
        ));
        foreach ($skipped as [$domain, $why]) {
            $this->line("  – {$domain} : {$why}");
        }

        if ($todo === []) {
            $this->info('Rien à faire.');

            return self::SUCCESS;
        }

        if (! $this->option('now')) {
            foreach ($todo as $install) {
                $this->markPending($install, $action);
                ManageClientJob::dispatch($install->id, $action);
                if ($install->isBlocked()) {
                    ManageClientJob::dispatch($install->id, 'disable');
                }
            }
            $this->info('Queued "'.$action.'" for '.count($todo).' client(s).');
            $this->line('Run the worker to execute them:  php artisan queue:work --stop-when-empty');

            return self::SUCCESS;
        }

        $this->newLine();
        $failed = [];

        foreach ($todo as $i => $install) {
            $wasBlocked = $install->isBlocked();
            $this->line(sprintf('[%d/%d] %s …', $i + 1, count($todo), $install->domain));
            $started = microtime(true);

            $this->markPending($install, $action);
            ManageClientJob::dispatchSync($install->id, $action);
            $install->refresh();

            $ok = ManageClientJob::succeeded($install, $action);

            // Back to blocked, if it was: the update re-enabled it.
            if ($ok && $wasBlocked) {
                ManageClientJob::dispatchSync($install->id, 'disable');
                $install->refresh();
                $ok = ManageClientJob::succeeded($install, 'disable');
            }

            $seconds = (int) round(microtime(true) - $started);

            if ($ok) {
                $this->info(sprintf(
                    '      ✓ %s%s (%ds)',
                    $action === 'update' ? ($install->commit_sha ?? '') : 'ok',
                    $wasBlocked ? ' · toujours bloqué' : '',
                    $seconds,
                ));
            } else {
                $this->error('      ✗ '.($install->current_step ?: 'échec sans détail'));
                $failed[] = $install->domain;
            }
        }

        $this->newLine();
        $done = count($todo) - count($failed);
        if ($failed === []) {
            $this->info("Terminé : {$done}/".count($todo).' client(s) à jour.');

            return self::SUCCESS;
        }

        $this->error("Terminé avec des erreurs : {$done}/".count($todo).' réussi(s). Échec : '.implode(', ', $failed));
        $this->line('Le détail de chaque échec est dans la page Détails du client (journal de mise à jour).');

        return self::FAILURE;
    }

    /**
     * Same bookkeeping as the admin "Mettre à jour" button, so the client
     * detail page reflects an in-flight action either way.
     */
    private function markPending(TenantInstall $install, string $action): void
    {
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
    }
}
