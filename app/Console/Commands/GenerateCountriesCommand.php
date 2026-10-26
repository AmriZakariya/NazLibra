<?php

namespace App\Console\Commands;

use App\Support\Countries;
use Illuminate\Console\Command;

/**
 * Regenerates the country list the signup forms offer — on the server and,
 * when the app sits beside it, in the Flutter app.
 *
 * The codes are the 249 officially assigned ISO 3166-1 alpha-2 codes, written
 * out here rather than enumerated from ICU: ICU's region list also carries
 * reserved and non-country codes (EU, UN, QO, ZZ, Sark's CQ…) and changes from
 * one ICU release to the next. ICU supplies only the NAMES, in every language
 * the site and the app ship in, so the generated files work on a host without
 * the intl extension.
 *
 *   php artisan castlit:countries-generate
 *   php artisan castlit:countries-generate --dart=../naz_pos/lib/core/geo/countries.dart
 */
class GenerateCountriesCommand extends Command
{
    protected $signature = 'castlit:countries-generate
        {--dart= : Also write the Flutter list here (default: the sibling naz_pos checkout, if present)}';

    protected $description = 'Regenerate the signup country list (server data file, and the Flutter app list).';

    /** Every officially assigned ISO 3166-1 alpha-2 code. */
    public const ISO_3166_1 = [
        'AD', 'AE', 'AF', 'AG', 'AI', 'AL', 'AM', 'AO', 'AQ', 'AR', 'AS', 'AT', 'AU', 'AW', 'AX', 'AZ',
        'BA', 'BB', 'BD', 'BE', 'BF', 'BG', 'BH', 'BI', 'BJ', 'BL', 'BM', 'BN', 'BO', 'BQ', 'BR', 'BS',
        'BT', 'BV', 'BW', 'BY', 'BZ', 'CA', 'CC', 'CD', 'CF', 'CG', 'CH', 'CI', 'CK', 'CL', 'CM', 'CN',
        'CO', 'CR', 'CU', 'CV', 'CW', 'CX', 'CY', 'CZ', 'DE', 'DJ', 'DK', 'DM', 'DO', 'DZ', 'EC', 'EE',
        'EG', 'EH', 'ER', 'ES', 'ET', 'FI', 'FJ', 'FK', 'FM', 'FO', 'FR', 'GA', 'GB', 'GD', 'GE', 'GF',
        'GG', 'GH', 'GI', 'GL', 'GM', 'GN', 'GP', 'GQ', 'GR', 'GS', 'GT', 'GU', 'GW', 'GY', 'HK', 'HM',
        'HN', 'HR', 'HT', 'HU', 'ID', 'IE', 'IL', 'IM', 'IN', 'IO', 'IQ', 'IR', 'IS', 'IT', 'JE', 'JM',
        'JO', 'JP', 'KE', 'KG', 'KH', 'KI', 'KM', 'KN', 'KP', 'KR', 'KW', 'KY', 'KZ', 'LA', 'LB', 'LC',
        'LI', 'LK', 'LR', 'LS', 'LT', 'LU', 'LV', 'LY', 'MA', 'MC', 'MD', 'ME', 'MF', 'MG', 'MH', 'MK',
        'ML', 'MM', 'MN', 'MO', 'MP', 'MQ', 'MR', 'MS', 'MT', 'MU', 'MV', 'MW', 'MX', 'MY', 'MZ', 'NA',
        'NC', 'NE', 'NF', 'NG', 'NI', 'NL', 'NO', 'NP', 'NR', 'NU', 'NZ', 'OM', 'PA', 'PE', 'PF', 'PG',
        'PH', 'PK', 'PL', 'PM', 'PN', 'PR', 'PS', 'PT', 'PW', 'PY', 'QA', 'RE', 'RO', 'RS', 'RU', 'RW',
        'SA', 'SB', 'SC', 'SD', 'SE', 'SG', 'SH', 'SI', 'SJ', 'SK', 'SL', 'SM', 'SN', 'SO', 'SR', 'SS',
        'ST', 'SV', 'SX', 'SY', 'SZ', 'TC', 'TD', 'TF', 'TG', 'TH', 'TJ', 'TK', 'TL', 'TM', 'TN', 'TO',
        'TR', 'TT', 'TV', 'TW', 'TZ', 'UA', 'UG', 'UM', 'US', 'UY', 'UZ', 'VA', 'VC', 'VE', 'VG', 'VI',
        'VN', 'VU', 'WF', 'WS', 'YE', 'YT', 'ZA', 'ZM', 'ZW',
    ];

    /** The site's languages; the data file carries names in each. */
    private const SITE_LOCALES = ['fr', 'en', 'ar'];

    /** The app's languages (lib/l10n/app_*.arb). */
    private const APP_LOCALES = ['fr', 'en', 'ar', 'es', 'de', 'it', 'pt'];

    public function handle(): int
    {
        if (! class_exists(\Locale::class) || ! class_exists(\NumberFormatter::class)) {
            $this->error('The intl extension is needed to generate the names. Run this on a machine that has it.');

            return self::FAILURE;
        }

        $codes = array_values(array_diff(self::ISO_3166_1, Countries::EXCLUDED));

        $names = [];
        foreach (self::APP_LOCALES as $locale) {
            foreach ($codes as $code) {
                $name = \Locale::getDisplayRegion('-'.$code, $locale);
                if (! is_string($name) || $name === '' || $name === $code) {
                    $this->error("ICU has no {$locale} name for {$code}.");

                    return self::FAILURE;
                }
                $names[$locale][$code] = $name;
            }
        }

        // The currency a shop there most likely trades in — kept only when the
        // signup form offers it, since a suggestion the form cannot select is
        // no suggestion at all.
        $currency = [];
        foreach ($codes as $code) {
            $formatter = new \NumberFormatter('en_'.$code, \NumberFormatter::CURRENCY);
            $iso = (string) $formatter->getTextAttribute(\NumberFormatter::CURRENCY_CODE);
            if (in_array($iso, Countries::OFFERED_CURRENCIES, true)) {
                $currency[$code] = $iso;
            }
        }

        $this->writePhp($codes, $names, $currency);
        $this->info('Wrote '.Countries::dataPath().' — '.count($codes).' countries.');

        $dart = (string) ($this->option('dart') ?: base_path('../naz_pos/lib/core/geo/countries.dart'));
        if (is_dir(dirname($dart))) {
            $this->writeDart($dart, $codes, $names, $currency);
            $this->info("Wrote {$dart}.");
        } else {
            $this->warn("Flutter list not written: {$dart} is not in a checkout here. Pass --dart=<path>.");
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $codes
     * @param  array<string, array<string, string>>  $names
     * @param  array<string, string>  $currency
     */
    private function writePhp(array $codes, array $names, array $currency): void
    {
        $data = [
            'codes' => $codes,
            'names' => array_intersect_key($names, array_flip(self::SITE_LOCALES)),
            'currency' => $currency,
        ];

        $php = "<?php\n\n"
            ."// GENERATED by `php artisan castlit:countries-generate` — do not edit by hand.\n"
            ."// The 249 ISO 3166-1 codes minus App\\Support\\Countries::EXCLUDED, with ICU names.\n\n"
            .'return '.var_export($data, true).";\n";

        file_put_contents(Countries::dataPath(), $php);
    }

    /**
     * @param  list<string>  $codes
     * @param  array<string, array<string, string>>  $names
     * @param  array<string, string>  $currency
     */
    private function writeDart(string $path, array $codes, array $names, array $currency): void
    {
        $q = fn (string $s): string => "'".str_replace(['\\', "'", '$'], ['\\\\', "\\'", '\\$'], $s)."'";

        $out = "// GENERATED by `php artisan castlit:countries-generate` in NazLibra — do not\n"
            ."// edit by hand. The server validates the same codes (App\\Support\\Countries).\n\n"
            ."/// Every country a shop can sign up from: the 249 ISO 3166-1 codes minus\n"
            ."/// the ones the server excludes.\n"
            ."const countryCodes = <String>[\n";
        foreach (array_chunk($codes, 12) as $row) {
            $out .= '  '.implode(', ', array_map($q, $row)).",\n";
        }
        $out .= "];\n\n";

        $out .= "/// Shown first, in this order, before the alphabetical list.\n"
            ."const frequentCountries = <String>[".implode(', ', array_map($q, Countries::FREQUENT))."];\n\n"
            ."const defaultCountry = ".$q(Countries::DEFAULT).";\n\n";

        $out .= "/// Country names by language. A language missing here falls back to French.\n"
            ."const _countryNames = <String, Map<String, String>>{\n";
        foreach ($names as $locale => $byCode) {
            $out .= "  {$q($locale)}: {\n";
            foreach ($byCode as $code => $name) {
                $out .= "    {$q($code)}: {$q($name)},\n";
            }
            $out .= "  },\n";
        }
        $out .= "};\n\n";

        $out .= "/// The currency a shop there most likely trades in, where the form offers it.\n"
            ."const _currencyByCountry = <String, String>{\n";
        foreach ($currency as $code => $iso) {
            $out .= "  {$q($code)}: {$q($iso)},\n";
        }
        $out .= "};\n\n";

        $out .= <<<'DART'
String countryName(String code, String languageCode) =>
    (_countryNames[languageCode] ?? _countryNames['fr']!)[code] ?? code;

/// Null when the form offers no currency for that country: the user's choice
/// is then left as it is, rather than replaced with a guess.
String? currencyForCountry(String code) => _currencyByCountry[code];

/// Emoji flag from the two letters, so the list reads at a glance.
String countryFlag(String code) => code.length != 2
    ? ''
    : String.fromCharCodes(
        code.toUpperCase().codeUnits.map((u) => 0x1F1E6 + u - 0x41));

/// The order a picker shows: the frequent countries first, then every other
/// one alphabetically in [languageCode].
List<String> orderedCountryCodes(String languageCode) {
  final rest = countryCodes
      .where((c) => !frequentCountries.contains(c))
      .toList()
    ..sort((a, b) => countryFoldKey(countryName(a, languageCode))
        .compareTo(countryFoldKey(countryName(b, languageCode))));
  return [...frequentCountries, ...rest];
}

/// Lower-cased with accents folded, for sorting and for search: "Égypte"
/// sorts with the Es rather than after Zimbabwe, and typing "egy" finds it.
String countryFoldKey(String name) {
  const from = 'ÀÁÂÃÄÅàáâãäåÇçÈÉÊËèéêëÌÍÎÏìíîïÑñÒÓÔÕÖØòóôõöøÙÚÛÜùúûüÝýÿŒœÆæ';
  const to = 'AAAAAAaaaaaaCcEEEEeeeeIIIIiiiiNnOOOOOOooooooUUUUuuuuYyyOoAa';
  final buffer = StringBuffer();
  for (final rune in name.runes) {
    final ch = String.fromCharCode(rune);
    final i = from.indexOf(ch);
    buffer.write(i >= 0 ? to[i] : ch);
  }
  return buffer.toString().toLowerCase();
}

DART;

        file_put_contents($path, $out);
    }
}
