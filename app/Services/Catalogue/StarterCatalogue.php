<?php

namespace App\Services\Catalogue;

use App\Models\Item;
use App\Models\Tenant;
use App\Support\BusinessMode;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * The ready-made catalogue a new shop can start from: typical services with
 * their categories, prices, durations, variants, options and photos.
 *
 * It is stored as data (resources/starter/{key}/catalogue.json plus a photos
 * folder) and applied through the very import a shop's own Excel goes
 * through — the ZIP this class builds is a sheet and its photos — so
 * variants, options, durations and « à partir de » behave identically and
 * applying twice updates instead of duplicating.
 */
class StarterCatalogue
{
    public const SALON_MA = 'beauty-ma';

    /** The sheet's columns, in the import's own French headers. */
    private const HEADERS = [
        "Nom de l'article", "Catégorie/Type d'élément", 'Unité', "Prix d'achat", 'Prix de vente', 'Impôt',
        'Statut', 'Tags', 'Description', "Type d'élément", 'Image', 'Durée (min)', 'Prix à partir de', 'Variantes', 'Options',
    ];

    /** Offered to beauty shops pricing in dirhams. */
    public static function availableFor(?Tenant $tenant, string $key = self::SALON_MA): bool
    {
        return $tenant !== null
            && $key === self::SALON_MA
            && BusinessMode::current($tenant)['key'] === 'beauty'
            && Money::normalizeCode($tenant->currency) === 'MAD'
            && is_file(self::dir($key).'/catalogue.json');
    }

    public static function dir(string $key = self::SALON_MA): string
    {
        return resource_path('starter/'.$key);
    }

    /** @return array{categories: list<array{name: string, description: string}>, services: list<array<string, string>>} */
    public function data(string $key = self::SALON_MA): array
    {
        return json_decode((string) file_get_contents(self::dir($key).'/catalogue.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** What the confirm step shows: category names and how many services. */
    public function preview(string $key = self::SALON_MA): array
    {
        $data = $this->data($key);
        $counts = collect($data['services'])->countBy('category');

        return [
            'services' => count($data['services']),
            'categories' => collect($data['categories'])->map(fn (array $c) => [
                'name' => $c['name'],
                'count' => (int) ($counts[$c['name']] ?? 0),
            ])->all(),
        ];
    }

    /** Titles (lower-cased) of the services this shop already has, deleted ones included. */
    public function existingTitles(Tenant $tenant): array
    {
        return Item::withTrashed()->where('tenant_id', $tenant->id)->get(['id', 'title', 'images'])
            ->mapWithKeys(fn (Item $i) => [mb_strtolower(trim($i->title)) => ! empty($i->images)])->all();
    }

    /**
     * The upload the import takes: a ZIP with the sheet and the photos. A
     * service that already has a photo gets none, so applying again never
     * replaces a photo the shop chose.
     *
     * @param  array<string, bool>  $existing  lower-cased title => has a photo
     */
    public function buildUpload(array $existing, string $key = self::SALON_MA): UploadedFile
    {
        $data = $this->data($key);
        $csv = fopen('php://temp', 'w+');
        fputcsv($csv, self::HEADERS);
        $photos = [];
        foreach ($data['services'] as $s) {
            $hasPhoto = $existing[mb_strtolower($s['title'])] ?? false;
            $photo = $s['image'] !== '' && ! $hasPhoto && is_file(self::dir($key).'/photos/'.$s['image']) ? $s['image'] : '';
            if ($photo !== '') {
                $photos[] = $photo;
            }
            fputcsv($csv, [
                $s['title'], $s['category'], 'Service', '0', $s['price'], 'Sans TVA',
                'Active', $s['tags'], $s['description'], 'Prestation', $photo, $s['duration'], $s['from'], $s['variants'], $s['options'],
            ]);
        }
        rewind($csv);
        $sheet = (string) stream_get_contents($csv);
        fclose($csv);

        $path = sys_get_temp_dir().'/starter-'.Str::random(12).'.zip';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('catalogue.csv', $sheet);
        foreach (array_unique($photos) as $photo) {
            $zip->addFile(self::dir($key).'/photos/'.$photo, $photo);
        }
        $zip->close();

        return new UploadedFile($path, 'catalogue-type-salon.zip', 'application/zip', null, true);
    }

    /** Give the categories their description, unless the shop wrote its own. */
    public function describeCategories(Tenant $tenant, string $key = self::SALON_MA): void
    {
        foreach ($this->data($key)['categories'] as $c) {
            \App\Models\Category::where('tenant_id', $tenant->id)->where('slug', Str::slug($c['name']))
                ->where(fn ($q) => $q->whereNull('description')->orWhere('description', ''))
                ->update(['description' => $c['description']]);
        }
    }

    public static function cleanup(?UploadedFile $upload): void
    {
        if ($upload) {
            File::delete($upload->getRealPath());
        }
    }
}
