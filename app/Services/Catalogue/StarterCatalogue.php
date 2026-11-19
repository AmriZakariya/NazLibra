<?php

namespace App\Services\Catalogue;

use App\Models\Category;
use App\Models\Item;
use App\Models\ItemComponent;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\Unit;
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

    /**
     * The care products the salon's services use (« Produits utilisés »), and
     * the recipes of the services that exist: starting estimates the shop
     * adjusts, never a promise about its real practice.
     *
     * Idempotent: a product that exists (by title, deleted ones included) is
     * left as the shop made it, and a service that already has a recipe keeps
     * it. Products start with no stock; those that are not sold retail stay
     * off the till.
     *
     * @return array{products: int, recipes: int}
     */
    public function applySupplies(Tenant $tenant, string $key = self::SALON_MA): array
    {
        $data = $this->data($key);
        $supplies = $data['supplies'] ?? [];
        if ($supplies === []) {
            return ['products' => 0, 'recipes' => 0];
        }

        $category = Category::firstOrCreate(
            ['tenant_id' => $tenant->id, 'slug' => Str::slug('PRODUITS DE SOIN')],
            ['name' => 'PRODUITS DE SOIN', 'icon' => 'archive', 'color' => '#4F46E5', 'loan_duration_days' => 14, 'daily_fine_amount' => 2,
                'description' => 'Produits de soin et consommables utilisés par les services'],
        );
        $unit = Unit::firstOrCreate(['tenant_id' => $tenant->id, 'name' => 'Pièce']);
        $tax = Tax::firstOrCreate(['tenant_id' => $tenant->id, 'name' => 'Sans TVA'], ['rate' => 0]);

        $existing = Item::withTrashed()->where('tenant_id', $tenant->id)->get(['id', 'tenant_id', 'title', 'item_code', 'type'])
            ->keyBy(fn (Item $i) => mb_strtolower(trim($i->title)));
        $codes = Item::withTrashed()->where('tenant_id', $tenant->id)->pluck('item_code')->flip();

        $created = 0;
        foreach ($supplies as $i => $supply) {
            $title = $supply['title'];
            if ($existing->has(mb_strtolower($title))) {
                continue;
            }
            $n = $i + 1;
            do {
                $code = 'SOIN-'.str_pad((string) $n++, 3, '0', STR_PAD_LEFT);
            } while ($codes->has($code));
            $codes->put($code, true);

            $retail = (float) ($supply['sale_price'] ?? 0) > 0;
            $item = Item::create([
                'tenant_id' => $tenant->id,
                'category_id' => $category->id,
                'unit_id' => $unit->id,
                'tax_id' => $tax->id,
                'type' => 'supply',
                'status' => 'out_of_stock',
                'is_enabled' => true,
                // Off the till unless the shop sells it over the counter.
                'checkout_visible' => $retail,
                'online_store_visible' => false,
                'item_code' => $code,
                'title' => $title,
                'price' => $supply['purchase_price'],
                'purchase_price' => $supply['purchase_price'],
                'sale_price' => $supply['sale_price'] ?? 0,
                'tax_type' => 'Exclusive',
                'discount_type' => 'Percentage',
                'discount' => 0,
                'opening_stock' => 0,
                'stock_quantity' => 0,
                'min_stock_threshold' => 1,
                'track_inventory' => true,
                'content_quantity' => $supply['content_quantity'],
                'content_unit' => $supply['content_unit'],
            ]);
            $existing->put(mb_strtolower($title), $item);
            $created++;
        }

        $recipes = 0;
        $components = app(ItemComponentService::class);
        foreach ($data['recipes'] ?? [] as $serviceTitle => $rows) {
            $service = $existing->get(mb_strtolower($serviceTitle));
            if (! $service || $service->type !== 'service') {
                continue;
            }
            if (ItemComponent::where('tenant_id', $tenant->id)->where('item_id', $service->id)->exists()) {
                continue;
            }
            $payload = [];
            foreach ($rows as $order => $row) {
                $product = $existing->get(mb_strtolower($row['product']));
                if (! $product) {
                    continue;
                }
                $payload[] = [
                    'component_item_id' => $product->id,
                    'quantity' => $row['quantity'],
                    'choice_group' => $row['group'] ?? null,
                    'is_default' => (bool) ($row['default'] ?? false),
                    'sort_order' => $order,
                ];
            }
            if ($payload !== []) {
                $components->sync($service, $payload);
                $recipes++;
            }
        }

        return ['products' => $created, 'recipes' => $recipes];
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
