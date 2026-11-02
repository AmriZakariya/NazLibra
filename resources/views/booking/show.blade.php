@php
    $shopMoney = \App\Support\Money::for($tenant);
    $theme = data_get($tenant->settings, 'theme', []);
    $primary = $theme['primary'] ?? '#3157D5';
    $logo = data_get($tenant->settings, 'company.logo_path');
    $locale = app()->getLocale();
    $dir = \App\Support\Locale::dir($locale);
    $tz = \App\Support\Appointments::timezone($tenant);
    $oldDay = old('date');
    $oldTime = old('time');
    $scriptText = [
        'loading' => __('booking.loading'),
        'none' => __('booking.no_slots'),
        'error' => __('booking.slots_error'),
        'pickDay' => __('booking.pick_day_first'),
        'empty' => __('booking.summary_empty'),
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $dir }}" data-phone-country="{{ \App\Support\Phone::defaultCountry($tenant) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('booking.title') }} · {{ $tenant->name }}</title>
    @vite(['resources/js/phone.js'])
    <style>
        :root {
            --brand: {{ $primary }};
            --bg: #f5f6fa; --surface: #fff; --line: #e3e7ef; --text: #0f172a; --muted: #5d6b82;
            --soft: color-mix(in srgb, var(--brand) 9%, #fff);
            --danger: #be123c;
        }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #0b1120; --surface: #121a2c; --line: rgba(255,255,255,.09); --text: #e8ecf5; --muted: #9aa7bf;
                --soft: color-mix(in srgb, var(--brand) 22%, #121a2c); --danger: #fb7185; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text);
            font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, "Noto Sans Arabic", sans-serif; }
        .wrap { max-width: 40rem; margin: 0 auto; padding: 20px 16px 120px; }
        header { display: flex; align-items: center; gap: 12px; margin-bottom: 18px; }
        .mark { width: 52px; height: 52px; flex: none; border-radius: 14px; overflow: hidden; display: grid; place-items: center;
            background: var(--brand); color: #fff; font-weight: 800; font-size: 22px; }
        .mark img { width: 100%; height: 100%; object-fit: cover; }
        h1 { margin: 0; font-size: 1.35rem; line-height: 1.25; text-wrap: balance; }
        .shop { margin: 0; color: var(--muted); font-size: .9rem; }
        .intro { color: var(--muted); margin: 0 0 18px; font-size: .95rem; }
        section { background: var(--surface); border: 1px solid var(--line); border-radius: 16px; padding: 16px; margin-bottom: 12px; }
        h2 { margin: 0 0 12px; font-size: .78rem; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); }
        .choices { display: grid; gap: 8px; }
        .choices.two { grid-template-columns: repeat(auto-fill, minmax(9.5rem, 1fr)); }
        .choice { position: relative; display: flex; align-items: center; justify-content: space-between; gap: 10px;
            border: 1.5px solid var(--line); border-radius: 12px; padding: 12px 14px; cursor: pointer; }
        .choice input { position: absolute; opacity: 0; pointer-events: none; }
        .choice:has(input:checked) { border-color: var(--brand); background: var(--soft); }
        .choice:has(input:focus-visible) { outline: 2px solid var(--brand); outline-offset: 2px; }
        .choice b { font-weight: 600; }
        .choice small { display: block; color: var(--muted); font-size: .8rem; }
        .price { font-variant-numeric: tabular-nums; font-weight: 700; white-space: nowrap; }
        .days { display: flex; gap: 8px; overflow-x: auto; padding-bottom: 4px; scroll-snap-type: x mandatory; }
        .day { flex: none; width: 4.2rem; text-align: center; padding: 8px 4px; scroll-snap-align: start; flex-direction: column; justify-content: center; gap: 0; }
        .day span { display: block; font-size: .72rem; text-transform: uppercase; color: var(--muted); }
        .day b { font-size: 1.25rem; font-variant-numeric: tabular-nums; }
        .slots { display: grid; grid-template-columns: repeat(auto-fill, minmax(4.6rem, 1fr)); gap: 8px; }
        .slot { justify-content: center; padding: 10px 4px; font-variant-numeric: tabular-nums; font-weight: 600; }
        .hint { color: var(--muted); font-size: .9rem; margin: 0; }
        label.field { display: block; margin-bottom: 10px; }
        label.field span { display: block; font-size: .85rem; font-weight: 600; margin-bottom: 4px; }
        input[type=text], input[type=tel], textarea { width: 100%; font: inherit; color: inherit; background: var(--surface);
            border: 1.5px solid var(--line); border-radius: 10px; padding: 10px 12px; }
        input:focus, textarea:focus { outline: none; border-color: var(--brand); }
        .error { color: var(--danger); font-size: .9rem; margin: 0 0 12px; padding: 10px 12px; border-radius: 10px;
            background: color-mix(in srgb, var(--danger) 10%, transparent); }
        .bar { position: fixed; inset: auto 0 0 0; background: var(--surface); border-top: 1px solid var(--line); padding: 12px 16px; }
        .bar .in { max-width: 40rem; margin: 0 auto; display: flex; align-items: center; gap: 12px; }
        .bar p { flex: 1; margin: 0; font-size: .9rem; color: var(--muted); min-width: 0; }
        .bar p b { color: var(--text); }
        button.go { flex: none; border: 0; border-radius: 12px; background: var(--brand); color: #fff; font: inherit; font-weight: 700;
            padding: 12px 18px; cursor: pointer; }
        button.go:disabled { opacity: .45; cursor: not-allowed; }
        .done { text-align: center; padding: 28px 18px; }
        .done .tick { width: 56px; height: 56px; margin: 0 auto 12px; border-radius: 50%; display: grid; place-items: center;
            background: var(--soft); color: var(--brand); font-size: 28px; font-weight: 800; }
        .done h2 { font-size: 1.2rem; text-transform: none; letter-spacing: 0; color: var(--text); margin-bottom: 6px; }
        .done .when { font-weight: 700; margin: 12px 0; }
        .done a { color: var(--brand); font-weight: 600; }
        .trap { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }
        @media (prefers-reduced-motion: no-preference) { .choice { transition: border-color .15s, background .15s; } }
    </style>
</head>
<body>
<div class="wrap">
    <header>
        <div class="mark">
            @if ($logo)
                <img src="{{ asset('storage/'.$logo) }}" alt="">
            @else
                {{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($tenant->name, 0, 1)) }}
            @endif
        </div>
        <div>
            <h1>{{ __('booking.title') }}</h1>
            <p class="shop">{{ $tenant->name }}</p>
        </div>
    </header>

    @if ($sent && empty($sent['honeypot']))
        <section class="done" data-booking-sent>
            <div class="tick">✓</div>
            <h2>{{ __('booking.sent_title') }}</h2>
            <p class="hint">{{ __('booking.sent_body') }}</p>
            <p class="when">{{ $sent['service'] }} · {{ ucfirst($sent['date']) }} · {{ $sent['time'] }}@if ($sent['staff']) · {{ __('booking.sent_with', ['name' => $sent['staff']]) }}@endif</p>
            <a href="{{ route('booking.show') }}">{{ __('booking.sent_again') }}</a>
        </section>
    @elseif ($sent)
        <section class="done"><div class="tick">✓</div><h2>{{ __('booking.sent_title') }}</h2></section>
    @elseif ($services->isEmpty())
        <section><p class="hint">{{ __('booking.no_service') }}</p></section>
    @else
        <p class="intro">{{ __('booking.intro') }}</p>
        @if ($errors->any())
            <p class="error" role="alert" data-booking-error>{{ $errors->first() }}</p>
        @endif

        <form method="POST" action="{{ route('booking.store') }}" id="booking" novalidate>
            @csrf
            <div class="trap" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

            <section>
                <h2>{{ __('booking.step_service') }}</h2>
                <div class="choices">
                    @foreach ($services as $service)
                        <label class="choice">
                            <input type="radio" name="item_id" value="{{ $service->id }}" data-label="{{ $service->title }}" @checked((string) old('item_id', $services->count() === 1 ? $service->id : '') === (string) $service->id) required>
                            <span><b>{{ $service->title }}</b><small>{{ __('booking.minutes', ['count' => $duration]) }}</small></span>
                            <span class="price">{{ $shopMoney->format($service->sale_price) }}</span>
                        </label>
                    @endforeach
                </div>
            </section>

            @if ($staff->count() > 1)
                <section>
                    <h2>{{ __('booking.step_staff') }}</h2>
                    <div class="choices two">
                        <label class="choice">
                            <input type="radio" name="staff_user_id" value="" data-label="" @checked(! old('staff_user_id'))>
                            <span><b>{{ __('booking.any_staff') }}</b><small>{{ __('booking.any_staff_hint') }}</small></span>
                        </label>
                        @foreach ($staff as $member)
                            <label class="choice">
                                <input type="radio" name="staff_user_id" value="{{ $member->id }}" data-label="{{ $member->name }}" @checked((string) old('staff_user_id') === (string) $member->id)>
                                <span><b>{{ $member->name }}</b></span>
                            </label>
                        @endforeach
                    </div>
                </section>
            @elseif ($staff->count() === 1)
                <input type="hidden" name="staff_user_id" value="{{ $staff->first()->id }}">
            @endif

            <section>
                <h2>{{ __('booking.step_day') }}</h2>
                <div class="days">
                    @foreach ($days as $day)
                        @php $d = \Carbon\CarbonImmutable::parse($day, $tz)->locale($locale); @endphp
                        <label class="choice day">
                            <input type="radio" name="date" value="{{ $day }}" data-label="{{ ucfirst($d->isoFormat('ddd D MMM')) }}" @checked($oldDay === $day)>
                            <span>{{ $d->isoFormat('ddd') }}</span>
                            <b>{{ $d->format('j') }}</b>
                            <span>{{ $d->isoFormat('MMM') }}</span>
                        </label>
                    @endforeach
                </div>
            </section>

            <section>
                <h2>{{ __('booking.step_time') }}</h2>
                <div class="slots" id="slots" aria-live="polite"></div>
                <p class="hint" id="slots-hint">{{ __('booking.pick_day_first') }}</p>
            </section>

            <section>
                <h2>{{ __('booking.step_you') }}</h2>
                <label class="field"><span>{{ __('booking.name') }}</span>
                    <input type="text" name="customer_name" value="{{ old('customer_name') }}" autocomplete="name" required maxlength="160"></label>
                <label class="field"><span>{{ __('booking.phone') }}</span>
                    <input type="tel" name="customer_phone" value="{{ old('customer_phone') }}" autocomplete="tel" inputmode="tel" dir="ltr" required></label>
                <label class="field"><span>{{ __('booking.note') }}</span>
                    <textarea name="note" rows="2" maxlength="500">{{ old('note') }}</textarea></label>
            </section>

            <div class="bar">
                <div class="in">
                    <p id="summary">{{ __('booking.summary_empty') }}</p>
                    <button class="go" type="submit" id="submit" disabled>{{ __('booking.submit') }}</button>
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

    const picked = (name) => form.querySelector(`input[name="${name}"]:checked`);
    const staffValue = () => picked('staff_user_id')?.value ?? form.querySelector('input[type=hidden][name="staff_user_id"]')?.value ?? '';

    function refreshSummary() {
        const service = picked('item_id'), day = picked('date'), time = picked('time');
        const name = form.customer_name.value.trim(), phone = form.customer_phone.value.trim();
        if (service && day && time) {
            summary.innerHTML = '';
            const b = document.createElement('b');
            b.textContent = `${day.dataset.label} · ${time.value}`;
            summary.append(b, document.createTextNode(` · ${service.dataset.label}`));
        } else {
            summary.textContent = text.empty;
        }
        submit.disabled = !(service && day && time && name && phone.replace(/\D/g, '').length >= 8);
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
            const res = await fetch(url, { headers: { Accept: 'application/json' } });
            if (!res.ok) throw new Error(res.status);
            const { slots: times } = await res.json();
            if (mine !== request) return;
            hint.hidden = times.length > 0;
            hint.textContent = text.none;
            for (const t of times) {
                const label = document.createElement('label');
                label.className = 'choice slot';
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
        if (e.target.name === 'date' || e.target.name === 'staff_user_id') loadSlots();
        else refreshSummary();
    });
    form.addEventListener('input', refreshSummary);
    form.addEventListener('submit', () => { submit.disabled = true; });
    loadSlots();
})();
</script>
@endif
</body>
</html>
