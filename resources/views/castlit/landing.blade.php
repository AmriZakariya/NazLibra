@extends('castlit.layout')

@section('content')
<style>
    /* ── Hero ── */
    .hero { padding: 68px 0 40px; }
    .hero-grid { display: grid; grid-template-columns: 1.05fr .95fr; gap: 56px; align-items: center; }
    .hero h1 { font-size: clamp(34px, 5vw, 54px); line-height: 1.04; letter-spacing: -.03em;
               font-weight: 800; margin: 16px 0 18px; text-wrap: balance; }
    .hero h1 .hl { color: var(--brand); }
    .hero p.lead { font-size: 18px; color: var(--muted); max-width: 34ch; }
    .hero-cta { display: flex; gap: 12px; margin-top: 28px; flex-wrap: wrap; }
    .hero-note { margin-top: 16px; font-size: 13px; color: var(--muted); display: flex; align-items: center; gap: 7px; }
    .dot { width: 7px; height: 7px; border-radius: 50%; background: var(--ok); box-shadow: 0 0 0 3px var(--ok-bg); }

    /* ── Receipt motif ── */
    .receipt { background: var(--surface); border-radius: 14px; box-shadow: var(--shadow-lg);
               padding: 26px 24px 30px; position: relative; max-width: 340px; margin-left: auto;
               border: 1px solid var(--sand);
               -webkit-mask: radial-gradient(9px at 9px 50%, transparent 98%, #000) 0 -9px/100% 18px;
               mask: radial-gradient(9px at 9px 50%, transparent 98%, #000) 0 -9px/100% 18px; }
    .receipt::after { content: ""; }
    .rc-head { text-align: center; border-bottom: 2px dashed var(--sand); padding-bottom: 14px; }
    .rc-head .store { font-weight: 800; font-size: 17px; letter-spacing: -.01em; }
    .rc-head .sub { font-size: 11.5px; color: var(--muted); margin-top: 2px; }
    .rc-line { display: flex; justify-content: space-between; font-size: 13px; padding: 7px 0;
               font-variant-numeric: tabular-nums; }
    .rc-line .q { color: var(--muted); }
    .rc-total { display: flex; justify-content: space-between; font-weight: 800; font-size: 16px;
                border-top: 2px dashed var(--sand); margin-top: 8px; padding-top: 12px; font-variant-numeric: tabular-nums; }
    .rc-pay { margin-top: 14px; background: var(--brand); color: #fff; border-radius: 9px;
              text-align: center; font-weight: 700; font-size: 13px; padding: 10px; letter-spacing: .02em; }
    .rc-badge { position: absolute; top: -14px; right: -14px; background: var(--accent); color: #40260a;
                font-weight: 800; font-size: 11px; padding: 7px 11px; border-radius: 999px; box-shadow: var(--shadow);
                transform: rotate(4deg); }

    /* ── Trust strip ── */
    .strip { display: flex; flex-wrap: wrap; gap: 10px 26px; color: var(--muted); font-size: 13.5px;
             font-weight: 600; padding: 22px 0 8px; }
    .strip span { display: inline-flex; align-items: center; gap: 7px; }
    .check { color: var(--brand); font-weight: 800; }

    /* ── Features ── */
    .section { padding: 62px 0 8px; }
    .section-head { max-width: 46ch; margin-bottom: 34px; }
    .section-head h2 { font-size: clamp(26px, 3.4vw, 34px); letter-spacing: -.02em; font-weight: 800;
                       margin: 10px 0 8px; text-wrap: balance; }
    .section-head p { color: var(--muted); font-size: 16px; }
    .feats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; }
    .feat { background: var(--surface); border: 1px solid var(--sand); border-radius: var(--radius);
            padding: 22px; box-shadow: var(--shadow); }
    .feat .ico { width: 42px; height: 42px; border-radius: 11px; display: grid; place-items: center;
                 font-size: 20px; background: color-mix(in srgb, var(--brand) 10%, transparent); color: var(--brand); margin-bottom: 14px; }
    .feat h3 { font-size: 16.5px; font-weight: 700; margin-bottom: 6px; }
    .feat p { color: var(--muted); font-size: 14px; }

    /* ── Sign-up ── */
    .signup { padding: 70px 0 10px; }
    .signup-card { background: var(--surface); border: 1px solid var(--sand); border-radius: 22px;
                   box-shadow: var(--shadow-lg); overflow: hidden; display: grid; grid-template-columns: .8fr 1.2fr; }
    .signup-aside { background: linear-gradient(160deg, var(--brand), #24357e); color: #fff; padding: 38px 34px; }
    .signup-aside h2 { font-size: 26px; font-weight: 800; letter-spacing: -.02em; line-height: 1.12; text-wrap: balance; }
    .signup-aside p { color: rgba(255,255,255,.82); font-size: 14.5px; margin-top: 12px; }
    .signup-aside ul { list-style: none; margin-top: 22px; display: flex; flex-direction: column; gap: 12px; }
    .signup-aside li { display: flex; gap: 10px; font-size: 14px; align-items: flex-start; }
    .signup-aside li b { display: inline-grid; place-items: center; width: 20px; height: 20px; border-radius: 6px;
                         background: rgba(255,255,255,.18); font-size: 12px; flex-shrink: 0; margin-top: 1px; }
    .form-body { padding: 34px; }
    .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    .fld { display: flex; flex-direction: column; gap: 6px; margin-bottom: 16px; }
    .fld label { font-size: 12.5px; font-weight: 650; color: var(--ink); }
    .fld .req { color: var(--brand); }
    .fld input, .fld select {
        font: inherit; font-size: 14.5px; padding: 11px 13px; border-radius: 10px;
        border: 1.5px solid var(--sand); background: var(--paper); color: var(--ink); width: 100%; transition: border-color .15s, box-shadow .15s;
    }
    .fld input:focus, .fld select:focus { outline: none; border-color: var(--brand);
        box-shadow: 0 0 0 3px color-mix(in srgb, var(--brand) 14%, transparent); }
    .subdomain-row { display: flex; align-items: stretch; border: 1.5px solid var(--sand); border-radius: 10px;
                     overflow: hidden; background: var(--paper); }
    .subdomain-row:focus-within { border-color: var(--brand); box-shadow: 0 0 0 3px color-mix(in srgb, var(--brand) 14%, transparent); }
    .subdomain-row input { border: none; box-shadow: none; background: transparent; text-align: right; }
    .subdomain-row input:focus { box-shadow: none; }
    .subdomain-suf { display: flex; align-items: center; padding: 0 13px; font-size: 13.5px; color: var(--muted);
                     background: color-mix(in srgb, var(--sand) 40%, transparent); white-space: nowrap; font-variant-numeric: tabular-nums; }
    .hp { position: absolute; left: -9999px; }

    @media (max-width: 900px) {
        .hero-grid { grid-template-columns: 1fr; gap: 36px; }
        .receipt { margin: 0 auto; }
        .feats { grid-template-columns: 1fr; }
        .signup-card { grid-template-columns: 1fr; }
        .signup-aside { display: none; }
    }
</style>

<header class="hero">
    <div class="wrap hero-grid">
        <div>
            <span class="eyebrow">Caisse & stock · Maroc</span>
            <h1>La caisse qui tient<br><span class="hl">votre commerce</span> en main.</h1>
            <p class="lead">Encaissez, suivez votre stock et vos ventes depuis une tablette — en boutique, au comptoir, même sans internet.</p>
            <div class="hero-cta">
                <a href="#inscription" class="btn btn-primary">Créer ma boutique</a>
                <a href="#fonctionnalites" class="btn btn-ghost">Voir comment ça marche</a>
            </div>
            <div class="hero-note"><span class="dot"></span> Votre espace prêt en quelques minutes après validation.</div>
        </div>

        <div>
            <div class="receipt" role="img" aria-label="Exemple de ticket de caisse CastLit POS">
                <div class="rc-badge">Hors ligne ✓</div>
                <div class="rc-head">
                    <div class="store">LIBRAIRIE AL MANARA</div>
                    <div class="sub">Casablanca · Ticket #10427</div>
                </div>
                <div style="padding:6px 0">
                    <div class="rc-line"><span class="q">2× Cahier 96p</span><span>24,00</span></div>
                    <div class="rc-line"><span class="q">1× Stylo bille bleu</span><span>6,50</span></div>
                    <div class="rc-line"><span class="q">1× Roman — Le Pain nu</span><span>48,00</span></div>
                    <div class="rc-total"><span>Total MAD</span><span>78,50</span></div>
                </div>
                <div class="rc-pay">Payé · Espèces</div>
            </div>
        </div>
    </div>

    <div class="wrap">
        <div class="strip">
            <span><span class="check">✓</span> Sans engagement</span>
            <span><span class="check">✓</span> Fonctionne hors ligne</span>
            <span><span class="check">✓</span> Multi-postes & multi-magasins</span>
            <span><span class="check">✓</span> Français & arabe</span>
        </div>
    </div>
</header>

<section class="section" id="fonctionnalites">
    <div class="wrap">
        <div class="section-head">
            <span class="eyebrow">Tout au même endroit</span>
            <h2>Pensé pour le rythme du comptoir.</h2>
            <p>Des librairies aux cafés, pharmacies et épiceries — CastLit s'adapte à votre activité dès la création de votre espace.</p>
        </div>
        <div class="feats">
            <div class="feat">
                <div class="ico">🧾</div>
                <h3>Encaissement rapide</h3>
                <p>Scannez, encaissez, imprimez le ticket. Espèces, carte, virement ou avance client — en quelques gestes.</p>
            </div>
            <div class="feat">
                <div class="ico">📦</div>
                <h3>Stock en temps réel</h3>
                <p>Suivi des quantités, alertes de rupture, inventaires et coûts d'achat calculés automatiquement.</p>
            </div>
            <div class="feat">
                <div class="ico">📶</div>
                <h3>Mode hors ligne</h3>
                <p>Une coupure internet ? Les ventes continuent et se synchronisent dès la reconnexion. Rien n'est perdu.</p>
            </div>
        </div>
    </div>
</section>

<section class="signup" id="inscription">
    <div class="wrap">
        <div class="signup-card">
            <aside class="signup-aside">
                <span class="eyebrow" style="color:#cfe0ff">Inscription</span>
                <h2>Créons votre espace CastLit.</h2>
                <p>Dites-nous en quelques champs qui vous êtes. Nous validons votre demande, puis votre caisse est mise en ligne sur votre propre adresse.</p>
                <ul>
                    <li><b>1</b><span>Vous remplissez le formulaire.</span></li>
                    <li><b>2</b><span>Nous validons et créons <code>votreboutique.{{ $mainDomain }}</code>.</span></li>
                    <li><b>3</b><span>Vous recevez vos accès par email et vous vendez.</span></li>
                </ul>
            </aside>

            <div class="form-body">
                @if ($errors->any())
                    <div class="flash flash-err">Merci de corriger les champs indiqués ci-dessous.</div>
                @endif

                <form method="POST" action="{{ route('castlit.subscribe') }}" novalidate>
                    @csrf
                    {{-- Honeypot: real users never see or fill this --}}
                    <input class="hp" type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">

                    <div class="fld">
                        <label for="business_name">Nom du commerce <span class="req">*</span></label>
                        <input id="business_name" name="business_name" required maxlength="120"
                               value="{{ old('business_name') }}" placeholder="Ex : Librairie Al Manara">
                        @error('business_name')<span class="field-error">{{ $message }}</span>@enderror
                    </div>

                    <div class="grid2">
                        <div class="fld">
                            <label for="activity">Activité</label>
                            <select id="activity" name="activity">
                                <option value="">Choisir…</option>
                                @foreach ($activities as $key => $label)
                                    <option value="{{ $key }}" @selected(old('activity') === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('activity')<span class="field-error">{{ $message }}</span>@enderror
                        </div>
                        <div class="fld">
                            <label for="currency">Devise <span class="req">*</span></label>
                            <select id="currency" name="currency" required>
                                @foreach ($currencies as $c)
                                    <option value="{{ $c }}" @selected(old('currency', 'MAD') === $c)>{{ $c }}</option>
                                @endforeach
                            </select>
                            @error('currency')<span class="field-error">{{ $message }}</span>@enderror
                        </div>
                    </div>

                    <div class="fld">
                        <label for="desired_subdomain">Adresse souhaitée <span class="req">*</span></label>
                        <div class="subdomain-row">
                            <input id="desired_subdomain" name="desired_subdomain" required
                                   pattern="[a-z0-9]{2,30}" maxlength="30" autocomplete="off" spellcheck="false"
                                   value="{{ old('desired_subdomain') }}" placeholder="almanara"
                                   oninput="this.value=this.value.toLowerCase().replace(/[^a-z0-9]/g,'')">
                            <span class="subdomain-suf">.{{ $mainDomain }}</span>
                        </div>
                        @error('desired_subdomain')<span class="field-error">{{ $message }}</span>@enderror
                    </div>

                    <div class="grid2">
                        <div class="fld">
                            <label for="contact_name">Votre nom <span class="req">*</span></label>
                            <input id="contact_name" name="contact_name" required maxlength="120"
                                   value="{{ old('contact_name') }}" placeholder="Prénom Nom">
                            @error('contact_name')<span class="field-error">{{ $message }}</span>@enderror
                        </div>
                        <div class="fld">
                            <label for="phone">Téléphone</label>
                            <input id="phone" name="phone" maxlength="40" value="{{ old('phone') }}"
                                   placeholder="06 00 00 00 00">
                            @error('phone')<span class="field-error">{{ $message }}</span>@enderror
                        </div>
                    </div>

                    <div class="fld">
                        <label for="email">Email <span class="req">*</span></label>
                        <input id="email" name="email" type="email" required maxlength="190"
                               value="{{ old('email') }}" placeholder="vous@exemple.com">
                        @error('email')<span class="field-error">{{ $message }}</span>@enderror
                    </div>

                    <div class="fld">
                        <label for="heard_about">Comment nous avez-vous connus ?</label>
                        <input id="heard_about" name="heard_about" maxlength="120"
                               value="{{ old('heard_about') }}" placeholder="Bouche-à-oreille, réseaux sociaux, recherche…">
                        @error('heard_about')<span class="field-error">{{ $message }}</span>@enderror
                    </div>

                    <button type="submit" class="btn btn-primary" style="width:100%; margin-top:6px">
                        Envoyer ma demande →
                    </button>
                    <p style="font-size:12px; color:var(--muted); margin-top:12px; text-align:center">
                        En envoyant ce formulaire, vous acceptez d'être contacté au sujet de votre inscription.
                    </p>
                </form>
            </div>
        </div>
    </div>
</section>
@endsection
