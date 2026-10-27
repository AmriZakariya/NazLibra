<?php

namespace App\Console\Commands;

use App\Models\TenantInstall;
use App\Support\ClientArtisan;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * MASTER: gives a space's support login a new random password, and keeps it
 * (encrypted) for the platform admin.
 *
 * Spaces created before 2026-10-27 all shared "admin" as that password. Run
 * once with --all after deploying; the public demo is left alone unless named.
 *
 *   php artisan castlit:rotate-support-passwords --all
 *   php artisan castlit:rotate-support-passwords spaparis
 */
class RotateSupportPasswordsCommand extends Command
{
    protected $signature = 'castlit:rotate-support-passwords
        {subdomain? : One space, e.g. "spaparis"}
        {--all : Every live space except the public demo}';

    protected $description = 'Give client support logins a new random password (master only).';

    public function handle(): int
    {
        if (! config('castlit.is_master')) {
            $this->error('Master only.');

            return self::FAILURE;
        }

        $subdomain = $this->argument('subdomain');
        if (! $subdomain && ! $this->option('all')) {
            $this->error('Name a subdomain or pass --all.');

            return self::FAILURE;
        }

        $installs = TenantInstall::query()
            ->where('status', TenantInstall::STATUS_LIVE)
            ->when($subdomain, fn ($q) => $q->where('subdomain', $subdomain))
            ->get();

        $demo = self::demoSubdomain();
        $failed = 0;
        foreach ($installs as $install) {
            if (! $subdomain && $install->subdomain === $demo) {
                $this->line("· {$install->subdomain} : démo publique, laissée telle quelle");
                continue;
            }
            $failed += $this->rotate($install) ? 0 : 1;
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function rotate(TenantInstall $install): bool
    {
        if (! ClientArtisan::reachable($install)) {
            $this->warn("✗ {$install->subdomain} : dossier introuvable");

            return false;
        }

        $email = $install->support_email ?: str_replace(
            '{sub}', $install->subdomain,
            (string) config('castlit.provision.default_admin.email_pattern', 'admin@{sub}.com'),
        );
        $password = Str::password(20, symbols: false);

        $process = ClientArtisan::process($install, ['castlit:support-password', $email], ['CASTLIT_SUPPORT_PASSWORD' => $password], 120);
        $process->run();
        $lines = preg_split('/\r?\n/', trim($process->getOutput())) ?: [];
        $result = json_decode((string) end($lines), true);

        if (! $process->isSuccessful() || ! is_array($result)) {
            $this->warn("✗ {$install->subdomain} : ".ClientArtisan::failure($process));

            return false;
        }
        if (($result['status'] ?? '') === 'absent') {
            $this->line("· {$install->subdomain} : pas de compte support ({$email})");

            return true;
        }

        $install->forceFill(['support_email' => $email, 'support_password' => $password])->save();
        $this->info("✓ {$install->subdomain} : nouveau mot de passe enregistré");

        return true;
    }

    public static function demoSubdomain(): ?string
    {
        $host = (string) parse_url((string) config('castlit.demo.url'), PHP_URL_HOST);
        $suffix = '.'.config('castlit.main_domain');

        return str_ends_with($host, $suffix) ? substr($host, 0, -strlen($suffix)) : null;
    }
}
