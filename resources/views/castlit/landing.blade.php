@extends('castlit.layout')

@php
    $brand = config('castlit.brand');
    $playStore = $brand['play_store'] ?? '';
    $faqs = (array) __('castlit.faqs');
    $featureIcons = [
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 10h18M7 15h4"/></svg>',
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m21 8-9-5-9 5 9 5 9-5Z"/><path d="M3 8v8l9 5 9-5V8"/></svg>',
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12.5a9 9 0 0 1 14 0M8.5 16a4.5 4.5 0 0 1 7 0M12 19.5h.01"/><path d="m2 2 20 20" opacity=".5"/></svg>',
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2Z"/><path d="M9 13h6M9 17h4"/></svg>',
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6"/></svg>',
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3v18h18"/><path d="M7 14l4-4 3 3 5-6"/></svg>',
    ];
    $sectorIcons = [
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20V3H6.5A2.5 2.5 0 0 0 4 5.5v14Z"/></svg>',
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8h1a3 3 0 0 1 0 6h-1M2 8h16v6a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8Z"/></svg>',
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 7v10M7 12h10"/><rect x="3" y="3" width="18" height="18" rx="4"/></svg>',
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 6H6"/></svg>',
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

@section('content')
<style>
    :root { --brand-deep: #1E2A6B; }
    .c-wrap { max-width: 1160px; margin: 0 auto; padding: 0 24px; }
    .eyebrow { font-size: 12px; font-weight: 800; letter-spacing: .16em; text-transform: uppercase; color: var(--brand);
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

    /* Reveal-on-scroll (progressive; disabled for reduced motion) */
    .reveal { opacity: 0; transform: translateY(18px); transition: opacity .6s ease, transform .6s cubic-bezier(.2,.7,.2,1); }
    .reveal.is-in { opacity: 1; transform: none; }
    @media (prefers-reduced-motion: reduce) { .reveal { opacity: 1 !important; transform: none !important; } }

    /* Keep anchored sections clear of the 66px sticky nav on jump/scrollIntoView. */
    section[id], [id="inscription"] { scroll-margin-top: 86px; }

    /* ── HERO ─────────────────────────────────────────────────────────────── */
    .hero { position: relative; overflow: hidden; padding: 72px 0 48px; }
    .hero::before { content: ""; position: absolute; inset: -20% 30% auto -10%; height: 620px;
        background: radial-gradient(50% 50% at 50% 40%, color-mix(in srgb, var(--brand) 22%, transparent), transparent 70%);
        filter: blur(10px); z-index: 0; pointer-events: none; }
    .hero::after { content: ""; position: absolute; inset: 8% -12% auto auto; width: 420px; height: 420px;
        background: radial-gradient(50% 50% at 50% 50%, color-mix(in srgb, var(--accent) 16%, transparent), transparent 70%);
        z-index: 0; pointer-events: none; }
    .hero-grid { position: relative; z-index: 1; display: grid; grid-template-columns: 1.02fr .98fr; gap: 48px; align-items: center; }
    .hero h1 { font-size: clamp(36px, 5.4vw, 60px); line-height: 1.02; letter-spacing: -.035em; font-weight: 800; margin: 18px 0 18px; text-wrap: balance; }
    .hero h1 .hl { color: var(--brand); position: relative; white-space: nowrap; }
    .hero p.lead { font-size: 18.5px; color: var(--muted); max-width: 42ch; line-height: 1.6; }
    .hero-cta { display: flex; gap: 12px; margin-top: 30px; flex-wrap: wrap; align-items: center; }
    .btn-demo { background: color-mix(in srgb, var(--brand) 10%, transparent); border-color: color-mix(in srgb, var(--brand) 35%, transparent); color: var(--brand); }
    .btn-demo:hover { background: color-mix(in srgb, var(--brand) 16%, transparent); border-color: var(--brand); }
    .demo-note { display: inline-flex; align-items: center; gap: 8px; margin-top: 14px; font-size: 13.5px; font-weight: 600; color: var(--muted); }
    .demo-note svg { width: 15px; height: 15px; color: var(--accent); }
    .demo-creds { margin-top: 8px; font-size: 13px; color: var(--muted); }
    .demo-creds code { background: color-mix(in srgb, var(--brand) 10%, transparent); color: var(--brand); padding: 2px 8px; border-radius: 6px; font-weight: 700; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
    .hero-badges { display: flex; gap: 18px; margin-top: 22px; flex-wrap: wrap; }
    .hero-badges span { display: inline-flex; align-items: center; gap: 7px; font-size: 13.5px; font-weight: 600; color: var(--muted); }
    .hero-badges svg { width: 16px; height: 16px; color: var(--brand); }

    /* Device mockup: a tablet running the POS */
    .stage { position: relative; }
    .tablet { position: relative; background: var(--surface); border: 1px solid var(--sand); border-radius: 22px;
              box-shadow: var(--shadow-lg); padding: 14px; z-index: 2; }
    .tablet-bar { display: flex; align-items: center; gap: 7px; padding: 4px 6px 12px; }
    .tablet-bar i { width: 9px; height: 9px; border-radius: 50%; background: var(--sand); display: inline-block; }
    .tablet-bar .addr { margin-left: 8px; font-size: 11px; color: var(--muted); font-variant-numeric: tabular-nums; }
    .pos { display: grid; grid-template-columns: 1fr 128px; gap: 10px; }
    .pos-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
    .pcard { background: var(--paper); border: 1px solid var(--sand); border-radius: 10px; padding: 9px; }
    .pcard .thumb { height: 30px; border-radius: 6px; background: color-mix(in srgb, var(--brand) 12%, transparent); margin-bottom: 7px; }
    .pcard .l1 { height: 6px; width: 82%; background: color-mix(in srgb, var(--ink) 16%, transparent); border-radius: 3px; }
    .pcard .l2 { height: 6px; width: 52%; background: color-mix(in srgb, var(--ink) 9%, transparent); border-radius: 3px; margin-top: 5px; }
    .pcard .price { margin-top: 8px; font-size: 11px; font-weight: 800; color: var(--brand); }
    .pcart { background: var(--paper); border: 1px solid var(--sand); border-radius: 10px; padding: 10px; display: flex; flex-direction: column; }
    .pcart h4 { font-size: 10px; text-transform: uppercase; letter-spacing: .08em; color: var(--muted); margin-bottom: 8px; }
    .pcart .row { display: flex; justify-content: space-between; font-size: 10.5px; color: var(--ink); padding: 4px 0; font-variant-numeric: tabular-nums; }
    .pcart .tot { margin-top: auto; padding-top: 8px; border-top: 1px dashed var(--sand); display: flex; justify-content: space-between; font-weight: 800; font-size: 12px; }
    .pcart .pay { margin-top: 8px; background: var(--brand); color: #fff; border-radius: 7px; text-align: center; font-size: 10.5px; font-weight: 700; padding: 7px; }

    /* Floating phone overlapping the tablet */
    .phone { position: absolute; right: -18px; bottom: -26px; width: 132px; background: var(--ink); border-radius: 20px; padding: 7px;
             box-shadow: var(--shadow-lg); z-index: 3; }
    .phone .scr { background: var(--surface); border-radius: 14px; overflow: hidden; }
    .phone .top { height: 26px; background: var(--brand); }
    .phone .body { padding: 8px; display: flex; flex-direction: column; gap: 6px; }
    .phone .li { height: 24px; border-radius: 6px; background: var(--paper); border: 1px solid var(--sand); }
    .phone .nav { height: 22px; background: var(--paper); border-top: 1px solid var(--sand); display: flex; }
    .phone .nav b { flex: 1; margin: 6px 5px; height: 4px; border-radius: 2px; background: var(--sand); }
    .phone .nav b:first-child { background: var(--brand); }
    .float-chip { position: absolute; z-index: 4; background: var(--surface); border: 1px solid var(--sand); border-radius: 12px;
                  box-shadow: var(--shadow); padding: 9px 12px; display: flex; align-items: center; gap: 9px; font-size: 12.5px; font-weight: 700; }
    .float-chip svg { width: 16px; height: 16px; }
    .chip-offline { top: 6px; left: -22px; color: var(--ok); }
    .chip-sync { bottom: 44px; left: -30px; color: var(--brand); }

    /* Play Store / store buttons */
    .store-btns { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }
    /* Polished Google Play badge — dark, official two-line layout. Fixed dark
       (never --ink, which flips light in dark mode); height matches the CTA. */
    .store-btn { position: relative; display: inline-flex; align-items: center; gap: 12px; height: 56px; padding: 0 22px 0 18px;
                 background: #0C1020; color: #fff; border-radius: 14px; border: 1px solid rgba(255,255,255,.16);
                 text-decoration: none; box-shadow: var(--shadow);
                 transition: transform .14s ease, box-shadow .2s ease, border-color .2s ease; }
    .store-btn:hover { transform: translateY(-2px); border-color: rgba(255,255,255,.34); box-shadow: var(--shadow-lg); }
    .store-btn svg { width: 24px; height: 24px; flex-shrink: 0; }
    .store-btn .st-text { display: flex; flex-direction: column; line-height: 1.12; }
    .store-btn .st-small { font-size: 10px; letter-spacing: .09em; text-transform: uppercase; color: #aeb6cc; }
    .store-btn .st-big { font-size: 17px; font-weight: 800; letter-spacing: -.01em; }
    .store-btn.is-soon { cursor: default; }
    .store-btn .st-ribbon { position: absolute; top: -9px; right: -8px; background: var(--accent); color: #3a2400;
                            font-size: 10px; font-weight: 800; letter-spacing: .02em; padding: 3px 8px; border-radius: 999px;
                            box-shadow: 0 4px 12px rgba(245,158,11,.4); }
    /* White variant on the indigo app band */
    .app .store-btn { background: #fff; color: #0C1020; border: none; }
    .app .store-btn .st-small { color: #5C6270; }
    /* Align the hero CTA + badge heights */
    .hero-cta .btn, .app .store-btns .btn { height: 56px; }

    /* ── Trust strip ──────────────────────────────────────────────────────── */
    .trust { border-top: 1px solid var(--sand); border-bottom: 1px solid var(--sand); margin-top: 40px; }
    .trust-inner { display: flex; flex-wrap: wrap; gap: 12px 30px; padding: 18px 0; color: var(--muted); font-size: 14px; font-weight: 650; }
    .trust-inner span { display: inline-flex; align-items: center; gap: 8px; }
    .trust-inner svg { width: 17px; height: 17px; color: var(--brand); }

    /* ── Sections ─────────────────────────────────────────────────────────── */
    .section { padding: 76px 0 4px; }
    .section-head { max-width: 56ch; margin-bottom: 40px; }
    .section-head h2 { font-size: clamp(28px, 3.6vw, 38px); letter-spacing: -.025em; font-weight: 800; margin: 12px 0 10px; text-wrap: balance; }
    .section-head p { color: var(--muted); font-size: 16.5px; line-height: 1.6; }

    /* Advantages band */
    .adv { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; }
    .adv-card { background: var(--surface); border: 1px solid var(--sand); border-radius: 16px; padding: 22px; box-shadow: var(--shadow); }
    .adv-card .n { font-size: 30px; font-weight: 800; letter-spacing: -.03em; color: var(--brand); line-height: 1; }
    .adv-card .t { font-weight: 750; margin: 10px 0 4px; font-size: 15.5px; }
    .adv-card .d { color: var(--muted); font-size: 13.5px; line-height: 1.5; }

    /* Features */
    .feats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; }
    .feat { background: var(--surface); border: 1px solid var(--sand); border-radius: 18px; padding: 24px; box-shadow: var(--shadow);
            transition: transform .16s ease, box-shadow .2s ease, border-color .2s ease; }
    .feat:hover { transform: translateY(-3px); box-shadow: var(--shadow-lg); border-color: color-mix(in srgb, var(--brand) 35%, var(--sand)); }
    .feat .ico { width: 46px; height: 46px; border-radius: 13px; display: grid; place-items: center; margin-bottom: 16px;
                 background: color-mix(in srgb, var(--brand) 10%, transparent); color: var(--brand); }
    .feat .ico svg { width: 23px; height: 23px; }
    .feat h3 { font-size: 17px; font-weight: 750; margin-bottom: 7px; }
    .feat p { color: var(--muted); font-size: 14.5px; line-height: 1.55; }

    /* App download section */
    .app { position: relative; overflow: hidden; margin-top: 84px; border-radius: 28px;
           background: linear-gradient(150deg, var(--brand), var(--brand-deep)); color: #fff; }
    .app-inner { display: grid; grid-template-columns: 1.1fr .9fr; gap: 36px; align-items: center; padding: 48px 44px; }
    .app h2 { font-size: clamp(26px, 3.4vw, 36px); font-weight: 800; letter-spacing: -.02em; line-height: 1.1; text-wrap: balance; }
    .app p { color: rgba(255,255,255,.85); font-size: 16px; margin: 14px 0 24px; max-width: 44ch; line-height: 1.6; }
    .app-list { list-style: none; display: flex; flex-direction: column; gap: 10px; margin-top: 20px; }
    .app-list li { display: flex; gap: 10px; align-items: center; font-size: 14.5px; color: rgba(255,255,255,.9); }
    .app-list svg { width: 18px; height: 18px; color: #fff; flex-shrink: 0; }
    .app-visual { display: flex; justify-content: center; }
    .app-phone { width: 220px; background: #0b1020; border-radius: 30px; padding: 10px; box-shadow: 0 30px 70px rgba(0,0,0,.4); }
    .app-phone .scr { background: var(--paper); border-radius: 22px; overflow: hidden; }
    .app-phone .top { height: 42px; background: var(--brand); display: flex; align-items: center; padding: 0 14px; color: #fff; font-weight: 800; font-size: 13px; }
    .app-phone .content { padding: 12px; display: flex; flex-direction: column; gap: 8px; }
    .app-phone .card { background: #fff; border: 1px solid var(--sand); border-radius: 10px; padding: 9px; display: flex; align-items: center; gap: 9px; }
    .app-phone .card .sq { width: 26px; height: 26px; border-radius: 7px; background: color-mix(in srgb, var(--brand) 14%, transparent); }
    .app-phone .card .ln { flex: 1; }
    /* Fixed greys — the card is always white, so never key off --ink (flips light in dark mode). */
    .app-phone .card .ln i { display: block; height: 6px; border-radius: 3px; background: #cbd1e0; }
    .app-phone .card .ln i + i { width: 55%; margin-top: 5px; background: #e0e4ee; }
    .app-phone .card .pr { font-size: 11px; font-weight: 800; color: var(--brand); }

    /* Sectors */
    .sectors { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; }
    .sector { background: var(--surface); border: 1px solid var(--sand); border-radius: 16px; padding: 22px; box-shadow: var(--shadow); }
    .sector .ico { width: 42px; height: 42px; border-radius: 12px; display: grid; place-items: center; color: var(--brand);
                   background: color-mix(in srgb, var(--brand) 9%, transparent); }
    .sector .ico svg { width: 22px; height: 22px; }
    .sector h3 { font-size: 15.5px; font-weight: 750; margin: 12px 0 6px; }
    .sector p { color: var(--muted); font-size: 13.5px; line-height: 1.5; }

    /* FAQ */
    .faq { max-width: 780px; }
    .faq details { border: 1px solid var(--sand); border-radius: 14px; background: var(--surface); margin-bottom: 10px; box-shadow: var(--shadow); }
    .faq summary { cursor: pointer; padding: 17px 20px; font-weight: 750; font-size: 15.5px; list-style: none; display: flex; justify-content: space-between; gap: 12px; }
    .faq summary::-webkit-details-marker { display: none; }
    .faq summary::after { content: '+'; color: var(--brand); font-weight: 800; font-size: 20px; line-height: 1; }
    .faq details[open] summary::after { content: '−'; }
    .faq details p { padding: 0 20px 18px; color: var(--muted); font-size: 14.5px; line-height: 1.6; }

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
    .hp { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
          overflow: hidden; clip: rect(0 0 0 0); clip-path: inset(50%);
          white-space: nowrap; border: 0; }

    @media (max-width: 980px) {
        .hero-grid { grid-template-columns: 1fr; gap: 40px; }
        .stage { max-width: 460px; margin: 0 auto; }
        .adv, .sectors { grid-template-columns: 1fr 1fr; }
        .feats { grid-template-columns: 1fr 1fr; }
        .app-inner { grid-template-columns: 1fr; text-align: center; }
        .app p { margin-inline: auto; }
        .app .store-btns, .app-list { align-items: center; justify-content: center; }
        .app-list li { justify-content: center; }
        .signup-card { grid-template-columns: 1fr; }
        .signup-aside { display: none; }
    }
    @media (max-width: 560px) {
        .adv, .sectors, .feats { grid-template-columns: 1fr; }
        .app-inner { padding: 34px 22px; }
    }
</style>

@php
    // Google Play badge (reused in hero + app section). Coming-soon shows a
    // "Bientôt" ribbon; the .app context restyles it white via CSS.
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
@endphp

<header class="hero">
    <div class="c-wrap hero-grid">
        <div class="reveal">
            <span class="eyebrow">{{ __('castlit.hero_eyebrow') }}</span>
            <h1>{{ __('castlit.hero_title_pre') }} <span class="hl">Castl-it-POS</span>.</h1>
            <p class="lead">{{ __('castlit.hero_lead') }}</p>
            @php
                $demoUrl = config('castlit.demo.url');
                $demoCreds = null;
                if ($demoUrl) {
                    $host = parse_url($demoUrl, PHP_URL_HOST) ?? '';
                    $suffix = '.'.config('castlit.main_domain');
                    $sub = \Illuminate\Support\Str::endsWith($host, $suffix)
                        ? substr($host, 0, -strlen($suffix)) : 'demo';
                    $da = config('castlit.provision.default_admin', []);
                    $demoCreds = [
                        'email'    => str_replace('{sub}', $sub ?: 'demo', $da['email_pattern'] ?? 'admin@{sub}.com'),
                        'password' => $da['password'] ?? 'admin',
                    ];
                }
            @endphp
            <div class="hero-cta">
                <a href="#inscription" class="btn btn-primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                    {{ __('castlit.cta_create') }}
                </a>
                @if ($demoUrl)
                    <a href="{{ $demoUrl }}" target="_blank" rel="noopener" class="btn btn-ghost btn-demo">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                        {{ __('castlit.cta_demo') }}
                    </a>
                @endif
                {!! $storeButton() !!}
            </div>
            @if ($demoUrl)
                <p class="demo-note">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                    {{ __('castlit.demo_note') }}
                </p>
                @if ($demoCreds)
                    <p class="demo-creds">{{ __('castlit.demo_creds') }} <code dir="ltr">{{ $demoCreds['email'] }}</code> / <code dir="ltr">{{ $demoCreds['password'] }}</code></p>
                @endif
            @endif
            <div class="hero-badges">
                <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M20 6 9 17l-5-5"/></svg> {{ __('castlit.badge_no_commitment') }}</span>
                <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M20 6 9 17l-5-5"/></svg> {{ __('castlit.badge_ready') }}</span>
                <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M20 6 9 17l-5-5"/></svg> {{ __('castlit.badge_support') }}</span>
            </div>
        </div>

        <div class="stage reveal">
            <div class="float-chip chip-offline"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M20 6 9 17l-5-5"/></svg> {{ __('castlit.device_offline') }}</div>
            <div class="float-chip chip-sync"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/></svg> {{ __('castlit.device_sync') }}</div>
            <div class="tablet" role="img" aria-label="Castl-it-POS">
                <div class="tablet-bar"><i></i><i></i><i></i><span class="addr">almanara.castlitpos.com/caisse</span></div>
                <div class="pos">
                    <div class="pos-grid">
                        @for ($i = 0; $i < 6; $i++)
                            <div class="pcard"><div class="thumb"></div><div class="l1"></div><div class="l2"></div><div class="price">{{ [12,40,6,48,25,18][$i] }},00 DH</div></div>
                        @endfor
                    </div>
                    <div class="pcart">
                        <h4>{{ __('castlit.device_cart') }}</h4>
                        <div class="row"><span>Cahier 96p</span><span>24,00</span></div>
                        <div class="row"><span>Stylo bleu</span><span>6,50</span></div>
                        <div class="row"><span>Roman</span><span>48,00</span></div>
                        <div class="tot"><span>Total</span><span>78,50</span></div>
                        <div class="pay">{{ __('castlit.device_pay') }}</div>
                    </div>
                </div>
            </div>
            <div class="phone" aria-hidden="true">
                <div class="scr"><div class="top"></div><div class="body"><div class="li"></div><div class="li"></div><div class="li"></div></div><div class="nav"><b></b><b></b><b></b><b></b></div></div>
            </div>
        </div>
    </div>
</header>

<div class="trust">
    <div class="c-wrap trust-inner">
        <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg> {{ __('castlit.trust_books') }}</span>
        <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8h1a3 3 0 0 1 0 6h-1M2 8h16v6a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8Z"/></svg> {{ __('castlit.trust_food') }}</span>
        <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3v18M3 12h18" /></svg> {{ __('castlit.trust_pharma') }}</span>
        <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 6H6"/></svg> {{ __('castlit.trust_retail') }}</span>
    </div>
</div>

<section class="section" id="avantages" aria-labelledby="avantages-title">
    <div class="c-wrap">
        <div class="section-head reveal">
            <span class="eyebrow">{{ __('castlit.adv_eyebrow') }}</span>
            <h2 id="avantages-title">{{ __('castlit.adv_title') }}</h2>
            <p>{{ __('castlit.adv_sub') }}</p>
        </div>
        <div class="adv">
            @foreach ((array) __('castlit.adv') as $a)
                <div class="adv-card reveal"><div class="n">{{ $a['n'] }}</div><div class="t">{{ $a['t'] }}</div><div class="d">{{ $a['d'] }}</div></div>
            @endforeach
        </div>
    </div>
</section>

<section class="section" id="fonctionnalites" aria-labelledby="fonctionnalites-title">
    <div class="c-wrap">
        <div class="section-head reveal">
            <span class="eyebrow">{{ __('castlit.feat_eyebrow') }}</span>
            <h2 id="fonctionnalites-title">{{ __('castlit.feat_title') }}</h2>
            <p>{{ __('castlit.feat_sub') }}</p>
        </div>
        <div class="feats">
            @foreach ((array) __('castlit.features') as $i => $f)
                <article class="feat reveal">
                    <div class="ico">{!! $featureIcons[$i] ?? '' !!}</div>
                    <h3>{{ $f['t'] }}</h3>
                    <p>{{ $f['d'] }}</p>
                </article>
            @endforeach
        </div>
    </div>
</section>

<section class="section" aria-labelledby="app-title">
    <div class="c-wrap">
        <div class="app reveal">
            <div class="app-inner">
                <div>
                    <span class="eyebrow" style="color:#cfe0ff">{{ __('castlit.app_eyebrow') }}</span>
                    <h2 id="app-title">{{ __('castlit.app_title') }}</h2>
                    <p>{{ __('castlit.app_sub') }}</p>
                    <div class="store-btns">
                        {!! $storeButton() !!}
                        <a href="#inscription" class="btn btn-ghost" style="background:rgba(255,255,255,.1);color:#fff;border-color:rgba(255,255,255,.25)">{{ __('castlit.app_web') }}</a>
                    </div>
                    <ul class="app-list">
                        @foreach ((array) __('castlit.app_list') as $li)
                            <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M20 6 9 17l-5-5"/></svg> {{ $li }}</li>
                        @endforeach
                    </ul>
                </div>
                <div class="app-visual" aria-hidden="true">
                    <div class="app-phone">
                        <div class="scr">
                            <div class="top">Castl-it-POS</div>
                            <div class="content">
                                @foreach (['Cahier 96p'=>'12,00','Stylo bleu'=>'6,50','Roman — Le Pain nu'=>'48,00','Agenda scolaire'=>'50,00'] as $n=>$p)
                                    <div class="card"><span class="sq"></span><span class="ln"><i></i><i></i></span><span class="pr">{{ $p }}</span></div>
                                @endforeach
                            </div>
                        </div>
                    </div>
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
        <div class="sectors">
            @foreach ((array) __('castlit.sectors') as $i => $s)
                <article class="sector reveal"><div class="ico">{!! $sectorIcons[$i] ?? '' !!}</div><h3>{{ $s['t'] }}</h3><p>{{ $s['d'] }}</p></article>
            @endforeach
        </div>
    </div>
</section>

<section class="section" id="faq" aria-labelledby="faq-title">
    <div class="c-wrap">
        <div class="section-head reveal">
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
                                @foreach ($currencies as $c)
                                    <option value="{{ $c }}" @selected(old('currency', 'MAD') === $c)>{{ $c }}</option>
                                @endforeach
                            </select>
                            @error('currency')<span class="field-error">{{ $message }}</span>@enderror
                        </div>
                    </div>

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
                            <input id="phone" name="phone" maxlength="40" value="{{ old('phone') }}" placeholder="06 00 00 00 00">
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
