<?php

namespace App\Support;

/**
 * Resolves the short commit sha of the code that is actually running, so the
 * deployed version is verifiable from the web UI. Order:
 *   1. .git/HEAD        — the master keeps a checkout (authoritative there)
 *   2. .version         — stamped into each client folder at deploy time
 *   3. deployed marker  — written by the master's git-pull
 */
class AppVersion
{
    /** Short 7-char sha of the running deploy, or null if it can't be resolved. */
    public static function shortSha(): ?string
    {
        static $cached = false;
        if ($cached !== false) {
            return $cached;
        }

        return $cached = self::fromGit() ?? self::fromVersionFile() ?? self::fromMarker();
    }

    private static function fromVersionFile(): ?string
    {
        $file = base_path('.version');
        if (! is_file($file)) {
            return null;
        }
        $v = trim((string) @file_get_contents($file));

        return $v !== '' ? substr($v, 0, 7) : null;
    }

    private static function fromMarker(): ?string
    {
        $file = storage_path('app/castlit_deployed.sha');
        if (! is_file($file)) {
            return null;
        }
        $v = trim((string) @file_get_contents($file));

        return $v !== '' ? substr($v, 0, 7) : null;
    }

    private static function fromGit(): ?string
    {
        $head = base_path('.git/HEAD');
        if (! is_file($head)) {
            return null;
        }
        $ref = trim((string) @file_get_contents($head));

        // Detached HEAD: the sha is stored directly.
        if (! str_starts_with($ref, 'ref:')) {
            return $ref !== '' ? substr($ref, 0, 7) : null;
        }

        $refPath = trim(substr($ref, 4));                 // e.g. refs/heads/main
        $loose = base_path('.git/'.$refPath);
        if (is_file($loose)) {
            return substr(trim((string) @file_get_contents($loose)), 0, 7) ?: null;
        }

        $packed = base_path('.git/packed-refs');
        if (is_file($packed)) {
            foreach (file($packed, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                if (str_ends_with($line, ' '.$refPath)) {
                    return substr((string) strtok($line, ' '), 0, 7) ?: null;
                }
            }
        }

        return null;
    }
}
