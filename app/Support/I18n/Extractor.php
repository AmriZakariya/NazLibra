<?php

namespace App\Support\I18n;

use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

/**
 * Every French text the shop back office can show: page text, placeholders
 * and tooltips, the texts its scripts put on screen or in dialogs, and the
 * server's messages. The back office is written in French; these are the
 * keys of lang/{ar,en,es,pt,de,it}.json.
 *
 * A text built around values (« Bonjour, {{ $name }} », `${n} résultat(s)`,
 * "Article {$name} créé") becomes a pattern, « Bonjour, {0} »: the page's
 * translator matches it whatever the values.
 *
 * Used by `castlit:i18n-extract` to list them, and by a test that fails
 * when a text has no translation.
 */
final class Extractor
{
    /** Back-office pages: not the marketing site, the public shop pages, or vendor views. */
    public const VIEW_EXCLUDES = ['castlit', 'storefront', 'booking', 'vendor'];

    public const SCRIPTS = ['resources/js/app.js', 'resources/js/hardware.js'];

    /** Server code whose messages reach the back office (not the app's API, not castlit-admin). */
    public const PHP_DIRS = ['app/Http/Controllers', 'app/Support', 'app/Services', 'app/Http/Middleware', 'app/Models'];

    public const PHP_EXCLUDES = ['Castlit', 'Api', 'I18n'];

    private const ATTRIBUTES = ['placeholder', 'title', 'aria-label', 'alt', 'data-confirm', 'data-title', 'data-label', 'data-empty', 'data-placeholder'];

    /** @return array<string, list<string>> key => where it was found */
    public function strings(): array
    {
        $found = [];
        $add = function (string $text, string $where) use (&$found): void {
            $text = $this->normalise($text);
            if ($text !== null) {
                $found[$text][] = $where;
            }
        };

        foreach ($this->views() as $path => $source) {
            foreach ($this->fromBlade($source) as $text) {
                $add($text, $path);
            }
        }
        foreach (self::SCRIPTS as $path) {
            foreach ($this->fromScript((string) @file_get_contents(base_path($path))) as $text) {
                $add($text, $path);
            }
        }
        foreach ($this->phpFiles() as $path => $source) {
            foreach ($this->fromPhp($source) as $text) {
                $add($text, $path);
            }
        }

        ksort($found, SORT_STRING);

        return array_map(fn ($w) => array_values(array_unique($w)), $found);
    }

    /** @return array<string, string> */
    private function views(): array
    {
        $files = [];
        $finder = (new Finder)->files()->in(resource_path('views'))->name('*.blade.php');
        foreach (self::VIEW_EXCLUDES as $dir) {
            $finder->exclude($dir);
        }
        foreach ($finder as $file) {
            $files[Str::after($file->getPathname(), base_path().'/')] = $file->getContents();
        }
        ksort($files);

        return $files;
    }

    /** @return array<string, string> */
    private function phpFiles(): array
    {
        $files = [];
        foreach (self::PHP_DIRS as $dir) {
            if (! is_dir(base_path($dir))) {
                continue;
            }
            $finder = (new Finder)->files()->in(base_path($dir))->name('*.php');
            foreach (self::PHP_EXCLUDES as $exclude) {
                $finder->exclude($exclude);
            }
            foreach ($finder as $file) {
                $files[Str::after($file->getPathname(), base_path().'/')] = $file->getContents();
            }
        }
        ksort($files);

        return $files;
    }

    /** @return list<string> */
    public function fromBlade(string $source): array
    {
        $texts = [];

        // Comments hold no page text; scripts are read apart.
        $source = preg_replace('/\{\{--.*?--\}\}/s', '', $source) ?? $source;
        $source = preg_replace('/<!--.*?-->/s', '', $source) ?? $source;

        // Text written in the page's PHP: menu lists in @php blocks, fallbacks
        // in echoes ({{ $label ?? 'Liste des factures' }}), directive arguments.
        preg_match_all('/@php\b(?!\s*\().*?@endphp|\{\{.*?\}\}|\{!!.*?!!\}|@php\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\)/s', $source, $php);
        foreach ($php[0] as $code) {
            array_push($texts, ...$this->phpLiterals($code));
        }
        $source = preg_replace('/@php\b(?!\s*\().*?@endphp/s', '', $source) ?? $source;
        $source = preg_replace('/<style\b.*?<\/style>/is', '', $source) ?? $source;

        // Texts the server already translates: $tr('…'), __('…'), Locale::t('…').
        foreach ($this->calledWith($source, ['\$tr', '__', 'Locale::t', 'trans']) as $text) {
            $texts[] = $text;
        }

        preg_match_all('/<script\b[^>]*>(.*?)<\/script>/is', $source, $scripts);
        foreach ($scripts[1] as $script) {
            array_push($texts, ...$this->fromScript($script));
        }
        $markup = preg_replace('/<script\b.*?<\/script>/is', '', $source) ?? $source;

        // Attributes shown to the user.
        $attr = implode('|', array_map('preg_quote', self::ATTRIBUTES));
        preg_match_all('/\s(?:'.$attr.')\s*=\s*"([^"]*)"/i', $markup, $m);
        foreach ($m[1] as $value) {
            $texts[] = $this->bladeToPattern($value);
        }

        // Text between tags. Blade first masked: an echo or a directive can
        // hold « > » (`route('x', ['a' => 1])`, `@if ($n > 0)`), which would
        // end a tag early and lose the text after it.
        $masked = $this->maskBlade($markup);
        $masked = preg_replace('/<[^>]*>/s', "\x1F", $masked) ?? $masked;
        foreach (explode("\x1F", $masked) as $chunk) {
            foreach (explode("\x03", $chunk) as $piece) {
                $texts[] = $this->bladeToPattern(str_replace("\x02", '{{ }}', $piece));
            }
        }

        return array_values(array_filter($texts, fn ($t) => $t !== null && $t !== ''));
    }

    /** @return list<string> */
    public function fromScript(string $source): array
    {
        $texts = [];
        // Blade inside a script: an echo becomes a value.
        $source = preg_replace('/\{\{.*?\}\}|\{!!.*?!!\}|@json\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\)/s', '0', $source) ?? $source;
        // Line comments out (not URLs inside strings: only after whitespace or line start).
        $source = preg_replace('#(^|[\s;])//[^\n]*#', '$1', $source) ?? $source;
        $source = preg_replace('#/\*.*?\*/#s', '', $source) ?? $source;

        preg_match_all("/'((?:[^'\\\\\\n]|\\\\.)*)'|\"((?:[^\"\\\\\\n]|\\\\.)*)\"|`((?:[^`\\\\]|\\\\.)*)`/s", $source, $m, PREG_SET_ORDER);
        foreach ($m as $match) {
            $raw = $match[3] ?? '';
            $isTemplate = isset($match[3]) && $match[3] !== '';
            $raw = $isTemplate ? $match[3] : ($match[2] ?? '').($match[1] ?? '');
            $raw = stripcslashes($raw);
            if ($isTemplate) {
                // HTML built in a template: its text parts, values as placeholders.
                if (str_contains($raw, '<')) {
                    foreach ($this->fromTemplateHtml($raw) as $text) {
                        $texts[] = $text;
                    }

                    continue;
                }
                $raw = $this->templateToPattern($raw);
            }
            if ($this->looksLikeUiText($raw)) {
                $texts[] = $raw;
            }
        }

        return $texts;
    }

    /** @return list<string> */
    public function fromPhp(string $source): array
    {
        $texts = [];
        foreach ($this->calledWith($source, ['__', 'Locale::t', 'trans', '\$tr']) as $text) {
            $texts[] = $text;
        }
        // Messages: flashes, JSON messages, aborts, validation messages.
        $patterns = [
            "/->with\(\s*'(?:success|error|status|warning|info|message)'\s*,\s*(['\"])((?:(?!\\1)[^\\\\]|\\\\.)*)\\1/s",
            "/'(?:message|error|warning|status|reason|label|title|description)'\s*=>\s*(['\"])((?:(?!\\1)[^\\\\]|\\\\.)*)\\1/s",
            "/abort(?:_if|_unless)?\(\s*[^,]+,\s*(?:[^,]+,\s*)?(['\"])((?:(?!\\1)[^\\\\]|\\\\.)*)\\1/s",
            "/withErrors\(\s*\[\s*'[^']+'\s*=>\s*(['\"])((?:(?!\\1)[^\\\\]|\\\\.)*)\\1/s",
            "/'[a-z_.*]+'\s*=>\s*(['\"])((?:(?!\\1)[^\\\\]|\\\\.)*)\\1/s",
            "/(?:Exception|InvalidArgumentException|RuntimeException|DomainException)\(\s*(['\"])((?:(?!\\1)[^\\\\]|\\\\.)*)\\1/s",
        ];
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $source, $m, PREG_SET_ORDER);
            foreach ($m as $match) {
                $quote = $match[1];
                $text = $quote === '"' ? $this->phpInterpolationToPattern($match[2]) : str_replace("\\'", "'", $match[2]);
                if ($this->looksLikeUiText($text)) {
                    $texts[] = $text;
                }
            }
        }
        // "Article ".$name." créé" concatenations are left out: the page
        // shows them through a pattern written by hand when one matters.

        return $texts;
    }

    /** Quoted strings in a piece of PHP that read as text for people. @return list<string> */
    private function phpLiterals(string $code): array
    {
        $texts = [];
        preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'|\"((?:[^\"\\\\]|\\\\.)*)\"/s", $code, $m, PREG_SET_ORDER);
        foreach ($m as $match) {
            $text = isset($match[2]) && $match[2] !== '' ? $this->phpInterpolationToPattern($match[2]) : str_replace("\\'", "'", $match[1]);
            // Route names, keys, classes and paths are not text.
            if (preg_match('/^[a-z0-9_.\-\/:*]+$/i', $text) && ! str_contains($text, ' ')) {
                continue;
            }
            if ($this->looksLikeUiText($text)) {
                $texts[] = $text;
            }
        }

        return $texts;
    }

    /** First string argument of calls to these functions. @return list<string> */
    private function calledWith(string $source, array $functions): array
    {
        $names = implode('|', $functions);
        preg_match_all("/(?<![\\w>])(?:{$names})\(\s*(['\"])((?:(?!\\1)[^\\\\]|\\\\.)*)\\1/s", $source, $m, PREG_SET_ORDER);

        $texts = [];
        foreach ($m as $match) {
            $text = $match[1] === '"' ? stripcslashes($match[2]) : str_replace("\\'", "'", $match[2]);
            // Keys like castlit.hero_title are group files, not page text.
            if (preg_match('/^[a-z0-9_]+(\.[a-z0-9_*]+)+$/', $text)) {
                continue;
            }
            $texts[] = $text;
        }

        return $texts;
    }

    /** Echoes inside tags (attributes) would break the tag split. */
    private function hideEchoesInTags(string $markup): string
    {
        return preg_replace_callback('/<[^>]*>/s', fn ($tag) => preg_replace('/\{\{.*?\}\}|\{!!.*?!!\}/s', '', $tag[0]) ?? $tag[0], $markup) ?? $markup;
    }

    /**
     * Echoes become \x02 (a value in the text), directives become \x03 (a cut
     * between two texts), so nothing they hold can be read as markup.
     */
    private function maskBlade(string $markup): string
    {
        $markup = preg_replace('/\{\{.*?\}\}|\{!!.*?!!\}/s', "\x02", $markup) ?? $markup;
        $out = '';
        $len = strlen($markup);
        for ($i = 0; $i < $len; $i++) {
            $c = $markup[$i];
            if ($c === '@' && $i + 1 < $len && ctype_alpha($markup[$i + 1]) && ($i === 0 || ! ctype_alnum($markup[$i - 1]))) {
                $j = $i + 1;
                while ($j < $len && ctype_alpha($markup[$j])) {
                    $j++;
                }
                $k = $j;
                while ($k < $len && $markup[$k] === ' ') {
                    $k++;
                }
                if ($k < $len && $markup[$k] === '(') {
                    $depth = 0;
                    for (; $k < $len; $k++) {
                        if ($markup[$k] === '(') {
                            $depth++;
                        } elseif ($markup[$k] === ')' && --$depth === 0) {
                            break;
                        }
                    }
                    $i = $k;
                } else {
                    $i = $j - 1;
                }
                $out .= "\x03";

                continue;
            }
            $out .= $c;
        }

        return $out;
    }

    /** Text pieces between Blade directives (@if(...), @else, @endforeach…). @return list<string> */
    private function splitOnDirectives(string $chunk): array
    {
        $pieces = [];
        $buffer = '';
        $len = strlen($chunk);
        for ($i = 0; $i < $len; $i++) {
            if ($chunk[$i] === '@' && $i + 1 < $len && ctype_alpha($chunk[$i + 1]) && ($i === 0 || ! ctype_alnum($chunk[$i - 1]))) {
                // An email address is not a directive.
                if ($i > 0 && ctype_alnum($chunk[$i - 1])) {
                    $buffer .= '@';

                    continue;
                }
                $pieces[] = $buffer;
                $buffer = '';
                $j = $i + 1;
                while ($j < $len && ctype_alpha($chunk[$j])) {
                    $j++;
                }
                $k = $j;
                while ($k < $len && $chunk[$k] === ' ') {
                    $k++;
                }
                if ($k < $len && $chunk[$k] === '(') {
                    $depth = 0;
                    for (; $k < $len; $k++) {
                        if ($chunk[$k] === '(') {
                            $depth++;
                        } elseif ($chunk[$k] === ')') {
                            $depth--;
                            if ($depth === 0) {
                                break;
                            }
                        }
                    }
                    $i = $k;
                } else {
                    $i = $j - 1;
                }

                continue;
            }
            $buffer .= $chunk[$i];
        }
        $pieces[] = $buffer;

        return $pieces;
    }

    /** « Bonjour, {{ $name }} ! » → « Bonjour, {0} ! »; null when nothing to say. */
    private function bladeToPattern(string $text): ?string
    {
        // A text that is only translated server-side ({{ $tr('…') }}) is already a key.
        if (preg_match('/^\s*\{\{\s*(?:\$tr|__)\(.*\)\s*\}\}\s*$/s', $text)) {
            return null;
        }
        $n = 0;
        $text = preg_replace_callback('/\{\{.*?\}\}|\{!!.*?!!\}/s', function () use (&$n) {
            return '{'.$n++.'}';
        }, $text) ?? $text;

        return $this->looksLikeUiText($text) ? $text : null;
    }

    private function templateToPattern(string $text): string
    {
        $n = 0;

        return preg_replace_callback('/\$\{(?:[^{}]|\{[^{}]*\})*\}/s', function () use (&$n) {
            return '{'.$n++.'}';
        }, $text) ?? $text;
    }

    /** @return list<string> */
    private function fromTemplateHtml(string $html): array
    {
        $texts = [];
        $attr = implode('|', array_map('preg_quote', self::ATTRIBUTES));
        preg_match_all('/\s(?:'.$attr.')\s*=\s*"([^"]*)"/i', $html, $m);
        foreach ($m[1] as $value) {
            $value = $this->templateToPattern($value);
            if ($this->looksLikeUiText($value)) {
                $texts[] = $value;
            }
        }
        $body = preg_replace('/<[^>]*>/s', "\x1F", $html) ?? $html;
        foreach (explode("\x1F", $body) as $chunk) {
            $chunk = $this->templateToPattern($chunk);
            if ($this->looksLikeUiText($chunk)) {
                $texts[] = $chunk;
            }
        }

        return $texts;
    }

    private function phpInterpolationToPattern(string $text): string
    {
        $n = 0;
        $text = preg_replace_callback('/\{\$[^}]+\}|\$[a-zA-Z_][a-zA-Z0-9_]*(?:->[a-zA-Z_][a-zA-Z0-9_]*)*/', function () use (&$n) {
            return '{'.$n++.'}';
        }, $text) ?? $text;

        return stripcslashes($text);
    }

    /** Words a person reads, not code, CSS, a URL or a value. */
    public function looksLikeUiText(?string $text): bool
    {
        if ($text === null) {
            return false;
        }
        $t = trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($t === '' || mb_strlen($t) < 2 || mb_strlen($t) > 600) {
            return false;
        }
        // Letters, and not only placeholders.
        $letters = preg_replace('/\{\d+\}/', '', $t) ?? $t;
        if (! preg_match('/\p{L}{2,}/u', $letters)) {
            return false;
        }
        // Already a non-Latin text (Arabic dictionary values, flags…).
        if (preg_match('/\p{Arabic}/u', $t)) {
            return false;
        }
        // Code-looking.
        if (preg_match('#^(https?:|/|\./|\#|\.|\[|data-|aria-|js-|application/|text/|image/)#', $t)) {
            return false;
        }
        if (preg_match('/[;=<>{}$]|=>|::|\(\)|&&|\|\|/', preg_replace('/\{\d+\}/', '', $t) ?? $t)) {
            return false;
        }
        // CSS media queries, sizes, file names, environment keys.
        if (preg_match('/^\((?:max|min)-(?:width|height)|^\(prefers-|^\d+(?:\.\d+)?\s*(?:px|rem|em|ms|%)$|\.(?:jpe?g|png|webp|gif|svg|xlsx|csv|zip|pdf)$/i', $t)) {
            return false;
        }
        // Environment keys (SETUP_SECRET…): capitals and underscores, case-sensitive.
        if (preg_match('/^[A-Z][A-Z0-9]*_[A-Z0-9_]+(?:\s|$)/', $t)) {
            return false;
        }
        if (preg_match('/\.(?:jpe?g|png|webp|gif|svg)$/i', $t) || preg_match('/^\(?(?:max|min)-width|^\(prefers-/', $t)) {
            return false;
        }
        // CSS class lists and identifiers: lowercase words with dashes/colons, no accents.
        if (! preg_match('/[A-ZÀ-Ýà-ÿ]/u', $t) && preg_match('/^[a-z0-9_:\-\/\s.\[\]%]+$/', $t) && (str_contains($t, '-') || str_contains($t, '_') || str_contains($t, ':') || ! str_contains($t, ' '))) {
            return false;
        }
        // A single lowercase word with no accent is almost always a key or a value.
        if (! str_contains($t, ' ') && ! preg_match('/[À-ÿ]/u', $t) && ! preg_match('/^\p{Lu}/u', $t)) {
            return false;
        }
        // Words in CAPS like SKU, TVA, HT are kept; brand-only words are not.
        if (in_array($t, ['Castl-it-POS', 'Castl-it POS', 'NazLibra', 'Laravel', 'PDF', 'CSV', 'Excel', 'OK', 'ID', 'QR', 'POS', 'ESC/POS'], true)) {
            return false;
        }

        return true;
    }

    /** Spaces collapsed, entities decoded: the key the page's text is matched on. */
    public function normalise(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $text)) ?? $text);

        return $this->looksLikeUiText($text) ? $text : null;
    }
}
