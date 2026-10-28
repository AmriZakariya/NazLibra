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

        // The currency each country trades in. Every one of them is offered:
        // a shop in Riyadh or Toronto used to be left in dirhams because its
        // currency was not on a list of six.
        $currency = [];
        foreach ($codes as $code) {
            $formatter = new \NumberFormatter('en_'.$code, \NumberFormatter::CURRENCY);
            $iso = (string) $formatter->getTextAttribute(\NumberFormatter::CURRENCY_CODE);
            if (preg_match('/^[A-Z]{3}$/', $iso) && $iso !== 'XXX') {
                $currency[$code] = $iso;
            }
        }

        // Each currency's names and its minor unit (TND 3, XOF 0, MAD 2).
        $currencies = [];
        $isos = array_values(array_unique(array_merge(Countries::FREQUENT_CURRENCIES, array_values($currency))));
        sort($isos);
        foreach ($isos as $iso) {
            $digits = (new \NumberFormatter('en@currency='.$iso, \NumberFormatter::CURRENCY))
                ->getAttribute(\NumberFormatter::FRACTION_DIGITS);
            $currencies[$iso] = ['digits' => (int) $digits, 'names' => []];
            foreach (self::APP_LOCALES as $locale) {
                $bundle = \ResourceBundle::create($locale, 'ICUDATA-curr');
                $entry = $bundle?->get('Currencies')?->get($iso);
                $name = $entry ? (string) $entry->get(1) : '';
                $currencies[$iso]['names'][$locale] = $name !== '' ? mb_strtoupper(mb_substr($name, 0, 1)).mb_substr($name, 1) : $iso;
            }
        }

        $this->writePhp($codes, $names, $currency, $currencies);
        $this->info('Wrote '.Countries::dataPath().' — '.count($codes).' countries.');

        $dart = (string) ($this->option('dart') ?: base_path('../naz_pos/lib/core/geo/countries.dart'));
        if (is_dir(dirname($dart))) {
            $this->writeDart($dart, $codes, $names, $currency, $currencies);
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
     * @param  array<string, array{digits: int, names: array<string, string>}>  $currencies
     */
    private function writePhp(array $codes, array $names, array $currency, array $currencies): void
    {
        $data = [
            'codes' => $codes,
            'names' => array_intersect_key($names, array_flip(self::SITE_LOCALES)),
            'currency' => $currency,
            'currencies' => array_map(fn (array $c) => [
                'digits' => $c['digits'],
                'names' => array_intersect_key($c['names'], array_flip(self::SITE_LOCALES)),
            ], $currencies),
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
     * @param  array<string, array{digits: int, names: array<string, string>}>  $currencies
     */
    private function writeDart(string $path, array $codes, array $names, array $currency, array $currencies): void
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

        $out .= "/// The currency each country trades in.\n"
            ."const _currencyByCountry = <String, String>{\n";
        foreach ($currency as $code => $iso) {
            $out .= "  {$q($code)}: {$q($iso)},\n";
        }
        $out .= "};\n\n";

        $out .= "/// Shown first in a currency picker, in this order.\n"
            ."const frequentCurrencies = <String>[".implode(', ', array_map($q, Countries::FREQUENT_CURRENCIES))."];\n\n";

        $out .= "/// Every currency a shop can trade in.\n"
            ."const currencyCodes = <String>[\n";
        foreach (array_chunk(array_keys($currencies), 12) as $row) {
            $out .= '  '.implode(', ', array_map($q, $row)).",\n";
        }
        $out .= "];\n\n";

        $out .= "/// Minor unit: TND 3 (millimes), XOF 0 (no centimes), MAD 2.\n"
            ."const _currencyDigits = <String, int>{\n";
        foreach ($currencies as $iso => $c) {
            $out .= "  {$q($iso)}: {$c['digits']},\n";
        }
        $out .= "};\n\n";

        $out .= "/// Currency names by language.\n"
            ."const _currencyNames = <String, Map<String, String>>{\n";
        foreach (self::APP_LOCALES as $locale) {
            $out .= "  {$q($locale)}: {\n";
            foreach ($currencies as $iso => $c) {
                $out .= "    {$q($iso)}: {$q($c['names'][$locale])},\n";
            }
            $out .= "  },\n";
        }
        $out .= "};\n\n";

        $out .= <<<'DART'
String countryName(String code, String languageCode) =>
    (_countryNames[languageCode] ?? _countryNames['fr']!)[code] ?? code;

/// Null only for a territory with no currency of its own (Antarctica): the
/// user's choice is then left as it is.
String? currencyForCountry(String code) => _currencyByCountry[code];

/// The currency's decimals: 3 for TND, 0 for XOF, 2 when unknown.
int currencyDigits(String code) => _currencyDigits[code.toUpperCase()] ?? 2;

String currencyName(String code, String languageCode) =>
    (_currencyNames[languageCode] ?? _currencyNames['fr']!)[code.toUpperCase()] ??
    code;

/// The frequent currencies first, then every other one by code.
List<String> orderedCurrencyCodes() => [
      ...frequentCurrencies,
      ...currencyCodes.where((c) => !frequentCurrencies.contains(c)),
    ];

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
