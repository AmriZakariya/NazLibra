<?php

namespace App\Console\Commands;

use App\Support\SimpleXlsx;
use Illuminate\Console\Command;

/**
 * Builds the demo catalogue in the shape the catalogue importer reads.
 *
 * The file is generated rather than checked in as a binary so the data stays
 * reviewable in `database/data/demo_catalogue.php`: a diff on an .xlsx tells
 * nobody what changed about the shop.
 */
class BuildDemoCatalogue extends Command
{
    protected $signature = 'demo:catalogue
        {--dir= : Where to write (default storage/app/demo)}
        {--csv : Also write CSV alongside the workbook}';

    protected $description = "Génère le catalogue de démonstration au format d'import";

    /** The import template's columns, in its order. */
    private const ITEM_HEADERS = [
        'Code de barre', 'ISBN', "Nom de l'article", "Catégorie/Type d'élément",
        'Marque', 'Auteur', 'Unité', 'Stock', "Quantité d'alerte",
        "Prix d'achat", 'Prix de vente', 'Impôt', 'Statut', 'Tags',
        'Description', "Type d'élément",
    ];

    private const SERVICE_HEADERS = [
        'Code de barre', "Nom de l'article", "Catégorie/Type d'élément", 'Unité',
        "Prix d'achat", 'Prix de vente', 'Impôt', 'Statut', 'Tags',
        'Description', "Type d'élément",
    ];

    /**
     * Books are VAT-exempt in Morocco; everything else is at the standard rate.
     * Both names are seeded active for the demo tenant.
     */
    private const BOOK_CATEGORIES = [
        'LIVRES SCOLAIRES', 'PARASCOLAIRE', 'ROMANS',
        'LIVRES JEUNESSE', 'LIVRES RELIGIEUX',
    ];

    public function handle(): int
    {
        $catalogue = require database_path('data/demo_catalogue.php');

        $directory = $this->option('dir') ?: storage_path('app/demo');
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            $this->error("Impossible de créer $directory");

            return self::FAILURE;
        }

        $itemRows = $this->itemRows($catalogue['items']);
        $serviceRows = $this->serviceRows($catalogue['services']);

        $written = [
            SimpleXlsx::write("Liste d'articles", self::ITEM_HEADERS, $itemRows,
                $directory.'/catalogue-librairie-articles.xlsx'),
            SimpleXlsx::write('Liste des services', self::SERVICE_HEADERS, $serviceRows,
                $directory.'/catalogue-librairie-services.xlsx'),
        ];

        if ($this->option('csv')) {
            $written[] = $this->writeCsv($directory.'/catalogue-librairie-articles.csv', self::ITEM_HEADERS, $itemRows);
            $written[] = $this->writeCsv($directory.'/catalogue-librairie-services.csv', self::SERVICE_HEADERS, $serviceRows);
        }

        $this->info(count($itemRows).' articles, '.count($serviceRows).' services');
        foreach ($written as $path) {
            $this->line('  '.$path);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<int, string>>
     */
    private function itemRows(array $items): array
    {
        $rows = [];

        foreach ($items as $index => $item) {
            $isBook = in_array($item['category'], self::BOOK_CATEGORIES, true);
            // A book's barcode IS its ISBN on the shelf, which is how a
            // bookseller scans it — so the two columns carry the same value
            // rather than inventing a second code nobody would ever scan.
            $barcode = $item['isbn'] !== ''
                ? $item['isbn']
                : ($isBook
                    ? $this->ean13('978'.str_pad((string) (1000 + $index), 9, '0', STR_PAD_LEFT))
                    : $this->ean13('611'.str_pad((string) (2000 + $index), 9, '0', STR_PAD_LEFT)));

            $rows[] = [
                $barcode,
                $isBook ? $barcode : '',
                $item['name'],
                $item['category'].'[ITEM]',
                $item['brand'],
                $item['author'],
                $item['unit'],
                (string) $item['stock'],
                (string) $item['alert'],
                number_format($item['cost'], 2, '.', ''),
                number_format($item['price'], 2, '.', ''),
                $isBook ? 'Sans TVA(0.00%)' : 'TVA 20%(20.00%)',
                'Active',
                $item['tags'],
                $item['description'],
                $item['kind'],
            ];
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
            'TVA 20%(20.00%)',
            'Active',
            $service['tags'],
            $service['description'],
            'Service',
        ], $services);
    }

    /**
     * Completes a 12-digit body with its EAN-13 check digit.
     *
     * Real check digits because these get scanned: a POS that validates the
     * barcode would reject demo data built from arbitrary digits, and the
     * failure would look like a scanner fault.
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
    private function writeCsv(string $path, array $headers, array $rows): string
    {
        $handle = fopen($path, 'w');
        fputcsv($handle, $headers);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);

        return $path;
    }
}
