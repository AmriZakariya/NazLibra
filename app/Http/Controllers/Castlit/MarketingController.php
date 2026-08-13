<?php

namespace App\Http\Controllers\Castlit;

use App\Http\Controllers\Controller;
use App\Support\BusinessMode;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class MarketingController extends Controller
{
    /** Public marketing landing page for castlitpos.com, with the sign-up form. */
    public function landing(): View
    {
        return view('castlit.landing', [
            'activities'  => $this->activityOptions(),
            'currencies'  => ['MAD', 'EUR', 'USD', 'XOF', 'DZD', 'TND'],
            'mainDomain'  => config('castlit.main_domain'),
        ]);
    }

    /** Confirmation page after a subscription request is submitted. */
    public function success(Request $request): View
    {
        return view('castlit.success', [
            'subdomain'  => $request->session()->get('subscribed_subdomain'),
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

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n"
            .'  <url><loc>'.$base.'/</loc><changefreq>weekly</changefreq><priority>1.0</priority></url>'."\n"
            .'</urlset>'."\n";

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
        $width = 1200;
        $height = 630;
        $img = imagecreatetruecolor($width, $height);

        $ink = imagecolorallocate($img, 14, 19, 48);      // deep ink ground
        $panel = imagecolorallocate($img, 49, 87, 213);    // brand indigo
        $white = imagecolorallocate($img, 255, 255, 255);
        $muted = imagecolorallocate($img, 190, 202, 235);
        $accent = imagecolorallocate($img, 245, 158, 11);  // receipt amber

        imagefill($img, 0, 0, $ink);
        imagefilledrectangle($img, 0, 0, 18, $height, $panel);         // left brand rail
        imagefilledrectangle($img, 80, 300, 200, 308, $accent);        // accent underline

        $bold = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf');
        $reg = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf');
        $brand = config('castlit.brand');

        if (is_file($bold) && is_file($reg)) {
            imagettftext($img, 74, 0, 80, 250, $white, $bold, $brand['name']);
            imagettftext($img, 30, 0, 82, 360, $muted, $reg, $brand['tagline']);
            imagettftext($img, 22, 0, 82, 560, $accent, $bold, config('castlit.main_domain'));
        } else {
            imagestring($img, 5, 80, 220, $brand['name'], $white);
        }

        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();
        imagedestroy($img);

        return response($png, 200)
            ->header('Content-Type', 'image/png')
            ->header('Cache-Control', 'public, max-age=86400');
    }

    /** @return array<string,string> business_mode key => human label */
    private function activityOptions(): array
    {
        return collect(BusinessMode::all())
            ->map(fn (array $m) => $m['label'] ?? '')
            ->toArray();
    }
}
