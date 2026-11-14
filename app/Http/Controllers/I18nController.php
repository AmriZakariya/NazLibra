<?php

namespace App\Http\Controllers;

use App\Support\Locale;
use Illuminate\Http\Response;

/**
 * GET /i18n/{locale}.js — a language's dictionary for the back office's
 * pages (resources/js/i18n.js translates every text with it). Cached for
 * good: the page asks for it with the file's version, so a new
 * translation is a new address.
 */
class I18nController extends Controller
{
    public function script(string $locale): Response
    {
        abort_unless(in_array($locale, Locale::SUPPORTED, true) && $locale !== 'fr', 404);

        $json = json_encode(Locale::dictionary($locale), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);

        return response('window.libraireProTranslations='.$json.';', 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
