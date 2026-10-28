@extends('castlit.layout')

@php
    $brand = config('castlit.brand');
    $playStore = $brand['play_store'] ?? '';
    $months = (int) config('castlit.trial_months', 3);
    // Placeholders inside the translated arrays are not replaced by __(), so
    // the trial length is filled in here for the strings that carry it.
    $withMonths = fn ($text) => str_replace(':months', (string) $months, (string) $text);
    $faqs = collect((array) __('castlit.faqs'))
        ->map(fn ($f) => ['q' => $withMonths($f['q']), 'a' => $withMonths($f['a'])])
        ->all();
    $img = fn (string $file) => asset('img/marketing/'.$file);
    // Real screenshots: [width, height] at 2x, so the browser reserves the
    // space before the file arrives and nothing jumps while the page loads.
    $shotSize = [
        'pos.webp' => [2684, 1560], 'dashboard.webp' => [2664, 1600],
        'catalogue.webp' => [2664, 1520], 'reports.webp' => [2664, 1280],
        'app-pos.jpg' => [540, 1104], 'app-tickets.jpg' => [540, 1104], 'app-cart.jpg' => [540, 1104],
    ];

    $ico = fn (string $paths) => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$paths.'</svg>';
    $check = $ico('<path d="M20 6 9 17l-5-5"/>');
    $areaIcons = [
        'caisse'   => $ico('<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M7 20h10M12 16v4M7 8h4"/>'),
        'stock'    => $ico('<path d="m21 8-9-5-9 5 9 5 9-5Z"/><path d="M3 8v8l9 5 9-5V8M12 13v8"/>'),
        'ventes'   => $ico('<path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2Z"/><path d="M9 13h6M9 17h4"/>'),
        'clients'  => $ico('<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/>'),
        'pilotage' => $ico('<path d="M3 3v18h18"/><path d="m7 14 4-4 3 3 5-6"/>'),
    ];
    $restoIcons = [
        $ico('<circle cx="12" cy="13" r="8"/><path d="M12 9v4l2 2M9 2h6"/>'),
        $ico('<rect x="2" y="4" width="20" height="13" rx="2"/><path d="M8 21h8M12 17v4M6 9h5M6 12h8"/>'),
        $ico('<path d="M12 5v14M5 12h14"/>'),
        $ico('<path d="M5 12.5a9 9 0 0 1 14 0M8.5 16a4.5 4.5 0 0 1 7 0"/><circle cx="12" cy="19.5" r=".8" fill="currentColor"/>'),
    ];
    $hwIcons = [
        $ico('<rect x="6" y="2" width="12" height="20" rx="2.5"/><path d="M11 18h2"/>'),
        $ico('<rect x="2" y="4" width="20" height="13" rx="2"/><path d="M8 21h8M12 17v4"/>'),
        $ico('<rect x="5" y="2" width="14" height="20" rx="2"/><path d="M8 6h8v5H8zM9 15h.01M12 15h.01M15 15h.01M9 18h.01M12 18h.01M15 18h.01"/>'),
        $ico('<path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8" rx="1"/>'),
        $ico('<path d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2M7 8v8M11 8v8M14 8v8M17 8v8"/>'),
        $ico('<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M12 17v4M8 21h8"/><path d="M7 10h4M14 8h3v4h-3z"/>'),
    ];
    $sectorIcons = [
        $ico('<path d="M2 4h6a4 4 0 0 1 4 4v13a3 3 0 0 0-3-3H2ZM22 4h-6a4 4 0 0 0-4 4v13a3 3 0 0 1 3-3h7Z"/>'),
        $ico('<path d="M17 8h1a4 4 0 1 1 0 8h-1M3 8h14v9a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4Z"/><path d="M6 2v2M10 2v2M14 2v2"/>'),
        $ico('<path d="M3 2v7c0 1.1.9 2 2 2h4a2 2 0 0 0 2-2V2M7 2v20M21 15V2a5 5 0 0 0-5 5v6c0 1.1.9 2 2 2h3Zm0 0v7"/>'),
        $ico('<path d="M4 15a6 6 0 0 1 4-11 6 6 0 0 1 8 0 6 6 0 0 1 4 11Z"/><path d="M6 15h12v4a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2Z"/>'),
        $ico('<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 6H6"/>'),
        $ico('<path d="M20.4 4.6 16 2c-.5 1.7-2.1 3-4 3s-3.5-1.3-4-3L3.6 4.6a2 2 0 0 0-1.3 2.2l.6 3.5a1 1 0 0 0 1 .8H6v10c0 1.1.9 2 2 2h8a2 2 0 0 0 2-2V11h2.1a1 1 0 0 0 1-.8l.6-3.5a2 2 0 0 0-1.3-2.2Z"/>'),
        $ico('<circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><path d="M20 4 8.1 15.9M14.5 14.5 20 20M8.1 8.1 12 12"/>'),
        $ico('<path d="M9 3h6v4H9zM7 7h10l1 4v9a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2v-9Z"/>'),
        $ico('<rect x="3" y="3" width="18" height="18" rx="4"/><path d="M12 8v8M8 12h8"/>'),
        $ico('<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.8-3.8a6 6 0 0 1-7.9 7.9l-6.9 6.9a2.1 2.1 0 0 1-3-3l6.9-6.9a6 6 0 0 1 7.9-7.9Z"/>'),
        $ico('<rect x="5" y="2" width="14" height="20" rx="2"/><path d="M12 18h.01"/>'),
        $ico('<path d="M3 9 4.5 4h15L21 9M3 9v11h18V9M3 9h18M9 20v-6h6v6"/>'),
    ];
@endphp

@push('jsonld')
<script type="application/ld+json">
{!! json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'FAQPage',
    'mainEntity' => collect($faqs)->map(fn ($f) => [
        '@type' => 'Question',
        'name' => $f['q'],
        'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']],
    ])->all(),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}
</script>
@endpush

@push('head')
    {{-- Le champ téléphone : drapeau + indicatif, suit le pays choisi. --}}
    @vite('resources/js/phone.js')
    {{-- The hero screenshot is the largest thing above the fold. --}}
    <link rel="preload" as="image" href="{{ $img('pos.webp') }}" type="image/webp">
@endpush

@section('content')
<style>
    :root { --brand-deep: #1E2A6B; --frame: #E9EDF5; }
    @media (prefers-color-scheme: dark) { :root { --frame: #1B2238; } }
    .c-wrap { max-width: 1180px; margin: 0 auto; padding: 0 24px; }
    .eyebrow { font-size: 12px; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; color: var(--brand);
               display: inline-flex; align-items: center; gap: 8px; }
    .eyebrow::before { content: ""; width: 22px; height: 2px; background: var(--accent); border-radius: 2px; }

    .btn { display: inline-flex; align-items: center; justify-content: center; gap: 9px; font-weight: 700; font-size: 15px;
           padding: 14px 24px; border-radius: 12px; border: 1px solid transparent; cursor: pointer;
           transition: transform .14s ease, box-shadow .2s ease, background .2s ease, border-color .2s ease; }
    .btn-primary { background: var(--brand); color: #fff; box-shadow: 0 10px 26px color-mix(in srgb, var(--brand) 34%, transparent); }
    .btn-primary:hover { background: var(--brand-strong); transform: translateY(-2px); }
    .btn-ghost { background: transparent; color: var(--ink); border-color: var(--sand); }
    .btn-ghost:hover { border-color: var(--brand); color: var(--brand); }
    .btn svg { width: 18px; height: 18px; }
    [dir="rtl"] .btn svg { transform: scaleX(-1); }
    .btn:focus-visible, .tour-tab:focus-visible, .faq summary:focus-visible { outline: 3px solid color-mix(in srgb, var(--brand) 45%, transparent); outline-offset: 2px; }

    .reveal { opacity: 0; transform: translateY(18px); transition: opacity .6s ease, transform .6s cubic-bezier(.2,.7,.2,1); }
    .reveal.is-in { opacity: 1; transform: none; }
    @media (prefers-reduced-motion: reduce) { .reveal { opacity: 1 !important; transform: none !important; } }
    section[id], [id="inscription"] { scroll-margin-top: 86px; }

    /* Screens: a browser window and a phone, framing real screenshots. */
    .win { background: var(--surface); border: 1px solid var(--sand); border-radius: 16px; box-shadow: var(--shadow-lg); overflow: hidden; }
    .win-bar { display: flex; align-items: center; gap: 6px; padding: 10px 14px; background: var(--frame); border-bottom: 1px solid var(--sand); }
    .win-bar i { width: 9px; height: 9px; border-radius: 50%; background: color-mix(in srgb, var(--muted) 40%, transparent); }
    .win-bar span { margin-inline-start: 10px; font-size: 11.5px; color: var(--muted); direction: ltr; unicode-bidi: isolate;
                    background: var(--surface); border-radius: 6px; padding: 2px 10px; }
    .win img, .phone-frame img { display: block; width: 100%; height: auto; }
    .phone-frame { background: #0C1020; border-radius: 30px; padding: 8px; box-shadow: 0 30px 60px -18px rgba(12,16,32,.55); }
    .phone-frame img { border-radius: 23px; }

    /* ── HERO ─────────────────────────────────────────────────────────────── */
    .hero { position: relative; overflow: hidden; padding: 64px 0 40px; }
    .hero::before { content: ""; position: absolute; inset: -30% 20% auto -15%; height: 640px; pointer-events: none;
        background: radial-gradient(50% 50% at 50% 40%, color-mix(in srgb, var(--brand) 20%, transparent), transparent 70%); }
    .hero-grid { position: relative; display: grid; grid-template-columns: minmax(0, .92fr) minmax(0, 1.08fr); gap: 52px; align-items: center; }
    .hero h1 { font-size: clamp(36px, 5vw, 58px); line-height: 1.04; letter-spacing: -.035em; font-weight: 800; margin: 18px 0; text-wrap: balance; }
    .hero h1 .hl { color: var(--brand); }
    .hero .lead { font-size: 18px; color: var(--muted); max-width: 46ch; line-height: 1.6; }
    .hero-cta { display: flex; gap: 12px; margin-top: 28px; flex-wrap: wrap; align-items: center; }
    .hero-cta .btn, .hero-cta .store-btn { height: 54px; }
    .cta-note { margin-top: 12px; font-size: 13.5px; font-weight: 600; color: var(--muted); }
    .btn-demo { background: color-mix(in srgb, var(--brand) 9%, transparent); border-color: color-mix(in srgb, var(--brand) 32%, transparent); color: var(--brand); }
    .btn-demo:hover { background: color-mix(in srgb, var(--brand) 15%, transparent); border-color: var(--brand); }
    .demo-creds { margin-top: 6px; font-size: 13px; color: var(--muted); }
    .demo-creds code { background: color-mix(in srgb, var(--brand) 10%, transparent); color: var(--brand); padding: 2px 7px; border-radius: 6px; font-weight: 700; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
    .hero-points { list-style: none; display: grid; gap: 9px; margin-top: 26px; }
    .hero-points li { display: flex; gap: 10px; align-items: center; font-size: 14.5px; font-weight: 600; }
    .hero-points svg { width: 18px; height: 18px; color: var(--ok); flex-shrink: 0; }

    .stage { position: relative; padding-block-end: 34px; }
    .stage .win { transform: perspective(1600px) rotateY(-4deg); transform-origin: center left; }
    [dir="rtl"] .stage .win { transform: perspective(1600px) rotateY(4deg); transform-origin: center right; }
    .stage .phone-frame { position: absolute; inset-inline-end: -14px; bottom: 0; width: 27%; }
    .chip { position: absolute; z-index: 3; display: inline-flex; align-items: center; gap: 8px; padding: 9px 13px; border-radius: 12px;
            background: var(--surface); border: 1px solid var(--sand); box-shadow: var(--shadow); font-size: 12.5px; font-weight: 700; }
    .chip svg { width: 16px; height: 16px; }
    .chip-offline { top: -18px; inset-inline-end: 4%; color: var(--ok); }
    .chip-sync { bottom: 64px; inset-inline-start: -16px; color: var(--brand); }

    /* Store badge (hero + app band) */
    .store-btns { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }
    .store-btn { position: relative; display: inline-flex; align-items: center; gap: 12px; height: 54px; padding: 0 20px 0 16px;
                 background: #0C1020; color: #fff; border-radius: 13px; border: 1px solid rgba(255,255,255,.16); box-shadow: var(--shadow);
                 transition: transform .14s ease, border-color .2s ease; }
    .store-btn:hover { transform: translateY(-2px); border-color: rgba(255,255,255,.34); }
    .store-btn svg { width: 24px; height: 24px; flex-shrink: 0; }
    .store-btn .st-text { display: flex; flex-direction: column; line-height: 1.12; }
    .store-btn .st-small { font-size: 10px; letter-spacing: .09em; text-transform: uppercase; color: #aeb6cc; }
    .store-btn .st-big { font-size: 16.5px; font-weight: 800; }
    .store-btn.is-soon { cursor: default; }
    .store-btn .st-ribbon { position: absolute; top: -9px; inset-inline-end: -8px; background: var(--accent); color: #3a2400; font-size: 10px; font-weight: 800; padding: 3px 8px; border-radius: 999px; }
    .app .store-btn { background: #fff; color: #0C1020; border: none; }
    .app .store-btn .st-small { color: #5C6270; }

    /* ── Proof strip ──────────────────────────────────────────────────────── */
    .proof { border-block: 1px solid var(--sand); margin-top: 36px; }
    .proof-inner { display: grid; grid-template-columns: repeat(4, 1fr); }
    .proof-item { padding: 22px 20px; display: flex; gap: 12px; align-items: baseline; }
    .proof-item + .proof-item { border-inline-start: 1px solid var(--sand); }
    .proof-item b { direction: ltr; unicode-bidi: isolate; font-size: 30px; font-weight: 800; letter-spacing: -.03em; color: var(--brand); font-variant-numeric: tabular-nums; }
    .proof-item span { font-size: 13.5px; color: var(--muted); font-weight: 600; line-height: 1.35; }

    /* ── Sections ─────────────────────────────────────────────────────────── */
    .section { padding: 84px 0 0; }
    .section-head { max-width: 60ch; margin-bottom: 36px; }
    .section-head.center { margin-inline: auto; text-align: center; }
    .section-head.center .eyebrow { justify-content: center; }
    .section-head h2 { font-size: clamp(28px, 3.5vw, 40px); letter-spacing: -.025em; font-weight: 800; margin: 12px 0 10px; text-wrap: balance; line-height: 1.1; }
    .section-head p { color: var(--muted); font-size: 16.5px; line-height: 1.6; }
    .checks { list-style: none; display: grid; gap: 11px; }
    .checks li { display: flex; gap: 11px; align-items: flex-start; font-size: 15px; line-height: 1.45; }
    .checks svg { width: 18px; height: 18px; margin-top: 2px; flex-shrink: 0; color: var(--brand); }

    /* Product tour: one tab per area of the shop. */
    .tour-tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 22px; }
    .tour-tab { display: inline-flex; align-items: center; gap: 9px; padding: 11px 16px; border-radius: 12px; cursor: pointer;
                font: inherit; font-size: 14.5px; font-weight: 700; color: var(--muted); background: var(--surface); border: 1px solid var(--sand);
                transition: color .15s, border-color .15s, background .15s; }
    .tour-tab svg { width: 18px; height: 18px; }
    .tour-tab:hover { color: var(--ink); border-color: color-mix(in srgb, var(--brand) 40%, var(--sand)); }
    .tour-tab[aria-selected="true"] { color: #fff; background: var(--brand); border-color: var(--brand); }
    .tour-panel { display: grid; grid-template-columns: minmax(0, .8fr) minmax(0, 1.2fr); gap: 44px; align-items: center;
                  background: var(--surface); border: 1px solid var(--sand); border-radius: 24px; padding: 36px; box-shadow: var(--shadow); }
    .tour-panel + .tour-panel { margin-top: 18px; }
    .js-tabs .tour-panel + .tour-panel { margin-top: 0; }
    .tour-panel[hidden] { display: none; }
    .tour-panel h3 { font-size: clamp(22px, 2.4vw, 28px); font-weight: 800; letter-spacing: -.02em; line-height: 1.15; text-wrap: balance; }
    .tour-panel .lead { color: var(--muted); font-size: 15.5px; line-height: 1.6; margin: 10px 0 20px; }
    .tour-media { min-width: 0; }
    .tour-media.is-phone { display: flex; justify-content: center; }
    .tour-media.is-phone .phone-frame { width: min(270px, 70%); }

    /* Restaurants */
    .resto { display: grid; grid-template-columns: minmax(0, .75fr) minmax(0, 1.25fr); gap: 56px; align-items: center; }
    .resto-visual { display: flex; justify-content: center; position: relative; }
    .resto-visual .phone-frame { width: min(300px, 80%); }
    .resto-visual::before { content: ""; position: absolute; inset: 10% 0; border-radius: 40px; z-index: -1;
        background: radial-gradient(60% 60% at 50% 50%, color-mix(in srgb, var(--accent) 24%, transparent), transparent 70%); }
    .resto-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 26px; }
    .mini { background: var(--surface); border: 1px solid var(--sand); border-radius: 16px; padding: 20px; }
    .mini .ico { width: 40px; height: 40px; border-radius: 11px; display: grid; place-items: center; color: var(--brand);
                 background: color-mix(in srgb, var(--brand) 10%, transparent); margin-bottom: 12px; }
    .mini .ico svg { width: 21px; height: 21px; }
    .mini h3 { font-size: 15.5px; font-weight: 750; margin-bottom: 5px; }
    .mini p { color: var(--muted); font-size: 13.8px; line-height: 1.55; }

    /* Offline band */
    .band { border-radius: 28px; background: linear-gradient(150deg, var(--brand), var(--brand-deep)); color: #fff; padding: 52px 48px; position: relative; overflow: hidden; }
    .band::after { content: ""; position: absolute; inset: auto -80px -120px auto; width: 360px; height: 360px; border-radius: 50%;
        background: radial-gradient(50% 50% at 50% 50%, rgba(245,158,11,.28), transparent 70%); pointer-events: none; }
    .band .eyebrow { color: #cfe0ff; }
    .band h2 { font-size: clamp(26px, 3.3vw, 38px); font-weight: 800; letter-spacing: -.025em; line-height: 1.1; margin: 12px 0 10px; max-width: 22ch; text-wrap: balance; }
    .band > p { color: rgba(255,255,255,.84); font-size: 16px; max-width: 60ch; line-height: 1.6; }
    .flow { list-style: none; display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; margin-top: 34px; counter-reset: flow; position: relative; }
    .flow li { position: relative; background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.16); border-radius: 16px; padding: 20px; counter-increment: flow; }
    .flow li::before { content: counter(flow); display: grid; place-items: center; width: 30px; height: 30px; border-radius: 9px;
                       background: #fff; color: var(--brand); font-weight: 800; font-size: 14px; margin-bottom: 12px; }
    .flow li:not(:last-child)::after { content: ""; position: absolute; top: 34px; inset-inline-end: -19px; width: 20px; height: 2px; background: rgba(255,255,255,.4); }
    .flow h3 { font-size: 16px; font-weight: 750; margin-bottom: 5px; }
    .flow p { color: rgba(255,255,255,.8); font-size: 14px; line-height: 1.55; }

    /* Hardware */
    .hw { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; }
    .hw-item { display: flex; gap: 14px; align-items: center; background: var(--surface); border: 1px solid var(--sand); border-radius: 16px; padding: 18px; }
    .hw-item .ico { width: 44px; height: 44px; border-radius: 12px; display: grid; place-items: center; flex-shrink: 0; color: var(--ink);
                    background: color-mix(in srgb, var(--ink) 6%, transparent); }
    .hw-item .ico svg { width: 22px; height: 22px; }
    .hw-item h3 { font-size: 15px; font-weight: 750; }
    .hw-item p { color: var(--muted); font-size: 13.5px; }

    /* App band */
    .app { position: relative; overflow: hidden; border-radius: 28px; background: linear-gradient(150deg, var(--brand), var(--brand-deep)); color: #fff; }
    .app-inner { display: grid; grid-template-columns: minmax(0, 1.15fr) minmax(0, .85fr); gap: 36px; align-items: center; padding: 48px 48px 0; }
    .app-copy { padding-bottom: 48px; }
    .app h2 { font-size: clamp(26px, 3.3vw, 36px); font-weight: 800; letter-spacing: -.02em; line-height: 1.1; text-wrap: balance; }
    .app p { color: rgba(255,255,255,.85); font-size: 16px; margin: 14px 0 24px; max-width: 46ch; line-height: 1.6; }
    .app-list { list-style: none; display: grid; gap: 10px; margin-top: 22px; }
    .app-list li { display: flex; gap: 10px; align-items: center; font-size: 14.5px; color: rgba(255,255,255,.92); }
    .app-list svg { width: 18px; height: 18px; flex-shrink: 0; }
    .app-id { display: flex; align-items: center; gap: 14px; margin-top: 14px; }
    .app-id img { border-radius: 13px; box-shadow: 0 8px 20px rgba(0,0,0,.28); flex-shrink: 0; }
    .app-visual { align-self: end; display: flex; justify-content: center; }
    .app-visual .phone-frame { width: min(270px, 80%); border-radius: 30px 30px 0 0; padding-bottom: 0; margin-bottom: -2px; }
    .app-visual .phone-frame img { border-radius: 23px 23px 0 0; max-height: 440px; object-fit: cover; object-position: top; }

    /* Sectors */
    .sectors { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; }
    .sector { display: flex; gap: 13px; align-items: flex-start; background: var(--surface); border: 1px solid var(--sand); border-radius: 15px; padding: 16px; }
    .sector .ico { width: 38px; height: 38px; border-radius: 11px; display: grid; place-items: center; flex-shrink: 0; color: var(--brand);
                   background: color-mix(in srgb, var(--brand) 9%, transparent); }
    .sector .ico svg { width: 20px; height: 20px; }
    .sector h3 { font-size: 14.5px; font-weight: 750; line-height: 1.3; }
    .sector p { color: var(--muted); font-size: 13px; line-height: 1.45; margin-top: 2px; }

    /* Steps + offer */
    .steps { list-style: none; display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; counter-reset: step; }
    .steps li { counter-increment: step; border-top: 3px solid var(--brand); padding-top: 18px; }
    .steps li::before { content: counter(step, decimal-leading-zero); font-size: 13px; font-weight: 800; color: var(--brand); letter-spacing: .08em; }
    .steps h3 { font-size: 17px; font-weight: 750; margin: 6px 0 6px; }
    .steps p { color: var(--muted); font-size: 14.5px; line-height: 1.55; }
    .offer { display: grid; grid-template-columns: minmax(0, 1.3fr) minmax(0, .7fr); gap: 18px; margin-top: 44px; }
    .offer-main { background: var(--surface); border: 2px solid var(--brand); border-radius: 24px; padding: 34px; box-shadow: var(--shadow-lg); }
    .offer-main h2 { font-size: clamp(26px, 3vw, 34px); font-weight: 800; letter-spacing: -.025em; line-height: 1.1; margin: 10px 0 8px; }
    .offer-main > p { color: var(--muted); font-size: 15.5px; line-height: 1.6; max-width: 54ch; }
    .offer-main .checks { grid-template-columns: 1fr 1fr; margin: 22px 0 26px; }
    .offer-side { border: 1px dashed var(--sand); border-radius: 24px; padding: 30px; display: flex; flex-direction: column; justify-content: center; gap: 10px; }
    .offer-side h3 { font-size: 18px; font-weight: 750; }
    .offer-side p { color: var(--muted); font-size: 14.5px; line-height: 1.55; }
    .offer-side .btn { align-self: flex-start; margin-top: 8px; }

    /* FAQ */
    .faq { max-width: 820px; margin-inline: auto; }
    .faq details { border: 1px solid var(--sand); border-radius: 14px; background: var(--surface); margin-bottom: 10px; }
    .faq summary { cursor: pointer; padding: 17px 20px; font-weight: 750; font-size: 15.5px; list-style: none; display: flex; justify-content: space-between; gap: 12px; }
    .faq summary::-webkit-details-marker { display: none; }
    .faq summary::after { content: '+'; color: var(--brand); font-weight: 800; font-size: 20px; line-height: 1; }
    .faq details[open] summary::after { content: '−'; }
    .faq details p { padding: 0 20px 18px; color: var(--muted); font-size: 14.5px; line-height: 1.65; }

    /* Sign-up */
    .signup { padding: 84px 0 10px; }
    .signup-card { background: var(--surface); border: 1px solid var(--sand); border-radius: 24px; box-shadow: var(--shadow-lg); overflow: hidden; display: grid; grid-template-columns: .82fr 1.18fr; }
    .signup-aside { background: linear-gradient(160deg, var(--brand), var(--brand-deep)); color: #fff; padding: 40px 36px; }
    .signup-aside h2 { font-size: 27px; font-weight: 800; letter-spacing: -.02em; line-height: 1.12; text-wrap: balance; }
    .signup-aside p { color: rgba(255,255,255,.82); font-size: 14.5px; margin-top: 12px; line-height: 1.6; }
    .signup-aside ul { list-style: none; margin-top: 24px; display: flex; flex-direction: column; gap: 14px; }
    .signup-aside li { display: flex; gap: 11px; font-size: 14px; align-items: flex-start; }
    .signup-aside li b { display: inline-grid; place-items: center; width: 22px; height: 22px; border-radius: 7px; background: rgba(255,255,255,.18); font-size: 12px; flex-shrink: 0; }
    .form-body { padding: 36px; }
    .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    .fld { display: flex; flex-direction: column; gap: 6px; margin-bottom: 16px; }
    .fld label { font-size: 12.5px; font-weight: 700; color: var(--ink); }
    .fld .req { color: var(--brand); }
    .fld input, .fld select { font: inherit; font-size: 14.5px; padding: 12px 13px; border-radius: 11px; border: 1.5px solid var(--sand); background: var(--paper); color: var(--ink); width: 100%; transition: border-color .15s, box-shadow .15s; }
    .fld input:focus, .fld select:focus { outline: none; border-color: var(--brand); box-shadow: 0 0 0 3px color-mix(in srgb, var(--brand) 14%, transparent); }
    .subdomain-row { display: flex; align-items: stretch; border: 1.5px solid var(--sand); border-radius: 11px; overflow: hidden; background: var(--paper); }
    .subdomain-row:focus-within { border-color: var(--brand); box-shadow: 0 0 0 3px color-mix(in srgb, var(--brand) 14%, transparent); }
    .subdomain-row input { border: none; box-shadow: none; background: transparent; text-align: right; }
    .subdomain-row input:focus { box-shadow: none; }
    .subdomain-suf { display: flex; align-items: center; padding: 0 13px; font-size: 13.5px; color: var(--muted); background: color-mix(in srgb, var(--sand) 40%, transparent); white-space: nowrap; }
    /* Honeypot: hidden without the old left:-9999px trick, which in RTL created
       a ~10000px horizontal scroll area (jump-to-anchor then landed on blank). */
    .hp { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0 0 0 0); clip-path: inset(50%); white-space: nowrap; border: 0; }

    @media (max-width: 1020px) {
        .hero-grid { grid-template-columns: 1fr; gap: 44px; }
        .stage { max-width: 680px; }
        .stage .win, [dir="rtl"] .stage .win { transform: none; }
        .tour-panel { grid-template-columns: 1fr; gap: 28px; padding: 26px; }
        .resto { grid-template-columns: 1fr; gap: 36px; }
        .resto-visual { order: 2; }
        .proof-inner { grid-template-columns: 1fr 1fr; }
        .proof-item:nth-child(3) { border-inline-start: 0; }
        .proof-item:nth-child(n+3) { border-top: 1px solid var(--sand); }
        .hw, .sectors { grid-template-columns: 1fr 1fr; }
        .app-inner { grid-template-columns: 1fr; padding: 40px 32px 0; }
        .app-copy { padding-bottom: 0; }
        .offer { grid-template-columns: 1fr; }
        .signup-card { grid-template-columns: 1fr; }
        .signup-aside { display: none; }
    }
    @media (max-width: 640px) {
        .c-wrap { padding: 0 16px; }
        .hero { padding-top: 40px; }
        .hero-cta .btn { flex: 1 1 100%; }
        .chip-sync { display: none; }
        .stage .phone-frame { width: 30%; inset-inline-end: -4px; }
        .proof-item { padding: 16px 12px; flex-direction: column; gap: 2px; }
        .proof-item b { font-size: 24px; }
        .tour-tabs { flex-wrap: nowrap; overflow-x: auto; margin-inline: -16px; padding-inline: 16px; scrollbar-width: none; }
        .tour-tab { flex-shrink: 0; }
        .resto-grid, .flow, .hw, .sectors, .steps, .offer-main .checks { grid-template-columns: 1fr; }
        .flow li:not(:last-child)::after { display: none; }
        .band, .offer-main, .offer-side { padding: 28px 20px; }
        .app-inner { padding: 32px 20px 0; }
        .grid2 { grid-template-columns: 1fr; gap: 0; }
        .form-body { padding: 24px 18px; }
    }
</style>

@php
    // Google Play badge (hero + app band). Coming-soon shows a ribbon.
    $storeButton = function () use ($playStore) {
        $soon   = $playStore === '';
        $cls    = 'store-btn'.($soon ? ' is-soon' : '');
        $href   = $playStore ?: '#';
        $small  = $soon ? __('castlit.store_soon') : __('castlit.store_available');
        $ribbon = $soon ? '<span class="st-ribbon">'.__('castlit.store_ribbon').'</span>' : '';
        $svg    = '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M3.6 2.4 13 12 3.6 21.6a1 1 0 0 1-.6-.9V3.3a1 1 0 0 1 .6-.9Z" fill="#34d399"/><path d="M13 12 3.6 2.4l11.9 6.8L13 12Z" fill="#fbbf24"/><path d="M13 12l2.5 2.8-11.9 6.8L13 12Z" fill="#f87171"/><path d="m15.5 9.2 4.4 2.5c.8.5.8 1.6 0 2l-4.4 2.5L13 12l2.5-2.8Z" fill="#60a5fa"/></svg>';
        return '<a href="'.$href.'" '.($soon ? 'aria-disabled="true"' : 'target="_blank" rel="noopener"').' class="'.$cls.'">'
            .$ribbon.$svg.'<span class="st-text"><span class="st-small">'.$small.'</span><span class="st-big">Google Play</span></span></a>';
    };
    $demoUrl = config('castlit.demo.url');
    $demoCreds = null;
    if ($demoUrl) {
        $host = parse_url($demoUrl, PHP_URL_HOST) ?? '';
        $suffix = '.'.config('castlit.main_domain');
        $sub = \Illuminate\Support\Str::endsWith($host, $suffix) ? substr($host, 0, -strlen($suffix)) : 'demo';
        $da = config('castlit.provision.default_admin', []);
        // The demo's own login, public on purpose; never the support login of
        // a real shop (those are random per space).
        $demoCreds = [
            'email'    => config('castlit.demo.email') ?: str_replace('{sub}', $sub ?: 'demo', $da['email_pattern'] ?? 'admin@{sub}.com'),
            'password' => (string) config('castlit.demo.password', 'admin'),
        ];
    }
    $shot = function (string $file, string $alt, bool $eager = false) use ($img, $shotSize) {
        [$w, $h] = $shotSize[$file] ?? [1600, 1000];
        return '<img src="'.e($img($file)).'" width="'.$w.'" height="'.$h.'" alt="'.e($alt).'"'
            .($eager ? ' fetchpriority="high"' : ' loading="lazy"').' decoding="async">';
    };
@endphp

<header class="hero">
    <div class="c-wrap hero-grid">
        <div class="reveal">
            <span class="eyebrow">{{ __('castlit.hero_eyebrow') }}</span>
            <h1>{{ __('castlit.hero_title_a') }} <span class="hl">{{ __('castlit.hero_title_hl') }}</span> {{ __('castlit.hero_title_b') }}</h1>
            <p class="lead">{{ __('castlit.hero_lead') }}</p>
            <div class="hero-cta">
                <a href="#inscription" class="btn btn-primary">
                    {{ __('castlit.cta_trial') }}
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                </a>
                @if ($demoUrl)
                    <a href="{{ $demoUrl }}" target="_blank" rel="noopener" class="btn btn-ghost btn-demo">{{ __('castlit.cta_demo') }}</a>
                @endif
                {!! $storeButton() !!}
            </div>
            <p class="cta-note">{{ __('castlit.cta_trial_note', ['months' => $months]) }}</p>
            @if ($demoCreds)
                <p class="demo-creds">{{ __('castlit.demo_creds') }} <code dir="ltr">{{ $demoCreds['email'] }}</code> / <code dir="ltr">{{ $demoCreds['password'] }}</code></p>
            @endif
            <ul class="hero-points">
                @foreach ((array) __('castlit.hero_points') as $point)
                    <li>{!! $check !!} {{ $point }}</li>
                @endforeach
            </ul>
        </div>

        <div class="stage reveal">
            <div class="chip chip-offline">{!! $check !!} {{ __('castlit.chip_offline') }}</div>
            <div class="chip chip-sync"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/></svg> {{ __('castlit.chip_sync') }}</div>
            <div class="win">
                <div class="win-bar" aria-hidden="true"><i></i><i></i><i></i><span>boutique.{{ config('castlit.main_domain') }}/caisse</span></div>
                {!! $shot('pos.webp', __('castlit.hero_shot_alt'), true) !!}
            </div>
            <div class="phone-frame">{!! $shot('app-pos.jpg', __('castlit.hero_app_alt')) !!}</div>
        </div>
    </div>
</header>

<div class="proof" aria-label="Castl-it-POS">
    <div class="c-wrap proof-inner">
        @foreach ((array) __('castlit.proof') as $p)
            <div class="proof-item"><b>{{ $p['n'] }}</b><span>{{ $p['t'] }}</span></div>
        @endforeach
    </div>
</div>

<section class="section" id="fonctionnalites" aria-labelledby="fonctionnalites-title">
    <div class="c-wrap">
        <div class="section-head reveal">
            <span class="eyebrow">{{ __('castlit.tour_eyebrow') }}</span>
            <h2 id="fonctionnalites-title">{{ __('castlit.tour_title') }}</h2>
            <p>{{ __('castlit.tour_sub') }}</p>
        </div>
        <div class="tour reveal" data-tour>
            <div class="tour-tabs" role="tablist" aria-label="{{ __('castlit.nav_features') }}" hidden>
                @foreach ((array) __('castlit.areas') as $i => $area)
                    <button type="button" class="tour-tab" role="tab" id="tab-{{ $area['key'] }}" aria-controls="panel-{{ $area['key'] }}"
                            aria-selected="{{ $i === 0 ? 'true' : 'false' }}" tabindex="{{ $i === 0 ? '0' : '-1' }}">
                        {!! $areaIcons[$area['key']] ?? '' !!}{{ $area['tab'] }}
                    </button>
                @endforeach
            </div>
            @foreach ((array) __('castlit.areas') as $i => $area)
                <article class="tour-panel" role="tabpanel" id="panel-{{ $area['key'] }}" aria-labelledby="tab-{{ $area['key'] }}">
                    <div>
                        <h3>{{ $area['title'] }}</h3>
                        <p class="lead">{{ $area['lead'] }}</p>
                        <ul class="checks">
                            @foreach ($area['items'] as $item)
                                <li>{!! $check !!}<span>{{ $item }}</span></li>
                            @endforeach
                        </ul>
                    </div>
                    @if (! empty($area['phone']))
                        <div class="tour-media is-phone"><div class="phone-frame">{!! $shot($area['shot'], $area['alt']) !!}</div></div>
                    @else
                        <div class="tour-media"><div class="win"><div class="win-bar" aria-hidden="true"><i></i><i></i><i></i></div>{!! $shot($area['shot'], $area['alt']) !!}</div></div>
                    @endif
                </article>
            @endforeach
        </div>
    </div>
</section>

<section class="section" id="restauration" aria-labelledby="restauration-title">
    <div class="c-wrap resto">
        <div class="resto-visual reveal">
            <div class="phone-frame">{!! $shot('app-tickets.jpg', __('castlit.resto_shot_alt')) !!}</div>
        </div>
        <div class="reveal">
            <div class="section-head" style="margin-bottom:0">
                <span class="eyebrow">{{ __('castlit.resto_eyebrow') }}</span>
                <h2 id="restauration-title">{{ __('castlit.resto_title') }}</h2>
                <p>{{ __('castlit.resto_sub') }}</p>
            </div>
            <div class="resto-grid">
                @foreach ((array) __('castlit.resto_items') as $i => $r)
                    <div class="mini"><div class="ico">{!! $restoIcons[$i] ?? '' !!}</div><h3>{{ $r['t'] }}</h3><p>{{ $r['d'] }}</p></div>
                @endforeach
            </div>
        </div>
    </div>
</section>

<section class="section" id="hors-ligne" aria-labelledby="offline-title">
    <div class="c-wrap">
        <div class="band reveal">
            <span class="eyebrow">{{ __('castlit.offline_eyebrow') }}</span>
            <h2 id="offline-title">{{ __('castlit.offline_title') }}</h2>
            <p>{{ __('castlit.offline_sub') }}</p>
            <ol class="flow">
                @foreach ((array) __('castlit.offline_steps') as $s)
                    <li><h3>{{ $s['t'] }}</h3><p>{{ $s['d'] }}</p></li>
                @endforeach
            </ol>
        </div>
    </div>
</section>

<section class="section" id="materiel" aria-labelledby="materiel-title">
    <div class="c-wrap">
        <div class="section-head reveal">
            <span class="eyebrow">{{ __('castlit.hw_eyebrow') }}</span>
            <h2 id="materiel-title">{{ __('castlit.hw_title') }}</h2>
            <p>{{ __('castlit.hw_sub') }}</p>
        </div>
        <div class="hw reveal">
            @foreach ((array) __('castlit.hardware') as $i => $h)
                <div class="hw-item"><div class="ico">{!! $hwIcons[$i] ?? '' !!}</div><div><h3>{{ $h['t'] }}</h3><p>{{ $h['d'] }}</p></div></div>
            @endforeach
        </div>
    </div>
</section>

<section class="section" aria-labelledby="app-title">
    <div class="c-wrap">
        <div class="app reveal">
            <div class="app-inner">
                <div class="app-copy">
                    <span class="eyebrow" style="color:#cfe0ff">{{ __('castlit.app_eyebrow') }}</span>
                    {{-- The real launcher icon: someone deciding whether to
                         install should see the thing they will be tapping on
                         their home screen, not a generic illustration. --}}
                    <div class="app-id">
                        <img src="{{ asset('img/app-icon.png') }}" width="56" height="56" alt="" loading="lazy" decoding="async">
                        <h2 id="app-title">{{ __('castlit.app_title') }}</h2>
                    </div>
                    <p>{{ __('castlit.app_sub') }}</p>
                    <div class="store-btns">
                        {!! $storeButton() !!}
                        <a href="#inscription" class="btn btn-ghost" style="background:rgba(255,255,255,.1);color:#fff;border-color:rgba(255,255,255,.25)">{{ __('castlit.app_web') }}</a>
                    </div>
                    <ul class="app-list">
                        @foreach ((array) __('castlit.app_list') as $li)
                            <li>{!! $check !!} {{ $li }}</li>
                        @endforeach
                    </ul>
                </div>
                <div class="app-visual">
                    <div class="phone-frame">{!! $shot('app-pos.jpg', __('castlit.hero_app_alt')) !!}</div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="section" id="secteurs" aria-labelledby="secteurs-title">
    <div class="c-wrap">
        <div class="section-head reveal">
            <span class="eyebrow">{{ __('castlit.sec_eyebrow') }}</span>
            <h2 id="secteurs-title">{{ __('castlit.sec_title') }}</h2>
            <p>{{ __('castlit.sec_sub') }}</p>
        </div>
        <div class="sectors reveal">
            @foreach ((array) __('castlit.sectors') as $i => $s)
                <article class="sector"><div class="ico">{!! $sectorIcons[$i] ?? '' !!}</div><div><h3>{{ $s['t'] }}</h3><p>{{ $s['d'] }}</p></div></article>
            @endforeach
        </div>
    </div>
</section>

<section class="section" id="essai" aria-labelledby="steps-title">
    <div class="c-wrap">
        <div class="section-head reveal">
            <span class="eyebrow">{{ __('castlit.steps_eyebrow') }}</span>
            <h2 id="steps-title">{{ __('castlit.steps_title') }}</h2>
        </div>
        <ol class="steps reveal">
            @foreach ((array) __('castlit.steps') as $s)
                <li><h3>{{ $s['t'] }}</h3><p>{{ $s['d'] }}</p></li>
            @endforeach
        </ol>
        <div class="offer reveal">
            <div class="offer-main">
                <span class="eyebrow">{{ __('castlit.offer_eyebrow') }}</span>
                <h2>{{ __('castlit.offer_title', ['months' => $months]) }}</h2>
                <p>{{ __('castlit.offer_sub') }}</p>
                <ul class="checks">
                    @foreach ((array) __('castlit.offer_list') as $o)
                        <li>{!! $check !!}<span>{{ $o }}</span></li>
                    @endforeach
                </ul>
                <a href="#inscription" class="btn btn-primary">{{ __('castlit.cta_trial') }}</a>
            </div>
            <div class="offer-side">
                <h3>{{ __('castlit.offer_quote') }}</h3>
                <p>{{ __('castlit.offer_quote_sub') }}</p>
                <a href="mailto:{{ $brand['email'] }}?subject={{ rawurlencode('Castl-it-POS — '.__('castlit.offer_quote_cta')) }}" class="btn btn-ghost">{{ __('castlit.offer_quote_cta') }}</a>
            </div>
        </div>
    </div>
</section>

<section class="section" id="faq" aria-labelledby="faq-title">
    <div class="c-wrap">
        <div class="section-head center reveal">
            <span class="eyebrow">{{ __('castlit.faq_eyebrow') }}</span>
            <h2 id="faq-title">{{ __('castlit.faq_title') }}</h2>
        </div>
        <div class="faq reveal">
            @foreach ($faqs as $faq)
                <details @if($loop->first) open @endif>
                    <summary>{{ $faq['q'] }}</summary>
                    <p>{{ $faq['a'] }}</p>
                </details>
            @endforeach
        </div>
    </div>
</section>

<section class="signup" id="inscription" aria-labelledby="inscription-title">
    <div class="c-wrap">
        <div class="signup-card reveal">
            <aside class="signup-aside">
                <span class="eyebrow" style="color:#cfe0ff">{{ __('castlit.su_eyebrow') }}</span>
                <h2 id="inscription-title">{{ __('castlit.su_title') }}</h2>
                <p>{{ __('castlit.su_sub') }}</p>
                <ul>
                    <li><b>1</b><span>{{ __('castlit.su_step1') }}</span></li>
                    <li><b>2</b><span>{{ __('castlit.su_step2') }} <code>*.{{ $mainDomain ?? config('castlit.main_domain') }}</code>.</span></li>
                    <li><b>3</b><span>{{ __('castlit.su_step3') }}</span></li>
                </ul>
            </aside>

            <div class="form-body">
                @if ($errors->any())
                    <div class="flash flash-err">{{ __('castlit.f_errors') }}</div>
                @endif

                <form method="POST" action="{{ route('castlit.subscribe') }}" novalidate>
                    @csrf
                    <input class="hp" type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">
                    {{-- The visitor's timezone, for the shop's clock (Montréal is not Toronto). --}}
                    <input type="hidden" name="timezone" value="">
                    <script>try { document.currentScript.previousElementSibling.value = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) {}</script>

                    <div class="fld">
                        <label for="business_name">{{ __('castlit.f_business') }} <span class="req">*</span></label>
                        <input id="business_name" name="business_name" required maxlength="120" value="{{ old('business_name') }}" placeholder="{{ __('castlit.f_business_ph') }}">
                        @error('business_name')<span class="field-error">{{ $message }}</span>@enderror
                    </div>

                    <div class="grid2">
                        <div class="fld">
                            <label for="activity">{{ __('castlit.f_activity') }}</label>
                            <select id="activity" name="activity">
                                <option value="">{{ __('castlit.f_choose') }}</option>
                                @foreach ($activities as $key => $label)
                                    <option value="{{ $key }}" @selected(old('activity') === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('activity')<span class="field-error">{{ $message }}</span>@enderror
                        </div>
                        <div class="fld">
                            <label for="currency">{{ __('castlit.f_currency') }} <span class="req">*</span></label>
                            <select id="currency" name="currency" required>
                                @php $currencyPreset = old('currency', \App\Support\Countries::currencyMap()[$detectedCountry ?? ''] ?? 'MAD'); @endphp
                                @foreach ($currencies as $c => $currencyName)
                                    <option value="{{ $c }}" @selected($currencyPreset === $c)>{{ $c }} — {{ $currencyName }}</option>
                                @endforeach
                            </select>
                            @error('currency')<span class="field-error">{{ $message }}</span>@enderror
                        </div>
                    </div>

                    @include('castlit.partials.place-fields')

                    <div class="fld">
                        <label for="desired_subdomain">{{ __('castlit.f_address') }} <span class="req">*</span></label>
                        <div class="subdomain-row">
                            <input id="desired_subdomain" name="desired_subdomain" required pattern="[a-z0-9]{2,30}" maxlength="30" autocomplete="off" spellcheck="false" dir="ltr" value="{{ old('desired_subdomain') }}" placeholder="almanara" oninput="this.value=this.value.toLowerCase().replace(/[^a-z0-9]/g,'')">
                            <span class="subdomain-suf">.{{ $mainDomain ?? config('castlit.main_domain') }}</span>
                        </div>
                        @error('desired_subdomain')<span class="field-error">{{ $message }}</span>@enderror
                    </div>

                    <div class="grid2">
                        <div class="fld">
                            <label for="contact_name">{{ __('castlit.f_your_name') }} <span class="req">*</span></label>
                            <input id="contact_name" name="contact_name" required maxlength="120" value="{{ old('contact_name') }}" placeholder="{{ __('castlit.f_your_name_ph') }}">
                            @error('contact_name')<span class="field-error">{{ $message }}</span>@enderror
                        </div>
                        <div class="fld">
                            <label for="phone">{{ __('castlit.f_phone') }}</label>
                            <input id="phone" name="phone" type="tel" data-phone-country-from="#country" maxlength="40" value="{{ old('phone') }}" placeholder="06 00 00 00 00">
                            @error('phone')<span class="field-error">{{ $message }}</span>@enderror
                        </div>
                    </div>

                    <div class="fld">
                        <label for="email">{{ __('castlit.f_email') }} <span class="req">*</span></label>
                        <input id="email" name="email" type="email" required maxlength="190" value="{{ old('email') }}" placeholder="vous@exemple.com">
                        @error('email')<span class="field-error">{{ $message }}</span>@enderror
                    </div>

                    <div class="fld">
                        <label for="heard_about">{{ __('castlit.f_heard') }}</label>
                        <input id="heard_about" name="heard_about" maxlength="120" value="{{ old('heard_about') }}" placeholder="{{ __('castlit.f_heard_ph') }}">
                        @error('heard_about')<span class="field-error">{{ $message }}</span>@enderror
                    </div>

                    <button type="submit" class="btn btn-primary" style="width:100%; margin-top:6px">{{ __('castlit.f_submit') }}</button>
                    <p style="font-size:12px; color:var(--muted); margin-top:12px; text-align:center">{{ __('castlit.f_consent') }}</p>
                </form>
            </div>
        </div>
    </div>
</section>
@endsection

@push('scripts')
<script>
    (function () {
        var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var els = document.querySelectorAll('.reveal');

        // Make a section (and its reveal ancestor/descendants) visible at once —
        // used when we jump straight to an anchor so it never lands faded out.
        function revealNow(el) {
            if (!el) return;
            el.classList.add('is-in');
            var anc = el.closest('.reveal'); if (anc) anc.classList.add('is-in');
            el.querySelectorAll('.reveal').forEach(function (c) { c.classList.add('is-in'); });
        }

        if (!('IntersectionObserver' in window) || reduce) {
            els.forEach(function (el) { el.classList.add('is-in'); });
        } else {
            var io = new IntersectionObserver(function (entries) {
                entries.forEach(function (e) {
                    if (e.isIntersecting) { e.target.classList.add('is-in'); io.unobserve(e.target); }
                });
            }, { threshold: 0.12, rootMargin: '0px 0px -8% 0px' });
            els.forEach(function (el) { io.observe(el); });
        }

        function goTo(target, push) {
            if (!target) return;
            revealNow(target);
            if (push) { try { history.pushState(null, '', '#' + target.id); } catch (e) {} }
            target.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
            // Focus the first field when landing on the signup form (no extra jump).
            if (target.id === 'inscription') {
                var first = target.querySelector('input, select');
                if (first) { setTimeout(function () { try { first.focus({ preventScroll: true }); } catch (e) { first.focus(); } }, reduce ? 0 : 520); }
            }
        }

        // Intercept in-page anchor clicks (including the nav CTA that carries the
        // full URL) so we smooth-scroll here instead of reloading — which also
        // preserves the chosen language and avoids the janky jump under the nav.
        document.addEventListener('click', function (ev) {
            var a = ev.target.closest('a[href*="#"]');
            if (!a || a.hasAttribute('data-no-scroll')) return;
            var href = a.getAttribute('href') || '';
            var i = href.indexOf('#');
            if (i < 0) return;
            var id = href.slice(i + 1);
            if (!id) return;
            var target = document.getElementById(id);
            if (!target) return; // anchor lives on another page → let it navigate
            ev.preventDefault();
            goTo(target, true);
        });

        // Product tour: tabs once the script runs; without it every panel
        // stays on the page, one under the other, which is also what search
        // engines read.
        document.querySelectorAll('[data-tour]').forEach(function (tour) {
            var list = tour.querySelector('[role="tablist"]');
            var tabs = Array.prototype.slice.call(tour.querySelectorAll('[role="tab"]'));
            if (!list || !tabs.length) return;
            list.hidden = false;
            tour.classList.add('js-tabs');
            function select(tab, focus) {
                tabs.forEach(function (t) {
                    var on = t === tab;
                    t.setAttribute('aria-selected', on ? 'true' : 'false');
                    t.tabIndex = on ? 0 : -1;
                    var panel = document.getElementById(t.getAttribute('aria-controls'));
                    if (panel) panel.hidden = !on;
                });
                if (focus) tab.focus();
            }
            tabs.forEach(function (tab, i) {
                tab.addEventListener('click', function () { select(tab, false); });
                tab.addEventListener('keydown', function (ev) {
                    var rtl = document.documentElement.dir === 'rtl';
                    var next = { ArrowRight: rtl ? -1 : 1, ArrowLeft: rtl ? 1 : -1, Home: -i, End: tabs.length - 1 - i }[ev.key];
                    if (next === undefined) return;
                    ev.preventDefault();
                    select(tabs[(i + next + tabs.length) % tabs.length], true);
                });
            });
            select(tabs[0], false);
        });

        // The nav says where you are on the page.
        var navLinks = document.querySelectorAll('[data-nav-section]');
        if (navLinks.length && 'IntersectionObserver' in window) {
            var current = new IntersectionObserver(function (entries) {
                entries.forEach(function (e) {
                    if (!e.isIntersecting) return;
                    navLinks.forEach(function (a) {
                        a.classList.toggle('is-current', a.getAttribute('data-nav-section') === e.target.id);
                    });
                });
            }, { rootMargin: '-45% 0px -50% 0px' });
            navLinks.forEach(function (a) {
                var s = document.getElementById(a.getAttribute('data-nav-section'));
                if (s) current.observe(s);
            });
        }

        // Arrived with a hash (fresh load / external link): reveal + align once
        // layout settles so we don't land under the nav or on a faded section.
        function handleHash() {
            if (!location.hash || location.hash.length < 2) return;
            var target = document.getElementById(location.hash.slice(1));
            if (target) { requestAnimationFrame(function () { goTo(target, false); }); }
        }
        window.addEventListener('load', handleHash);
        handleHash();
    })();
</script>
@endpush
