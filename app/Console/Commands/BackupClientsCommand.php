<?php

namespace App\Console\Commands;

use App\Models\TenantInstall;
use Illuminate\Console\Command;
use PDO;
use Throwable;

/**
 * MASTER, nightly: a copy of every client space's SQLite database, gzipped,
 * kept `castlit.backups.keep_days` days (never fewer than the last 3).
 *
 * `VACUUM INTO` takes a consistent copy while the shop keeps selling; a plain
 * file copy could catch the database mid-write.
 *
 *   php artisan castlit:backup-clients            # every live space
 *   php artisan castlit:backup-clients spaparis   # one
 */
class BackupClientsCommand extends Command
{
    protected $signature = 'castlit:backup-clients {subdomain? : One space only}';

    protected $description = 'Back up every client space database (master only).';

    public function handle(): int
    {
        if (! config('castlit.is_master')) {
            $this->error('Master only.');

            return self::FAILURE;
        }

        $installs = TenantInstall::query()
            ->where('status', TenantInstall::STATUS_LIVE)
            ->when($this->argument('subdomain'), fn ($q, $sub) => $q->where('subdomain', $sub))
            ->get();

        $failed = 0;
        foreach ($installs as $install) {
            try {
                $bytes = $this->backup($install);
                $install->forceFill(['last_backup_at' => now(), 'last_backup_bytes' => $bytes, 'last_backup_error' => null])->save();
                $this->info(sprintf('✓ %s : %s Ko', $install->subdomain, number_format($bytes / 1024, 0, ',', ' ')));
            } catch (Throwable $e) {
                $failed++;
                $install->forceFill(['last_backup_error' => mb_substr($e->getMessage(), 0, 250)])->save();
                $this->warn("✗ {$install->subdomain} : ".$e->getMessage());
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return int the size of the gzipped copy */
    private function backup(TenantInstall $install): int
    {
        $source = rtrim((string) $install->docroot, '/').'/database/database.sqlite';
        if (! is_file($source)) {
            throw new \RuntimeException('Base introuvable : '.$source);
        }

        $dir = self::dir($install->subdomain);
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Dossier de sauvegarde impossible à créer.');
        }

        $stamp = now()->format('Ymd-His');
        $raw = $dir.'/'.$install->subdomain.'-'.$stamp.'.sqlite';
        $gz = $raw.'.gz';

        try {
            $db = new PDO('sqlite:'.$source, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $db->exec('VACUUM INTO '.$db->quote($raw));
            $db = null;

            $in = fopen($raw, 'rb');
            $out = gzopen($gz, 'wb6');
            if ($in === false || $out === false) {
                throw new \RuntimeException('Écriture de la sauvegarde impossible.');
            }
            while (! feof($in)) {
                gzwrite($out, (string) fread($in, 1 << 20));
            }
            fclose($in);
            gzclose($out);
        } finally {
            if (is_file($raw)) {
                unlink($raw);
            }
        }

        $this->prune($dir);

        return (int) filesize($gz);
    }

    private function prune(string $dir): void
    {
        $files = glob($dir.'/*.sqlite.gz') ?: [];
        rsort($files); // newest first: the names carry the date
        $cutoff = now()->subDays(max(1, (int) config('castlit.backups.keep_days', 14)))->getTimestamp();
        foreach (array_slice($files, 3) as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
            }
        }
    }

    public static function dir(string $subdomain): string
    {
        return rtrim((string) config('castlit.backups.path'), '/').'/'.preg_replace('/[^a-z0-9-]/', '', strtolower($subdomain));
    }

    /** The newest backup file of a space, or null. */
    public static function latest(string $subdomain): ?string
    {
        $files = glob(self::dir($subdomain).'/*.sqlite.gz') ?: [];
        rsort($files);

        return $files[0] ?? null;
    }
}
