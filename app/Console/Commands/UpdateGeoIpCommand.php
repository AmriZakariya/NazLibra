<?php

namespace App\Console\Commands;

use App\Support\GeoCountry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use MaxMind\Db\Reader;

/**
 * Downloads this month's DB-IP "IP to Country Lite" and swaps it in.
 *
 * The new file only replaces the old one once it opens and answers a known
 * address correctly: a truncated download must never leave the signup with
 * no country at all. DB-IP publishes on the first days of the month, so a
 * month not out yet falls back to the previous one.
 */
class UpdateGeoIpCommand extends Command
{
    protected $signature = 'castlit:update-geoip';

    protected $description = 'Télécharge la base IP → pays (DB-IP Lite) utilisée par l’inscription';

    public function handle(): int
    {
        $path = (string) config('castlit.geoip.path');
        @mkdir(dirname($path), 0775, true);
        $temp = $path.'.download';

        foreach ([now(), now()->subMonthNoOverflow()] as $month) {
            $url = sprintf((string) config('castlit.geoip.url'), $month->format('Y-m'));
            try {
                $response = Http::timeout(120)->get($url);
            } catch (\Throwable $e) {
                $this->warn($e->getMessage());

                continue;
            }
            if (! $response->successful()) {
                continue;
            }

            $data = @gzdecode($response->body());
            if ($data === false || file_put_contents($temp, $data) === false) {
                $this->error('Fichier reçu illisible : '.$url);

                return self::FAILURE;
            }
            if (! $this->looksRight($temp)) {
                @unlink($temp);
                $this->error('Base reçue incohérente, l’ancienne est conservée.');

                return self::FAILURE;
            }

            rename($temp, $path);
            GeoCountry::reset();
            $this->info('Base IP → pays à jour ('.$month->format('Y-m').', '.round(filesize($path) / 1048576, 1).' Mo).');

            return self::SUCCESS;
        }

        $this->error('Aucune base disponible au téléchargement.');

        return self::FAILURE;
    }

    private function looksRight(string $file): bool
    {
        try {
            $reader = new Reader($file);
            $us = $reader->get('8.8.8.8')['country']['iso_code'] ?? null;
            $reader->close();

            return $us === 'US';
        } catch (\Throwable) {
            return false;
        }
    }
}
