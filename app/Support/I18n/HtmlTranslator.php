<?php

namespace App\Support\I18n;

use App\Models\Tenant;
use App\Support\Locale;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\App;

/**
 * resources/js/i18n.js on the server, for HTML no browser runs: the PDFs.
 * Every French text node and label in the HTML is swapped for its
 * translation from lang/{locale}.json, patterns (« Facture {0} ») included.
 */
final class HtmlTranslator
{
    private const ATTRIBUTES = ['placeholder', 'title', 'aria-label', 'alt'];

    /**
     * Languages a PDF can be written in. Arabic too: dompdf cannot join its
     * letters nor lay them right to left, so ArabicShaper does it first.
     */
    public const PDF_LOCALES = ['ar', 'en', 'es', 'pt', 'de', 'it'];

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

    /**
     * A PDF view rendered in the shop's language: the amounts and words of
     * that language while it renders (« ج.م », the total in words), its
     * texts translated, and any Arabic in it — the translation, or a
     * customer's name typed in Arabic — joined and ordered for dompdf.
     *
     * @param  array<string, mixed>  $data
     */
    public static function pdfHtml(string $view, array $data, ?Tenant $tenant): string
    {
        $locale = self::pdfLocale($tenant);
        $previous = App::getLocale();
        App::setLocale($locale);
        try {
            $html = view($view, $data + ['pdfLocale' => $locale])->render();
        } finally {
            App::setLocale($previous);
        }

        return self::shapeArabic(self::translate($html, $locale), $locale === 'ar');
    }

    /** Every Arabic text node written in visual order with joined letters; a right-to-left page when the document is Arabic. */
    public static function shapeArabic(string $html, bool $rightToLeft = false): string
    {
        if (! $rightToLeft && ! ArabicShaper::hasArabic($html)) {
            return $html;
        }

        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);
        foreach ($xpath->query('//text()[not(ancestor::script)][not(ancestor::style)]') ?: [] as $node) {
            if (ArabicShaper::hasArabic($node->nodeValue)) {
                $node->nodeValue = ArabicShaper::visual($node->nodeValue);
            }
        }

        if ($rightToLeft) {
            self::mirror($dom, $xpath);
            $root = $dom->documentElement;
            $root?->setAttribute('dir', 'rtl');
            $root?->setAttribute('lang', 'ar');
            $head = $dom->getElementsByTagName('head')->item(0);
            if ($head) {
                // Text already in visual order: the page only needs aligning
                // to the right; dompdf does nothing else with « direction ».
                $style = $dom->createElement('style', 'body { direction: rtl; text-align: right; }');
                $head->appendChild($style);
            }
        }

        return str_replace('<?xml encoding="UTF-8">', '', $dom->saveHTML() ?: $html);
    }

    private const INLINE = ['span', 'strong', 'b', 'em', 'i', 'a', 'small', 'sup', 'sub', 'u', 'mark', 'label', 'code'];

    /**
     * What dompdf would not do for a right-to-left page: table columns run
     * from the right (each row's cells reversed), and the pieces of one line
     * (« المجموع » then <strong>343.14</strong>) are placed so that the first
     * one read is the rightmost — left-to-right pieces keeping their order.
     */
    private static function mirror(DOMDocument $dom, DOMXPath $xpath): void
    {
        foreach ($xpath->query('//tr') ?: [] as $row) {
            $cells = [];
            foreach (iterator_to_array($row->childNodes) as $child) {
                if ($child instanceof DOMElement && in_array(strtolower($child->tagName), ['td', 'th'], true)) {
                    $cells[] = $child;
                }
            }
            foreach (array_reverse($cells) as $cell) {
                $row->appendChild($cell);
            }
        }

        foreach ($xpath->query('//body//*[not(self::script)][not(self::style)][not(self::tr)][not(self::table)][not(self::thead)][not(self::tbody)][not(self::tfoot)]') ?: [] as $element) {
            /** @var DOMElement $element */
            $children = iterator_to_array($element->childNodes);
            if (count($children) < 2) {
                continue;
            }
            $types = [];
            foreach ($children as $child) {
                if ($child instanceof DOMElement && ! in_array(strtolower($child->tagName), self::INLINE, true)) {
                    continue 2; // a block, a line break, an image: not one line
                }
                $text = (string) $child->textContent;
                $types[] = ArabicShaper::hasArabic($text) ? 'R' : (preg_match('/[\p{L}\p{N}]/u', $text) ? 'L' : 'N');
            }
            if (! in_array('R', $types, true)) {
                continue;
            }
            $count = count($types);
            foreach ($types as $i => $type) {
                if ($type !== 'N') {
                    continue;
                }
                $before = null;
                for ($j = $i - 1; $j >= 0 && $before === null; $j--) {
                    $before = $types[$j] !== 'N' ? $types[$j] : null;
                }
                $after = null;
                for ($j = $i + 1; $j < $count && $after === null; $j++) {
                    $after = $types[$j] !== 'N' ? $types[$j] : null;
                }
                $types[$i] = ($before === 'L' && $after === 'L') ? 'L' : 'R';
            }
            $runs = [];
            foreach ($children as $i => $child) {
                $rtl = $types[$i] === 'R';
                if ($runs !== [] && $runs[array_key_last($runs)][0] === $rtl) {
                    $runs[array_key_last($runs)][1][] = $child;
                } else {
                    $runs[] = [$rtl, [$child]];
                }
            }
            foreach (array_reverse($runs) as [$rtl, $nodes]) {
                foreach ($rtl ? array_reverse($nodes) : $nodes as $node) {
                    // The line now reads the other way: a space that ended a
                    // piece now starts it (« Total : » + « 12 »).
                    if ($node instanceof \DOMText && trim($node->nodeValue) !== '') {
                        preg_match('/^\s*/u', $node->nodeValue, $lead);
                        preg_match('/\s*$/u', $node->nodeValue, $trail);
                        $node->nodeValue = $trail[0].trim($node->nodeValue).$lead[0];
                    }
                    $element->appendChild($node);
                }
            }
        }
    }

    /** The language a shop's PDFs are written in. */
    public static function pdfLocale(?Tenant $tenant): string
    {
        $locale = Locale::shop($tenant);

        return in_array($locale, self::PDF_LOCALES, true) ? $locale : 'fr';
    }
}
