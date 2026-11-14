<?php

namespace App\Support;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Session;

class Locale
{
    /**
     * The shop's language, as offered in Paramètres › Société and in the
     * language menu: every language the app speaks, each named in itself.
     * The web back office speaks all of them (lang/{locale}.json, French
     * being the source), and so does the app.
     */
    public const SHOP_LANGUAGES = [
        'fr' => 'Français',
        'ar' => 'العربية',
        'en' => 'English',
        'es' => 'Español',
        'pt' => 'Português',
        'de' => 'Deutsch',
        'it' => 'Italiano',
    ];

    /** What the web back office is translated into. */
    public const SUPPORTED = ['fr', 'ar', 'en', 'es', 'pt', 'de', 'it'];

    /** A flag beside each language in the menu. */
    public const FLAGS = ['fr' => '🇫🇷', 'ar' => '🇲🇦', 'en' => '🇬🇧', 'es' => '🇪🇸', 'pt' => '🇵🇹', 'de' => '🇩🇪', 'it' => '🇮🇹'];

    /** The shop's number/date locale for each language. */
    public const SHOP_LOCALES = [
        'fr' => 'fr_MA',
        'ar' => 'ar_MA',
        'en' => 'en_US',
        'es' => 'es_ES',
        'pt' => 'pt_PT',
        'de' => 'de_DE',
        'it' => 'it_IT',
    ];

    public const SESSION_KEY = 'librairepro_locale';

    /**
     * The language this person reads the back office in: picked in the menu
     * (this session, or on the login page), else their account's, else the
     * shop's, else French.
     */
    public static function current(?Tenant $tenant = null, ?User $user = null): string
    {
        $user ??= auth()->user();

        foreach ([Session::get(self::SESSION_KEY), $user?->locale, self::shop($tenant)] as $candidate) {
            if (in_array($candidate, self::SUPPORTED, true)) {
                return $candidate;
            }
        }

        return 'fr';
    }

    /** The shop's own language (Paramètres › Société): its documents speak it. */
    public static function shop(?Tenant $tenant): string
    {
        $locale = data_get($tenant?->settings, 'language_id')
            ?: substr((string) $tenant?->locale, 0, 2);

        return in_array($locale, self::SUPPORTED, true) ? $locale : 'fr';
    }

    public static function apply(?Tenant $tenant = null): string
    {
        $locale = self::current($tenant);
        App::setLocale($locale);

        return $locale;
    }

    public static function dir(?string $locale = null): string
    {
        return ($locale ?? App::getLocale()) === 'ar' ? 'rtl' : 'ltr';
    }

    public static function label(?string $locale = null): string
    {
        return self::SHOP_LANGUAGES[$locale ?? App::getLocale()] ?? 'Français';
    }

    /** For the public shop pages, which still switch between French and Arabic. */
    public static function opposite(?string $locale = null): string
    {
        return ($locale ?? App::getLocale()) === 'ar' ? 'fr' : 'ar';
    }

    /**
     * A French text of the back office in [$locale]: lang/{locale}.json,
     * the French text itself when it has no translation.
     */
    public static function t(string $text, ?string $locale = null): string
    {
        $locale ??= App::getLocale();
        if ($locale === 'fr' || ! in_array($locale, self::SUPPORTED, true)) {
            return $text;
        }

        return self::dictionary($locale)[$text] ?? $text;
    }

    /** @return array<string, string> French text => translation */
    public static function dictionary(string $locale): array
    {
        static $loaded = [];
        if (! isset($loaded[$locale])) {
            $file = lang_path($locale.'.json');
            $loaded[$locale] = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
        }

        return $loaded[$locale];
    }

    /** Changes when the language's file does: the page asks for the new one. */
    public static function version(string $locale): string
    {
        $file = lang_path($locale.'.json');

        return is_file($file) ? substr(md5_file($file) ?: '', 0, 10) : '0';
    }
}
