@php
    $scriptText = ['ticket' => __('queue.ticket'), 'called' => __('queue.called'), 'now' => __('queue.now'), 'wait' => __('queue.wait'), 'unknown' => __('queue.unknown')];
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('queue.title') }} · {{ $tenant->name }}</title>
    <style>
        :root { --bg: #0b1120; --text: #e8ecf5; --muted: #9aa7bf; --brand: {{ data_get($tenant->settings, 'theme.primary', '#3157D5') }}; }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font: 22px/1.4 system-ui, -apple-system, "Segoe UI", Roboto, "Noto Sans Arabic", sans-serif; }
        .wrap { max-width: 64rem; margin: 0 auto; padding: 24px; }
        h1 { margin: 0 0 4px; font-size: 1.8rem; } .shop { color: var(--muted); margin: 0 0 24px; }
        ul { list-style: none; margin: 0; padding: 0; display: grid; gap: 12px; }
        li { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 16px 20px; border-radius: 16px; background: rgba(255,255,255,.06); }
        li.called { background: color-mix(in srgb, var(--brand) 55%, transparent); }
        .n { font-size: 2.2rem; font-weight: 800; min-width: 4.5rem; font-variant-numeric: tabular-nums; }
        .who { flex: 1; font-weight: 600; } .wait { color: var(--muted); white-space: nowrap; } li.called .wait { color: #fff; font-weight: 700; }
        .empty { color: var(--muted); text-align: center; padding: 48px 0; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>{{ __('queue.title') }}</h1>
    <p class="shop">{{ $tenant->name }}</p>
    <ul id="list"></ul>
    <p class="empty" id="empty" hidden>{{ __('queue.empty') }}</p>
</div>
<script>
(() => {
    const text = @json($scriptText);
    const list = document.getElementById('list'), empty = document.getElementById('empty');
    function draw(data) {
        list.replaceChildren();
        const rows = data.entries.filter((e) => e.status !== 'seated');
        empty.hidden = rows.length > 0;
        for (const e of rows) {
            const li = document.createElement('li');
            li.className = e.status;
            const n = Object.assign(document.createElement('span'), { className: 'n', textContent: e.ticket });
            const who = Object.assign(document.createElement('span'), { className: 'who', textContent: e.first_name || (text.ticket + ' ' + e.ticket) });
            const wait = Object.assign(document.createElement('span'), { className: 'wait' });
            wait.textContent = e.status === 'called' ? text.called
                : e.wait_minutes === null ? text.unknown
                : e.wait_minutes === 0 ? text.now : text.wait.replace(':n', e.wait_minutes);
            li.append(n, who, wait);
            list.append(li);
        }
    }
    draw(@json($data));
    async function refresh() {
        try { const r = await fetch(@json($dataUrl), { headers: { Accept: 'application/json' }, cache: 'no-store' }); if (r.ok) draw(await r.json()); } catch {}
    }
    setInterval(refresh, 15000);
})();
</script>
</body>
</html>
