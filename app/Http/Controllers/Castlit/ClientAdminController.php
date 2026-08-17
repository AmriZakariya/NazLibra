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
            try {
                $p = new Process(['git', 'rev-parse', '--short', 'HEAD'], base_path());
                $p->run();

                return $p->isSuccessful() ? trim($p->getOutput()) ?: null : null;
            } catch (\Throwable) {
                return null;
            }
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
            try {
                // Unit separator between fields, record separator between commits —
                // safe against pipes/quotes in commit messages.
                $fmt = '%h%x1f%cI%x1f%s%x1f%an%x1e';
                $p = new Process(['git', 'log', '-n', (string) $limit, '--no-merges', '--pretty=format:'.$fmt], base_path());
                $p->run();
                if (! $p->isSuccessful()) {
                    return [];
                }

                $out = [];
                foreach (explode("\x1e", trim($p->getOutput())) as $row) {
                    $row = trim($row);
                    if ($row === '') {
                        continue;
                    }
                    [$sha, $date, $subject, $author] = array_pad(explode("\x1f", $row), 4, '');
                    $out[] = [
                        'sha'     => $sha,
                        'date'    => $date,
                        'subject' => $subject,
                        'author'  => $author,
                        'type'    => $this->commitType($subject),
                    ];
                }

                return $out;
            } catch (\Throwable) {
                return [];
            }
        });
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
