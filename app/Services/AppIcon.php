<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * The shop's icon: sidebar badge, browser tab, home-screen shortcut.
 *
 * Three sources, in order: an icon the shop uploaded, else its document logo
 * fitted into a square, else its initials on its brand colour. Every file
 * lives under storage/app/branding/<tenant id>/ — never under public/, which
 * an update re-copies from the code: the icons used to sit in public/icons,
 * were committed, and every update replaced a shop's own icon with the same
 * blank square for everyone.
 *
 * Derived icons (logo, initials) are named after a hash of what they were
 * drawn from, so a renamed shop or a new colour draws a new file instead of
 * serving the old one, and the same hash is the ?v= that busts browser caches.
 */
class AppIcon
{
    public const SIZES = [32, 192, 512];

    public const SOURCE_CUSTOM = 'custom';
    public const SOURCE_LOGO = 'logo';
    public const SOURCE_INITIALS = 'initials';

    public function dir(Tenant $tenant): string
    {
        return storage_path('app/branding/'.$tenant->id);
    }

    public function source(Tenant $tenant): string
    {
        if (is_file($this->customPath($tenant, 512))) {
            return self::SOURCE_CUSTOM;
        }

        return $this->logoFile($tenant) !== null ? self::SOURCE_LOGO : self::SOURCE_INITIALS;
    }

    /** Changes whenever the picture would: the cache-busting ?v= of every icon URL. */
    public function version(Tenant $tenant): string
    {
        $source = $this->source($tenant);

        $basis = match ($source) {
            self::SOURCE_CUSTOM => (string) filemtime($this->customPath($tenant, 512)),
            self::SOURCE_LOGO => $this->logoFile($tenant).'|'.filemtime((string) $this->logoFile($tenant)),
            default => self::initials((string) $tenant->name).'|'.$this->primary($tenant),
        };

        return substr(md5($source.'|'.$basis), 0, 10);
    }

    public function url(Tenant $tenant, int $size): string
    {
        return route('app.icon', $size).'?v='.$this->version($tenant);
    }

    /** The PNG for [$size], drawn now if it has not been yet. */
    public function path(Tenant $tenant, int $size): string
    {
        $size = in_array($size, self::SIZES, true) ? $size : 192;
        $source = $this->source($tenant);

        if ($source === self::SOURCE_CUSTOM) {
            $path = $this->customPath($tenant, $size);
            if (! is_file($path)) {
                $this->fit($this->customPath($tenant, 512), $path, $size);
            }

            return $path;
        }

        $version = $this->version($tenant);
        $path = $this->dir($tenant)."/{$source}-{$version}-{$size}.png";

        if (! is_file($path)) {
            $this->ensureDir($tenant);
            $this->forgetStale($tenant, $source, $version);

            $source === self::SOURCE_LOGO
                ? $this->fit((string) $this->logoFile($tenant), $path, $size)
                : $this->drawInitials($path, $size, $this->primary($tenant), self::initials((string) $tenant->name));
        }

        return $path;
    }

    /**
     * Keep an uploaded icon, at every size.
     *
     * @throws \InvalidArgumentException when the file is not an image GD can read
     */
    public function store(Tenant $tenant, UploadedFile|string $file): void
    {
        $source = $file instanceof UploadedFile ? (string) $file->getRealPath() : $file;
        $this->load($source); // refuse before touching the current icon

        $this->ensureDir($tenant);
        foreach (self::SIZES as $size) {
            $this->fit($source, $this->customPath($tenant, $size), $size);
        }
    }

    /** Back to the logo, or the initials. */
    public function reset(Tenant $tenant): void
    {
        foreach (self::SIZES as $size) {
            @unlink($this->customPath($tenant, $size));
        }
    }

    /** "spa paris" → "SP", "Zara" → "Z"; letters and digits only. */
    public static function initials(string $name): string
    {
        $initials = '';
        foreach (preg_split('/\s+/u', trim($name)) ?: [] as $word) {
            $letter = mb_substr((string) preg_replace('/[^\p{L}\p{N}]/u', '', $word), 0, 1);
            if ($letter !== '') {
                $initials .= mb_strtoupper($letter);
            }
            if (mb_strlen($initials) === 2) {
                break;
            }
        }

        return $initials !== '' ? $initials : 'C';
    }

    private function customPath(Tenant $tenant, int $size): string
    {
        return $this->dir($tenant)."/custom-{$size}.png";
    }

    /** The document logo as a local file, when there is one to draw from. */
    private function logoFile(Tenant $tenant): ?string
    {
        $logo = trim((string) data_get($tenant->settings, 'company_profile.store_logo', ''));
        if ($logo === '' || Str::startsWith($logo, ['http://', 'https://'])) {
            return null;
        }

        $path = public_path(ltrim($logo, '/'));

        return is_file($path) && @getimagesize($path) !== false ? $path : null;
    }

    private function primary(Tenant $tenant): string
    {
        $color = (string) data_get($tenant->settings, 'theme.primary', '#3157D5');

        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? strtoupper($color) : '#3157D5';
    }

    private function ensureDir(Tenant $tenant): void
    {
        if (! is_dir($this->dir($tenant))) {
            mkdir($this->dir($tenant), 0755, true);
        }
    }

    private function forgetStale(Tenant $tenant, string $source, string $version): void
    {
        foreach (glob($this->dir($tenant)."/{$source}-*.png") ?: [] as $file) {
            if (! str_starts_with(basename($file), "{$source}-{$version}-")) {
                @unlink($file);
            }
        }
    }

    private function load(string $path): \GdImage
    {
        $info = @getimagesize($path);
        $image = match ($info['mime'] ?? null) {
            'image/png' => @imagecreatefrompng($path),
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/webp' => @imagecreatefromwebp($path),
            'image/gif' => @imagecreatefromgif($path),
            default => false,
        };

        if (! $image instanceof \GdImage) {
            throw new \InvalidArgumentException('Image illisible : utilisez un PNG, JPG, WEBP ou GIF.');
        }

        return $image;
    }

    /**
     * Fit [$source] into a [$size] square, never stretched and never cropped.
     *
     * A square image that already fills its corners is used edge to edge — it
     * is an icon already. Anything else (a wide logo, a transparent one) is
     * centred on white with a margin: a home screen paints a transparent icon
     * black on iOS, and a dark logo would vanish on the dark sidebar.
     */
    private function fit(string $source, string $destination, int $size): void
    {
        $src = $this->load($source);
        imagepalettetotruecolor($src);
        $w = imagesx($src);
        $h = imagesy($src);

        $canvas = imagecreatetruecolor($size, $size);
        imagealphablending($canvas, true);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));

        $fullBleed = abs($w - $h) <= max($w, $h) * 0.04 && $this->cornersOpaque($src);
        $box = $fullBleed ? $size : (int) round($size * 0.8);
        $scale = min($box / $w, $box / $h);
        $dw = max(1, (int) round($w * $scale));
        $dh = max(1, (int) round($h * $scale));

        imagecopyresampled($canvas, $src, intdiv($size - $dw, 2), intdiv($size - $dh, 2), 0, 0, $dw, $dh, $w, $h);
        imagepng($canvas, $destination, 9);

        imagedestroy($src);
        imagedestroy($canvas);
    }

    private function cornersOpaque(\GdImage $image): bool
    {
        $x = imagesx($image) - 1;
        $y = imagesy($image) - 1;
        foreach ([[0, 0], [$x, 0], [0, $y], [$x, $y]] as [$px, $py]) {
            if (((imagecolorat($image, $px, $py) >> 24) & 0x7F) > 8) {
                return false;
            }
        }

        return true;
    }

    private function drawInitials(string $destination, int $size, string $color, string $text): void
    {
        [$r, $g, $b] = sscanf(ltrim($color, '#'), '%2x%2x%2x');

        $image = imagecreatetruecolor($size, $size);
        imagefill($image, 0, 0, imagecolorallocate($image, $r, $g, $b));
        $white = imagecolorallocate($image, 255, 255, 255);

        $font = self::font();
        if ($font !== null) {
            $points = $size * (mb_strlen($text) > 1 ? 0.34 : 0.42);
            $bbox = imagettfbbox($points, 0, $font, $text);
            $x = (int) round(($size - ($bbox[2] - $bbox[0])) / 2 - $bbox[0]);
            $y = (int) round(($size - ($bbox[1] - $bbox[7])) / 2 - $bbox[7]);
            imagettftext($image, $points, 0, $x, $y, $white, $font, $text);
        } else {
            imagestring($image, 5, intdiv($size - imagefontwidth(5) * strlen($text), 2), intdiv($size - imagefontheight(5), 2), $text, $white);
        }

        imagepng($image, $destination, 9);
        imagedestroy($image);
    }

    /**
     * A bold face that is on every install: dompdf ships DejaVu with the code
     * (and DejaVu covers Arabic letters). The old icon looked for a font that
     * was never deployed and drew an empty square.
     */
    public static function font(): ?string
    {
        $path = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf');

        return is_file($path) ? $path : null;
    }
}
