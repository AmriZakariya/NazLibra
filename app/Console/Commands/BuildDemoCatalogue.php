<?php

namespace App\Console\Commands;

use App\Support\ItemTypes;
use App\Support\SimpleXlsx;
use Illuminate\Console\Command;

/**
 * Builds a demo catalogue per business activity, in the shape the catalogue
 * importer reads.
 *
 * One catalogue per activity because the activities do not merely sell
 * different things — they use different item TYPES. A pharmacy stocks
 * medications, a clothing shop garments, a restaurant dishes and drinks, and
 * a commerce général has only one physical type to put anything in. Demo data
 * that ignored that would exercise the shop we do not ship.
 *
 * The files are generated rather than checked in as binaries so the data
 * stays reviewable in `database/data/demo_catalogues/`: a diff on an .xlsx
 * tells nobody what changed about the shop.
 */
class BuildDemoCatalogue extends Command
{
    protected $signature = 'demo:catalogue
        {--activity=* : bookstore, restaurant, cafe, pharmacy, clothing, general (default: all)}
        {--dir= : Where to write (default storage/app/demo)}
        {--csv : Also write CSV alongside each workbook}';

    protected $description = "Génère les catalogues de démonstration par activité, au format d'import";

    /**
     * What the "Type d'élément" column has to say for each item type.
     *
     * These are the words `importedItemType` matches on. Sending the activity's
     * own display label ("Plat / menu") would not match and the row would fall
     * back to `supply`, so the mapping is explicit rather than borrowed from
     * ItemTypes' labels.
     */
    private const TYPE_LABELS = [
        'book' => 'Livre',
        'supply' => 'Article',
        'medication' => 'Médicament',
        'clothing' => 'Vêtement',
        'service' => 'Service',
    ];

    public function handle(): int
    {
        $activities = $this->option('activity') ?: $this->available();

        $directory = $this->option('dir') ?: storage_path('app/demo');
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            $this->error("Impossible de créer $directory");

            return self::FAILURE;
        }

        foreach ($activities as $activity) {
            $path = database_path("data/demo_catalogues/$activity.php");
            if (! is_file($path)) {
                $this->error("Activité inconnue : $activity");

                return self::FAILURE;
            }

            $this->build($activity, require $path, $directory);
        }

        return self::SUCCESS;
    }

    /** @return array<int, string> */
    private function available(): array
    {
        return array_values(array_filter(
            ItemTypes::activityKeys(),
            fn (string $activity) => is_file(database_path("data/demo_catalogues/$activity.php")),
        ));
    }

    /** @param array<string, mixed> $catalogue */
    private function build(string $activity, array $catalogue, string $directory): void
    {
        $withIsbn = (bool) ($catalogue['isbn_column'] ?? false);

        $itemHeaders = array_values(array_filter([
            'Code de barre',
            $withIsbn ? 'ISBN' : null,
            "Nom de l'article",
            "Catégorie/Type d'élément",
            'Marque',
            $withIsbn ? 'Auteur' : null,
            'Unité', 'Stock', "Quantité d'alerte",
            "Prix d'achat", 'Prix de vente', 'Impôt', 'Statut', 'Tags',
            'Description', "Type d'élément",
        ]));

        $serviceHeaders = [
            'Code de barre', "Nom de l'article", "Catégorie/Type d'élément", 'Unité',
            "Prix d'achat", 'Prix de vente', 'Impôt', 'Statut', 'Tags',
            'Description', "Type d'élément",
        ];

        $itemRows = $this->itemRows($catalogue['items'], $withIsbn);
        $serviceRows = $this->serviceRows($catalogue['services']);

        $stem = $directory."/catalogue-$activity";
        SimpleXlsx::write($catalogue['title'], $itemHeaders, $itemRows, "$stem-articles.xlsx");
        SimpleXlsx::write($catalogue['title'].' — services', $serviceHeaders, $serviceRows, "$stem-services.xlsx");

        if ($this->option('csv')) {
            $this->writeCsv("$stem-articles.csv", $itemHeaders, $itemRows);
            $this->writeCsv("$stem-services.csv", $serviceHeaders, $serviceRows);
        }

        $this->info(str_pad($activity, 11).count($itemRows).' articles, '.count($serviceRows).' services');
        $this->line("  $stem-articles.xlsx");
        $this->line("  $stem-services.xlsx");
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<int, string>>
     */
    private function itemRows(array $items, bool $withIsbn): array
    {
        $rows = [];

        foreach ($items as $index => $item) {
            $isBook = $item['kind'] === 'book';
            // A book's barcode IS its ISBN on the shelf, which is how a
            // bookseller scans it, so the two columns carry one value. Every
            // other trade gets an in-house EAN.
            $barcode = $item['isbn'] !== ''
                ? $item['isbn']
                : $this->ean13(($isBook ? '978' : '611').str_pad((string) (1000 + $index), 9, '0', STR_PAD_LEFT));

            $rows[] = array_values(array_filter([
                $barcode,
                $withIsbn ? ($isBook ? $barcode : '') : null,
                $item['name'],
                $item['category'].'[ITEM]',
                $item['brand'],
                $withIsbn ? $item['author'] : null,
                $item['unit'],
                (string) $item['stock'],
                (string) $item['alert'],
                number_format($item['cost'], 2, '.', ''),
                number_format($item['price'], 2, '.', ''),
                $this->taxLabel($item['vat']),
                'Active',
                $item['tags'],
                $item['description'],
                self::TYPE_LABELS[$item['kind']],
            ], fn ($value) => $value !== null));
        }

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $services
     * @return array<int, array<int, string>>
     */
    private function serviceRows(array $services): array
    {
        return array_map(fn (array $service) => [
            '',
            $service['name'],
            'SERVICES[SERVICE]',
            'Service',
            number_format($service['cost'], 2, '.', ''),
            number_format($service['price'], 2, '.', ''),
            $this->taxLabel($service['vat']),
            'Active',
            $service['tags'],
            $service['description'],
            'Service',
        ], $services);
    }

    /** The importer parses "Nom(taux%)" and creates the rate if it is new. */
    private function taxLabel(int $rate): string
    {
        return $rate === 0
            ? 'Sans TVA(0.00%)'
            : sprintf('TVA %d%%(%s%%)', $rate, number_format($rate, 2, '.', ''));
    }

    /**
     * Completes a 12-digit body with its EAN-13 check digit.
     *
     * Real check digits because these get scanned: a wrong one reads as a
     * broken scanner rather than as bad data.
     */
    private function ean13(string $body): string
    {
        $body = substr(str_pad($body, 12, '0'), 0, 12);
        $sum = 0;
        foreach (str_split($body) as $position => $digit) {
            $sum += (int) $digit * ($position % 2 === 0 ? 1 : 3);
        }

        return $body.((10 - $sum % 10) % 10);
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string>>  $rows
     */
    private function writeCsv(string $path, array $headers, array $rows): void
    {
        $handle = fopen($path, 'w');
        fputcsv($handle, $headers);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);
    }
}
