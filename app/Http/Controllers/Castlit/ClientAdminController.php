<?php

namespace App\Http\Controllers\Castlit;

use App\Http\Controllers\Controller;
use App\Jobs\ManageClientJob;
use App\Models\TenantInstall;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;

/**
 * Platform-admin manager for provisioned client installs: see the deployed
 * version of each subdomain, push code updates, and suspend / reactivate them.
 */
class ClientAdminController extends Controller
{
    /** List every install with its live/enabled state and version vs master. */
    public function index(): View
    {
        $installs = TenantInstall::with('subscription')
            ->orderByDesc('provisioned_at')
            ->orderByDesc('id')
            ->paginate(30);

        // Backfill access codes for installs provisioned before this feature.
        $installs->getCollection()->each(fn (TenantInstall $i) => $i->ensureAccessCode());

        $masterSha = $this->masterSha();
        $commits = $this->recentCommits();

        return view('castlit.admin.clients', [
            'installs'  => $installs,
            'masterSha' => $masterSha,
            'commits'   => $commits,
            // How many of the listed commits are newer than the deployed one
            // (i.e. on origin/main but not yet deployed). null = can't tell.
            'behind'    => $this->commitsBehind($masterSha, $commits),
            'upToDate'  => TenantInstall::where('status', TenantInstall::STATUS_LIVE)
                ->when($masterSha, fn ($q) => $q->where('commit_sha', $masterSha))
                ->count(),
            'stats'     => [
                'paid'    => TenantInstall::whereNotNull('paid_at')->count(),
                'trial'   => TenantInstall::whereNull('paid_at')
                    ->whereNotNull('trial_ends_at')
                    ->where('trial_ends_at', '>', now())
                    ->count(),
                'blocked' => TenantInstall::where('is_enabled', false)->count(),
            ],
        ]);
    }

    /**
     * Single flat action endpoint for all per-client operations. Nested,
     * parametrized POSTs (/clients/{id}/maj) were being blocked/404'd by the
     * host's edge WAF; a flat POST with the id in the body (like git-pull, which
     * works) avoids that, and the manual lookup avoids implicit-binding 404s.
     */
    public function action(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'install' => ['required', 'integer'],
            'do'      => ['required', 'in:update,clear-cache,disable,enable,paid,unpaid,regenerate-code'],
        ]);

        $install = TenantInstall::find((int) $data['install']);
        if (! $install) {
            return back()->with('error', "Client introuvable (#{$data['install']}).");
        }

        return match ($data['do']) {
            'update'          => $this->update($install),
            'clear-cache'     => $this->clearCache($install),
            'disable'         => $this->disable($install),
            'enable'          => $this->enable($install),
            'paid'            => $this->markPaid($install),
            'unpaid'          => $this->markUnpaid($install),
            'regenerate-code' => $this->regenerateCode($install),
        };
    }

    /**
     * Redeploy the latest master code to one client. Dispatched to the queue and
     * run by the same worker as the first deploy (ProvisionTenantJob) — that
     * runs in CLI where the shell/copy works, unlike the web process.
     */
    public function update(TenantInstall $install): RedirectResponse
    {
        if (! $install->isLive()) {
            return back()->with('error', "Cet espace n'est pas en ligne : mise à jour impossible.");
        }

        $install->forceFill([
            'last_action'    => 'update',
            'current_step'   => 'Mise à jour du code — en attente du worker…',
            'update_log'     => null,
            'updated_log_at' => now(),
        ])->save();

        ManageClientJob::dispatch($install->id, 'update');

        return back()->with('success',
            "Mise à jour de « {$install->domain} » planifiée. Le worker l’exécutera sous une minute "
            .'(actualisez la page pour voir le résultat).');
    }

    /** Suspend a client — runs synchronously for instant feedback. */
    public function disable(TenantInstall $install): RedirectResponse
    {
        return $this->runSync($install, 'disable', "« {$install->domain} » suspendu.");
    }

    /** Reactivate a suspended client — synchronous. */
    public function enable(TenantInstall $install): RedirectResponse
    {
        return $this->runSync($install, 'enable', "« {$install->domain} » réactivé.");
    }

    /**
     * Clear the compiled caches (views, config, routes, events…) for one client
     * by booting the client app in-process. Useful after a Blade/view change to
     * force new markup to be served on hosts without proc_open.
     */
    public function clearCache(TenantInstall $install): RedirectResponse
    {
        ManageClientJob::dispatchSync($install->id, 'clear-cache');
        $install->refresh();

        return str_contains((string) $install->current_step, 'échec')
            ? back()->with('error', "Vidage du cache impossible pour « {$install->domain} » : {$install->current_step}")
            : back()->with('success', "Cache vidé pour « {$install->domain} ».")
                ->with('update_log', $install->provision_log);
    }

    /** Regenerate the client's mobile access code (invalidates the old one). */
    public function regenerateCode(TenantInstall $install): RedirectResponse
    {
        $install->forceFill(['access_code' => TenantInstall::generateAccessCode()])->save();

        return back()->with('success',
            "Nouveau code d'accès pour « {$install->domain} » : {$install->access_code}. "
            .'Communiquez-le au client.');
    }

    /** Mark the client as paid (ends the trial state). */
    public function markPaid(TenantInstall $install): RedirectResponse
    {
        $install->forceFill(['paid_at' => now()])->save();

        return back()->with('success', "« {$install->domain} » marqué comme payé.");
    }

    /** Revert to unpaid / trial. */
    public function markUnpaid(TenantInstall $install): RedirectResponse
    {
        $install->forceFill(['paid_at' => null])->save();

        return back()->with('success', "« {$install->domain} » remis en essai / impayé.");
    }

    /**
     * Pull the latest code on the master from Git, then refresh the cached
     * version + changelog. Requires the git binary to be runnable by the web
     * process; if it isn't (common on shared hosting), we say so and refresh
     * the version info from GitHub instead.
     */
    public function pullFromGit(): RedirectResponse
    {
        $branch = (string) (config('castlit.provision.repo_branch') ?: 'main');

        $fetch = $this->runGit(['fetch', 'origin', $branch]);
        $reset = $fetch === null ? null : $this->runGit(['reset', '--hard', 'origin/'.$branch]);

        // Always refresh what we show.
        Cache::forget('castlit.master_sha');
        Cache::forget('castlit.recent_commits');

        if ($reset === null) {
            // Git binary can't run here (shared host). Fall back to deploying the
            // latest branch tarball straight from GitHub — no SSH required.
            $result = $this->deployFromGithubTarball($branch);

            Cache::forget('castlit.master_sha');
            Cache::forget('castlit.recent_commits');

            if (! $result['ok']) {
                return back()->with('error',
                    'Déploiement depuis GitHub impossible : '.$result['error'].' '
                    ."Repli SSH : git fetch origin {$branch} && git reset --hard origin/{$branch}.");
            }

            // Clear ALL compiled caches (routes included) in-process so newly
            // added routes/controllers are picked up — proc_open is unavailable
            // on the shared host, so this runs via Artisan::call, not the CLI.
            $this->runArtisan(['optimize:clear']);
            $this->resetOpcache();
            $sha = $this->masterSha();

            return back()->with('success',
                'Code déployé depuis GitHub'.($sha ? " (version {$sha})" : '')
                .'. Déployez ensuite vers chaque client via « Mettre à jour ». '
                .'Si des dépendances ont changé, lancez composer/npm en SSH.');
        }

        // Clear ALL compiled caches (routes included) so the new code is served.
        $this->runArtisan(['optimize:clear']);
        $this->resetOpcache();

        // Keep the deployed-sha marker in sync with the git checkout so the
        // version display stays correct on hosts that later lose the git binary.
        if ($head = $this->runGit(['rev-parse', '--short', 'HEAD'])) {
            $this->writeDeployedSha(trim($head));
        }

        $sha = $this->masterSha();

        return back()->with('success',
            'Code mis à jour depuis Git'.($sha ? " (version {$sha})" : '').'.');
    }

    /**
     * Deploy the latest branch code by downloading the GitHub tarball and
     * extracting it over the master install — the shared-host path when the git
     * binary can't run. Only git-tracked files are overwritten; runtime data
     * (.env, storage, sqlite, uploads) is gitignored so it is never in the
     * tarball and stays untouched. Files deleted upstream are NOT removed.
     *
     * @return array{ok:bool,sha:?string,error:?string}
     */
    private function deployFromGithubTarball(string $branch): array
    {
        $cfg = config('castlit.provision');
        $owner = (string) ($cfg['repo_owner'] ?? '');
        $name = (string) ($cfg['repo_name'] ?? '');
        $token = (string) ($cfg['github_token'] ?? '');

        if ($owner === '' || $name === '') {
            return ['ok' => false, 'sha' => null, 'error' => 'dépôt non configuré'];
        }
        if (! class_exists(\PharData::class)) {
            return ['ok' => false, 'sha' => null, 'error' => 'extension PHP « phar » indisponible'];
        }

        $headSha = $this->githubHeadSha($owner, $name, $branch, $token);

        $work = storage_path('app/castlit-deploy-'.uniqid());
        $tarGz = $work.'.tar.gz';

        try {
            @mkdir($work, 0755, true);

            // Stream the tarball to disk (avoids holding the whole archive in memory).
            $req = Http::timeout(180)->withHeaders([
                'User-Agent' => 'castlit-admin',
                'X-GitHub-Api-Version' => '2022-11-28',
            ])->withOptions(['sink' => $tarGz]);
            if ($token !== '') {
                $req = $req->withToken($token);
            }
            $res = $req->get("https://api.github.com/repos/{$owner}/{$name}/tarball/{$branch}");

            if (! $res->successful() || ! is_file($tarGz) || filesize($tarGz) === 0) {
                return ['ok' => false, 'sha' => null, 'error' => 'téléchargement de l’archive échoué (HTTP '.$res->status().')'];
            }

            // Extract .tar.gz → the archive has a single top-level folder.
            (new \PharData($tarGz))->extractTo($work, null, true);

            $roots = array_values(array_filter(
                glob($work.'/*', GLOB_ONLYDIR) ?: [],
                fn ($d) => is_dir($d),
            ));
            if (count($roots) !== 1) {
                return ['ok' => false, 'sha' => null, 'error' => 'archive inattendue'];
            }

            // Overwrite tracked files in place; never touch .git.
            $this->recursiveCopyOver($roots[0], base_path(), ['.git']);

            if ($headSha) {
                $this->writeDeployedSha($headSha);
            }

            return ['ok' => true, 'sha' => $headSha, 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'sha' => null, 'error' => $e->getMessage()];
        } finally {
            @unlink($tarGz);
            $this->deleteTree($work);
        }
    }

    /** Resolve the short HEAD sha of a branch via the GitHub API (best-effort). */
    private function githubHeadSha(string $owner, string $name, string $branch, string $token): ?string
    {
        try {
            $req = Http::timeout(8)->acceptJson()->withHeaders([
                'User-Agent' => 'castlit-admin',
                'X-GitHub-Api-Version' => '2022-11-28',
            ]);
            if ($token !== '') {
                $req = $req->withToken($token);
            }
            $res = $req->get("https://api.github.com/repos/{$owner}/{$name}/commits/{$branch}");

            return $res->successful()
                ? (substr((string) $res->json('sha'), 0, 7) ?: null)
                : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Recursively copy $src into $dst, overwriting files and creating dirs.
     * Top-level entries in $skip are never copied.
     *
     * @param  array<int,string>  $skip
     */
    private function recursiveCopyOver(string $src, string $dst, array $skip = []): void
    {
        foreach (scandir($src) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || in_array($entry, $skip, true)) {
                continue;
            }
            $from = $src.'/'.$entry;
            $to = $dst.'/'.$entry;

            if (is_dir($from)) {
                if (! is_dir($to)) {
                    @mkdir($to, 0755, true);
                }
                $this->recursiveCopyOver($from, $to, []); // skip only applies at top level
            } else {
                @copy($from, $to);
            }
        }
    }

    /** Recursively delete a directory (cleanup of the extraction workspace). */
    private function deleteTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->deleteTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /** Path of the marker file recording the last tarball-deployed sha. */
    private function deployedShaPath(): string
    {
        return storage_path('app/castlit_deployed.sha');
    }

    /** Read the last deployed sha from the marker file (null if absent). */
    private function deployedShaMarker(): ?string
    {
        $path = $this->deployedShaPath();

        return is_file($path) ? (trim((string) file_get_contents($path)) ?: null) : null;
    }

    /** Persist the deployed sha so the version display survives a missing git binary. */
    private function writeDeployedSha(string $sha): void
    {
        try {
            @file_put_contents($this->deployedShaPath(), substr($sha, 0, 7));
        } catch (\Throwable) {
            // best-effort
        }
    }

    /** Run an artisan command on the master (best-effort). */
    private function runArtisan(array $args): void
    {
        try {
            \Illuminate\Support\Facades\Artisan::call($args[0], array_slice($args, 1));
        } catch (\Throwable) {
            // best-effort
        }
    }

    /**
     * Reset the PHP OPcache pool that serves web requests. On LiteSpeed/LSPHP
     * shared hosts opcache.validate_timestamps is often 0, so freshly deployed
     * code (new routes/controllers) keeps serving stale bytecode until the cache
     * is reset — artisan cache clears do NOT touch OPcache.
     */
    private function resetOpcache(): void
    {
        try {
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
        } catch (\Throwable) {
            // best-effort
        }
    }

    private function runSync(TenantInstall $install, string $action, string $okMessage): RedirectResponse
    {
        if (! $install->isLive()) {
            return back()->with('error', "Cet espace n'est pas en ligne : action impossible.");
        }

        ManageClientJob::dispatchSync($install->id, $action);

        $install->refresh();
        if (str_contains((string) $install->current_step, 'échec')) {
            return back()->with('error', $install->current_step);
        }

        return back()->with('success', $okMessage);
    }

    /** Short HEAD sha of the master checkout (cached 60s) — the "latest" version. */
    private function masterSha(): ?string
    {
        return Cache::remember('castlit.master_sha', 60, function (): ?string {
            $out = $this->runGit(['rev-parse', '--short', 'HEAD']);
            $sha = $out !== null ? trim($out) : '';
            if ($sha !== '') {
                return $sha; // git binary works — authoritative
            }

            // No git binary: prefer the marker written by a tarball deploy
            // (the .git checkout is stale after a tarball extract), then fall
            // back to reading .git directly so the version still shows.
            return $this->deployedShaMarker() ?: $this->shaFromGitDir();
        });
    }

    /**
     * Recent git history (the deployed version log), newest first, cached 60s.
     *
     * @return array<int,array{sha:string,date:string,subject:string,author:string,type:string}>
     */
    private function recentCommits(int $limit = 25): array
    {
        return Cache::remember('castlit.recent_commits', 60, function () use ($limit): array {
            // Prefer the local git binary; fall back to the GitHub API when git
            // can't run in the (shared-host) web process — otherwise the log is
            // empty even though the code is deployed.
            $commits = $this->gitLogCommits($limit);

            return $commits ?: $this->githubCommits($limit);
        });
    }

    /**
     * How many of the (newest-first) commits are newer than the deployed SHA —
     * i.e. sit on origin/main but aren't deployed yet. Returns the index of the
     * deployed commit; null when it isn't in the list (can't determine).
     */
    private function commitsBehind(?string $deployedSha, array $commits): ?int
    {
        if (! $deployedSha || empty($commits)) {
            return null;
        }
        foreach ($commits as $i => $c) {
            $sha = (string) ($c['sha'] ?? '');
            if ($sha !== '' && (str_starts_with($sha, $deployedSha) || str_starts_with($deployedSha, $sha))) {
                return $i; // commits above index $i are newer than deployed
            }
        }

        return null;
    }

    /** Recent commits via the local git binary (empty if git can't run here). */
    private function gitLogCommits(int $limit): array
    {
        // Unit separator between fields, record separator between commits —
        // safe against pipes/quotes in commit messages.
        $fmt = '%h%x1f%cI%x1f%s%x1f%an%x1e';
        $out = $this->runGit(['log', '-n', (string) $limit, '--no-merges', '--pretty=format:'.$fmt]);
        if ($out === null || trim($out) === '') {
            return [];
        }

        $commits = [];
        foreach (explode("\x1e", trim($out)) as $row) {
            $row = trim($row);
            if ($row === '') {
                continue;
            }
            [$sha, $date, $subject, $author] = array_pad(explode("\x1f", $row), 4, '');
            $commits[] = [
                'sha'     => $sha,
                'date'    => $date,
                'subject' => $subject,
                'author'  => $author,
                'type'    => $this->commitType($subject),
            ];
        }

        return $commits;
    }

    /** Recent commits from the GitHub API (works with no git binary on the host). */
    private function githubCommits(int $limit): array
    {
        $cfg = config('castlit.provision');
        $owner = $cfg['repo_owner'] ?? null;
        $name = $cfg['repo_name'] ?? null;
        $branch = $cfg['repo_branch'] ?? 'main';
        $token = (string) ($cfg['github_token'] ?? '');
        if (! $owner || ! $name) {
            return [];
        }

        try {
            $req = Http::timeout(8)->acceptJson()->withHeaders([
                'User-Agent' => 'castlit-admin',
                'X-GitHub-Api-Version' => '2022-11-28',
            ]);
            if ($token !== '') {
                $req = $req->withToken($token);
            }
            $res = $req->get("https://api.github.com/repos/{$owner}/{$name}/commits", [
                'sha' => $branch,
                'per_page' => $limit,
            ]);
            if (! $res->successful()) {
                return [];
            }

            $commits = [];
            foreach ($res->json() as $c) {
                $subject = trim(strtok((string) ($c['commit']['message'] ?? ''), "\n"));
                $commits[] = [
                    'sha'     => substr((string) ($c['sha'] ?? ''), 0, 7),
                    'date'    => $c['commit']['author']['date'] ?? '',
                    'subject' => $subject,
                    'author'  => $c['commit']['author']['name'] ?? '',
                    'type'    => $this->commitType($subject),
                ];
            }

            return $commits;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Run a git command against the deployed checkout with an explicit PATH,
     * HOME and safe.directory so it works under the shared-host / chroot PHP
     * user. Returns stdout, or null if git isn't runnable.
     */
    private function runGit(array $args): ?string
    {
        // Local process execution is disabled by this shared host. GitHub API
        // and the deployed SHA marker provide the no-process fallback.
        return null;
    }

    /** Read the short HEAD sha straight from .git (no git binary needed). */
    private function shaFromGitDir(): ?string
    {
        try {
            $head = base_path('.git/HEAD');
            if (! is_file($head)) {
                return null;
            }
            $ref = trim((string) file_get_contents($head));

            // Detached HEAD: the file holds the sha directly.
            if (! str_starts_with($ref, 'ref:')) {
                return substr($ref, 0, 7) ?: null;
            }

            $refPath = trim(substr($ref, 4));                 // e.g. refs/heads/main
            $loose = base_path('.git/'.$refPath);
            if (is_file($loose)) {
                return substr(trim((string) file_get_contents($loose)), 0, 7) ?: null;
            }

            // Packed refs: "<sha> refs/heads/main".
            $packed = base_path('.git/packed-refs');
            if (is_file($packed)) {
                foreach (file($packed, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                    if (str_ends_with($line, ' '.$refPath)) {
                        return substr((string) strtok($line, ' '), 0, 7) ?: null;
                    }
                }
            }

            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Conventional-commit prefix → a short label for colour-coding the log. */
    private function commitType(string $subject): string
    {
        $prefix = strtolower(strtok($subject, ':'));

        return match (true) {
            str_starts_with($prefix, 'feat')            => 'feat',
            str_starts_with($prefix, 'fix')             => 'fix',
            str_starts_with($prefix, 'refactor')        => 'refactor',
            str_starts_with($prefix, 'perf')            => 'perf',
            str_starts_with($prefix, 'chore'),
            str_starts_with($prefix, 'build'),
            str_starts_with($prefix, 'ci')              => 'chore',
            str_starts_with($prefix, 'docs')            => 'docs',
            str_starts_with($prefix, 'style')           => 'style',
            str_starts_with($prefix, 'test')            => 'test',
            default                                     => 'other',
        };
    }
}
