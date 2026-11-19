@php
    $theme = data_get($tenant->settings, 'theme', []);
    $primary = $theme['primary'] ?? '#3157D5';
    $logo = data_get($tenant->settings, 'company.logo_path');
    $locale = app()->getLocale();
    $dir = \App\Support\Locale::dir($locale);
    $tz = \App\Support\Appointments::timezone($tenant);
    $oldDay = old('date');
    $oldTime = old('time');
    $initials = fn (string $name) => \Illuminate\Support\Str::upper(collect(preg_split('/\s+/u', trim($name)))->filter()->take(2)->map(fn ($w) => \Illuminate\Support\Str::substr($w, 0, 1))->implode(''));
    $minutesLabel = fn (array $c) => $c['minutes_min'] === $c['minutes_max']
        ? __('booking.minutes', ['count' => $c['minutes_min']])
        : __('booking.minutes_range', ['min' => $c['minutes_min'], 'max' => $c['minutes_max']]);
    $scriptText = [
        'loading' => __('booking.loading'),
        'none' => __('booking.no_slots'),
        'error' => __('booking.slots_error'),
        'pickDay' => __('booking.pick_day_first'),
        'empty' => __('booking.summary_empty'),
        'noResult' => __('booking.no_result', ['q' => ':q']),
        'results' => __('booking.results', ['count' => ':count']),
        'nextService' => __('booking.next_service'),
        'nextOption' => __('booking.next_option'),
        'nextDay' => __('booking.next_day'),
        'nextTime' => __('booking.next_time'),
        'nextYou' => __('booking.next_you'),
        'submit' => __('booking.submit'),
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $dir }}" data-phone-country="{{ \App\Support\Phone::defaultCountry($tenant) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>{{ __('booking.title') }} · {{ $tenant->name }}</title>
    @vite(['resources/js/phone.js'])
    <style>
        :root {
            --brand: {{ $primary }};
            --bg: #f5f6fa; --surface: #fff; --line: #e3e7ef; --text: #0f172a; --muted: #5d6b82;
            --soft: color-mix(in srgb, var(--brand) 9%, #fff);
            --ph: color-mix(in srgb, var(--brand) 14%, #fff);
            --danger: #be123c; --wa: #128c4a;
            --radius: 16px;
        }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #0b1120; --surface: #121a2c; --line: rgba(255,255,255,.1); --text: #e8ecf5; --muted: #a3b0c7;
                --soft: color-mix(in srgb, var(--brand) 24%, #121a2c); --ph: color-mix(in srgb, var(--brand) 30%, #121a2c);
                --danger: #fb7185; --wa: #34d399; }
        }
        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body { margin: 0; background: var(--bg); color: var(--text);
            font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, "Noto Sans Arabic", sans-serif; overflow-x: hidden; }
        .wrap { max-width: 60rem; margin-inline: auto; padding: 16px 16px 150px; }
        :focus-visible { outline: 3px solid var(--brand); outline-offset: 2px; }
        .hint { color: var(--muted); font-size: .9rem; margin: 0; }

        /* Shop header */
        .hero { background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); padding: 16px; margin-bottom: 12px; }
        .hero-top { display: flex; align-items: center; gap: 14px; }
        .mark { width: 60px; height: 60px; flex: none; border-radius: 16px; overflow: hidden; display: grid; place-items: center;
            background: var(--brand); color: #fff; font-weight: 800; font-size: 24px; }
        .mark img { width: 100%; height: 100%; object-fit: cover; }
        h1 { margin: 0; font-size: 1.35rem; line-height: 1.25; text-wrap: balance; overflow-wrap: anywhere; }
        .tagline { margin: 2px 0 0; color: var(--muted); font-size: .92rem; }
        .info { margin: 14px 0 0; padding: 0; list-style: none; display: grid; gap: 6px; font-size: .92rem; }
        .info li { display: flex; gap: 8px; align-items: flex-start; }
        .info svg { flex: none; width: 18px; height: 18px; margin-top: 3px; color: var(--muted); }
        .hours summary { cursor: pointer; list-style: none; min-height: 28px; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
        .hours summary::-webkit-details-marker { display: none; }
        .hours summary::after { content: "▾"; color: var(--muted); }
        .hours[open] summary::after { content: "▴"; }
        .hours ul { list-style: none; margin: 6px 0 0; padding: 0; display: grid; gap: 2px; max-width: 18rem; }
        .hours li { display: flex; justify-content: space-between; gap: 16px; padding: 2px 8px; border-radius: 8px; }
        .hours li.today { background: var(--soft); font-weight: 700; }
        .hours li span:last-child { font-variant-numeric: tabular-nums; direction: ltr; }
        .open-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #16a34a; }
        .open-dot.off { background: var(--danger); }
        .cta { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 14px; }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 44px; padding: 0 16px; border-radius: 12px;
            border: 1.5px solid var(--line); background: var(--surface); color: var(--text); font: inherit; font-weight: 600; text-decoration: none; cursor: pointer; }
        .btn svg { width: 18px; height: 18px; }
        .btn.wa { background: var(--wa); border-color: var(--wa); color: #fff; }
        @media (prefers-color-scheme: dark) { .btn.wa { color: #04281a; } }
        .intro { color: var(--muted); margin: 4px 0 14px; font-size: .95rem; }

        /* Steps */
        section.step { background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); padding: 16px; margin-bottom: 12px; scroll-margin-top: 12px; }
        .step-h { display: flex; align-items: center; gap: 10px; margin: 0 0 12px; font-size: 1rem; }
        .step-h .n { flex: none; width: 26px; height: 26px; border-radius: 50%; display: grid; place-items: center; background: var(--brand); color: #fff; font-size: .8rem; font-weight: 800; }
        .step-h.plain { font-size: 1rem; }

        .search { position: relative; margin-bottom: 10px; }
        .search svg { position: absolute; inset-inline-start: 14px; top: 50%; transform: translateY(-50%); width: 20px; height: 20px; color: var(--muted); pointer-events: none; }
        .search input { width: 100%; min-height: 48px; font: inherit; color: inherit; background: var(--bg); border: 1.5px solid var(--line); border-radius: 14px; padding-block: 10px; padding-inline: 44px 14px; }
        .search input:focus { outline: none; border-color: var(--brand); box-shadow: 0 0 0 3px color-mix(in srgb, var(--brand) 25%, transparent); }
        .chips { display: flex; gap: 8px; overflow-x: auto; padding: 2px 0 10px; margin-bottom: 4px; scrollbar-width: none; scroll-snap-type: x proximity; }
        .chips::-webkit-scrollbar { display: none; }
        .chip { flex: none; min-height: 44px; padding: 0 16px; border-radius: 999px; border: 1.5px solid var(--line); background: var(--surface); color: var(--text);
            font: inherit; font-weight: 600; cursor: pointer; scroll-snap-align: start; white-space: nowrap; }
        .chip[aria-pressed="true"] { background: var(--brand); border-color: var(--brand); color: #fff; }

        .group h3 { margin: 14px 0 8px; font-size: .78rem; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); }
        .group:first-child h3 { margin-top: 4px; }
        .grid { display: grid; gap: 8px; }
        .svc { position: relative; display: grid; grid-template-columns: 76px 1fr; gap: 12px; align-items: center; min-height: 92px; padding: 8px;
            border: 1.5px solid var(--line); border-radius: 14px; cursor: pointer; background: var(--surface); }
        .svc input, .opt input, .day input, .slot input, .person input { position: absolute; opacity: 0; pointer-events: none; }
        .svc:has(input:checked), .opt:has(input:checked), .day:has(input:checked), .slot:has(input:checked), .person:has(input:checked) { border-color: var(--brand); background: var(--soft); }
        .svc:has(input:focus-visible), .opt:has(input:focus-visible), .day:has(input:focus-visible), .slot:has(input:focus-visible), .person:has(input:focus-visible) { outline: 3px solid var(--brand); outline-offset: 2px; }
        .thumb { width: 76px; height: 76px; border-radius: 10px; overflow: hidden; background: var(--ph); display: grid; place-items: center; color: var(--brand); font-weight: 800; font-size: 1.3rem; }
        .thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .svc-body { min-width: 0; padding-inline-end: 28px; }
        .svc-body b { display: block; font-weight: 650; line-height: 1.3; overflow-wrap: anywhere; }
        .meta { display: block; margin-top: 2px; color: var(--muted); font-size: .85rem; }
        .meta .price { color: var(--text); white-space: nowrap; font-weight: 700; font-variant-numeric: tabular-nums; }
        .chosen { display: block; margin-top: 2px; font-size: .85rem; font-weight: 600; color: var(--brand); }
        .check { position: absolute; inset-block-start: 8px; inset-inline-end: 8px; width: 22px; height: 22px; border-radius: 50%; background: var(--brand); color: #fff;
            display: none; place-items: center; font-size: .8rem; font-weight: 800; }
        .svc:has(input:checked) .check, .opt:has(input:checked) .check { display: grid; }
        .nores { text-align: center; padding: 24px 8px; color: var(--muted); }
        .nores b { display: block; color: var(--text); margin-bottom: 4px; }

        /* Variant sheet */
        dialog.sheet { border: 0; padding: 0; background: var(--surface); color: var(--text); width: 100%; max-width: 32rem; max-height: 85vh; max-height: 85dvh;
            margin: auto auto 0; border-radius: 22px 22px 0 0; overflow: auto; }
        dialog.sheet::backdrop { background: rgba(8, 12, 24, .55); }
        .sheet-in { padding: 8px 16px 16px; }
        .sheet-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; position: sticky; top: 0; background: var(--surface); padding: 12px 0 8px; }
        .sheet-head h3 { margin: 0; font-size: 1.1rem; overflow-wrap: anywhere; }
        .x { flex: none; width: 44px; height: 44px; border-radius: 50%; border: 0; background: var(--bg); color: var(--text); font-size: 1.4rem; cursor: pointer; }
        .opts { display: grid; gap: 8px; margin-top: 10px; }
        .opt { position: relative; display: flex; align-items: center; justify-content: space-between; gap: 12px; min-height: 60px; padding: 10px 44px 10px 14px;
            padding-inline: 14px 44px; border: 1.5px solid var(--line); border-radius: 14px; cursor: pointer; }
        .opt b { font-weight: 650; }
        .opt small { display: block; color: var(--muted); font-size: .85rem; }
        .opt .price { font-weight: 700; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .opt .check { inset-block-start: 50%; transform: translateY(-50%); }

        /* Staff, days, times */
        .people { display: grid; grid-template-columns: repeat(auto-fill, minmax(11rem, 1fr)); gap: 8px; }
        .person { position: relative; display: flex; align-items: center; gap: 10px; min-height: 60px; padding: 8px 12px; border: 1.5px solid var(--line); border-radius: 14px; cursor: pointer; }
        .person[hidden] { display: none; }
        .avatar { flex: none; width: 40px; height: 40px; border-radius: 50%; background: var(--ph); color: var(--brand); display: grid; place-items: center; font-weight: 800; font-size: .95rem; }
        .person b { font-weight: 650; line-height: 1.25; overflow-wrap: break-word; }
        .person small { display: block; color: var(--muted); font-size: .78rem; line-height: 1.3; }
        .days { display: flex; gap: 8px; overflow-x: auto; padding: 2px 2px 8px; scroll-snap-type: x proximity; scrollbar-width: thin; }
        .day { position: relative; flex: none; width: 4.4rem; min-height: 84px; text-align: center; padding: 8px 4px; scroll-snap-align: start; display: flex; flex-direction: column; justify-content: center;
            border: 1.5px solid var(--line); border-radius: 14px; cursor: pointer; }
        .day span { display: block; font-size: .72rem; text-transform: uppercase; color: var(--muted); }
        .day b { font-size: 1.3rem; font-variant-numeric: tabular-nums; line-height: 1.2; }
        .day.off { opacity: .45; cursor: not-allowed; background: transparent; border-style: dashed; }
        .day.off b { text-decoration: line-through; }
        .slots { display: grid; grid-template-columns: repeat(auto-fill, minmax(5rem, 1fr)); gap: 8px; }
        .slot { position: relative; display: flex; align-items: center; justify-content: center; min-height: 48px; border: 1.5px solid var(--line); border-radius: 12px;
            cursor: pointer; font-variant-numeric: tabular-nums; font-weight: 700; direction: ltr; }
        .slot:has(input:checked) { background: var(--brand); border-color: var(--brand); color: #fff; }

        /* Contact */
        label.field { display: block; margin-bottom: 12px; }
        label.field span { display: block; font-size: .85rem; font-weight: 600; margin-bottom: 4px; }
        input[type=text], input[type=tel], input[type=time], textarea { width: 100%; min-height: 48px; font: inherit; color: inherit; background: var(--surface);
            border: 1.5px solid var(--line); border-radius: 12px; padding: 10px 12px; }
        input[type=text]:focus, input[type=tel]:focus, textarea:focus { outline: none; border-color: var(--brand); box-shadow: 0 0 0 3px color-mix(in srgb, var(--brand) 25%, transparent); }
        .iti { width: 100%; }
        .error { color: var(--danger); font-size: .92rem; margin: 0 0 12px; padding: 10px 12px; border-radius: 12px; background: color-mix(in srgb, var(--danger) 10%, transparent); }

        /* Sticky summary */
        .bar { position: fixed; inset: auto 0 0 0; z-index: 20; background: var(--surface); border-top: 1px solid var(--line);
            padding: 10px 16px calc(10px + env(safe-area-inset-bottom)); box-shadow: 0 -6px 20px rgba(0,0,0,.06); }
        .bar .in { max-width: 60rem; margin-inline: auto; display: flex; align-items: center; gap: 12px; }
        .bar p { flex: 1; margin: 0; font-size: .9rem; color: var(--muted); min-width: 0; overflow-wrap: anywhere; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .bar p b { color: var(--text); }
        button.go { flex: none; min-height: 48px; border: 0; border-radius: 12px; background: var(--brand); color: #fff; font: inherit; font-weight: 700; padding: 0 20px; cursor: pointer; max-width: 55%; }

        .done { text-align: center; padding: 28px 18px; }
        .done .tick { width: 56px; height: 56px; margin: 0 auto 12px; border-radius: 50%; display: grid; place-items: center; background: var(--soft); color: var(--brand); font-size: 28px; font-weight: 800; }
        .done h2 { font-size: 1.2rem; margin: 0 0 6px; }
        .done .when { font-weight: 700; margin: 12px 0; }
        .done a { color: var(--brand); font-weight: 600; display: inline-block; min-height: 44px; line-height: 44px; }
        .trap { position: absolute; inset-inline-start: -9999px; width: 1px; height: 1px; overflow: hidden; }

        @media (min-width: 720px) {
            .wrap { padding-inline: 24px; }
            .bar { padding-inline: 24px; }
            .hero { padding: 22px; }
            .grid { grid-template-columns: 1fr 1fr; }
            dialog.sheet { margin: auto; border-radius: 22px; }
            .cta { margin-top: 16px; }
        }
        @media (prefers-reduced-motion: no-preference) {
            .svc, .opt, .chip, .day, .slot, .person { transition: border-color .15s, background .15s; }
            dialog.sheet[open] { animation: rise .22s ease-out; }
            @keyframes rise { from { transform: translateY(24px); opacity: 0; } }
        }
        @media (prefers-reduced-motion: reduce) { html { scroll-behavior: auto; } }
    </style>
    <noscript><style>
        .needs-js { display: none !important; }
        dialog.sheet { display: block; position: static; margin: 12px 0 0; max-width: none; border: 1px solid var(--line); border-radius: 14px; }
        .x { display: none; }
    </style></noscript>
</head>
<body>
<div class="wrap">
    <header class="hero">
        <div class="hero-top">
            <div class="mark">
                @if ($logo)
                    <img src="{{ asset('storage/'.$logo) }}" alt="">
                @else
                    {{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($tenant->name, 0, 1)) }}
                @endif
            </div>
            <div style="min-width:0">
                <h1>{{ $tenant->name }}</h1>
                <p class="tagline">{{ __('booking.title') }}</p>
            </div>
        </div>
        <ul class="info">
            @if ($shop['address'])
                <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s7-6.2 7-11a7 7 0 1 0-14 0c0 4.8 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/></svg><span>{{ $shop['address'] }}</span></li>
            @endif
            <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                <details class="hours" data-hours>
                    <summary aria-label="{{ __('booking.hours') }}">
                        <span class="open-dot {{ $shop['today']['hours'] ? '' : 'off' }}" aria-hidden="true"></span>
                        <b>{{ $shop['today']['hours'] ? __('booking.open_today', ['hours' => $shop['today']['hours']]) : __('booking.closed_today') }}</b>
                    </summary>
                    <ul>
                        @foreach ($shop['week'] as $row)
                            <li @class(['today' => $row['today']])><span>{{ $row['day'] }}</span><span>{{ $row['hours'] ?? __('booking.closed') }}</span></li>
                        @endforeach
                    </ul>
                </details>
            </li>
        </ul>
        @if ($shop['tel'])
            <div class="cta">
                <a class="btn wa" href="{{ $shop['whatsapp'] }}" target="_blank" rel="noopener"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm5.2 13.8c-.2.6-1.3 1.2-1.8 1.2-.5.1-1 .2-3.3-.7-2.8-1.2-4.6-4-4.7-4.2-.1-.2-1.1-1.5-1.1-2.8s.7-2 1-2.3c.2-.3.5-.3.7-.3h.5c.2 0 .4 0 .6.5l.8 1.9c.1.1.1.3 0 .5l-.4.6-.3.4c-.1.1-.3.3-.1.6.2.3.7 1.2 1.5 1.9 1 .9 1.900 1.200 2.200 1.300.3.1.4.1.6-.1l.8-1c.2-.3.4-.2.6-.1l1.800.9c.3.1.5.2.5.3.1.2.1.8-.1 1.300z"/></svg>{{ __('booking.whatsapp') }}</a>
                <a class="btn" href="{{ $shop['tel'] }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.100 4.200 2 2 0 0 1 4.100 2h3a2 2 0 0 1 2 1.700c.1 1 .4 1.900.7 2.800a2 2 0 0 1-.5 2.100L8.100 9.900a16 16 0 0 0 6 6l1.300-1.300a2 2 0 0 1 2.100-.4c.9.300 1.800.6 2.800.7a2 2 0 0 1 1.700 2z"/></svg>{{ __('booking.call') }}</a>
            </div>
        @endif
    </header>

    @if ($sent && empty($sent['honeypot']))
        <section class="step done" data-booking-sent>
            <div class="tick">✓</div>
            <h2>{{ __('booking.sent_title') }}</h2>
            <p class="hint">{{ __('booking.sent_body') }}</p>
            <p class="when">{{ $sent['service'] }} · {{ ucfirst($sent['date']) }} · {{ $sent['time'] }}@if ($sent['staff']) · {{ __('booking.sent_with', ['name' => $sent['staff']]) }}@endif</p>
            <a href="{{ route('booking.show') }}">{{ __('booking.sent_again') }}</a>
        </section>
    @elseif ($sent)
        <section class="step done"><div class="tick">✓</div><h2>{{ __('booking.sent_title') }}</h2></section>
    @elseif ($services->isEmpty())
        <section class="step"><p class="hint">{{ __('booking.no_service') }}</p></section>
    @else
        <p class="intro">{{ __('booking.intro') }}</p>
        @if ($errors->any())
            <p class="error" role="alert" data-booking-error>{{ $errors->first() }}</p>
        @endif

        <form method="POST" action="{{ route('booking.store') }}" id="booking" novalidate>
            @csrf
            <div class="trap" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

            <section class="step" id="step-service" aria-labelledby="h-service">
                <h2 class="step-h" id="h-service"><span class="n" aria-hidden="true">1</span>{{ __('booking.step_service') }}</h2>
                <div class="search" role="search">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.500-3.500"/></svg>
                    <input type="search" id="q" data-search-input autocomplete="off" enterkeyhint="search" placeholder="{{ __('booking.search_placeholder') }}" aria-label="{{ __('booking.search_label') }}" class="needs-js">
                </div>
                @if (count($categories) > 0)
                    <div class="chips needs-js" role="group" aria-label="{{ __('booking.categories') }}" data-chips>
                        <button type="button" class="chip" data-chip="" aria-pressed="true">{{ __('booking.all') }}</button>
                        @foreach ($categories as $category)
                            <button type="button" class="chip" data-chip="{{ $category['id'] }}" aria-pressed="false">{{ $category['name'] }}</button>
                        @endforeach
                    </div>
                @endif
                <p class="hint needs-js" id="count" aria-live="polite" hidden></p>

                <div id="catalogue">
                    @foreach (collect($cards)->chunkWhile(fn ($c, $k, $chunk) => $c['category_id'] === $chunk->last()['category_id']) as $group)
                        <div class="group" data-group="{{ $group->first()['category_id'] }}">
                            @if ($group->first()['category'])<h3 data-service-group>{{ $group->first()['category'] }}</h3>@endif
                            <div class="grid">
                                @foreach ($group as $card)
                                    <label class="svc" data-svc data-cat="{{ $card['category_id'] }}" data-title="{{ $card['title'] }}" data-category-name="{{ $card['category'] }}" @if ($card['variants']) data-has-variants @endif>
                                        <span class="thumb">
                                            @if ($card['photo'])
                                                <img src="{{ $card['photo'] }}" alt="" loading="lazy" decoding="async" width="76" height="76">
                                            @else
                                                {{ $initials($card['title']) }}
                                            @endif
                                        </span>
                                        <span class="svc-body">
                                            <input type="radio" name="item_id" value="{{ $card['id'] }}" data-label="{{ $card['title'] }}" @checked((string) old('item_id', count($cards) === 1 ? $card['id'] : '') === (string) $card['id']) required>
                                            <b>{{ $card['title'] }}</b>
                                            <span class="meta">{{ $minutesLabel($card) }} · <span class="price">{{ $card['price'] }}</span></span>
                                            <span class="chosen" data-chosen hidden></span>
                                        </span>
                                        <span class="check" aria-hidden="true">✓</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="nores" id="nores" role="status" hidden><b></b></div>
            </section>

            {{-- A service with several prices or lengths is booked as one of them: a sheet asks which. --}}
            @foreach ($cards as $card)
                @if ($card['variants'])
                    <dialog class="sheet" data-variants-for="{{ $card['id'] }}" aria-labelledby="sheet-{{ $card['id'] }}">
                        <div class="sheet-in">
                            <div class="sheet-head">
                                <h3 id="sheet-{{ $card['id'] }}">{{ $card['title'] }}</h3>
                                <button type="button" class="x" data-close aria-label="{{ __('booking.close') }}">×</button>
                            </div>
                            <p class="hint">{{ __('booking.step_variant') }} · {{ __('booking.choose_option') }}</p>
                            <div class="opts">
                                @foreach ($card['variants'] as $variant)
                                    <label class="opt">
                                        <input type="radio" name="variant_id" value="{{ $variant['id'] }}" data-label="{{ $variant['name'] }}" @checked((string) old('variant_id') === (string) $variant['id'])>
                                        <span><b>{{ $variant['name'] }}</b><small>{{ __('booking.minutes', ['count' => $variant['minutes']]) }}</small></span>
                                        <span class="price">{{ $variant['price'] }}</span>
                                        <span class="check" aria-hidden="true">✓</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    </dialog>
                @endif
            @endforeach

            @if ($staff->count() > 1)
                <section class="step" id="step-staff" aria-labelledby="h-staff">
                    <h2 class="step-h" id="h-staff"><span class="n" aria-hidden="true">2</span>{{ __('booking.step_staff') }}</h2>
                    <div class="people">
                        <label class="person">
                            <input type="radio" name="staff_user_id" value="" data-label="" @checked(! old('staff_user_id'))>
                            <span class="avatar" aria-hidden="true">★</span>
                            <span><b>{{ __('booking.any_staff') }}</b><small>{{ __('booking.any_staff_hint') }}</small></span>
                        </label>
                        @foreach ($staff as $member)
                            <label class="person" data-staff-choice="{{ $member->id }}">
                                <input type="radio" name="staff_user_id" value="{{ $member->id }}" data-label="{{ $member->name }}" @checked((string) old('staff_user_id') === (string) $member->id)>
                                <span class="avatar" aria-hidden="true">{{ $initials($member->name) }}</span>
                                <span><b>{{ $member->name }}</b></span>
                            </label>
                        @endforeach
                    </div>
                </section>
            @elseif ($staff->count() === 1)
                <input type="hidden" name="staff_user_id" value="{{ $staff->first()->id }}">
            @endif

            <section class="step" id="step-day" aria-labelledby="h-day">
                <h2 class="step-h" id="h-day"><span class="n" aria-hidden="true">{{ $staff->count() > 1 ? 3 : 2 }}</span>{{ __('booking.step_day') }}</h2>
                <div class="days" data-days>
                    @foreach ($dayStrip as $day)
                        @php $d = \Carbon\CarbonImmutable::parse($day['date'], $tz)->locale($locale); @endphp
                        <label @class(['day', 'off' => ! $day['open']])>
                            <input type="radio" name="date" value="{{ $day['date'] }}" data-label="{{ ucfirst($d->isoFormat('ddd D MMM')) }}" @checked($oldDay === $day['date']) @disabled(! $day['open'])>
                            <span>{{ $d->isoFormat('ddd') }}</span>
                            <b>{{ $d->format('j') }}</b>
                            <span>{{ $day['open'] ? $d->isoFormat('MMM') : __('booking.day_closed') }}</span>
                        </label>
                    @endforeach
                </div>
            </section>

            <section class="step" id="step-time" aria-labelledby="h-time">
                <h2 class="step-h" id="h-time"><span class="n" aria-hidden="true">{{ $staff->count() > 1 ? 4 : 3 }}</span>{{ __('booking.step_time') }}</h2>
                <div class="slots" id="slots" role="radiogroup" aria-labelledby="h-time" aria-live="polite"></div>
                <p class="hint" id="slots-hint">{{ __('booking.pick_day_first') }}</p>
                <noscript><label class="field"><span>{{ __('booking.time_manual') }}</span><input type="time" name="time" step="900"></label></noscript>
            </section>

            <section class="step" id="step-you" aria-labelledby="h-you">
                <h2 class="step-h" id="h-you"><span class="n" aria-hidden="true">{{ $staff->count() > 1 ? 5 : 4 }}</span>{{ __('booking.step_you') }}</h2>
                <label class="field"><span>{{ __('booking.name') }}</span>
                    <input type="text" name="customer_name" value="{{ old('customer_name') }}" autocomplete="name" required maxlength="160"></label>
                <label class="field"><span>{{ __('booking.phone') }}</span>
                    <input type="tel" name="customer_phone" value="{{ old('customer_phone') }}" autocomplete="tel" inputmode="tel" dir="ltr" required></label>
                <label class="field"><span>{{ __('booking.note') }}</span>
                    <textarea name="note" rows="2" maxlength="500">{{ old('note') }}</textarea></label>
            </section>

            <div class="bar">
                <div class="in">
                    <p id="summary" role="status">{{ __('booking.summary_empty') }}</p>
                    <button class="go" type="submit" id="submit">{{ __('booking.submit') }}</button>
                </div>
            </div>
        </form>
    @endif
</div>

@if (! $sent && $services->isNotEmpty())
<script>
(() => {
    const form = document.getElementById('booking');
    const slots = document.getElementById('slots');
    const hint = document.getElementById('slots-hint');
    const summary = document.getElementById('summary');
    const submit = document.getElementById('submit');
    const text = @json($scriptText);
    let wanted = @json($oldTime);
    let request = 0;
    const calm = matchMedia('(prefers-reduced-motion: reduce)').matches;

    const picked = (name) => form.querySelector(`input[name="${name}"]:checked`);
    const pickedVariant = () => form.querySelector('input[name="variant_id"]:checked:not(:disabled)');
    const sheetOf = (id) => form.querySelector(`[data-variants-for="${id}"]`);
    const norm = (s) => s.normalize('NFD').replace(/\p{M}/gu, '').toLowerCase().trim();

    // ── Catalogue: search + category chips ───────────────────────────────────
    const cards = [...form.querySelectorAll('[data-svc]')];
    const groups = [...form.querySelectorAll('[data-group]')];
    const finder = form.querySelector('[data-search-input]');
    const count = document.getElementById('count');
    const nores = document.getElementById('nores');
    const haystack = new Map(cards.map((c) => [c, norm(c.dataset.title + ' ' + (c.dataset.categoryName || ''))]));
    let category = '';
    function filter() {
        const q = norm(finder.value);
        let shown = 0;
        for (const c of cards) {
            const ok = (!category || c.dataset.cat === category) && (!q || haystack.get(c).includes(q));
            c.hidden = !ok;
            if (ok) shown++;
        }
        for (const g of groups) g.hidden = !g.querySelector('[data-svc]:not([hidden])');
        nores.hidden = shown > 0;
        nores.firstElementChild.textContent = text.noResult.replace(':q', finder.value.trim());
        count.hidden = !(q || category);
        count.textContent = text.results.replace(':count', shown);
    }
    finder.addEventListener('input', filter);
    for (const chip of form.querySelectorAll('[data-chip]')) {
        chip.addEventListener('click', () => {
            category = chip.dataset.chip;
            for (const o of form.querySelectorAll('[data-chip]')) o.setAttribute('aria-pressed', String(o === chip));
            chip.scrollIntoView({ inline: 'center', block: 'nearest', behavior: calm ? 'auto' : 'smooth' });
            filter();
        });
    }
    // The search box swallows Enter instead of submitting the booking.
    finder.addEventListener('keydown', (e) => { if (e.key === 'Enter') e.preventDefault(); });

    // ── Variants: a sheet per service that has options ───────────────────────
    function syncVariants() {
        const service = picked('item_id');
        for (const sheet of form.querySelectorAll('[data-variants-for]')) {
            const mine = !!service && sheet.dataset.variantsFor === service.value;
            for (const input of sheet.querySelectorAll('input')) {
                input.disabled = !mine;
                if (!mine) input.checked = false;
            }
        }
        for (const card of cards) {
            const label = card.querySelector('[data-chosen]');
            const v = card.querySelector('input[name="item_id"]').checked ? pickedVariant() : null;
            label.hidden = !v;
            label.textContent = v ? v.dataset.label : '';
        }
    }
    const needsVariant = () => { const s = picked('item_id'); return !!s && !!sheetOf(s.value); };
    function openSheet(id) {
        const sheet = sheetOf(id);
        if (sheet && !sheet.open && sheet.showModal) { sheet.showModal(); sheet.querySelector('input:checked, input')?.focus(); }
    }
    for (const sheet of form.querySelectorAll('dialog.sheet')) {
        sheet.addEventListener('click', (e) => { if (e.target === sheet || e.target.closest('[data-close]')) sheet.close(); });
        sheet.addEventListener('change', () => { if (pickedVariant()) setTimeout(() => sheet.close(), 120); });
    }
    // Each tap on a service with options opens its sheet (even if already chosen).
    for (const input of form.querySelectorAll('input[name="item_id"]')) {
        input.addEventListener('click', () => { if (sheetOf(input.value)) { syncVariants(); openSheet(input.value); } });
    }

    // ── Staff: only those who do the service; a single one is chosen for them
    const doing = @json($serviceStaff);
    const staffStep = document.getElementById('step-staff');
    const anyStaff = form.querySelector('input[name="staff_user_id"][value=""]');
    let autoStaff = false;
    function syncStaff() {
        if (!staffStep) return;
        const service = picked('item_id');
        const allowed = service ? doing[service.value] : null;
        const visible = [];
        for (const label of form.querySelectorAll('[data-staff-choice]')) {
            const show = !allowed || allowed.includes(Number(label.dataset.staffChoice));
            label.hidden = !show;
            if (show) visible.push(label);
            const input = label.querySelector('input');
            if (!show && input.checked) anyStaff.checked = true;
        }
        if (visible.length === 1) {
            visible[0].querySelector('input').checked = true;
            staffStep.hidden = true; autoStaff = true;
        } else {
            if (autoStaff) anyStaff.checked = true;
            staffStep.hidden = false; autoStaff = false;
        }
    }
    const staffValue = () => picked('staff_user_id')?.value ?? form.querySelector('input[type=hidden][name="staff_user_id"]')?.value ?? '';

    // ── Summary bar and « next step » button ─────────────────────────────────
    const go = (el, focus) => {
        el.scrollIntoView({ behavior: calm ? 'auto' : 'smooth', block: 'start' });
        if (focus) setTimeout(() => focus.focus({ preventScroll: true }), calm ? 0 : 350);
    };
    function missing() {
        const service = picked('item_id'), day = picked('date'), time = picked('time');
        const name = form.customer_name.value.trim(), phone = form.customer_phone.value.trim();
        const at = (id) => document.getElementById(id);
        if (!service) return { label: text.nextService, run: () => go(at('step-service'), finder) };
        if (needsVariant() && !pickedVariant()) return { label: text.nextOption, run: () => openSheet(service.value) };
        if (!day) return { label: text.nextDay, run: () => go(at('step-day')) };
        if (!time) return { label: text.nextTime, run: () => go(at('step-time')) };
        if (!name || phone.replace(/\D/g, '').length < 8) return { label: text.nextYou, run: () => go(at('step-you'), name ? form.customer_phone : form.customer_name) };
        return null;
    }
    function refreshSummary() {
        const service = picked('item_id'), day = picked('date'), time = picked('time'), variant = pickedVariant(), who = picked('staff_user_id');
        const parts = [];
        if (service) parts.push(service.dataset.label + (variant ? ' — ' + variant.dataset.label : ''));
        if (who && who.dataset.label) parts.push(who.dataset.label);
        if (day) parts.push(day.dataset.label);
        if (time) parts.push(time.value);
        summary.replaceChildren();
        if (parts.length) {
            const b = document.createElement('b');
            b.textContent = parts.join(' · ');
            summary.append(b);
        } else {
            summary.textContent = text.empty;
        }
        submit.textContent = missing()?.label ?? text.submit;
    }

    async function loadSlots() {
        const day = picked('date');
        slots.replaceChildren();
        if (!day) { hint.textContent = text.pickDay; hint.hidden = false; refreshSummary(); return; }
        hint.textContent = text.loading; hint.hidden = false;
        const mine = ++request;
        try {
            const url = new URL(@json(route('booking.slots')), location.href);
            url.searchParams.set('date', day.value);
            if (staffValue()) url.searchParams.set('staff', staffValue());
            if (picked('item_id')) url.searchParams.set('item', picked('item_id').value);
            if (pickedVariant()) url.searchParams.set('variant', pickedVariant().value);
            const res = await fetch(url, { headers: { Accept: 'application/json' } });
            if (!res.ok) throw new Error(res.status);
            const { slots: times } = await res.json();
            if (mine !== request) return;
            hint.hidden = times.length > 0;
            hint.textContent = text.none;
            for (const t of times) {
                const label = document.createElement('label');
                label.className = 'slot';
                const input = Object.assign(document.createElement('input'), { type: 'radio', name: 'time', value: t, checked: t === wanted });
                label.append(input, document.createTextNode(t));
                slots.append(label);
            }
        } catch {
            if (mine === request) hint.textContent = text.error;
        }
        refreshSummary();
    }

    form.addEventListener('change', (e) => {
        if (e.target.name === 'time') wanted = e.target.value;
        // A longer service fits in fewer slots: the times depend on it too.
        if (e.target.name === 'item_id') { syncVariants(); syncStaff(); }
        if (e.target.name === 'variant_id') syncVariants();
        if (['date', 'staff_user_id', 'item_id', 'variant_id'].includes(e.target.name)) loadSlots();
        else refreshSummary();
    });
    form.addEventListener('input', refreshSummary);
    submit.addEventListener('click', (e) => { const m = missing(); if (m) { e.preventDefault(); m.run(); } });
    form.addEventListener('submit', (e) => {
        const m = missing();
        if (m) { e.preventDefault(); m.run(); return; }
        submit.disabled = true;
    });
    syncVariants();
    syncStaff();
    filter();
    loadSlots();
    form.querySelector('[data-days] input:checked')?.closest('label').scrollIntoView({ inline: 'center', block: 'nearest' });
})();
</script>
@endif
</body>
</html>
