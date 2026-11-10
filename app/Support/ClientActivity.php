<?php

namespace App\Support;

use App\Models\TenantInstall;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * MASTER: asks each space what it last did (`castlit:activity`, run inside
 * the space) and keeps the answer on its install row, so the clients list can
 * say who uses the product and who has gone quiet.
 *
 * A space that does not answer keeps what it last said; the error and the time
 * of the attempt are kept beside it, so a stale answer never passes for fresh.
 */
final class ClientActivity
{
    public const FRESH = 'fresh';   // under 24 h

    public const IDLE = 'idle';     // 1 to 7 days

    public const GONE = 'gone';     // over 7 days, or never

    /** Every live, enabled space. Returns how many answered. */
    public static function collectAll(): int
    {
        $answered = 0;
        TenantInstall::query()
            ->where('status', TenantInstall::STATUS_LIVE)
            ->where('is_enabled', true)
            ->each(function (TenantInstall $install) use (&$answered) {
                $answered += self::collect($install) ? 1 : 0;
            });

        return $answered;
    }

    /** Ask one space. True when it answered. */
    public static function collect(TenantInstall $install): bool
    {
        if (! ClientArtisan::reachable($install)) {
            return self::failed($install, 'Dossier introuvable');
        }

        // Our own support login visiting the space is not the client's activity.
        $args = ['castlit:activity', '--no-interaction'];
        if ($install->support_email) {
            $args[] = '--ignore='.$install->support_email;
        }

        try {
            $process = ClientArtisan::process($install, $args, [], 60);
            $process->run();
        } catch (\Throwable $e) {
            return self::failed($install, $e->getMessage());
        }

        $data = self::parse($process->getOutput());
        if ($data === null) {
            $output = $process->getOutput().$process->getErrorOutput();

            return self::failed($install, str_contains($output, 'castlit:activity')
                ? 'Espace pas encore mis à jour'
                : ClientArtisan::failure($process));
        }

        $install->forceFill([
            'activity_at' => isset($data['at']) ? Carbon::parse($data['at']) : null,
            'activity' => $data,
            'activity_checked_at' => now(),
            'activity_error' => null,
        ])->save();

        return true;
    }

    /** The JSON line the space printed, if it printed one that succeeded. */
    public static function parse(string $output): ?array
    {
        foreach (array_reverse(preg_split('/\R/', trim($output)) ?: []) as $line) {
            $line = trim($line);
            if (str_starts_with($line, '{')) {
                $data = json_decode($line, true);

                return is_array($data) && ($data['status'] ?? null) === 'success' ? $data : null;
            }
        }

        return null;
    }

    /** How recently a space was active, as one of FRESH, IDLE, GONE. */
    public static function level(?Carbon $at): string
    {
        return match (true) {
            $at === null => self::GONE,
            $at->gt(now()->subDay()) => self::FRESH,
            $at->gt(now()->subDays(7)) => self::IDLE,
            default => self::GONE,
        };
    }

    /** "Vente BL00042 · 120,00 MAD · Ahmed" — what the last action was, and who. */
    public static function describe(?array $activity): ?string
    {
        $last = $activity['last'] ?? null;
        if (! is_array($last)) {
            return null;
        }

        $parts = match ($last['kind'] ?? null) {
            'sale' => ['Vente '.($last['number'] ?? ''),
                isset($last['total']) ? trim(number_format((float) $last['total'], 2, ',', ' ').' '.($last['currency'] ?? '')) : null],
            'app' => ['Application', $last['device'] ?? null],
            'web' => ['Back-office web'],
            'change' => [$last['label'] ?? 'Modification'],
            default => [],
        };
        $parts[] = $last['user'] ?? null;

        return implode(' · ', array_filter(array_map(fn ($p) => is_string($p) ? trim($p) : $p, $parts))) ?: null;
    }

    /**
     * The app versions the space's devices last ran, each with what the
     * update policy would tell it (ok / soft / force).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function devices(?TenantInstall $install): array
    {
        $devices = $install?->activity['devices'] ?? [];
        if (! is_array($devices) || $devices === []) {
            return [];
        }

        $policies = \App\Models\AppUpdatePolicy::query()->get()->keyBy('platform');
        return array_values(array_map(fn (array $d) => $d + [
            'status' => AppVersions::status($policies->get((string) ($d['platform'] ?? '')), (string) ($d['version'] ?? '')),
            'at_carbon' => isset($d['at']) ? Carbon::parse($d['at']) : null,
        ], array_filter($devices, 'is_array')));
    }

    private static function failed(TenantInstall $install, string $error): bool
    {
        Log::info('CastLit: activity not collected', ['install' => $install->id, 'error' => $error]);
        $install->forceFill([
            'activity_checked_at' => now(),
            'activity_error' => mb_substr($error, 0, 500),
        ])->save();

        return false;
    }
}
