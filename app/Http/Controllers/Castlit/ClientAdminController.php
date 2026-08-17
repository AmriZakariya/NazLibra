<?php

namespace App\Http\Controllers\Castlit;

use App\Http\Controllers\Controller;
use App\Jobs\ManageClientJob;
use App\Models\TenantInstall;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Symfony\Component\Process\Process;

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

        $masterSha = $this->masterSha();

        return view('castlit.admin.clients', [
            'installs'  => $installs,
            'masterSha' => $masterSha,
            'commits'   => $this->recentCommits(),
            'upToDate'  => TenantInstall::where('status', TenantInstall::STATUS_LIVE)
                ->when($masterSha, fn ($q) => $q->where('commit_sha', $masterSha))
                ->count(),
        ]);
    }

    /** Redeploy the latest master code to one client (queued — heavy). */
    public function update(TenantInstall $install): RedirectResponse
    {
        if (! $install->isLive()) {
            return back()->with('error', "Cet espace n'est pas en ligne : mise à jour impossible.");
        }

        $install->forceFill(['current_step' => 'Mise à jour en file d’attente…'])->save();
        ManageClientJob::dispatch($install->id, 'update');

        return back()->with('success', "Mise à jour de « {$install->domain} » lancée.");
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

            // Fall back to reading .git directly when the git binary can't run
            // (shared-host PATH / chroot ownership), so the version still shows.
            return $sha !== '' ? $sha : $this->shaFromGitDir();
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
        });
    }

    /**
     * Run a git command against the deployed checkout with an explicit PATH,
     * HOME and safe.directory so it works under the shared-host / chroot PHP
     * user. Returns stdout, or null if git isn't runnable.
     */
    private function runGit(array $args): ?string
    {
        try {
            $env = [
                'PATH' => '/usr/local/bin:/usr/bin:/bin:/usr/local/sbin:/usr/sbin:'.(getenv('PATH') ?: ''),
                'HOME' => storage_path('app'),
            ];
            $process = new Process(
                array_merge(['git', '-c', 'safe.directory=*'], $args),
                base_path(),
                $env,
            );
            $process->run();

            return $process->isSuccessful() ? $process->getOutput() : null;
        } catch (\Throwable) {
            return null;
        }
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
