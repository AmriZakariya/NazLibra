@extends('castlit.layout')

@section('title', $subscription->business_name.' — Administration Castl-it-POS')

@section('content')

@php
    $inst = $subscription->install;
    $inProgress = $inst && in_array($inst->status, [\App\Models\TenantInstall::STATUS_QUEUED, \App\Models\TenantInstall::STATUS_RUNNING], true);
@endphp

{{-- While provisioning, auto-refresh so the admin follows progress live. --}}
@if ($inProgress)
    @push('head')<meta http-equiv="refresh" content="4">@endpush
@endif

<style>
    .live-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: var(--brand); margin-right: 6px; animation: livepulse 1.1s ease infinite; }
    @keyframes livepulse { 0%,100% { box-shadow: 0 0 0 0 color-mix(in srgb, var(--brand) 55%, transparent); } 50% { box-shadow: 0 0 0 5px transparent; } }
    @media (prefers-reduced-motion: reduce) { .live-dot { animation: none; } }
    .step-now { font-weight: 700; }
    .secret { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 13px; letter-spacing: .5px; }
    .mini-btn { text-decoration: none; display: inline-block; border: 1px solid var(--sand); background: var(--surface); color: var(--muted); border-radius: 7px; font-size: 12px; font-weight: 650; padding: 2px 8px; cursor: pointer; margin-inline-start: 4px; }
    .mini-btn:hover { border-color: var(--brand); color: var(--brand); }
    .card-h { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
    .code-big { font-size: 16px; font-weight: 800; letter-spacing: 2px; }
    .hint { display: block; color: var(--muted); font-size: 12px; font-weight: 500; margin-top: 3px; }
    .sup-write { display: flex; flex-direction: column; gap: 8px; padding-top: 10px; }
    .sup-label { font-size: 13px; font-weight: 700; }
    .sup-write textarea { font: inherit; font-size: 14px; padding: 10px 12px; border-radius: 10px; border: 1.5px solid var(--sand); background: var(--surface); color: var(--ink); resize: vertical; }
    .sup-write textarea:focus { outline: none; border-color: var(--brand); }
    .sup-actions { display: flex; gap: 8px; flex-wrap: wrap; }
    .sup-err { color: var(--err); font-size: 12px; font-weight: 600; }
    .sup-unread { font-size: 11px; font-weight: 800; text-transform: none; letter-spacing: 0; color: #fff; background: var(--err); padding: 2px 8px; border-radius: 999px; }
    .sup-list { margin-top: 14px; border-top: 1px solid var(--sand); }
    .sup-row { display: flex; flex-direction: column; gap: 2px; padding: 10px 0; border-bottom: 1px solid color-mix(in srgb, var(--sand) 55%, transparent); text-decoration: none; color: var(--ink); }
    .sup-row:hover .sup-preview { color: var(--brand); }
    .sup-preview { font-size: 13.5px; font-weight: 600; overflow-wrap: anywhere; }
    .sup-meta { font-size: 12px; color: var(--muted); }
    .sup-badge { display: inline-block; min-width: 18px; text-align: center; color: #fff; background: var(--err); border-radius: 999px; padding: 0 5px; font-size: 11px; }
    .acc-group { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; color: var(--brand); padding: 14px 0 2px; }
    .acc-sum { font-size: 14px; line-height: 1.6; margin: 8px 0 12px; }
    .acc-never { display: inline-block; font-size: 12px; font-weight: 700; padding: 3px 10px; border-radius: 999px; background: var(--warn-bg); color: var(--warn); }
    .acc-bad { color: var(--err); font-weight: 650; }
    .acc-scroll { overflow-x: auto; margin: 0 -20px; padding: 0 20px; }
    .ver { display: inline-flex; font-size: 11px; font-weight: 800; padding: 2px 8px; border-radius: 999px; white-space: nowrap; }
    .ver-ok { background: var(--ok-bg); color: var(--ok); }
    .ver-soft { background: var(--warn-bg); color: var(--warn); }
    .ver-force { background: var(--err-bg); color: var(--err); }
    .acc-tbl { width: 100%; border-collapse: collapse; font-size: 13px; }
    .acc-tbl th { text-align: start; font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); padding: 8px 10px 8px 0; border-bottom: 1px solid var(--sand); }
    .acc-tbl td { padding: 9px 10px 9px 0; border-bottom: 1px solid color-mix(in srgb, var(--sand) 55%, transparent); vertical-align: top; }
    .acc-tbl tr.is-bad td { color: var(--err); }
    .acc-tbl .nowrap { white-space: nowrap; font-variant-numeric: tabular-nums; }
    .acc-tbl .mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; }
    .acc-id { color: var(--muted); font-size: 11.5px; margin-inline-start: 6px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
    .acc-ch { display: inline-flex; font-size: 11px; font-weight: 800; padding: 2px 8px; border-radius: 999px; }
    .acc-app { background: color-mix(in srgb, var(--brand) 12%, transparent); color: var(--brand); }
    .acc-web { background: var(--ok-bg); color: var(--ok); }
    .fail-banner { background: var(--err-bg); color: var(--err); border: 1px solid color-mix(in srgb, var(--err) 30%, transparent); border-radius: 12px; padding: 12px 14px; font-size: 14px; font-weight: 600; margin-bottom: 16px; }
    .detail { padding: 34px 0 0; max-width: 860px; margin: 0 auto; }
    .back { color: var(--muted); font-size: 13.5px; font-weight: 600; }
    .detail h1 { font-size: 26px; font-weight: 800; letter-spacing: -.02em; margin: 12px 0 4px; }
    .sd { color: var(--muted); font-variant-numeric: tabular-nums; }
    .grid { display: grid; grid-template-columns: 1.3fr .7fr; gap: 20px; margin-top: 24px; align-items: start; }
    .card { background: var(--surface); border: 1px solid var(--sand); border-radius: var(--radius); box-shadow: var(--shadow); }
    .card h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .06em; color: var(--muted);
               padding: 15px 20px; border-bottom: 1px solid var(--sand); }
    .card .body { padding: 8px 20px 16px; }
    .kv { display: flex; padding: 10px 0; border-bottom: 1px solid color-mix(in srgb, var(--sand) 55%, transparent); font-size: 14px; }
    .kv:last-child { border-bottom: none; }
    .kv .k { width: 140px; color: var(--muted); flex-shrink: 0; }
    .kv .v { font-weight: 600; word-break: break-word; }
    .pill { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 700; padding: 4px 10px; border-radius: 999px; }
    .pill-pending { background: var(--warn-bg); color: var(--warn); }
    .pill-approved, .pill-live { background: var(--ok-bg); color: var(--ok); }
    .pill-rejected, .pill-failed { background: var(--err-bg); color: var(--err); }
    .pill-running, .pill-queued { background: color-mix(in srgb, var(--brand) 12%, transparent); color: var(--brand); }
    .actions { display: flex; flex-direction: column; gap: 12px; }
    .actions form { display: contents; }
    textarea { font: inherit; font-size: 14px; padding: 11px 13px; border-radius: 10px; border: 1.5px solid var(--sand);
               background: var(--paper); color: var(--ink); width: 100%; min-height: 74px; resize: vertical; }
    textarea:focus { outline: none; border-color: var(--brand); box-shadow: 0 0 0 3px color-mix(in srgb, var(--brand) 14%, transparent); }
    .btn-danger { background: var(--err); color: #fff; }
    .btn-danger:hover { background: #b91c1c; }
    .btn-block { width: 100%; }
    .divider { height: 1px; background: var(--sand); margin: 6px 0 2px; }
    .log { background: #0b0f1f; color: #93d888; border-radius: 10px; padding: 14px; font-family: ui-monospace, monospace;
           font-size: 12px; white-space: pre-wrap; word-break: break-word; max-height: 320px; overflow: auto; line-height: 1.6; }
    .url-live { display: inline-flex; align-items: center; gap: 8px; font-weight: 700; color: var(--brand); }
    @media (max-width: 820px) { .grid { grid-template-columns: 1fr; } }
    .origin { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 700; padding: 4px 10px;
              border-radius: 999px; border: 1px solid var(--sand); white-space: nowrap; }
    .origin svg { width: 14px; height: 14px; flex: none; }
    .origin-website { color: #7FA3FF; } .origin-android { color: #3DDC84; } .origin-ios { color: #E6E8EF; }
    .origin-mobile { color: #B9A7FF; } .origin-desktop { color: #5EC8F0; } .origin-admin, .origin-unknown { color: var(--muted); }
</style>

<main class="detail">
    <div class="wrap">
        <a href="{{ route('castlit.admin.index') }}" class="back">← Toutes les demandes</a>
        <h1>{{ $subscription->business_name }}</h1>
        <div class="sd">{{ $subscription->desired_subdomain }}.{{ config('castlit.main_domain') }}
            <span class="pill pill-{{ $subscription->status }}" style="margin-left:8px">{{ ucfirst($subscription->status) }}</span>
        </div>

        @if (session('success'))<div class="flash flash-ok" style="margin-top:18px">{{ session('success') }}</div>@endif
        @if (session('error'))<div class="flash flash-err" style="margin-top:18px">{{ session('error') }}</div>@endif

        <div class="grid">
            <div style="display:flex; flex-direction:column; gap:20px">
                <div class="card">
                    <h2>Informations</h2>
                    <div class="body">
                        <div class="kv"><span class="k">Activité</span><span class="v">{{ \App\Support\BusinessMode::all()[$subscription->activity]['label'] ?? ($subscription->activity ?: '—') }}</span></div>
                        <div class="kv"><span class="k">Devise</span><span class="v">{{ $subscription->currency }}</span></div>
                        <div class="kv"><span class="k">Contact</span><span class="v">{{ $subscription->contact_name }}</span></div>
                        <div class="kv"><span class="k">Email</span><span class="v">{{ $subscription->email }}</span></div>
                        <div class="kv"><span class="k">Téléphone</span><span class="v">{{ $subscription->phone ?: '—' }}</span></div>
                        <div class="kv"><span class="k">Ville</span><span class="v">{{ $subscription->city ?: '—' }}</span></div>
                        <div class="kv"><span class="k">Pays</span><span class="v">@if ($subscription->country){{ \App\Support\Countries::flag($subscription->country) }} {{ $subscription->countryName() }}@else — @endif</span></div>
                        <div class="kv"><span class="k">Provenance</span><span class="v">@include('castlit.admin.partials.origin', ['sub' => $subscription])</span></div>
                        <div class="kv"><span class="k">Nous a connus via</span><span class="v">{{ $subscription->heard_about ?: '—' }}</span></div>
                        <div class="kv"><span class="k">Reçue le</span><span class="v">{{ $subscription->created_at->format('d/m/Y à H:i') }}</span></div>
                        @if ($subscription->reviewed_at)
                            <div class="kv"><span class="k">Traitée par</span><span class="v">{{ $subscription->reviewer?->name ?? '—' }} · {{ $subscription->reviewed_at->format('d/m/Y H:i') }}</span></div>
                        @endif
                        @if ($subscription->rejection_reason)
                            <div class="kv"><span class="k">Motif de refus</span><span class="v">{{ $subscription->rejection_reason }}</span></div>
                        @endif
                    </div>
                </div>

                @if ($subscription->install)
                    <div class="card">
                        <h2>Provisioning</h2>
                        <div class="body">
                            @if (config('castlit.provision.subdomain_manual') && $subscription->install->status !== \App\Models\TenantInstall::STATUS_LIVE)
                                <div class="fail-banner" style="background:var(--warn-bg); color:var(--warn); border-color:color-mix(in srgb, var(--warn) 30%, transparent)">
                                    Créez le sous-domaine dans le panneau LWS pointant vers
                                    <code>~/{{ $subscription->install->domain }}</code> — l'installation remplit ce dossier.
                                </div>
                            @endif
                            @if ($subscription->install->status === \App\Models\TenantInstall::STATUS_FAILED)
                                <div class="fail-banner">✗ {{ $subscription->install->current_step ?: 'Le provisioning a échoué — voir le journal ci-dessous.' }}</div>
                            @endif
                            <div class="kv"><span class="k">Statut</span><span class="v"><span class="pill pill-{{ $subscription->install->status }}">{{ ucfirst($subscription->install->status) }}</span></span></div>
                            @if ($subscription->install->current_step)
                                <div class="kv"><span class="k">Étape</span><span class="v {{ $inProgress ? 'step-now' : '' }}">@if ($inProgress)<span class="live-dot"></span>@endif{{ $subscription->install->current_step }}</span></div>
                            @endif
                            <div class="kv"><span class="k">Adresse</span><span class="v">
                                @if ($subscription->install->status === \App\Models\TenantInstall::STATUS_LIVE)
                                    <a class="url-live" href="{{ $subscription->install->url() }}" target="_blank" rel="noopener">{{ $subscription->install->domain }} ↗</a>
                                @else
                                    {{ $subscription->install->domain }}
                                @endif
                            </span></div>
                            @if ($subscription->install->db_name)
                                <div class="kv"><span class="k">Base de données</span><span class="v">{{ $subscription->install->db_name }}</span></div>
                            @endif
                            @if ($subscription->install->commit_sha)
                                <div class="kv"><span class="k">Commit</span><span class="v">{{ $subscription->install->commit_sha }}</span></div>
                            @endif
                            @if ($subscription->install->provisioned_at)
                                <div class="kv"><span class="k">Mis en ligne</span><span class="v">{{ $subscription->install->provisioned_at->format('d/m/Y H:i') }}</span></div>
                            @endif
                        </div>
                    </div>

                    {{-- Everything the client needs to get in, in one place:
                         what the app's first screen asks, and the two accounts
                         the platform created. Passwords stay masked until asked
                         for; the space's other users are hashed there and
                         cannot be read back. --}}
                    @php
                        $ownerLogin = $inst->owner_email ?: $subscription->email;
                        $sheet = collect([
                            'Adresse : '.$inst->url(),
                            'Nom du client : '.$inst->subdomain,
                            'Code d’accès : '.($inst->access_code ?: '—'),
                            '',
                            'Compte principal',
                            'Identifiant : '.$ownerLogin,
                            'Mot de passe : '.($inst->owner_password ?: '(non conservé)'),
                        ])->when($inst->support_email && $inst->support_password, fn ($lines) => $lines->merge([
                            '',
                            'Compte administrateur',
                            'Identifiant : '.$inst->support_email,
                            'Mot de passe : '.$inst->support_password,
                        ]))->implode("\n");
                    @endphp
                    <div class="card" id="acces" data-access-card>
                        <h2 class="card-h">Accès du client
                            <button type="button" class="mini-btn" data-copy="{{ $sheet }}">Tout copier</button>
                        </h2>
                        <div class="body">
                            <div class="kv"><span class="k">Adresse</span><span class="v">
                                <a href="{{ $inst->url() }}" target="_blank" rel="noopener">{{ $inst->domain }} ↗</a>
                            </span></div>
                            <div class="kv"><span class="k">Nom du client</span><span class="v">
                                <code class="secret" data-client-name>{{ $inst->subdomain }}</code>
                                <button type="button" class="mini-btn" data-copy="{{ $inst->subdomain }}">Copier</button>
                            </span></div>
                            <div class="kv"><span class="k">Code d’accès</span><span class="v">
                                @if ($inst->access_code)
                                    <code class="secret code-big" data-access-code>{{ $inst->access_code }}</code>
                                    <button type="button" class="mini-btn" data-copy="{{ $inst->access_code }}">Copier</button>
                                @else
                                    — <small class="hint">créé au prochain envoi des accès</small>
                                @endif
                            </span></div>

                            <div class="acc-group">Compte principal (propriétaire)</div>
                            <div class="kv"><span class="k">Identifiant</span><span class="v" data-owner-login>
                                {{ $ownerLogin }}
                                <button type="button" class="mini-btn" data-copy="{{ $ownerLogin }}">Copier</button>
                            </span></div>
                            <div class="kv"><span class="k">Mot de passe</span><span class="v">
                                @if ($inst->owner_password)
                                    <code class="secret" data-secret="{{ $inst->owner_password }}">••••••••••••</code>
                                    <button type="button" class="mini-btn" data-reveal>Afficher</button>
                                    <button type="button" class="mini-btn" data-copy="{{ $inst->owner_password }}">Copier</button>
                                    <small class="hint">Initial — le client a pu le changer.</small>
                                @else
                                    <span class="acc-never" data-owner-missing>Non conservé</span>
                                    <small class="hint">Espace créé avant que le mot de passe soit gardé : utilisez « Nouveau mot de passe propriétaire ».</small>
                                @endif
                            </span></div>

                            @if ($inst->support_email && $inst->support_password)
                                <div class="acc-group">Compte administrateur</div>
                                <div class="kv"><span class="k">Identifiant</span><span class="v" data-support>
                                    {{ $inst->support_email }}
                                    <button type="button" class="mini-btn" data-copy="{{ $inst->support_email }}">Copier</button>
                                </span></div>
                                <div class="kv"><span class="k">Mot de passe</span><span class="v">
                                    <code class="secret" data-secret="{{ $inst->support_password }}">••••••••••••</code>
                                    <button type="button" class="mini-btn" data-reveal>Afficher</button>
                                    <button type="button" class="mini-btn" data-copy="{{ $inst->support_password }}">Copier</button>
                                </span></div>
                            @endif
                        </div>
                    </div>
                    <script>
                        document.getElementById('acces').addEventListener('click', (e) => {
                            const b = e.target.closest('button');
                            if (!b) return;
                            if (b.hasAttribute('data-reveal')) {
                                const c = b.parentElement.querySelector('[data-secret]');
                                const hidden = c.textContent.startsWith('•');
                                c.textContent = hidden ? c.dataset.secret : '••••••••••••';
                                b.textContent = hidden ? 'Masquer' : 'Afficher';
                            } else if (b.hasAttribute('data-copy')) {
                                const label = b.textContent;
                                navigator.clipboard?.writeText(b.dataset.copy).then(() => {
                                    b.textContent = 'Copié ✓';
                                    setTimeout(() => { b.textContent = label; }, 1500);
                                });
                            }
                        });
                    </script>

                    {{-- Reaching the client: in their own chat (back office and app
                         join the shop's conversation and show a badge), or right
                         now on WhatsApp / by email. --}}
                    @php
                        $waDigits = preg_replace('/\D+/', '', (string) $subscription->phone);
                        $hello = 'Bonjour '.($subscription->contact_name ?: '').", ici l’équipe Castl-it-POS. ";
                        $mailTo = $inst->owner_email ?: $subscription->email;
                    @endphp
                    <div class="card" id="support" data-support-card>
                        <h2 class="card-h">Support
                            @if ($conversations->sum('unread_count') > 0)
                                <span class="sup-unread">{{ $conversations->sum('unread_count') }} non lu{{ $conversations->sum('unread_count') > 1 ? 's' : '' }}</span>
                            @endif
                        </h2>
                        <div class="body">
                            <form method="POST" action="{{ route('castlit.admin.support.write', $subscription) }}" class="sup-write">
                                @csrf
                                <label for="sup-body" class="sup-label">Écrire à ce client</label>
                                <textarea id="sup-body" name="body" rows="3" maxlength="4000" required
                                          placeholder="Ex. : Bonjour, avez-vous pu imprimer vos tickets ?">{{ old('body') }}</textarea>
                                @error('body')<span class="sup-err">{{ $message }}</span>@enderror
                                <div class="sup-actions">
                                    <button class="btn btn-primary" type="submit" style="padding:9px 16px">Envoyer dans son chat</button>
                                    @if (strlen($waDigits) >= 8)
                                        <a class="btn btn-ghost" style="padding:9px 14px" target="_blank" rel="noopener" data-support-whatsapp
                                           href="https://wa.me/{{ $waDigits }}?text={{ rawurlencode($hello) }}">WhatsApp</a>
                                    @endif
                                    @if ($mailTo)
                                        <a class="btn btn-ghost" style="padding:9px 14px" data-support-mail
                                           href="mailto:{{ $mailTo }}?subject={{ rawurlencode('Castl-it-POS — '.$subscription->business_name) }}">Email</a>
                                    @endif
                                </div>
                                <small class="hint">Il le verra dans « Contacter le support », avec un badge, dès qu’il ouvre son back-office ou l’application.</small>
                            </form>

                            @if ($conversations->isNotEmpty())
                                <div class="sup-list">
                                    @foreach ($conversations as $c)
                                        <a class="sup-row" href="{{ route('castlit.admin.support', ['c' => $c->id]) }}" data-conversation="{{ $c->id }}">
                                            <span class="sup-preview">{{ $c->last_message_preview ?: 'Conversation ouverte' }}</span>
                                            <span class="sup-meta">
                                                {{ ($c->last_message_at ?? $c->created_at)->locale('fr')->diffForHumans() }}
                                                @if ($c->unread_count > 0)<b class="sup-badge">{{ $c->unread_count }}</b>@endif
                                                · Ouvrir →
                                            </span>
                                        </a>
                                    @endforeach
                                </div>
                            @else
                                <p class="hint" style="margin-top:10px">Aucune conversation pour l’instant.</p>
                            @endif
                        </div>
                    </div>

                    {{-- Every time the verification code was entered, right or
                         wrong: proof the client reached their space, and from
                         what. --}}
                    <div class="card" id="connexions">
                        <h2>Connexions à l'espace</h2>
                        <div class="body">
                            @if ($access && $access['last'])
                                <p class="acc-sum">
                                    <strong>{{ $access['count'] }}</strong> accès avec le bon code,
                                    depuis <strong>{{ $access['devices'] }}</strong> {{ $access['devices'] > 1 ? 'appareils' : 'appareil' }}
                                    · dernier {{ $access['last']->created_at->locale('fr')->diffForHumans() }}
                                    @if ($access['failed'] > 0)
                                        · <span class="acc-bad">{{ $access['failed'] }} code{{ $access['failed'] > 1 ? 's' : '' }} erroné{{ $access['failed'] > 1 ? 's' : '' }} sur 30 jours</span>
                                    @endif
                                </p>
                            @else
                                <p class="acc-sum"><span class="acc-never">Jamais connecté</span>
                                    — le code d'accès n'a encore été saisi ni dans l'application ni sur le web.
                                    @if (($access['failed'] ?? 0) > 0)
                                        <span class="acc-bad">{{ $access['failed'] }} tentative{{ $access['failed'] > 1 ? 's' : '' }} avec un mauvais code.</span>
                                    @endif
                                </p>
                            @endif

                            @if ($accessEvents->isNotEmpty())
                                <div class="acc-scroll">
                                    <table class="acc-tbl">
                                        <thead><tr><th>Date</th><th>Canal</th><th>Appareil</th><th>Adresse IP</th><th>Résultat</th></tr></thead>
                                        <tbody>
                                            @foreach ($accessEvents as $e)
                                                <tr class="{{ $e->outcome === 'ok' ? '' : 'is-bad' }}" data-outcome="{{ $e->outcome }}">
                                                    <td class="nowrap">{{ $e->created_at->format('d/m/Y H:i') }}</td>
                                                    <td><span class="acc-ch acc-{{ $e->channel }}">{{ $e->channel === 'web' ? 'Web' : 'App' }}</span></td>
                                                    <td>{{ $e->device_label ?? '—' }}
                                                        @if ($e->device_id)<span class="acc-id" title="Identifiant de l'appareil">#{{ substr($e->device_id, 0, 6) }}</span>@endif
                                                    </td>
                                                    <td class="nowrap mono">{{ $e->ip ?? '—' }}</td>
                                                    <td class="nowrap">{{ ['ok' => 'Code valide', 'invalid' => 'Code erroné', 'blocked' => 'Espace suspendu', 'unknown' => 'Inconnu'][$e->outcome] ?? $e->outcome }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                    </div>

                    {{-- Which build each of the shop's devices last used, as the
                         space reported it with its activity: who is behind,
                         before an update is forced. --}}
                    <div class="card" id="versions" data-app-versions>
                        <h2 class="card-h">Versions de l'application
                            <a class="mini-btn" href="{{ route('castlit.admin.app-updates') }}">Gérer les mises à jour</a>
                        </h2>
                        <div class="body">
                            @if ($appDevices === [])
                                <p class="acc-sum">Aucun appareil n'a encore envoyé sa version
                                    — elle s'affiche après une action depuis l'application (version 1.0.13 et suivantes),
                                    au prochain relevé d'activité.</p>
                            @else
                                <div class="acc-scroll">
                                    <table class="acc-tbl">
                                        <thead><tr><th>Appareil</th><th>Terminal</th><th>Version</th><th>Utilisateur</th><th>Dernière action</th></tr></thead>
                                        <tbody>
                                            @foreach ($appDevices as $d)
                                                <tr data-version-status="{{ $d['status'] }}">
                                                    <td>{{ $d['device'] ?: ucfirst((string) ($d['platform'] ?? '—')) }}</td>
                                                    <td>{{ $d['terminal'] ?? '—' }}</td>
                                                    <td class="nowrap">
                                                        <span class="ver ver-{{ $d['status'] }}" title="{{ ['ok' => 'À jour', 'soft' => 'Mise à jour proposée', 'force' => 'Mise à jour obligatoire'][$d['status']] ?? '' }}">v{{ $d['version'] }}</span>
                                                    </td>
                                                    <td>{{ $d['user'] ?? '—' }}</td>
                                                    <td class="nowrap">@if ($d['at_carbon'])<span title="{{ \App\Support\TenantClock::format($d['at_carbon']) }}">{{ $d['at_carbon']->locale('fr')->diffForHumans() }}</span>@else — @endif</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                    </div>

                    @if ($subscription->install->provision_log)
                        <div class="card">
                            <h2>Journal du premier déploiement</h2>
                            <div class="body"><div class="log">{{ $subscription->install->provision_log }}</div></div>
                        </div>
                    @endif

                    @if ($subscription->install->update_log)
                        <div class="card">
                            <h2>Journal des mises à jour
                                @if ($subscription->install->updated_log_at)
                                    <span style="font-weight:500; color:var(--muted); font-size:12.5px">· {{ $subscription->install->updated_log_at->format('d/m/Y H:i') }}</span>
                                @endif
                            </h2>
                            <div class="body"><div class="log">{{ $subscription->install->update_log }}</div></div>
                        </div>
                    @endif
                @endif
            </div>

            <div class="card">
                <h2>Actions</h2>
                <div class="body">
                    <div class="actions">
                        @if ($subscription->isPending())
                            <form method="POST" action="{{ route('castlit.admin.approve', $subscription) }}"
                                  onsubmit="return confirm('Approuver et provisionner {{ $subscription->desired_subdomain }}.{{ config('castlit.main_domain') }} ?')">
                                @csrf
                                <button class="btn btn-primary btn-block" type="submit">✓ Approuver & provisionner</button>
                            </form>
                            <div class="divider"></div>
                            <form method="POST" action="{{ route('castlit.admin.reject', $subscription) }}">
                                @csrf
                                <label style="font-size:12.5px; font-weight:650; display:block; margin-bottom:6px">Motif de refus (optionnel)</label>
                                <textarea name="rejection_reason" placeholder="Visible dans l'email envoyé au demandeur…">{{ old('rejection_reason') }}</textarea>
                                <button class="btn btn-danger btn-block" type="submit" style="margin-top:10px"
                                        onclick="return confirm('Refuser cette demande ?')">Rejeter la demande</button>
                            </form>
                        @elseif ($subscription->install && $subscription->install->status === \App\Models\TenantInstall::STATUS_FAILED)
                            <p style="font-size:14px; color:var(--muted); margin-bottom:4px">Le provisioning a échoué. Consultez le journal, corrigez la cause côté serveur, puis relancez.</p>
                            <form method="POST" action="{{ route('castlit.admin.retry', $subscription) }}">
                                @csrf
                                <button class="btn btn-primary btn-block" type="submit">↻ Relancer le provisioning</button>
                            </form>
                        @elseif ($subscription->install && $subscription->install->status === \App\Models\TenantInstall::STATUS_LIVE)
                            <p style="font-size:14px; color:var(--ok); font-weight:600">Espace en ligne ✓</p>
                            @if ($subscription->install->current_step)
                                <p style="font-size:12.5px; color:var(--muted); margin:-2px 0 8px">{{ $subscription->install->current_step }}</p>
                            @endif
                            <form method="POST" action="{{ route('castlit.admin.clients.action', [], false) }}"
                                  onsubmit="return confirm('Déployer la dernière version sur {{ $subscription->install->domain }} ? Les données du client sont conservées.')">
                                @csrf
                                <input type="hidden" name="install" value="{{ $subscription->install->id }}">
                                <input type="hidden" name="do" value="update">
                                <button class="btn btn-primary btn-block" type="submit">↻ Mettre à jour le code</button>
                            </form>
                            <a class="btn btn-ghost btn-block" href="{{ $subscription->install->url() }}" target="_blank" rel="noopener" style="margin-top:10px">Ouvrir l'espace client ↗</a>
                            <form method="POST" action="{{ route('castlit.admin.resend', $subscription) }}" style="margin-top:10px">
                                @csrf
                                <button class="btn btn-ghost btn-block" type="submit"
                                        onclick="return confirm('Renvoyer l’email d’accès à {{ $subscription->email }} ?')">✉ Renvoyer les accès</button>
                            </form>
                            {{-- A space created before the owner's password was kept, or an
                                 owner who lost it: a new one, set in the space and mailed. --}}
                            <form method="POST" action="{{ route('castlit.admin.owner-password', $subscription) }}" style="margin-top:10px">
                                @csrf
                                <button class="btn btn-ghost btn-block" type="submit" data-owner-password
                                        onclick="return confirm('Créer un nouveau mot de passe pour {{ $subscription->install->owner_email ?: $subscription->email }} et l’envoyer par email ? L’ancien ne marchera plus.')">🔑 Nouveau mot de passe propriétaire</button>
                            </form>
                        @elseif ($subscription->install && $subscription->install->status === \App\Models\TenantInstall::STATUS_QUEUED)
                            <p style="font-size:14px; font-weight:600; margin-bottom:4px"><span class="live-dot"></span>En file d'attente…</p>
                            <p style="font-size:13px; color:var(--muted)">En attente du worker de provisioning (depuis {{ $subscription->install->updated_at->diffForHumans(null, true) }}).</p>
                            @if ($subscription->install->updated_at->lt(now()->subSeconds(90)))
                                <div class="fail-banner" style="margin-top:10px; background:var(--warn-bg); color:var(--warn); border-color:color-mix(in srgb, var(--warn) 30%, transparent)">
                                    ⚠ Toujours en file après plus d'une minute. Le worker de file d'attente ne tourne probablement pas.<br>
                                    Lancez <code>php&nbsp;artisan&nbsp;queue:work</code> sur le serveur (ou le cron minute).
                                </div>
                            @endif
                            <p style="font-size:12px; color:var(--muted); margin-top:8px">Cette page s'actualise automatiquement.</p>
                        @else
                            <p style="font-size:14px; font-weight:600; margin-bottom:4px"><span class="live-dot"></span>Provisioning en cours…</p>
                            <p style="font-size:13px; color:var(--muted)">{{ optional($subscription->install)->current_step ?: 'Préparation…' }}</p>
                            <p style="font-size:12px; color:var(--muted); margin-top:8px">Cette page s'actualise automatiquement. Suivez le détail dans le journal ci-contre.</p>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>
@endsection
