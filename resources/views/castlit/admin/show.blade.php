@extends('castlit.layout')

@section('title', $subscription->business_name.' — Administration CastLit')

@section('content')
<style>
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
                            <div class="kv"><span class="k">Statut</span><span class="v"><span class="pill pill-{{ $subscription->install->status }}">{{ ucfirst($subscription->install->status) }}</span></span></div>
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

                    @if ($subscription->install->provision_log)
                        <div class="card">
                            <h2>Journal de provisioning</h2>
                            <div class="body"><div class="log">{{ $subscription->install->provision_log }}</div></div>
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
                            <a class="btn btn-ghost btn-block" href="{{ $subscription->install->url() }}" target="_blank" rel="noopener">Ouvrir l'espace client ↗</a>
                        @else
                            <p style="font-size:14px; color:var(--muted)">Demande déjà traitée. Provisioning en cours…</p>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>
@endsection
