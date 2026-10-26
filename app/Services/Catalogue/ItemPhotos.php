<?php

namespace App\Services\Catalogue;

use App\Models\Item;
use App\Models\Tenant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;

/**
 * Product photos in bulk: from a pile of files, a ZIP next to the import
 * sheet, or a link in the sheet.
 *
 * Every photo is normalised the same way whatever its source — upright,
 * at most 1000 px, a JPEG on white of ~100–200 KB — and stored on the public
 * disk like a photo added by hand (storage/, which updates never copy).
 * A phone photo is 4–8 MB and often sideways; stored as-is it made the
 * catalogue crawl on the till and the PDF heavy.
 */
class ItemPhotos
{
    public const MAX_SIDE = 1000;

    /** Refuse a picture whose decoding alone would exhaust a shared host's memory. */
    public const MAX_PIXELS = 40_000_000;

    public const MAX_DOWNLOAD_BYTES = 15 * 1024 * 1024;

    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    /** Test seam for DNS: host => list of IPs. */
    public static ?\Closure $resolveUsing = null;

    // ── Processing ────────────────────────────────────────────────────────────

    /**
     * Normalise [$source] and store it; returns the public-disk path.
     *
     * @throws \InvalidArgumentException when it is not a picture we can read
     */
    public function store(string $source): string
    {
        $info = @getimagesize($source);
        if ($info === false) {
            throw new \InvalidArgumentException('ce fichier n\'est pas une image lisible');
        }
        [$width, $height] = $info;
        if ($width * $height > self::MAX_PIXELS) {
            throw new \InvalidArgumentException('image trop grande ('.$width.'×'.$height.')');
        }

        $image = match ($info['mime'] ?? '') {
            'image/jpeg' => @imagecreatefromjpeg($source),
            'image/png' => @imagecreatefrompng($source),
            'image/webp' => @imagecreatefromwebp($source),
            'image/gif' => @imagecreatefromgif($source),
            default => false,
        };
        if (! $image instanceof \GdImage) {
            throw new \InvalidArgumentException('format non pris en charge (JPG, PNG, WEBP ou GIF)');
        }

        if (($info['mime'] ?? '') === 'image/jpeg') {
            $image = $this->upright($image, $source);
        }

        $w = imagesx($image);
        $h = imagesy($image);
        $scale = min(1, self::MAX_SIDE / max($w, $h));
        $dw = max(1, (int) round($w * $scale));
        $dh = max(1, (int) round($h * $scale));

        // On white: a transparent PNG would turn black as a JPEG.
        $canvas = imagecreatetruecolor($dw, $dh);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $dw, $dh, $w, $h);

        ob_start();
        imagejpeg($canvas, null, 82);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);
        imagedestroy($canvas);

        $path = 'catalogue/items/'.Str::uuid().'.jpg';
        Storage::disk('public')->put($path, $bytes);

        return $path;
    }

    /** Phone cameras store the rotation beside the pixels; apply it. */
    private function upright(\GdImage $image, string $source): \GdImage
    {
        $orientation = function_exists('exif_read_data')
            ? (int) (@exif_read_data($source)['Orientation'] ?? 1)
            : 1;

        $angle = match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        return $angle === 0 ? $image : (imagerotate($image, $angle, 0) ?: $image);
    }

    /** [$path] becomes the item's main photo; the main photo it replaces is deleted. */
    public function attach(Item $item, string $path): void
    {
        $images = array_values(array_filter((array) ($item->images ?? [])));
        $previous = $images[0] ?? null;

        if ($previous && str_starts_with($previous, 'catalogue/items/') && $previous !== $path) {
            Storage::disk('public')->delete($previous);
            array_shift($images);
        }

        $item->update(['images' => array_values(array_unique([$path, ...$images]))]);
    }

    // ── Matching a file to a product ──────────────────────────────────────────

    /**
     * A matcher over the shop's catalogue: file name → product.
     *
     * By barcode, item code, ISBN or SKU first, then by title. "6111000000101.jpg",
     * "IT26100001.png" and "Massage relaxant.jpg" all find their product; a
     * trailing " (2)" or "_2" from a phone or a second shot is ignored.
     *
     * @return \Closure(string): ?array{item: Item, by: string}
     */
    public function matcher(Tenant $tenant): \Closure
    {
        $items = Item::where('tenant_id', $tenant->id)
            ->get(['id', 'tenant_id', 'title', 'barcode', 'item_code', 'isbn', 'sku', 'images', 'type']);

        $byCode = [];
        foreach ($items as $item) {
            foreach (['barcode' => 'code-barres', 'item_code' => 'code article', 'isbn' => 'ISBN', 'sku' => 'SKU'] as $field => $label) {
                $value = mb_strtolower(trim((string) $item->{$field}));
                if ($value !== '') {
                    $byCode[$value] ??= ['item' => $item, 'by' => $label];
                }
            }
        }

        $byTitle = [];
        foreach ($items as $item) {
            $slug = Str::slug((string) $item->title);
            if ($slug === '') {
                continue;
            }
            // Two products with the same name: a photo cannot pick one.
            $byTitle[$slug] = isset($byTitle[$slug]) ? false : ['item' => $item, 'by' => 'nom'];
        }

        return function (string $filename) use ($byCode, $byTitle): ?array {
            foreach (self::keysFor($filename) as $key) {
                if (isset($byCode[mb_strtolower($key)])) {
                    return $byCode[mb_strtolower($key)];
                }
                $slug = Str::slug($key);
                if ($slug !== '' && ($byTitle[$slug] ?? false)) {
                    return $byTitle[$slug];
                }
            }

            return null;
        };
    }

    /** "dossier/6111 (2).JPG" → ["6111 (2)", "6111"]. */
    public static function keysFor(string $filename): array
    {
        $base = trim(pathinfo(str_replace('\\', '/', $filename), PATHINFO_FILENAME));
        $stripped = trim((string) preg_replace('/(\s*\(\d+\)|[_-]\d{1,2})$/u', '', $base));

        return array_values(array_unique(array_filter([$base, $stripped])));
    }

    public static function isImageName(string $filename): bool
    {
        return in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), self::IMAGE_EXTENSIONS, true);
    }

    // ── A link in the import sheet ────────────────────────────────────────────

    public static function isUrl(string $value): bool
    {
        return (bool) preg_match('#^https?://#i', trim($value));
    }

    /**
     * Download a picture from a link in the sheet into a temp file.
     *
     * Only public internet addresses: the link is typed by a shop, and the
     * server must not be made to fetch its own network (cloud metadata,
     * localhost admin pages) on their behalf — checked on every redirect too.
     *
     * @throws \InvalidArgumentException
     */
    public function download(string $url): string
    {
        $url = $this->directLink(trim($url));
        $this->assertPublic($url);

        try {
            $response = Http::timeout(20)
                ->withOptions([
                    'allow_redirects' => [
                        'max' => 3,
                        'protocols' => ['http', 'https'],
                        'on_redirect' => function (RequestInterface $request, $response, UriInterface $uri): void {
                            $this->assertPublic((string) $uri);
                        },
                    ],
                ])
                ->get($url);
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('lien injoignable');
        }

        if (! $response->successful()) {
            throw new \InvalidArgumentException('lien en erreur ('.$response->status().')');
        }
        $body = $response->body();
        if ($body === '' || strlen($body) > self::MAX_DOWNLOAD_BYTES) {
            throw new \InvalidArgumentException('image vide ou trop lourde');
        }

        $path = tempnam(sys_get_temp_dir(), 'item-photo-');
        file_put_contents($path, $body);

        return $path;
    }

    /** A Google Drive or Dropbox share page → the file itself. */
    public function directLink(string $url): string
    {
        if (preg_match('#drive\.google\.com/(?:file/d/|open\?id=|uc\?(?:.*&)?id=)([\w-]+)#', $url, $m)) {
            return 'https://drive.google.com/uc?export=download&id='.$m[1];
        }
        if (str_contains($url, 'dropbox.com')) {
            return preg_replace('/([?&])dl=0\b/', '$1dl=1', $url) ?: $url;
        }

        return $url;
    }

    private function assertPublic(string $url): void
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? '';
        if (! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || $host === '') {
            throw new \InvalidArgumentException('lien invalide');
        }

        $ips = self::$resolveUsing
            ? (self::$resolveUsing)($host)
            : (filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []));

        if ($ips === []) {
            throw new \InvalidArgumentException('adresse introuvable');
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new \InvalidArgumentException('adresse non autorisée');
            }
        }
    }

    // ── A ZIP next to the sheet ───────────────────────────────────────────────

    /**
     * Unpack an import ZIP: the sheet, and the photos by file name.
     *
     * Only file names are kept — never the paths inside the archive, so an
     * entry named "../../.env" cannot write outside the temp folder.
     *
     * @return array{dir: string, sheet: ?string, photos: array<string, string>}
     *
     * @throws \InvalidArgumentException
     */
    public function unzip(string $zipPath, int $maxBytes = 400 * 1024 * 1024): array
    {
        $zip = new \ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new \InvalidArgumentException('archive ZIP illisible');
        }

        $dir = sys_get_temp_dir().'/catalog-import-'.Str::random(12);
        mkdir($dir, 0700, true);

        $sheet = null;
        $photos = [];
        $total = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = (string) ($stat['name'] ?? '');
            $base = basename(str_replace('\\', '/', $name));
            if ($base === '' || str_ends_with($name, '/') || str_starts_with($base, '.') || str_contains($name, '__MACOSX')) {
                continue;
            }

            $extension = strtolower(pathinfo($base, PATHINFO_EXTENSION));
            $isSheet = in_array($extension, ['xlsx', 'csv', 'tsv'], true);
            if (! $isSheet && ! in_array($extension, self::IMAGE_EXTENSIONS, true)) {
                continue;
            }

            $total += (int) ($stat['size'] ?? 0);
            if ($total > $maxBytes) {
                $zip->close();
                throw new \InvalidArgumentException('archive trop volumineuse une fois décompressée');
            }

            $target = $dir.'/'.$i.'-'.preg_replace('/[^\w.\- ()]/u', '_', $base);
            $stream = $zip->getStream($name);
            if ($stream === false) {
                continue;
            }
            file_put_contents($target, $stream);
            fclose($stream);

            if ($isSheet) {
                $sheet ??= $target;
            } else {
                $photos[mb_strtolower($base)] = $target;
            }
        }
        $zip->close();

        return ['dir' => $dir, 'sheet' => $sheet, 'photos' => $photos];
    }

    public static function cleanup(?string $dir): void
    {
        if ($dir && is_dir($dir) && str_contains($dir, 'catalog-import-')) {
            foreach (glob($dir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }

    // ── Server limits, for the screens ────────────────────────────────────────

    /** @return array{file_bytes: int, post_bytes: int, max_files: int} */
    public static function uploadLimits(): array
    {
        $bytes = function (string $key, int $fallback): int {
            $value = trim((string) ini_get($key));
            if ($value === '' || $value === '0' || $value === '-1') {
                return $fallback;
            }
            $number = (float) $value;

            return (int) match (strtolower(substr($value, -1))) {
                'g' => $number * 1024 ** 3,
                'm' => $number * 1024 ** 2,
                'k' => $number * 1024,
                default => $number,
            };
        };

        return [
            'file_bytes' => $bytes('upload_max_filesize', 2 * 1024 ** 2),
            'post_bytes' => $bytes('post_max_size', 8 * 1024 ** 2),
            'max_files' => max(1, (int) (ini_get('max_file_uploads') ?: 20)),
        ];
    }
}
