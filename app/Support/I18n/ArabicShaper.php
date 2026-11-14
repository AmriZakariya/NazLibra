<?php

namespace App\Support\I18n;

/**
 * Arabic text written so dompdf can draw it.
 *
 * dompdf neither joins Arabic letters nor lays text out right to left: it
 * draws each code point on its own, left to right, so « فاتورة » came out
 * as six separate letters in reverse order (and Arabic shops got their
 * documents in French instead). This does both jobs beforehand:
 *
 *  - each letter becomes the form it takes in its word (isolated, initial,
 *    medial, final: Unicode's presentation forms, which DejaVu Sans has),
 *    lam + alef becoming one ligature;
 *  - the text is put in visual order: Arabic runs reversed, while numbers
 *    and Latin words (« FAC-2026-0001 », « 1,250.00 ») keep theirs.
 *
 * A text that holds no Arabic is returned as it is.
 */
final class ArabicShaper
{
    /** letter => [isolated, final, initial, medial]; a letter with two forms joins only the letter before it. */
    private const FORMS = [
        0x0621 => [0xFE80],
        0x0622 => [0xFE81, 0xFE82], 0x0623 => [0xFE83, 0xFE84], 0x0624 => [0xFE85, 0xFE86],
        0x0625 => [0xFE87, 0xFE88], 0x0626 => [0xFE89, 0xFE8A, 0xFE8B, 0xFE8C], 0x0627 => [0xFE8D, 0xFE8E],
        0x0628 => [0xFE8F, 0xFE90, 0xFE91, 0xFE92], 0x0629 => [0xFE93, 0xFE94],
        0x062A => [0xFE95, 0xFE96, 0xFE97, 0xFE98], 0x062B => [0xFE99, 0xFE9A, 0xFE9B, 0xFE9C],
        0x062C => [0xFE9D, 0xFE9E, 0xFE9F, 0xFEA0], 0x062D => [0xFEA1, 0xFEA2, 0xFEA3, 0xFEA4],
        0x062E => [0xFEA5, 0xFEA6, 0xFEA7, 0xFEA8], 0x062F => [0xFEA9, 0xFEAA], 0x0630 => [0xFEAB, 0xFEAC],
        0x0631 => [0xFEAD, 0xFEAE], 0x0632 => [0xFEAF, 0xFEB0],
        0x0633 => [0xFEB1, 0xFEB2, 0xFEB3, 0xFEB4], 0x0634 => [0xFEB5, 0xFEB6, 0xFEB7, 0xFEB8],
        0x0635 => [0xFEB9, 0xFEBA, 0xFEBB, 0xFEBC], 0x0636 => [0xFEBD, 0xFEBE, 0xFEBF, 0xFEC0],
        0x0637 => [0xFEC1, 0xFEC2, 0xFEC3, 0xFEC4], 0x0638 => [0xFEC5, 0xFEC6, 0xFEC7, 0xFEC8],
        0x0639 => [0xFEC9, 0xFECA, 0xFECB, 0xFECC], 0x063A => [0xFECD, 0xFECE, 0xFECF, 0xFED0],
        0x0641 => [0xFED1, 0xFED2, 0xFED3, 0xFED4], 0x0642 => [0xFED5, 0xFED6, 0xFED7, 0xFED8],
        0x0643 => [0xFED9, 0xFEDA, 0xFEDB, 0xFEDC], 0x0644 => [0xFEDD, 0xFEDE, 0xFEDF, 0xFEE0],
        0x0645 => [0xFEE1, 0xFEE2, 0xFEE3, 0xFEE4], 0x0646 => [0xFEE5, 0xFEE6, 0xFEE7, 0xFEE8],
        0x0647 => [0xFEE9, 0xFEEA, 0xFEEB, 0xFEEC], 0x0648 => [0xFEED, 0xFEEE],
        0x0649 => [0xFEEF, 0xFEF0], 0x064A => [0xFEF1, 0xFEF2, 0xFEF3, 0xFEF4],
    ];

    /** alef after lam => [isolated, final] ligature. */
    private const LAM_ALEF = [0x0622 => [0xFEF5, 0xFEF6], 0x0623 => [0xFEF7, 0xFEF8], 0x0625 => [0xFEF9, 0xFEFA], 0x0627 => [0xFEFB, 0xFEFC]];

    private const TATWEEL = 0x0640;

    /** In a right-to-left run, a bracket points the other way. */
    private const MIRROR = ['(' => ')', ')' => '(', '[' => ']', ']' => '[', '{' => '}', '}' => '{', '<' => '>', '>' => '<', '«' => '»', '»' => '«'];

    public static function hasArabic(string $text): bool
    {
        return (bool) preg_match('/[\x{0600}-\x{06FF}]/u', $text);
    }

    /** The text in visual order with joined letters, ready for dompdf. */
    public static function visual(string $text): string
    {
        if (! self::hasArabic($text)) {
            return $text;
        }
        preg_match('/^\s*/u', $text, $lead);
        preg_match('/\s*$/u', $text, $trail);
        $core = trim($text);

        $clusters = self::clusters(self::shape($core));
        $runs = self::runs($clusters);

        $out = '';
        foreach (array_reverse($runs) as [$rtl, $items]) {
            if ($rtl) {
                foreach (array_reverse($items) as $cluster) {
                    $out .= self::MIRROR[$cluster] ?? $cluster;
                }
            } else {
                $out .= implode('', $items);
            }
        }

        return $lead[0].$out.$trail[0];
    }

    /** Letters replaced by their contextual forms (logical order kept). */
    public static function shape(string $text): string
    {
        $chars = self::codepoints($text);
        $count = count($chars);
        $out = [];

        for ($i = 0; $i < $count; $i++) {
            $char = $chars[$i];
            if (! isset(self::FORMS[$char])) {
                $out[] = $char;

                continue;
            }

            $previous = self::neighbour($chars, $i, -1);
            $joinsBefore = $previous !== null && self::joinsForward($chars[$previous]);

            // Lam then alef: one ligature.
            $next = self::neighbour($chars, $i, 1);
            if ($char === 0x0644 && $next !== null && isset(self::LAM_ALEF[$chars[$next]])) {
                $out[] = self::LAM_ALEF[$chars[$next]][$joinsBefore ? 1 : 0];
                // Marks between lam and alef stay; the alef is consumed.
                for ($j = $i + 1; $j < $next; $j++) {
                    $out[] = $chars[$j];
                }
                $i = $next;

                continue;
            }

            $joinsAfter = $next !== null && self::joinsForward($char) && isset(self::FORMS[$chars[$next]]) || ($next !== null && $chars[$next] === self::TATWEEL && self::joinsForward($char));
            $forms = self::FORMS[$char];
            $out[] = match (true) {
                $joinsBefore && $joinsAfter => $forms[3],
                $joinsAfter => $forms[2],
                $joinsBefore => $forms[1] ?? $forms[0],
                default => $forms[0],
            };
        }

        return implode('', array_map(fn (int $c) => mb_chr($c, 'UTF-8'), $out));
    }

    /** @return list<int> */
    private static function codepoints(string $text): array
    {
        return array_map(fn (string $c) => mb_ord($c, 'UTF-8'), mb_str_split($text, 1, 'UTF-8'));
    }

    private static function isMark(int $char): bool
    {
        return ($char >= 0x064B && $char <= 0x065F) || $char === 0x0670;
    }

    /** The nearest letter before (-1) or after (1), skipping marks; null at a word's edge. */
    private static function neighbour(array $chars, int $i, int $step): ?int
    {
        for ($j = $i + $step; $j >= 0 && $j < count($chars); $j += $step) {
            if (self::isMark($chars[$j])) {
                continue;
            }

            return (isset(self::FORMS[$chars[$j]]) || $chars[$j] === self::TATWEEL) ? $j : null;
        }

        return null;
    }

    /** Whether a letter connects to the one after it (four forms, or the tatweel). */
    private static function joinsForward(int $char): bool
    {
        return $char === self::TATWEEL || count(self::FORMS[$char] ?? []) === 4;
    }

    /**
     * Characters grouped with the marks that follow them, so reversing a run
     * keeps each vowel sign on its letter.
     *
     * @return list<string>
     */
    private static function clusters(string $text): array
    {
        preg_match_all('/\P{M}\p{M}*/u', $text, $m);

        return $m[0];
    }

    /**
     * Runs of right-to-left (Arabic and the spaces and punctuation between)
     * and left-to-right text (Latin words, numbers with their signs).
     *
     * @param  list<string>  $clusters
     * @return list<array{0: bool, 1: list<string>}>
     */
    private static function runs(array $clusters): array
    {
        $types = array_map(function (string $cluster): string {
            if (preg_match('/^[\x{0600}-\x{06FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $cluster)) {
                // Arabic-Indic digits read left to right, like any number.
                return preg_match('/^[\x{0660}-\x{0669}\x{06F0}-\x{06F9}]/u', $cluster) ? 'L' : 'R';
            }

            return preg_match('/^[\p{L}\p{N}]/u', $cluster) ? 'L' : 'N';
        }, $clusters);

        $count = count($types);
        $isDigit = fn (int $i): bool => $i >= 0 && $i < $count && (bool) preg_match('/^[\p{N}]/u', $clusters[$i]);
        for ($i = 0; $i < $count; $i++) {
            if ($types[$i] !== 'N') {
                continue;
            }
            // Signs that belong to a number: « 14% », « -5 », « 1,250.00 », « 12/11 ».
            if (preg_match('/^[%‰+\-.,:\/]$/u', $clusters[$i]) && ($isDigit($i - 1) || $isDigit($i + 1))) {
                $types[$i] = 'L';
            }
        }
        // A neutral between two left-to-right characters goes with them
        // (« FAC-2026 », « Total HT »); any other neutral follows the Arabic.
        for ($i = 0; $i < $count; $i++) {
            if ($types[$i] !== 'N') {
                continue;
            }
            $before = null;
            for ($j = $i - 1; $j >= 0; $j--) {
                if ($types[$j] !== 'N') {
                    $before = $types[$j];
                    break;
                }
            }
            $after = null;
            for ($j = $i + 1; $j < $count; $j++) {
                if ($types[$j] !== 'N') {
                    $after = $types[$j];
                    break;
                }
            }
            $types[$i] = ($before === 'L' && $after === 'L') ? 'L' : 'R';
        }

        $runs = [];
        foreach ($clusters as $i => $cluster) {
            $rtl = $types[$i] === 'R';
            if ($runs !== [] && $runs[array_key_last($runs)][0] === $rtl) {
                $runs[array_key_last($runs)][1][] = $cluster;
            } else {
                $runs[] = [$rtl, [$cluster]];
            }
        }

        return $runs;
    }
}
