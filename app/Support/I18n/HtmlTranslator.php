<?php

namespace App\Support\I18n;

use App\Models\Tenant;
use App\Support\Locale;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * resources/js/i18n.js on the server, for HTML no browser runs: the PDFs.
 * Every French text node and label in the HTML is swapped for its
 * translation from lang/{locale}.json, patterns (« Facture {0} ») included.
 */
final class HtmlTranslator
{
    private const ATTRIBUTES = ['placeholder', 'title', 'aria-label', 'alt'];

    /**
     * Languages a PDF can be written in. Arabic is not: dompdf does not join
     * Arabic letters, so an Arabic shop keeps its documents in French.
     */
    public const PDF_LOCALES = ['en', 'es', 'pt', 'de', 'it'];

    /** @var array<string, list<array{0: string, 1: list<string>, 2: string}>> */
    private static array $patterns = [];

    public static function translate(string $html, string $locale): string
    {
        if ($locale === 'fr' || Locale::dictionary($locale) === []) {
            return $html;
        }

        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);
        foreach ($xpath->query('//text()[normalize-space()][not(ancestor::script)][not(ancestor::style)][not(ancestor::*[@data-no-translate])]') ?: [] as $node) {
            $original = $node->nodeValue;
            $translated = self::text($original, $locale);
            if ($translated !== null) {
                preg_match('/^\s*/u', $original, $lead);
                preg_match('/\s*$/u', $original, $trail);
                $node->nodeValue = $lead[0].$translated.$trail[0];
            }
        }
        foreach ($xpath->query('//*[@'.implode(' or @', self::ATTRIBUTES).']') ?: [] as $element) {
            /** @var DOMElement $element */
            foreach (self::ATTRIBUTES as $attribute) {
                if ($element->hasAttribute($attribute) && ($translated = self::text($element->getAttribute($attribute), $locale)) !== null) {
                    $element->setAttribute($attribute, $translated);
                }
            }
        }

        $out = $dom->saveHTML() ?: $html;

        return str_replace('<?xml encoding="UTF-8">', '', $out);
    }

    /** The translation of one text, null when it has none. */
    public static function text(string $text, string $locale): ?string
    {
        $value = trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $text) ?? $text);
        if ($value === '') {
            return null;
        }
        $dictionary = Locale::dictionary($locale);
        if (isset($dictionary[$value])) {
            return $dictionary[$value];
        }
        foreach (self::patterns($locale) as [$regex, $order, $translation]) {
            if (preg_match($regex, $value, $m)) {
                $values = [];
                foreach ($order as $i => $n) {
                    $values[$n] = self::text($m[$i + 1], $locale) ?? $m[$i + 1];
                }

                return preg_replace_callback('/\{(\d+)\}/', fn ($p) => $values[$p[1]] ?? '', $translation);
            }
        }

        if (str_contains($value, ' · ')) {
            $parts = explode(' · ', $value);
            $translated = array_map(fn ($part) => self::text($part, $locale) ?? $part, $parts);
            if ($translated !== $parts) {
                return implode(' · ', $translated);
            }
        }

        return null;
    }

    /** @return list<array{0: string, 1: list<string>, 2: string}> */
    private static function patterns(string $locale): array
    {
        if (isset(self::$patterns[$locale])) {
            return self::$patterns[$locale];
        }
        $patterns = [];
        foreach (Locale::dictionary($locale) as $key => $translation) {
            if (! preg_match('/\{\d+\}/', $key)) {
                continue;
            }
            if (mb_strlen(preg_replace('/[\s\W\d]/u', '', preg_replace('/\{\d+\}/', '', $key) ?? '') ?? '') < 2) {
                continue;
            }
            $order = [];
            $regex = '';
            foreach (preg_split('/(\{\d+\})/', $key, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $part) {
                if (preg_match('/^\{(\d+)\}$/', $part, $m)) {
                    $order[] = $m[1];
                    $regex .= '(.+?)';
                } else {
                    $regex .= preg_quote($part, '/');
                }
            }
            $patterns[] = ['/^'.$regex.'$/u', $order, $translation];
        }
        usort($patterns, fn ($a, $b) => strlen($b[0]) <=> strlen($a[0]));

        return self::$patterns[$locale] = $patterns;
    }

    /** The language a shop's PDFs are written in. */
    public static function pdfLocale(?Tenant $tenant): string
    {
        $locale = Locale::shop($tenant);

        return in_array($locale, self::PDF_LOCALES, true) ? $locale : 'fr';
    }
}
