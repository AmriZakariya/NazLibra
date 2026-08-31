<?php

namespace App\Http\Controllers\Castlit;

use App\Http\Controllers\Controller;
use App\Support\BusinessMode;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class MarketingController extends Controller
{
    /** Supported marketing locales. */
    public const LOCALES = ['fr', 'ar', 'en'];

    /** Resolve the marketing locale from ?lang= (persisted) and apply it. */
    private function resolveLocale(Request $request): string
    {
        $lang = $request->query('lang');
        if (in_array($lang, self::LOCALES, true)) {
            $request->session()->put('castlit_lang', $lang);
        } else {
            $lang = $request->session()->get('castlit_lang', 'fr');
            if (! in_array($lang, self::LOCALES, true)) {
                $lang = 'fr';
            }
        }
        app()->setLocale($lang);

        return $lang;
    }

    /** Public marketing landing page for castlitpos.com, with the sign-up form. */
    public function landing(Request $request): View
    {
        $this->resolveLocale($request);

        return view('castlit.landing', [
            'activities'  => $this->activityOptions(),
            'currencies'  => ['MAD', 'EUR', 'USD', 'XOF', 'DZD', 'TND'],
            'mainDomain'  => config('castlit.main_domain'),
        ]);
    }

    /** Confirmation page after a subscription request is submitted. */
    public function success(Request $request): View
    {
        $this->resolveLocale($request);

        return view('castlit.success', [
            'subdomain'  => $request->session()->get('subscribed_subdomain'),
            'mainDomain' => config('castlit.main_domain'),
        ]);
    }

    /** Privacy policy — required for the Play Store / App Store listings. */
    public function privacy(Request $request): View
    {
        $this->resolveLocale($request);

        return view('castlit.privacy', [
            'mainDomain' => config('castlit.main_domain'),
        ]);
    }

    /** Terms of service. */
    public function terms(Request $request): View
    {
        $this->resolveLocale($request);

        return view('castlit.terms', [
            'mainDomain' => config('castlit.main_domain'),
        ]);
    }

    /**
     * robots.txt — the master site is fully indexable; client subdomains (and
     * any non-master install) are kept out of search engines entirely.
     */
    public function robots(): Response
    {
        $base = 'https://'.config('castlit.main_domain');

        $body = config('castlit.is_master')
            ? "User-agent: *\nAllow: /\nDisallow: /castlit-admin\n\nSitemap: {$base}/sitemap.xml\n"
            : "User-agent: *\nDisallow: /\n";

        return response($body, 200)->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    /** sitemap.xml — the public marketing page (master only). */
    public function sitemap(): Response
    {
        $base = 'https://'.config('castlit.main_domain');
        $lastmod = now()->toDateString();

        // Per-locale URLs (fr is the clean default; ar/en carry ?lang=).
        $locales = [
            'fr' => $base.'/',
            'ar' => $base.'/?lang=ar',
            'en' => $base.'/?lang=en',
        ];

        // Reciprocal hreflang alternates on every entry (Google's requirement),
        // including x-default → fr.
        $alternates = '';
        foreach ($locales as $hl => $href) {
            $alternates .= '    <xhtml:link rel="alternate" hreflang="'.$hl.'" href="'.htmlspecialchars($href, ENT_XML1).'"/>'."\n";
        }
        $alternates .= '    <xhtml:link rel="alternate" hreflang="x-default" href="'.$locales['fr'].'"/>'."\n";

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">'."\n";

        foreach ($locales as $href) {
            $xml .= '  <url>'."\n"
                .'    <loc>'.htmlspecialchars($href, ENT_XML1).'</loc>'."\n"
                .$alternates
                .'    <lastmod>'.$lastmod.'</lastmod>'."\n"
                .'    <changefreq>weekly</changefreq>'."\n"
                .'    <priority>1.0</priority>'."\n"
                .'  </url>'."\n";
        }

        $xml .= '</urlset>'."\n";

        return response($xml, 200)->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    /** humans.txt — the people/tech behind the site (master only). */
    public function humans(): Response
    {
        $brand = config('castlit.brand');
        $body = "/* TEAM */\n"
            ."  {$brand['name']} — {$brand['tagline']}\n"
            ."  Contact: {$brand['email']}\n\n"
            ."/* SITE */\n"
            ."  Language: Français / العربية\n"
            ."  Standards: HTML5, JSON-LD\n"
            ."  Stack: Laravel, PWA\n";

        return response($body, 200)->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    /** /.well-known/security.txt (RFC 9116) — how to report a vulnerability. */
    public function securityTxt(): Response
    {
        $base = 'https://'.config('castlit.main_domain');
        $email = config('castlit.brand.email');
        $expires = now()->addYear()->startOfDay()->toIso8601String();

        $body = "Contact: mailto:{$email}\n"
            ."Expires: {$expires}\n"
            ."Preferred-Languages: fr, en\n"
            ."Canonical: {$base}/.well-known/security.txt\n";

        return response($body, 200)->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    /**
     * Open Graph share image (1200×630 PNG), generated with GD so social
     * previews show a branded banner instead of the app icon.
     */
    public function ogImage(): Response
    {
        $W = 1200;
        $H = 630;
        $img = imagecreatetruecolor($W, $H);
        imagesavealpha($img, true);

        $ink    = imagecolorallocate($img, 14, 19, 48);     // deep ink ground
        $brandc = imagecolorallocate($img, 49, 87, 213);    // brand indigo
        $white  = imagecolorallocate($img, 255, 255, 255);
        $muted  = imagecolorallocate($img, 190, 202, 235);
        $accent = imagecolorallocate($img, 245, 158, 11);   // receipt amber
        $chipbg = imagecolorallocate($img, 32, 40, 74);

        imagefill($img, 0, 0, $ink);
        imagefilledrectangle($img, 0, 0, $W, 8, $brandc);   // top brand band

        $bold  = $this->ogFont(true);
        $reg   = $this->ogFont(false);
        $brand = config('castlit.brand');

        // Brand mark: rounded indigo square + white "C" + amber dot.
        $this->ogRoundedRect($img, 80, 96, 200, 216, 26, $brandc);
        imagefilledellipse($img, 182, 200, 24, 24, $accent);

        if ($bold && $reg) {
            imagettftext($img, 66, 0, 108, 196, $white, $bold, 'C');
            imagettftext($img, 62, 0, 240, 175, $white, $bold, $brand['name']);

            $y = 246;
            foreach (explode("\n", wordwrap((string) $brand['tagline'], 44, "\n")) as $line) {
                imagettftext($img, 25, 0, 242, $y, $muted, $reg, $line);
                $y += 40;
            }

            imagettftext($img, 26, 0, 82, 566, $accent, $bold, config('castlit.main_domain'));

            // Feature chips echoing the product's core value.
            $chips = ['Caisse tactile', 'Gestion de stock', 'Hors-ligne'];
            $x = 470;
            foreach ($chips as $c) {
                $bb = imagettfbbox(20, 0, $reg, $c);
                $w = $bb[2] - $bb[0];
                $this->ogRoundedRect($img, $x, 542, $x + $w + 40, 584, 21, $chipbg);
                imagettftext($img, 20, 0, $x + 20, 570, $muted, $reg, $c);
                $x += $w + 40 + 16;
            }
        } else {
            imagestring($img, 5, 240, 150, (string) $brand['name'], $white);
        }

        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();
        imagedestroy($img);

        return response($png, 200)
            ->header('Content-Type', 'image/png')
            ->header('Cache-Control', 'public, max-age=86400');
    }

    /** First available DejaVu font (bundled with dompdf, or a common system path). */
    private function ogFont(bool $bold): ?string
    {
        $variant = $bold ? '-Bold' : '';
        foreach ([
            base_path("vendor/dompdf/dompdf/lib/fonts/DejaVuSans{$variant}.ttf"),
            "/usr/share/fonts/truetype/dejavu/DejaVuSans{$variant}.ttf",
            "/usr/share/fonts/dejavu/DejaVuSans{$variant}.ttf",
        ] as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /** Draw a filled rounded rectangle (GD has no native primitive for it). */
    private function ogRoundedRect(\GdImage $img, int $x1, int $y1, int $x2, int $y2, int $r, int $color): void
    {
        imagefilledrectangle($img, $x1 + $r, $y1, $x2 - $r, $y2, $color);
        imagefilledrectangle($img, $x1, $y1 + $r, $x2, $y2 - $r, $color);
        imagefilledellipse($img, $x1 + $r, $y1 + $r, $r * 2, $r * 2, $color);
        imagefilledellipse($img, $x2 - $r, $y1 + $r, $r * 2, $r * 2, $color);
        imagefilledellipse($img, $x1 + $r, $y2 - $r, $r * 2, $r * 2, $color);
        imagefilledellipse($img, $x2 - $r, $y2 - $r, $r * 2, $r * 2, $color);
    }

    /** @return array<string,string> business_mode key => human label */
    private function activityOptions(): array
    {
        return collect(BusinessMode::all())
            ->map(fn (array $m) => $m['label'] ?? '')
            ->toArray();
    }
}
