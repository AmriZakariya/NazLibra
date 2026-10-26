<?php

namespace App\Support;

/**
 * The latest entries of a Laravel log, readable by a person.
 *
 * Telescope stays off in production — on shared hosting it records every
 * request into the SQLite file — so support reads the log instead. Only the
 * end of the file is read: a log that has grown for months is not loaded
 * whole to show its last errors.
 */
final class LogTail
{
    public const SERIOUS = ['EMERGENCY', 'ALERT', 'CRITICAL', 'ERROR'];

    /** The newest laravel*.log in [$dir] (single or daily channel). */
    public static function latestFile(string $dir): ?string
    {
        $files = glob(rtrim($dir, '/').'/laravel*.log') ?: [];
        usort($files, fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return $files[0] ?? null;
    }

    /** The last [$bytes] of the file, starting at a line boundary. */
    public static function tail(string $file, int $bytes = 2_000_000): string
    {
        $size = filesize($file) ?: 0;
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            return '';
        }
        fseek($handle, max(0, $size - $bytes));
        $text = (string) stream_get_contents($handle);
        fclose($handle);

        if ($size > $bytes && ($newline = strpos($text, "\n")) !== false) {
            $text = substr($text, $newline + 1);
        }

        return $text;
    }

    /**
     * Newest first.
     *
     * @return list<array{at: string, level: string, message: string, details: string, serious: bool}>
     */
    public static function entries(string $file, int $limit = 150, bool $seriousOnly = true): array
    {
        $text = self::tail($file);
        $parts = preg_split('/^(?=\[\d{4}-\d{2}-\d{2}[ T][\d:.+\-]+\] )/m', $text) ?: [];

        $entries = [];
        foreach ($parts as $part) {
            if (! preg_match('/^\[([^\]]+)\] [\w-]+\.([A-Z]+): (.*)$/s', trim($part), $m)) {
                continue;
            }
            $level = $m[2];
            $serious = in_array($level, self::SERIOUS, true);
            if ($seriousOnly && ! $serious && ! str_contains($m[3], 'App sync failed')) {
                continue;
            }
            $lines = explode("\n", $m[3]);
            $entries[] = [
                'at' => $m[1],
                'level' => $level,
                'message' => mb_strimwidth(trim($lines[0]), 0, 600, '…'),
                // The trace, cut: its top frames are what tell where it broke.
                'details' => implode("\n", array_slice($lines, 1, 40)),
                'serious' => $serious,
            ];
        }

        return array_slice(array_reverse($entries), 0, $limit);
    }
}
