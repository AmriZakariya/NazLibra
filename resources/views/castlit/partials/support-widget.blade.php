{{--
    "Contacter le support": a bubble in the corner that opens a chat with the
    team, held on castlitpos.com. The conversation id and secret live in this
    browser (localStorage), so a visitor coming back finds the thread where
    they left it.

    On the landing page a visitor leaves a name and a phone. In a client's
    back office, $supportIdentity (signed by that space's server) says who is
    writing, and there is no form at all.
--}}
@php
    $supportApi = $supportApi ?? url('/api/v1/support/conversations');
    $supportIdentity = $supportIdentity ?? null;
    $supportUser = $supportUser ?? null;
    $supportSide = $supportSide ?? 'end';

    $supportText = [
        'title' => __('castlit.support_title'),
        'intro' => __('castlit.support_intro'),
        'welcome' => __('castlit.support_welcome'),
        'team' => __('castlit.support_team'),
        'you' => __('castlit.support_you'),
        'error' => __('castlit.support_error'),
        'micDenied' => __('castlit.support_mic_denied'),
        'recording' => __('castlit.support_recording'),
        'photo' => __('castlit.support_photo'),
        'voice' => __('castlit.support_voice'),
        'tooBig' => __('castlit.support_file_too_big'),
    ];
@endphp
<style>
    /* Its own tokens: the landing page and the back office name their colours differently. */
    .sw-root { --sw-brand: var(--brand, var(--brand-primary, #3157D5)); --sw-brand-strong: var(--brand-strong, var(--sw-brand));
               --sw-surface: var(--surface, #fff); --sw-line: var(--sand, var(--border-soft, #e2e8f0)); --sw-ink: var(--ink, var(--text-main, #0f172a));
               --sw-muted: var(--muted, var(--text-muted, #64748b)); --sw-err: var(--err, #dc2626);
               --sw-shadow: var(--shadow-lg, 0 24px 60px rgba(15,23,42,.18)); color: var(--sw-ink); font-family: inherit; }
    .sw-root.start .sw-bubble, .sw-root.start .sw-panel { inset-inline-end: auto; inset-inline-start: 20px; }
    .sw-bubble { position: fixed; inset-inline-end: 20px; bottom: 20px; z-index: 60; width: 60px; height: 60px; border-radius: 50%; border: 0;
                 background: var(--sw-brand); color: #fff; box-shadow: var(--sw-shadow); cursor: pointer; display: grid; place-items: center; }
    .sw-bubble:hover { background: var(--sw-brand-strong); }
    .sw-bubble:focus-visible, .sw-panel button:focus-visible { outline: 3px solid color-mix(in srgb, var(--sw-brand) 40%, transparent); outline-offset: 2px; }
    .sw-dot { position: absolute; top: 2px; inset-inline-end: 2px; min-width: 20px; height: 20px; padding: 0 5px; border-radius: 999px; background: var(--sw-err);
              color: #fff; font-size: 11px; font-weight: 800; display: none; place-items: center; border: 2px solid var(--sw-surface); }
    .sw-dot.on { display: grid; }
    .sw-panel { position: fixed; inset-inline-end: 20px; bottom: 92px; z-index: 61; width: min(380px, calc(100vw - 32px)); height: min(560px, calc(100dvh - 120px));
                background: var(--sw-surface); color: var(--sw-ink); border: 1px solid var(--sw-line); border-radius: 18px; box-shadow: var(--sw-shadow);
                display: none; flex-direction: column; overflow: hidden; }
    .sw-panel.on { display: flex; }
    .sw-panel [hidden] { display: none !important; }
    @media (max-width: 480px) { .sw-panel { inset: auto 16px 88px 16px; width: auto; } }
    .sw-head { display: flex; align-items: center; gap: 10px; padding: 14px 16px; background: var(--sw-brand); color: #fff; }
    .sw-head b { flex: 1; font-size: 15px; }
    .sw-head button { border: 0; background: transparent; color: #fff; cursor: pointer; font-size: 22px; line-height: 1; width: 32px; height: 32px; border-radius: 8px; }
    .sw-body { flex: 1; overflow-y: auto; padding: 14px; display: flex; flex-direction: column; gap: 8px; background: color-mix(in srgb, var(--sw-line) 20%, transparent); }
    .sw-intro { font-size: 14px; color: var(--sw-muted); }
    .sw-form { display: grid; gap: 10px; padding: 14px; }
    .sw-form label { display: grid; gap: 4px; font-size: 13px; font-weight: 650; }
    .sw-form input { padding: 10px 12px; border: 1px solid var(--sw-line); border-radius: 10px; font: inherit; font-size: 14px; background: var(--sw-surface); color: var(--sw-ink); }
    .sw-go { padding: 11px 14px; border: 0; border-radius: 10px; background: var(--sw-brand); color: #fff; font: inherit; font-weight: 700; cursor: pointer; }
    .sw-err { color: var(--sw-err); font-size: 13px; min-height: 1em; }
    .sw-msg { max-width: 82%; padding: 8px 11px; border-radius: 14px; font-size: 14px; line-height: 1.45; white-space: pre-wrap; word-wrap: break-word; }
    .sw-msg.client { align-self: flex-end; background: var(--sw-brand); color: #fff; border-end-end-radius: 4px; }
    .sw-msg.support { align-self: flex-start; background: var(--sw-surface); border: 1px solid var(--sw-line); border-end-start-radius: 4px; }
    .sw-msg .at { font-size: 10.5px; opacity: .7; margin-top: 3px; text-align: end; }
    .sw-msg img { display: block; max-width: 100%; max-height: 220px; border-radius: 10px; margin-top: 4px; }
    .sw-msg audio { display: block; width: 220px; max-width: 100%; margin-top: 4px; }
    .sw-msg a { color: inherit; font-weight: 650; }
    .sw-compose { display: flex; gap: 6px; align-items: flex-end; padding: 10px; border-top: 1px solid var(--sw-line); }
    .sw-compose textarea { flex: 1; resize: none; min-height: 40px; max-height: 120px; padding: 9px 11px; border: 1px solid var(--sw-line); border-radius: 12px;
                           font: inherit; font-size: 14px; background: var(--sw-surface); color: var(--sw-ink); }
    .sw-icon { width: 40px; height: 40px; flex: none; border-radius: 12px; border: 1px solid var(--sw-line); background: var(--sw-surface); color: var(--sw-ink);
               cursor: pointer; display: grid; place-items: center; }
    .sw-icon.send { background: var(--sw-brand); border-color: var(--sw-brand); color: #fff; }
    .sw-icon.rec { background: var(--sw-err); border-color: var(--sw-err); color: #fff; }
    .sw-status { font-size: 12px; color: var(--sw-muted); padding: 0 12px 6px; min-height: 0; }
</style>

<div class="sw-root {{ $supportSide }}">
<button type="button" class="sw-bubble" id="sw-bubble" aria-label="{{ __('castlit.support_open') }}" aria-expanded="false" aria-controls="sw-panel" data-support-bubble>
    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
    <span class="sw-dot" id="sw-dot"></span>
</button>

<section class="sw-panel" id="sw-panel" role="dialog" aria-label="{{ __('castlit.support_title') }}">
    <div class="sw-head">
        <b>{{ __('castlit.support_title') }}</b>
        <button type="button" id="sw-close" aria-label="{{ __('castlit.support_close') }}">×</button>
    </div>

    <form class="sw-form" id="sw-start" novalidate>
        <p class="sw-intro">{{ __('castlit.support_intro') }}</p>
        <label>{{ __('castlit.support_name') }}<input name="name" required maxlength="120" autocomplete="name"></label>
        <label>{{ __('castlit.support_phone') }}<input name="phone" type="tel" required inputmode="tel" autocomplete="tel" dir="ltr"></label>
        <p class="sw-err" id="sw-start-err" role="alert"></p>
        <button class="sw-go">{{ __('castlit.support_start') }}</button>
    </form>

    <div class="sw-body" id="sw-body" hidden aria-live="polite"></div>
    <div class="sw-status" id="sw-status"></div>
    <form class="sw-compose" id="sw-compose" hidden>
        <input type="file" id="sw-file" hidden accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.zip">
        <button type="button" class="sw-icon" id="sw-attach" aria-label="{{ __('castlit.support_attach') }}" title="{{ __('castlit.support_attach') }}">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
        </button>
        <textarea id="sw-text" rows="1" maxlength="4000" placeholder="{{ __('castlit.support_placeholder') }}"></textarea>
        <button type="button" class="sw-icon" id="sw-rec" aria-label="{{ __('castlit.support_record') }}" title="{{ __('castlit.support_record') }}">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="2" width="6" height="12" rx="3"/><path d="M5 10a7 7 0 0 0 14 0M12 17v5"/></svg>
        </button>
        <button class="sw-icon send" aria-label="{{ __('castlit.support_send') }}" title="{{ __('castlit.support_send') }}">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>
        </button>
    </form>
</section>
</div>

<script>
(() => {
    const api = @json($supportApi);
    const identity = @json($supportIdentity);
    const userName = @json($supportUser);
    const text = @json($supportText);
    // One thread per space: every logged-in device of the shop joins it.
    const key = 'castlit-support' + (identity ? ':' + identity.subdomain : '');
    const $ = (id) => document.getElementById(id);
    const panel = $('sw-panel'), bubble = $('sw-bubble'), dot = $('sw-dot');
    const startForm = $('sw-start'), body = $('sw-body'), compose = $('sw-compose'), input = $('sw-text'), file = $('sw-file'), status = $('sw-status');
    let session = null, lastId = 0, timer = null, open = false;
    try { session = JSON.parse(localStorage.getItem(key) || 'null'); } catch {}

    const headers = () => ({ Accept: 'application/json', 'X-Support-Token': session.token });
    const fmt = new Intl.DateTimeFormat(document.documentElement.lang || 'fr', { hour: '2-digit', minute: '2-digit' });

    function showChat() {
        startForm.hidden = true; body.hidden = false; compose.hidden = false;
        if (!body.children.length) bubbleOf({ id: 0, sender: 'support', body: text.welcome, created_at: null });
    }

    function bubbleOf(m) {
        if (m.id && body.querySelector(`[data-m="${m.id}"]`)) return;
        const div = document.createElement('div');
        div.className = `sw-msg ${m.sender}`;
        div.dataset.m = m.id;
        if (m.body) div.append(document.createTextNode(m.body));
        const a = m.attachment;
        const src = a ? `${a.url}?token=${encodeURIComponent(session.token)}` : null;
        if (a?.kind === 'image') { const img = new Image(); img.src = src; img.alt = a.name || text.photo; img.loading = 'lazy'; div.append(img); }
        else if (a?.kind === 'audio') { const au = document.createElement('audio'); au.controls = true; au.preload = 'none'; au.src = src; div.append(au); }
        else if (a) { const link = document.createElement('a'); link.href = src; link.textContent = `📎 ${a.name}`; link.target = '_blank'; link.rel = 'noopener'; div.append(link); }
        if (m.created_at) { const at = document.createElement('div'); at.className = 'at'; at.textContent = fmt.format(new Date(m.created_at)); div.append(at); }
        body.append(div);
        if (m.id) lastId = Math.max(lastId, m.id);
    }

    async function refresh() {
        if (!session) return;
        try {
            const res = await fetch(`${api}/${session.id}?after=${lastId}`, { headers: headers() });
            if (res.status === 404) { localStorage.removeItem(key); session = null; return; }
            const data = await res.json();
            const fresh = data.messages.length > 0;
            data.messages.forEach(bubbleOf);
            if (fresh) body.scrollTop = body.scrollHeight;
            if (open && data.unread) await fetch(`${api}/${session.id}/read`, { method: 'POST', headers: headers() });
            dot.textContent = data.unread || '';
            dot.classList.toggle('on', !open && data.unread > 0);
        } catch {}
    }

    function schedule() {
        clearInterval(timer);
        if (session) timer = setInterval(refresh, open ? 8000 : 60000);
    }

    function toggle(force) {
        open = force ?? !open;
        panel.classList.toggle('on', open);
        bubble.setAttribute('aria-expanded', String(open));
        if (open) {
            dot.classList.remove('on');
            if (session || identity) { showChat(); if (session) refresh(); input.focus(); } else { startForm.name.focus(); }
        }
        schedule();
    }

    /**
     * A logged-in shop joins its conversation — the one the team may have
     * written in first — rather than keeping one of its own.
     */
    async function join() {
        if (!identity) return false;
        try {
            const res = await fetch(`${api}/join`, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify({ identity }) });
            if (!res.ok) return false;
            const data = await res.json();
            if (!data.conversation_id) return false;
            if (session?.id !== data.conversation_id) {
                session = { id: data.conversation_id, token: data.token };
                lastId = 0;
                body.replaceChildren();
                try { localStorage.setItem(key, JSON.stringify(session)); } catch {}
            }
            return true;
        } catch { return false; }
    }

    /** A logged-in shop: its first message opens the conversation. */
    async function ensureSession() {
        if (session) return true;
        if (!identity) return false;
        if (await join()) return true;
        try {
            const res = await fetch(api, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify({ name: userName || identity.subdomain, channel: 'web', platform: 'web', locale: document.documentElement.lang, identity }) });
            if (!res.ok) return false;
            const data = await res.json();
            session = { id: data.conversation_id, token: data.token };
            try { localStorage.setItem(key, JSON.stringify(session)); } catch {}
            schedule();
            return true;
        } catch { return false; }
    }
    bubble.onclick = () => toggle();
    $('sw-close').onclick = () => toggle(false);
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && open) toggle(false); });

    startForm.onsubmit = async (e) => {
        e.preventDefault();
        const err = $('sw-start-err');
        err.textContent = '';
        const payload = { name: startForm.name.value.trim(), phone: startForm.phone.value.trim(), channel: 'web', platform: 'web', locale: document.documentElement.lang };
        if (!payload.name || payload.phone.replace(/\D/g, '').length < 8) { err.textContent = @json(__('castlit.support_phone_required')); return; }
        try {
            const res = await fetch(api, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
            const data = await res.json();
            if (!res.ok) { err.textContent = Object.values(data.errors || {})[0]?.[0] || data.message || text.error; return; }
            session = { id: data.conversation_id, token: data.token };
            try { localStorage.setItem(key, JSON.stringify(session)); } catch {}
            showChat();
            input.focus();
            schedule();
        } catch { err.textContent = text.error; }
    };

    async function send(form) {
        status.textContent = '';
        if (!(await ensureSession())) { status.textContent = text.error; return false; }
        try {
            const res = await fetch(`${api}/${session.id}/messages`, { method: 'POST', headers: headers(), body: form });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) { status.textContent = Object.values(data.errors || {})[0]?.[0] || data.message || text.error; return false; }
            bubbleOf(data.message);
            body.scrollTop = body.scrollHeight;
            return true;
        } catch { status.textContent = text.error; return false; }
    }

    compose.onsubmit = async (e) => {
        e.preventDefault();
        const value = input.value.trim();
        if (!value) return;
        const form = new FormData();
        form.set('body', value);
        if (await send(form)) input.value = '';
    };
    input.addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); compose.requestSubmit(); } });
    $('sw-attach').onclick = () => file.click();
    file.onchange = async () => {
        const f = file.files[0];
        file.value = '';
        if (!f) return;
        if (f.size > 10 * 1024 * 1024) { status.textContent = text.tooBig; return; }
        const form = new FormData();
        form.set('attachment', f);
        await send(form);
    };

    const rec = $('sw-rec');
    let recorder = null, chunks = [], started = 0;
    rec.onclick = async () => {
        if (recorder) { recorder.stop(); return; }
        if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) { status.textContent = text.micDenied; return; }
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            recorder = new MediaRecorder(stream);
            chunks = []; started = Date.now();
            recorder.ondataavailable = (e) => e.data.size && chunks.push(e.data);
            recorder.onstop = async () => {
                stream.getTracks().forEach((t) => t.stop());
                const type = recorder.mimeType || 'audio/webm';
                const seconds = Math.round((Date.now() - started) / 1000);
                recorder = null; rec.classList.remove('rec'); status.textContent = '';
                if (seconds < 1) return;
                const ext = type.includes('mp4') ? 'm4a' : (type.includes('ogg') ? 'ogg' : 'webm');
                const form = new FormData();
                form.set('attachment', new File([new Blob(chunks, { type })], `vocal.${ext}`, { type }));
                form.set('voice', '1');
                form.set('duration', String(seconds));
                await send(form);
            };
            recorder.start();
            rec.classList.add('rec');
            status.textContent = text.recording;
        } catch { status.textContent = text.micDenied; }
    };

    // « Contacter le support » buttons elsewhere on the page open this chat.
    window.CastlitSupport = { open: () => toggle(true) };
    document.addEventListener('click', (e) => {
        const trigger = e.target.closest('[data-open-support]');
        if (!trigger) return;
        e.preventDefault();
        toggle(true);
    });

    // The badge, even with the panel closed: for a shop, whatever the team
    // wrote in its conversation; for a visitor, replies to theirs.
    (async () => {
        if (identity) await join();
        if (session) { refresh(); schedule(); }
    })();
})();
</script>
