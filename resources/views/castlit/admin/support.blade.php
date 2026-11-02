@extends('castlit.layout')

@section('title', 'Support — Administration Castl-it-POS')

@php
    $toApi = fn ($m) => \App\Support\SupportChat::toApi($m, $m->attachment_kind
        ? route('castlit.admin.support.attachment', ['conversation' => $selected->id, 'message' => $m->id]) : null);
    $tz = 'Africa/Casablanca';
@endphp

@section('content')
<style>
    .admin { padding: 32px 0 40px; }
    .admin .wrap { max-width: 1440px; }
    .admin-head { display: flex; align-items: flex-end; gap: 16px; flex-wrap: wrap; margin-bottom: 18px; }
    .admin-head h1 { font-size: 26px; font-weight: 800; letter-spacing: -.02em; }
    .admin-head p { color: var(--muted); font-size: 14px; }
    .flash { padding: 10px 14px; border-radius: 10px; margin-bottom: 14px; font-size: 14px; }
    .flash-ok { background: var(--ok-bg); color: var(--ok); }
    .flash-err { background: var(--err-bg); color: var(--err); }
    .desk { display: grid; grid-template-columns: minmax(280px, 360px) 1fr; gap: 16px; align-items: start; }
    @media (max-width: 900px) { .desk { grid-template-columns: 1fr; } .desk.has-thread .inbox { display: none; } }
    .panel { background: var(--surface); border: 1px solid var(--sand); border-radius: var(--radius); box-shadow: var(--shadow); overflow: hidden; }
    .inbox-tools { padding: 12px; border-bottom: 1px solid var(--sand); display: grid; gap: 10px; }
    .inbox-tools input { width: 100%; padding: 9px 12px; border: 1px solid var(--sand); border-radius: 10px; background: var(--surface); color: var(--ink); font: inherit; font-size: 14px; }
    .filters { display: flex; gap: 6px; flex-wrap: wrap; }
    .filters a { font-size: 12.5px; font-weight: 650; padding: 5px 10px; border-radius: 999px; border: 1px solid var(--sand); color: var(--muted); text-decoration: none; }
    .filters a.on { background: var(--brand); border-color: var(--brand); color: #fff; }
    .convs { max-height: 70vh; overflow-y: auto; }
    .conv { display: grid; grid-template-columns: 1fr auto; gap: 2px 10px; padding: 12px 14px; border-bottom: 1px solid color-mix(in srgb, var(--sand) 55%, transparent);
            color: inherit; text-decoration: none; }
    .conv:hover { background: color-mix(in srgb, var(--brand) 4%, transparent); }
    .conv.on { background: color-mix(in srgb, var(--brand) 9%, transparent); }
    .conv b { font-size: 14px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .conv.unread b { font-weight: 800; }
    .conv time { font-size: 11.5px; color: var(--muted); white-space: nowrap; }
    .conv .pv { grid-column: 1; font-size: 13px; color: var(--muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .conv .meta { grid-column: 1 / -1; display: flex; gap: 6px; flex-wrap: wrap; margin-top: 3px; }
    .count { min-width: 22px; height: 22px; padding: 0 6px; border-radius: 999px; background: var(--err); color: #fff; font-size: 12px; font-weight: 800;
             display: inline-grid; place-items: center; }
    .tag { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; padding: 2px 7px; border-radius: 999px;
           background: color-mix(in srgb, var(--brand) 12%, transparent); color: var(--brand); }
    .tag.guest { background: color-mix(in srgb, var(--accent) 16%, transparent); color: var(--accent); }
    .tag.closed { background: color-mix(in srgb, var(--muted) 16%, transparent); color: var(--muted); }
    .empty { padding: 40px 20px; text-align: center; color: var(--muted); font-size: 14px; }
    .thread-head { display: flex; gap: 12px; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; padding: 14px 16px; border-bottom: 1px solid var(--sand); }
    .thread-head h2 { font-size: 18px; font-weight: 800; }
    .thread-head .who { color: var(--muted); font-size: 13px; margin-top: 2px; display: flex; gap: 10px; flex-wrap: wrap; }
    .thread-head .who a { color: var(--brand); text-decoration: none; font-weight: 650; }
    .tools { display: flex; gap: 6px; flex-wrap: wrap; }
    .tools form { margin: 0; }
    .btn-sm { font-size: 12.5px; font-weight: 650; padding: 7px 12px; border-radius: 9px; border: 1px solid var(--sand); background: var(--surface); color: var(--ink);
              cursor: pointer; white-space: nowrap; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
    .btn-sm:hover { border-color: var(--brand); color: var(--brand); }
    .btn-sm.wa { background: #16a34a; border-color: #16a34a; color: #fff; }
    .msgs { height: 58vh; overflow-y: auto; padding: 16px; display: flex; flex-direction: column; gap: 8px; background: color-mix(in srgb, var(--sand) 18%, transparent); }
    .msg { max-width: min(78%, 560px); padding: 9px 12px; border-radius: 14px; font-size: 14px; line-height: 1.45; word-wrap: break-word; white-space: pre-wrap; }
    .msg.client { align-self: flex-start; background: var(--surface); border: 1px solid var(--sand); border-bottom-left-radius: 4px; }
    .msg.support { align-self: flex-end; background: var(--brand); color: #fff; border-bottom-right-radius: 4px; }
    .msg .by { font-size: 11px; font-weight: 700; opacity: .75; margin-bottom: 2px; }
    .msg .at { font-size: 11px; opacity: .7; margin-top: 4px; text-align: end; font-variant-numeric: tabular-nums; }
    .msg img { display: block; max-width: 100%; max-height: 320px; border-radius: 10px; margin-top: 4px; cursor: zoom-in; }
    .msg audio { display: block; width: 260px; max-width: 100%; margin-top: 4px; }
    .msg a.file { display: inline-flex; gap: 6px; align-items: center; font-weight: 650; color: inherit; margin-top: 4px; }
    .compose { border-top: 1px solid var(--sand); padding: 10px; display: flex; gap: 8px; align-items: flex-end; }
    .compose textarea { flex: 1; min-height: 44px; max-height: 160px; resize: vertical; padding: 10px 12px; border: 1px solid var(--sand); border-radius: 12px;
                        background: var(--surface); color: var(--ink); font: inherit; font-size: 14px; }
    .icon-btn { width: 44px; height: 44px; flex: none; border-radius: 12px; border: 1px solid var(--sand); background: var(--surface); color: var(--ink);
                display: grid; place-items: center; cursor: pointer; }
    .icon-btn:hover { border-color: var(--brand); color: var(--brand); }
    .icon-btn.send { background: var(--brand); border-color: var(--brand); color: #fff; }
    .icon-btn.rec { background: var(--err); border-color: var(--err); color: #fff; }
    .pending { padding: 0 12px 8px; font-size: 12.5px; color: var(--muted); display: none; align-items: center; gap: 8px; }
    .pending.on { display: flex; }
    .pending button { border: 0; background: none; color: var(--err); cursor: pointer; font-weight: 700; }
</style>

<main class="admin">
    <div class="wrap">
        <div class="admin-head">
            <div>
                <h1>Support @if ($unreadTotal > 0)<span class="count" data-support-unread>{{ $unreadTotal }}</span>@endif</h1>
                <p>Les messages des clients (application) et des visiteurs (site). Ouvrir une conversation ne la marque pas comme lue.</p>
            </div>
            <div style="margin-left:auto; display:flex; gap:10px; flex-wrap:wrap">
                <a href="{{ route('castlit.admin.index') }}" class="btn btn-ghost" style="padding:11px 18px">← Abonnements</a>
                <a href="{{ route('castlit.admin.logs') }}" class="btn btn-ghost" style="padding:11px 18px">Journaux</a>
                <a href="{{ route('castlit.admin.clients') }}" class="btn btn-ghost" style="padding:11px 18px">Clients</a>
            </div>
        </div>

        @if (session('success'))<div class="flash flash-ok">{{ session('success') }}</div>@endif
        @if ($errors->any())<div class="flash flash-err">{{ $errors->first() }}</div>@endif

        <div class="desk {{ $selected ? 'has-thread' : '' }}">
            <section class="panel inbox">
                <form class="inbox-tools" method="GET" action="{{ route('castlit.admin.support') }}">
                    <input type="hidden" name="filtre" value="{{ $filter }}">
                    <input type="search" name="q" value="{{ $search }}" placeholder="Nom, téléphone, espace…">
                    <div class="filters">
                        @foreach (\App\Http\Controllers\Castlit\SupportAdminController::FILTERS as $key => $label)
                            <a href="{{ route('castlit.admin.support', array_filter(['filtre' => $key, 'q' => $search ?: null])) }}" class="{{ $filter === $key ? 'on' : '' }}">{{ $label }}</a>
                        @endforeach
                    </div>
                </form>
                <div class="convs">
                    @forelse ($conversations as $c)
                        <a href="{{ route('castlit.admin.support', array_filter(['c' => $c->id, 'filtre' => $filter, 'q' => $search ?: null])) }}"
                           class="conv {{ $c->unread_count ? 'unread' : '' }} {{ $selected?->id === $c->id ? 'on' : '' }}" data-conversation="{{ $c->id }}">
                            <b>{{ $c->name }}@if ($c->user_name && $c->user_name !== $c->name) · {{ $c->user_name }}@endif</b>
                            <time>{{ $c->last_message_at?->setTimezone($tz)->format('d/m H:i') }}</time>
                            <span class="pv">{{ $c->last_message_preview ?? '—' }}</span>
                            @if ($c->unread_count)<span class="count">{{ $c->unread_count }}</span>@endif
                            <span class="meta">
                                @if ($c->install)<span class="tag">{{ $c->install->subdomain }}</span>@else<span class="tag guest">Visiteur</span>@endif
                                <span class="tag">{{ $c->channel === 'app' ? 'Application' : 'Site' }}</span>
                                @if ($c->status === 'closed')<span class="tag closed">Fermée</span>@endif
                            </span>
                        </a>
                    @empty
                        <p class="empty">Aucune conversation ici.</p>
                    @endforelse
                </div>
            </section>

            <section class="panel thread">
                @if (! $selected)
                    <p class="empty">Choisissez une conversation.</p>
                @else
                    @php
                        $digits = preg_replace('/\D+/', '', (string) $selected->phone);
                    @endphp
                    <div class="thread-head">
                        <div>
                            <h2>{{ $selected->name }}</h2>
                            <div class="who">
                                @if ($selected->user_name)<span>{{ $selected->user_name }}</span>@endif
                                @if ($selected->install)<a href="https://{{ $selected->install->domain }}" target="_blank" rel="noopener">{{ $selected->install->domain }}</a>@else<span>Visiteur non client</span>@endif
                                @if ($selected->phone)<span>{{ $selected->phone }}</span>@endif
                                @if ($selected->email)<a href="mailto:{{ $selected->email }}">{{ $selected->email }}</a>@endif
                                <span>{{ $selected->channel === 'app' ? 'Application' : 'Site web' }}@if ($selected->platform) · {{ $selected->platform }}@endif @if ($selected->app_version) · v{{ $selected->app_version }}@endif</span>
                            </div>
                        </div>
                        <div class="tools">
                            @if (strlen($digits) >= 8)
                                <a class="btn-sm wa" href="https://wa.me/{{ $digits }}" target="_blank" rel="noopener">WhatsApp</a>
                            @endif
                            <form method="POST" action="{{ route('castlit.admin.support.read', $selected) }}">
                                @csrf
                                <input type="hidden" name="read" value="1">
                                <button class="btn-sm" data-mark-read>✓ Marquer comme lu</button>
                            </form>
                            <form method="POST" action="{{ route('castlit.admin.support.read', $selected) }}">
                                @csrf
                                <input type="hidden" name="read" value="0">
                                <button class="btn-sm">Marquer comme non lu</button>
                            </form>
                            <form method="POST" action="{{ route('castlit.admin.support.status', $selected) }}">
                                @csrf
                                <input type="hidden" name="status" value="{{ $selected->status === 'open' ? 'closed' : 'open' }}">
                                <button class="btn-sm">{{ $selected->status === 'open' ? 'Fermer' : 'Rouvrir' }}</button>
                            </form>
                            <a class="btn-sm" href="{{ route('castlit.admin.support', ['filtre' => $filter]) }}">← Liste</a>
                        </div>
                    </div>

                    <div class="msgs" id="msgs" aria-live="polite"></div>

                    <form id="compose" method="POST" action="{{ route('castlit.admin.support.reply', $selected) }}" enctype="multipart/form-data">
                        @csrf
                        <div class="pending" id="pending"><span id="pending-label"></span><button type="button" id="pending-clear">Retirer</button></div>
                        <div class="compose">
                            <input type="file" name="attachment" id="file" hidden accept="image/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.zip">
                            <button type="button" class="icon-btn" id="attach" title="Joindre un fichier ou une photo" aria-label="Joindre">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                            </button>
                            <textarea name="body" id="body" rows="1" placeholder="Votre réponse… (Entrée pour envoyer, Maj+Entrée pour aller à la ligne)"></textarea>
                            <button type="button" class="icon-btn" id="record" title="Message vocal" aria-label="Message vocal">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="2" width="6" height="12" rx="3"/><path d="M5 10a7 7 0 0 0 14 0M12 17v5"/></svg>
                            </button>
                            <button class="icon-btn send" id="send" title="Envoyer" aria-label="Envoyer">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>
                            </button>
                        </div>
                    </form>
                @endif
            </section>
        </div>
    </div>
</main>

@if ($selected)
<script>
(() => {
    const box = document.getElementById('msgs');
    const form = document.getElementById('compose');
    const body = document.getElementById('body');
    const file = document.getElementById('file');
    const pending = document.getElementById('pending');
    const pendingLabel = document.getElementById('pending-label');
    const recordBtn = document.getElementById('record');
    const pollUrl = @json(route('castlit.admin.support.poll', $selected));
    const logsUrl = @json(route('castlit.admin.logs.show', ['report' => '__REF__']));
    const csrf = form.querySelector('input[name=_token]').value;
    let lastId = 0;
    let readUpTo = 0;
    const fmt = new Intl.DateTimeFormat('fr-FR', { timeZone: @json($tz), day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });

    function el(tag, props = {}, ...children) {
        const node = Object.assign(document.createElement(tag), props);
        children.filter(Boolean).forEach((c) => node.append(c));
        return node;
    }

    function render(m) {
        if (document.querySelector(`[data-message="${m.id}"]`)) return;
        const bubble = el('div', { className: `msg ${m.sender}` });
        bubble.dataset.message = m.id;
        bubble.append(el('div', { className: 'by', textContent: m.sender === 'support' ? (m.author_name || 'Support') : @json($selected->user_name ?: $selected->name) }));
        if (m.body) {
            // "📋 Journal envoyé (LOG-7F3K)": a link to the report.
            m.body.split(/(LOG-[0-9A-Z]{4})/).forEach((part) => bubble.append(/^LOG-[0-9A-Z]{4}$/.test(part)
                ? el('a', { href: logsUrl.replace('__REF__', part), textContent: part, style: 'color:inherit;font-weight:800' })
                : document.createTextNode(part)));
        }
        const a = m.attachment;
        if (a?.kind === 'image') {
            const img = el('img', { src: a.url, alt: a.name || 'Photo', loading: 'lazy' });
            img.onclick = () => window.open(a.url, '_blank');
            bubble.append(img);
        } else if (a?.kind === 'audio') {
            bubble.append(el('audio', { src: a.url, controls: true, preload: 'none' }));
        } else if (a) {
            bubble.append(el('a', { className: 'file', href: a.url, textContent: `📎 ${a.name} (${Math.max(1, Math.round(a.size / 1024))} Ko)` }));
        }
        const at = el('div', { className: 'at', textContent: fmt.format(new Date(m.created_at)) });
        if (m.sender === 'support') at.dataset.tick = m.id;
        bubble.append(at);
        box.append(bubble);
        lastId = Math.max(lastId, m.id);
    }

    function ticks() {
        document.querySelectorAll('[data-tick]').forEach((at) => {
            const seen = Number(at.dataset.tick) <= readUpTo;
            at.textContent = at.textContent.replace(/ ✓+$/, '') + (seen ? ' ✓✓' : ' ✓');
        });
    }

    function scrollDown() { box.scrollTop = box.scrollHeight; }

    @json($messages->map($toApi)->values()).forEach(render);
    readUpTo = @json((int) $messages->where('sender', 'support')->whereNotNull('read_at')->max('id'));
    ticks();
    scrollDown();

    async function poll() {
        try {
            const res = await fetch(`${pollUrl}?after=${lastId}`, { headers: { Accept: 'application/json' } });
            if (!res.ok) return;
            const data = await res.json();
            const atBottom = box.scrollHeight - box.scrollTop - box.clientHeight < 80;
            data.messages.forEach(render);
            readUpTo = data.read_up_to;
            ticks();
            if (data.messages.length && atBottom) scrollDown();
            const badge = document.querySelector('[data-support-unread]');
            if (badge) badge.textContent = data.unread_total || '';
        } catch {}
    }
    setInterval(poll, 8000);

    async function send(extra) {
        const data = new FormData(form);
        if (extra) for (const [k, v] of Object.entries(extra)) data.set(k, v);
        if (!data.get('body')?.trim() && !(data.get('attachment') instanceof Blob && data.get('attachment').size)) return;
        form.querySelectorAll('button').forEach((b) => b.disabled = true);
        try {
            const res = await fetch(form.action, { method: 'POST', body: data, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf } });
            const json = await res.json().catch(() => ({}));
            if (!res.ok) { alert(json.message || 'Envoi impossible.'); return; }
            render(json.message);
            ticks();
            scrollDown();
            if (!extra) body.value = '';
            clearFile();
        } catch {
            alert('Envoi impossible. Vérifiez la connexion.');
        } finally {
            form.querySelectorAll('button').forEach((b) => b.disabled = false);
        }
    }

    function clearFile() { file.value = ''; pending.classList.remove('on'); }
    document.getElementById('attach').onclick = () => file.click();
    document.getElementById('pending-clear').onclick = clearFile;
    file.onchange = () => {
        if (!file.files[0]) return clearFile();
        pendingLabel.textContent = `📎 ${file.files[0].name}`;
        pending.classList.add('on');
    };
    form.addEventListener('submit', (e) => { e.preventDefault(); send(); });
    body.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
    });

    // Voice note: click to start, click again to send.
    let recorder = null, chunks = [], started = 0;
    recordBtn.onclick = async () => {
        if (recorder) { recorder.stop(); return; }
        if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) { alert('Enregistrement non pris en charge par ce navigateur.'); return; }
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            recorder = new MediaRecorder(stream);
            chunks = [];
            started = Date.now();
            recorder.ondataavailable = (e) => e.data.size && chunks.push(e.data);
            recorder.onstop = () => {
                stream.getTracks().forEach((t) => t.stop());
                const type = recorder.mimeType || 'audio/webm';
                const ext = type.includes('mp4') ? 'm4a' : (type.includes('ogg') ? 'ogg' : 'webm');
                const blob = new Blob(chunks, { type });
                const seconds = Math.round((Date.now() - started) / 1000);
                recorder = null;
                recordBtn.classList.remove('rec');
                if (seconds < 1) return;
                send({ attachment: new File([blob], `vocal.${ext}`, { type }), voice: '1', duration: String(seconds), body: '' });
            };
            recorder.start();
            recordBtn.classList.add('rec');
        } catch {
            alert('Le micro est bloqué. Autorisez-le dans le navigateur.');
        }
    };
})();
</script>
@endif
@endsection
