@php
    // Self-contained: the storefront layout assumes an enabled shop (catalogue,
    // cart, filters), none of which exist here.
    $theme = data_get($tenant->settings, 'theme', []);
    $primary = $theme['primary'] ?? '#3157D5';
    $logo = data_get($tenant->settings, 'company.logo_path');
    $locale = app()->getLocale();
    $dir = \App\Support\Locale::dir($locale);
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Never index a shop that is switched off. --}}
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('storefront.closed_title') }} · {{ $tenant->name }}</title>
    <style>
        :root { --brand: {{ $primary }}; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100dvh;
            display: grid;
            place-items: center;
            padding: 24px;
            background: #f6f7fb;
            color: #0f172a;
            font: 16px/1.6 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        }
        .card {
            width: 100%;
            max-width: 30rem;
            background: #fff;
            border: 1px solid #e6e9f0;
            border-radius: 18px;
            padding: 32px 28px;
            text-align: center;
            box-shadow: 0 18px 40px -24px rgba(15, 23, 42, .35);
        }
        .mark {
            width: 60px; height: 60px;
            margin: 0 auto 18px;
            border-radius: 16px;
            display: grid; place-items: center;
            background: var(--brand);
            color: #fff;
            font-size: 26px; font-weight: 800;
            overflow: hidden;
        }
        .mark img { width: 100%; height: 100%; object-fit: contain; }
        h1 { margin: 0 0 8px; font-size: 1.3rem; line-height: 1.3; }
        p { margin: 0; color: #55627a; }
        .shop { margin-top: 18px; font-size: .9rem; font-weight: 600; color: #0f172a; }
        .note {
            margin-top: 22px; padding-top: 18px;
            border-top: 1px solid #eef1f6;
            font-size: .82rem; color: #7c879c;
        }
        @media (prefers-color-scheme: dark) {
            body { background: #0b1120; color: #e8ecf5; }
            .card { background: #111a2e; border-color: rgba(255,255,255,.08); }
            p { color: #9aa7bf; }
            .shop { color: #e8ecf5; }
            .note { border-top-color: rgba(255,255,255,.08); color: #7c879c; }
        }
    </style>
</head>
<body>
    <main class="card">
        <div class="mark">
            @if ($logo)
                <img src="{{ \Illuminate\Support\Facades\Storage::url($logo) }}" alt="">
            @else
                {{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($tenant->name, 0, 1)) }}
            @endif
        </div>
        <h1>{{ __('storefront.closed_title') }}</h1>
        <p>{{ __('storefront.closed_body') }}</p>
        <p class="shop">{{ $tenant->name }}</p>
        <p class="note">{{ __('storefront.closed_admin_note') }}</p>
    </main>
</body>
</html>
