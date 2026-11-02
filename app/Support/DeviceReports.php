<?php

namespace App\Support;

use App\Models\DeviceReport;
use App\Models\SupportConversation;
use App\Models\TenantInstall;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The logs phones send with « Envoyer les journaux »: kept gzipped on the
 * private disk for 30 days, read back line by line in castlit-admin.
 */
class DeviceReports
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    public const KEEP_DAYS = 30;

    /** What the admin page reads at most: a report is a debugging aid, not an archive. */
    private const MAX_READ_BYTES = 20 * 1024 * 1024;

    /** @param array{user_name?: ?string, platform?: ?string, device?: ?string, app_version?: ?string, note?: ?string} $meta */
    public static function store(UploadedFile $file, array $meta, ?TenantInstall $install, ?SupportConversation $conversation): DeviceReport
    {
        if (! $file->isValid() || $file->getSize() > self::MAX_BYTES) {
            throw ValidationException::withMessages(['log' => 'Journal trop lourd (5 Mo au plus).']);
        }
        $reference = self::newReference();
        $path = $file->storeAs('device-reports', $reference.'.log.gz', 'local');

        $report = DeviceReport::create([
            'reference' => $reference,
            'tenant_install_id' => $install?->id,
            'support_conversation_id' => $conversation?->id,
            'user_name' => $meta['user_name'] ?? null,
            'platform' => $meta['platform'] ?? null,
            'device' => $meta['device'] ?? null,
            'app_version' => $meta['app_version'] ?? null,
            'note' => $meta['note'] ?? null,
            'file_path' => $path,
            'size' => $file->getSize(),
        ]);

        // In the thread too, so the team reading the chat sees it arrive.
        if ($conversation) {
            SupportChat::post($conversation, 'client', '📋 Journal envoyé ('.$reference.')'
                .(! empty($meta['note']) ? "\n".$meta['note'] : ''));
        }

        return $report;
    }

    /**
     * The report's lines, newest last. [$level] "error" keeps errors only,
     * "warning" errors and warnings; [$search] is a plain substring.
     *
     * @return list<string>
     */
    public static function lines(DeviceReport $report, string $level = 'all', string $search = ''): array
    {
        $raw = Storage::disk('local')->get($report->file_path) ?? '';
        $text = @gzdecode($raw);
        if ($text === false) {
            $text = $raw; // sent uncompressed
        }
        $text = substr($text, -self::MAX_READ_BYTES);

        $lines = preg_split('/\r?\n/', $text) ?: [];

        return array_values(array_filter($lines, function (string $line) use ($level, $search): bool {
            if ($search !== '' && stripos($line, $search) === false) {
                return false;
            }

            return match ($level) {
                'error' => self::levelOf($line) === 'E',
                'warning' => in_array(self::levelOf($line), ['E', 'W'], true),
                default => true,
            };
        }));
    }

    /**
     * "E", "W", "I", "D" or null. The app writes "… [E] tag: …"; Android's
     * logcat writes "11-03 10:00:00.123  1234  1250 E Tag: …".
     */
    public static function levelOf(string $line): ?string
    {
        if (preg_match('/\[(E|W|I|D)\]/', $line, $m)) {
            return $m[1];
        }
        if (preg_match('/^\d\d-\d\d \d\d:\d\d:\d\d\.\d+\s+\d+\s+\d+\s+([EWIDVF])\s/', $line, $m)) {
            return match ($m[1]) {
                'F' => 'E',
                'V' => 'D',
                default => $m[1],
            };
        }

        return null;
    }

    /** Deletes reports older than [KEEP_DAYS], files and rows. */
    public static function prune(): int
    {
        $old = DeviceReport::where('created_at', '<', now()->subDays(self::KEEP_DAYS))->get();
        foreach ($old as $report) {
            Storage::disk('local')->delete($report->file_path);
            $report->delete();
        }

        return $old->count();
    }

    private static function newReference(): string
    {
        do {
            $reference = 'LOG-'.collect(range(1, 4))
                ->map(fn () => '23456789ABCDEFGHJKLMNPQRSTUVWXYZ'[random_int(0, 31)])
                ->implode('');
        } while (DeviceReport::where('reference', $reference)->exists());

        return $reference;
    }
}
