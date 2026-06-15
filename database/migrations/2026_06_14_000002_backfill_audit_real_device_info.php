<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $platform = static function (string $userAgent): ?string {
            if ($userAgent === '') {
                return null;
            }

            return match (true) {
                str_contains($userAgent, 'Windows') => 'Windows',
                str_contains($userAgent, 'Macintosh'), str_contains($userAgent, 'Mac OS') => 'macOS',
                str_contains($userAgent, 'iPhone') => 'iPhone',
                str_contains($userAgent, 'iPad') => 'iPad',
                str_contains($userAgent, 'Android') => 'Android',
                str_contains($userAgent, 'Linux') => 'Linux',
                default => 'Navigateur',
            };
        };

        $browser = static function (string $userAgent): ?string {
            if ($userAgent === '') {
                return null;
            }

            return match (true) {
                str_contains($userAgent, 'Edg/') => 'Microsoft Edge',
                str_contains($userAgent, 'OPR/'), str_contains($userAgent, 'Opera') => 'Opera',
                str_contains($userAgent, 'Chrome/'), str_contains($userAgent, 'CriOS/') => 'Chrome',
                str_contains($userAgent, 'Firefox/'), str_contains($userAgent, 'FxiOS/') => 'Firefox',
                str_contains($userAgent, 'Safari/') => 'Safari',
                default => 'Navigateur',
            };
        };

        DB::table('audit_logs')
            ->where(function ($query): void {
                $query->whereNull('real_device_ip')
                    ->orWhereNull('real_device_user_agent')
                    ->orWhereNull('real_device_platform')
                    ->orWhereNull('real_device_browser');
            })
            ->orderBy('id')
            ->chunkById(200, function ($logs) use ($platform, $browser): void {
                foreach ($logs as $log) {
                    $properties = json_decode((string) $log->properties, true);
                    if (! is_array($properties)) {
                        continue;
                    }

                    $userAgent = (string) ($properties['user_agent'] ?? '');
                    $updates = array_filter([
                        'real_device_ip' => $log->real_device_ip ?: ($properties['ip'] ?? null),
                        'real_device_user_agent' => $log->real_device_user_agent ?: ($userAgent !== '' ? mb_substr($userAgent, 0, 500) : null),
                        'real_device_platform' => $log->real_device_platform ?: $platform($userAgent),
                        'real_device_browser' => $log->real_device_browser ?: $browser($userAgent),
                    ], fn ($value) => $value !== null && $value !== '');

                    if ($updates !== []) {
                        DB::table('audit_logs')->where('id', $log->id)->update($updates);
                    }
                }
            });
    }

    public function down(): void
    {
        // Audit backfill is intentionally not reverted.
    }
};
