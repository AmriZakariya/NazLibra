@php
    $brand = config('castlit.brand');
    $mainDomain = config('castlit.main_domain');
    $siteUrl = 'https://'.$mainDomain;
    $lang = in_array(app()->getLocale(), ['fr', 'ar', 'en'], true) ? app()->getLocale() : 'fr';
    $isRtl = $lang === 'ar';
    $langLabels = ['fr' => 'FR', 'ar' => 'ع', 'en' => 'EN'];
    $isMarketing = request()->routeIs('castlit.landing') || request()->routeIs('castlit.subscribe.success');

    // Self-referential, per-locale URLs so canonical and hreflang agree: fr is the
    // clean URL (default locale), ar/en carry ?lang=. Each localised URL is
    // canonical for itself and reciprocally linked via hreflang.
    $localizedUrls = [];
    foreach (['fr', 'ar', 'en'] as $hl) {
        $localizedUrls[$hl] = $hl === 'fr' ? url()->current() : url()->current().'?lang='.$hl;
    }
    $canonical = $canonical ?? ($localizedUrls[$lang] ?? url()->current());

    $ogLocaleMap = ['fr' => 'fr_MA', 'ar' => 'ar_MA', 'en' => 'en_US'];
    $ogLocale = $ogLocaleMap[$lang] ?? 'fr_MA';
    $ogLocaleAlt = array_values(array_diff($ogLocaleMap, [$ogLocale]));

    $metaTitle = trim($__env->yieldContent('title', __('castlit.meta_title')));
    $metaDescription = trim($__env->yieldContent('meta_description', __('castlit.meta_description')));
    $metaRobots = trim($__env->yieldContent('robots', 'index, follow, max-image-preview:large'));
    $ogImage = \Illuminate\Support\Facades\Route::has('castlit.og') ? route('castlit.og') : route('app.icon', 512);

    // Truthful feature list for structured data (pulled from the localized copy).
    $featureList = collect(__('castlit.features'))->pluck('t')->filter()->values()->all();
    $socials = array_values(array_filter((array) ($brand['social'] ?? [])));
@endphp
<!doctype html>
<html lang="{{ $lang }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $metaTitle }}</title>
    <meta name="description" content="{{ $metaDescription }}">
    <meta name="keywords" content="{{ $brand['keywords'] }}">
    <meta name="author" content="{{ $brand['legal'] }}">
    <meta name="robots" content="{{ $metaRobots }}">
    @if (config('castlit.gsc_verification'))
        <meta name="google-site-verification" content="{{ config('castlit.gsc_verification') }}">
    @endif
    <meta name="theme-color" content="#3157D5">
    <link rel="canonical" href="{{ $canonical }}">
    @if ($isMarketing)
        @foreach ($localizedUrls as $hl => $hlUrl)
            <link rel="alternate" hreflang="{{ $hl }}" href="{{ $hlUrl }}">
        @endforeach
        <link rel="alternate" hreflang="x-default" href="{{ $localizedUrls['fr'] }}">
    @endif
    <link rel="icon" type="image/svg+xml" href="{{ asset('img/castlit-icon.svg') }}">
    <link rel="icon" type="image/png" sizes="192x192" href="{{ route('app.icon', 192) }}">
    <link rel="apple-touch-icon" href="{{ route('app.icon', 192) }}">

    {{-- Open Graph --}}
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $brand['name'] }}">
    <meta property="og:title" content="{{ $metaTitle }}">
    <meta property="og:description" content="{{ $metaDescription }}">
    <meta property="og:url" content="{{ $canonical }}">
    <meta property="og:locale" content="{{ $ogLocale }}">
    @foreach ($ogLocaleAlt as $alt)
        <meta property="og:locale:alternate" content="{{ $alt }}">
    @endforeach
    <meta property="og:image" content="{{ $ogImage }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="{{ $brand['name'] }} — {{ $brand['tagline'] }}">

    {{-- Twitter --}}
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $metaTitle }}">
    <meta name="twitter:description" content="{{ $metaDescription }}">
    <meta name="twitter:image" content="{{ $ogImage }}">
    <meta name="twitter:image:alt" content="{{ $brand['name'] }} — {{ $brand['tagline'] }}">

    {{-- Structured data: Organization + WebSite + the software product + this page --}}
    <script type="application/ld+json">
    {!! json_encode([
        '@context' => 'https://schema.org',
        '@graph' => [
            array_filter([
                '@type' => 'Organization',
                '@id' => $siteUrl.'/#organization',
                'name' => $brand['name'],
                'url' => $siteUrl,
                'logo' => [
                    '@type' => 'ImageObject',
                    'url' => asset('img/castlit-logo.svg'),
                    'contentUrl' => asset('img/castlit-logo.svg'),
                ],
                'image' => $ogImage,
                'email' => $brand['email'],
                'areaServed' => ['@type' => 'Country', 'name' => 'Maroc'],
                'contactPoint' => [
                    '@type' => 'ContactPoint',
                    'contactType' => 'customer support',
                    'email' => $brand['email'],
                    'areaServed' => 'MA',
                    'availableLanguage' => ['French', 'Arabic', 'English'],
                ],
                'sameAs' => $socials ?: null,
            ]),
            [
                '@type' => 'WebSite',
                '@id' => $siteUrl.'/#website',
                'url' => $siteUrl,
                'name' => $brand['name'],
                'description' => $brand['description'],
                'inLanguage' => ['fr-MA', 'ar-MA', 'en'],
                'publisher' => ['@id' => $siteUrl.'/#organization'],
            ],
            array_filter([
                '@type' => 'SoftwareApplication',
                '@id' => $siteUrl.'/#app',
                'name' => $brand['name'],
                'applicationCategory' => 'BusinessApplication',
                'applicationSubCategory' => 'Point of Sale',
                'operatingSystem' => 'Web, Android, iOS',
                'description' => $brand['description'],
                'featureList' => $featureList ?: null,
                'screenshot' => $ogImage,
                'inLanguage' => ['fr', 'ar', 'en'],
                'url' => $siteUrl,
                'publisher' => ['@id' => $siteUrl.'/#organization'],
                'offers' => [
                    '@type' => 'Offer',
                    'price' => '0',
                    'priceCurrency' => 'MAD',
                    'description' => 'Essai sans engagement',
                ],
            ]),
            [
                '@type' => 'WebPage',
                '@id' => $canonical.'#webpage',
                'url' => $canonical,
                'name' => $metaTitle,
                'description' => $metaDescription,
                'inLanguage' => $ogLocale,
                'isPartOf' => ['@id' => $siteUrl.'/#website'],
                'about' => ['@id' => $siteUrl.'/#app'],
                'primaryImageOfPage' => $ogImage,
            ],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}
    </script>
    @stack('jsonld')

    <style>
        :root {
            --brand: #3157D5;
            --brand-strong: #2743a8;
            --accent: #F59E0B;
            --ink: #0E1330;
            --paper: #FBFAF7;
            --surface: #FFFFFF;
            --sand: #E7E2D8;
            --muted: #5C6270;
            --ok: #16A34A;
            --ok-bg: #ECFDF3;
            --warn: #B45309;
            --warn-bg: #FEF6E7;
            --err: #DC2626;
            --err-bg: #FEF2F2;
            --radius: 16px;
            --shadow: 0 1px 2px rgba(14,19,48,.04), 0 12px 32px rgba(14,19,48,.07);
            --shadow-lg: 0 24px 60px rgba(14,19,48,.16);
            --font: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --paper: #0B0F1F;
                --surface: #141A2E;
                --sand: #263049;
                --ink: #F3F5FA;
                --muted: #9AA3B8;
                --shadow: 0 1px 2px rgba(0,0,0,.3), 0 12px 32px rgba(0,0,0,.4);
                --shadow-lg: 0 24px 60px rgba(0,0,0,.55);
                --ok-bg: #08281a; --warn-bg: #2a1d05; --err-bg: #2a0f0f;
            }
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        /* Offset anchored scrolling by the 66px sticky nav so targeted headings
           aren't hidden underneath it (fixes the "lost" jump to #inscription). */
        html { scroll-behavior: smooth; scroll-padding-top: 86px; }
        :target { scroll-margin-top: 86px; }
        body {
            font-family: var(--font);
            background: var(--paper);
            color: var(--ink);
            line-height: 1.55;
            -webkit-font-smoothing: antialiased;
            /* Safety net for stray horizontal overflow (esp. RTL) without
               breaking the sticky nav the way overflow-x:hidden would. */
            overflow-x: clip;
        }
        a { color: inherit; text-decoration: none; }
        .wrap { max-width: 1140px; margin: 0 auto; padding: 0 24px; }

        .eyebrow { font-size: 12px; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; color: var(--brand); }
        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            font-weight: 650; font-size: 15px; padding: 13px 22px; border-radius: 12px;
            border: 1px solid transparent; cursor: pointer; transition: transform .12s ease, box-shadow .18s ease, background .18s ease;
        }
        .btn-primary { background: var(--brand); color: #fff; box-shadow: 0 6px 20px color-mix(in srgb, var(--brand) 32%, transparent); }
        .btn-primary:hover { background: var(--brand-strong); transform: translateY(-1px); }
        .btn-ghost { background: transparent; color: var(--ink); border-color: var(--sand); }
        .btn-ghost:hover { border-color: var(--brand); color: var(--brand); }

        .nav { position: sticky; top: 0; z-index: 40; backdrop-filter: blur(12px);
               background: color-mix(in srgb, var(--paper) 82%, transparent);
               border-bottom: 1px solid color-mix(in srgb, var(--sand) 60%, transparent); }
        .nav-inner { display: flex; align-items: center; gap: 16px; height: 66px; }
        .brand { display: flex; align-items: center; gap: 10px; font-weight: 800; font-size: 18px; letter-spacing: -.02em; }
        .brand .mark { width: 34px; height: 34px; display: block; flex-shrink: 0; border-radius: 9px; box-shadow: var(--shadow); }
        .brand.brand-sm .mark { width: 26px; height: 26px; }
        .wordmark { white-space: nowrap; }
        .wordmark .wm-accent { color: var(--brand); }
        .nav .spacer { margin-left: auto; }
        .nav-links { display: flex; align-items: center; gap: 8px; }
        @media (max-width: 640px) { .nav-links a.nav-hide-sm { display: none; } }
        .lang-switch { display: inline-flex; align-items: center; gap: 2px; padding: 3px; border-radius: 999px;
                       border: 1px solid var(--sand); background: color-mix(in srgb, var(--surface) 60%, transparent); }
        .lang-opt { display: inline-flex; align-items: center; justify-content: center; min-width: 30px; height: 26px; padding: 0 8px;
                    border-radius: 999px; font-size: 12.5px; font-weight: 700; color: var(--muted); transition: background .15s, color .15s; }
        .lang-opt:hover { color: var(--brand); }
        .lang-opt.is-active { background: var(--brand); color: #fff; }
        [dir="rtl"] body { text-align: right; }

        footer { border-top: 1px solid var(--sand); margin-top: 80px; padding: 40px 0; color: var(--muted); font-size: 13px; }
        .footer-inner { display: flex; flex-wrap: wrap; gap: 16px; align-items: center; }

        .field-error { color: var(--err); font-size: 12.5px; margin-top: 5px; font-weight: 500; }
        .flash { border-radius: 12px; padding: 12px 16px; font-size: 14px; font-weight: 500; margin-bottom: 18px; }
        .flash-ok { background: var(--ok-bg); color: var(--ok); }
        .flash-err { background: var(--err-bg); color: var(--err); }

        @media (prefers-reduced-motion: reduce) { * { transition: none !important; scroll-behavior: auto; } }
    </style>
    @stack('head')
</head>
<body>
    <nav class="nav">
        <div class="wrap nav-inner">
            <a href="{{ route('castlit.landing') }}" class="brand" aria-label="{{ $brand['name'] }} accueil">
                @include('castlit.partials.mark')
                <span class="wordmark">Castl-it-<span class="wm-accent">POS</span></span>
            </a>
            <div class="spacer"></div>
            <div class="nav-links">
                <a href="{{ route('castlit.landing') }}#fonctionnalites" class="btn btn-ghost nav-hide-sm" style="padding:9px 16px">{{ __('castlit.nav_features') }}</a>
                <a href="{{ route('castlit.landing') }}#secteurs" class="btn btn-ghost nav-hide-sm" style="padding:9px 16px">{{ __('castlit.nav_sectors') }}</a>
                <div class="lang-switch" role="group" aria-label="Language">
                    @foreach (['fr', 'ar', 'en'] as $code)
                        <a href="{{ request()->fullUrlWithQuery(['lang' => $code]) }}" hreflang="{{ $code }}" class="lang-opt {{ $lang === $code ? 'is-active' : '' }}">{{ $langLabels[$code] }}</a>
                    @endforeach
                </div>
                <a href="{{ route('castlit.landing') }}#inscription" class="btn btn-primary" style="padding:9px 18px">{{ __('castlit.nav_start') }}</a>
            </div>
        </div>
    </nav>

    @yield('content')

    <footer>
        <div class="wrap footer-inner">
            <a href="{{ route('castlit.landing') }}" class="brand brand-sm" style="font-size:15px">
                @include('castlit.partials.mark')
                <span class="wordmark">Castl-it-POS</span>
            </a>
            <span class="spacer" style="margin-left:auto"></span>
            <span>© {{ date('Y') }} {{ $brand['name'] }} — {{ __('castlit.footer_tagline') }}.</span>
        </div>
    </footer>
    @stack('scripts')
</body>
</html>
