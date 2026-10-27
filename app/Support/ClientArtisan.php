<?php

namespace App\Support;

use App\Models\TenantInstall;
use Symfony\Component\Process\Process;

/**
 * Runs `php artisan …` inside a client space's folder, from the master.
 *
 * The master's own settings must not reach the client's process — its
 * APP_KEY, its database, CASTLIT_MASTER — or the command would run against
 * the master (deploy/manage.sh unsets the same list for the same reason).
 * `false` removes a variable from the child's environment.
 */
final class ClientArtisan
{
    private const MASTER_ONLY = [
        'APP_KEY', 'APP_ENV', 'APP_DEBUG', 'APP_URL', 'APP_NAME', 'CASTLIT_MASTER',
        'DB_CONNECTION', 'DB_DATABASE', 'DB_HOST', 'DB_PORT', 'DB_USERNAME', 'DB_PASSWORD',
        'SESSION_DRIVER', 'QUEUE_CONNECTION', 'CACHE_STORE', 'MAIL_MAILER',
    ];

    /** @param  list<string>  $args  @param  array<string, string>  $env */
    public static function process(TenantInstall $install, array $args, array $env = [], int $timeout = 300): Process
    {
        $php = (string) (config('castlit.provision.php_bin') ?: 'php');
        $clean = array_fill_keys(self::MASTER_ONLY, false);

        return new Process(
            [$php, 'artisan', ...$args],
            $install->docroot,
            $env + $clean + ['PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'],
            null,
            $timeout,
        );
    }

    /** Whether the space has a folder the master can run commands in. */
    public static function reachable(TenantInstall $install): bool
    {
        return $install->docroot !== null && $install->docroot !== ''
            && is_file(rtrim($install->docroot, '/').'/artisan');
    }

    /** The last meaningful line a failed process printed, for the admin. */
    public static function failure(Process $process): string
    {
        $text = trim($process->getErrorOutput()."\n".$process->getOutput());
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $text) ?: [])));

        return mb_substr(end($lines) ?: 'Échec sans message (code '.$process->getExitCode().')', 0, 250);
    }
}
