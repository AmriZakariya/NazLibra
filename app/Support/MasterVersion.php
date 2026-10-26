<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Which commit the master install is running.
 *
 * The admin's Clients page compares each client against it to say "mise à
 * jour disponible", and `castlit:client-update --all` uses the same answer to
 * skip clients already there. It lived privately in the admin controller, so
 * the console could not ask the question the page was already answering.
 *
 * This host has no git binary: a tarball deploy writes a marker file, and the
 * .git directory is read directly as the last resort.
 */
final class MasterVersion
{
    /**
     * Replaces the lookup in tests. The test install has a real .git
     * directory, so "the version cannot be known" is otherwise impossible
     * to produce — and it is a case the update command must handle.
     *
     * @var (\Closure(): ?string)|null
     */
    public static ?\Closure $resolveUsing = null;

    public static function sha(): ?string
    {
        if (self::$resolveUsing !== null) {
            return (self::$resolveUsing)();
        }

        return Cache::remember('castlit.master_sha', 60, function (): ?string {
            return self::marker() ?: self::fromGitDir();
        });
    }

    public static function markerPath(): string
    {
        return storage_path('app/castlit_deployed.sha');
    }

    public static function marker(): ?string
    {
        $path = self::markerPath();

        return is_file($path) ? (trim((string) file_get_contents($path)) ?: null) : null;
    }

    /** Read the short HEAD sha straight from .git (no git binary needed). */
    public static function fromGitDir(): ?string
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
}
