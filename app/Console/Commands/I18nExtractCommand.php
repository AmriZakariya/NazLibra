<?php

namespace App\Console\Commands;

use App\Support\I18n\Extractor;
use Illuminate\Console\Command;

/**
 * Lists every French text of the shop back office into lang/i18n/source.json,
 * and says which are missing from each language's file.
 */
class I18nExtractCommand extends Command
{
    protected $signature = 'castlit:i18n-extract {--missing : Only list the texts a language lacks}';

    protected $description = 'List the back office French texts (the keys of lang/{locale}.json).';

    public function handle(Extractor $extractor): int
    {
        $strings = $extractor->strings();
        @mkdir(lang_path('i18n'), 0755, true);
        file_put_contents(lang_path('i18n/source.json'), json_encode(array_keys($strings), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
        $this->info(count($strings).' texts → lang/i18n/source.json');

        foreach (['ar', 'en', 'es', 'pt', 'de', 'it'] as $locale) {
            $file = lang_path($locale.'.json');
            $have = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
            $missing = array_values(array_diff(array_keys($strings), array_keys($have)));
            $this->line(sprintf('%s: %d missing', $locale, count($missing)));
            if ($this->option('missing') && $missing) {
                file_put_contents(lang_path("i18n/missing.{$locale}.json"), json_encode($missing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
            }
        }

        return self::SUCCESS;
    }
}
