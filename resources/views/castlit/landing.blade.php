@extends('castlit.layout')

@section('title', 'Castl-it-POS — Logiciel de caisse & gestion de stock au Maroc')
@section('meta_description', 'Castl-it-POS : logiciel de caisse tactile et gestion de stock pour librairies, cafés, restaurants, pharmacies et commerces au Maroc. Hors ligne, multi-postes, français & arabe. Essai sans engagement.')

@php
    $faqs = [
        ['q' => 'Castl-it-POS fonctionne-t-il sans connexion internet ?', 'a' => "Oui. Castl-it-POS est une caisse hors ligne : les ventes continuent même sans internet et se synchronisent automatiquement dès la reconnexion. Aucune vente n'est perdue."],
        ['q' => 'Pour quels types de commerce est-il adapté ?', 'a' => "Librairies, papeteries, cafés, restaurants, pharmacies, drogueries et commerces de détail. L'interface s'adapte à votre activité dès la création de votre espace."],
        ['q' => 'Est-ce disponible en français et en arabe ?', 'a' => 'Oui, Castl-it-POS est entièrement bilingue français / arabe, adapté au marché marocain (devise MAD, TVA, ICE).'],
        ['q' => 'Puis-je utiliser plusieurs caisses et plusieurs magasins ?', 'a' => 'Oui. Castl-it-POS gère plusieurs postes de caisse et plusieurs magasins ou dépôts avec un stock centralisé.'],
        ['q' => 'Comment démarrer ?', 'a' => "Remplissez le formulaire d'inscription. Après validation, votre espace est créé sur votre propre adresse (votreboutique.castlitpos.com) et vous recevez vos accès par email."],
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
    .hero { padding: 68px 0 40px; }
    .hero-grid { display: grid; grid-template-columns: 1.05fr .95fr; gap: 56px; align-items: center; }
    .hero h1 { font-size: clamp(34px, 5vw, 54px); line-height: 1.04; letter-spacing: -.03em; font-weight: 800; margin: 16px 0 18px; text-wrap: balance; }
    .hero h1 .hl { color: var(--brand); }
    .hero p.lead { font-size: 18px; color: var(--muted); max-width: 40ch; }
    .hero-cta { display: flex; gap: 12px; margin-top: 28px; flex-wrap: wrap; }
    .hero-note { margin-top: 16px; font-size: 13px; color: var(--muted); display: flex; align-items: center; gap: 7px; }
    .dot { width: 7px; height: 7px; border-radius: 50%; background: var(--ok); box-shadow: 0 0 0 3px var(--ok-bg); }

    .receipt { background: var(--surface); border-radius: 14px; box-shadow: var(--shadow-lg); padding: 26px 24px 30px; position: relative; max-width: 340px; margin-left: auto; border: 1px solid var(--sand);
               -webkit-mask: radial-gradient(9px at 9px 50%, transparent 98%, #000) 0 -9px/100% 18px; mask: radial-gradient(9px at 9px 50%, transparent 98%, #000) 0 -9px/100% 18px; }
    .rc-head { text-align: center; border-bottom: 2px dashed var(--sand); padding-bottom: 14px; }
    .rc-head .store { font-weight: 800; font-size: 17px; letter-spacing: -.01em; }
    .rc-head .sub { font-size: 11.5px; color: var(--muted); margin-top: 2px; }
    .rc-line { display: flex; justify-content: space-between; font-size: 13px; padding: 7px 0; font-variant-numeric: tabular-nums; }
    .rc-line .q { color: var(--muted); }
    .rc-total { display: flex; justify-content: space-between; font-weight: 800; font-size: 16px; border-top: 2px dashed var(--sand); margin-top: 8px; padding-top: 12px; font-variant-numeric: tabular-nums; }
    .rc-pay { margin-top: 14px; background: var(--brand); color: #fff; border-radius: 9px; text-align: center; font-weight: 700; font-size: 13px; padding: 10px; letter-spacing: .02em; }
    .rc-badge { position: absolute; top: -14px; right: -14px; background: var(--accent); color: #40260a; font-weight: 800; font-size: 11px; padding: 7px 11px; border-radius: 999px; box-shadow: var(--shadow); transform: rotate(4deg); }

    .strip { display: flex; flex-wrap: wrap; gap: 10px 26px; color: var(--muted); font-size: 13.5px; font-weight: 600; padding: 22px 0 8px; }
    .strip span { display: inline-flex; align-items: center; gap: 7px; }
    .check { color: var(--brand); font-weight: 800; }

    .section { padding: 62px 0 8px; }
    .section-head { max-width: 52ch; margin-bottom: 34px; }
    .section-head h2 { font-size: clamp(26px, 3.4vw, 34px); letter-spacing: -.02em; font-weight: 800; margin: 10px 0 8px; text-wrap: balance; }
    .section-head p { color: var(--muted); font-size: 16px; }

    .feats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; }
    .feat { background: var(--surface); border: 1px solid var(--sand); border-radius: var(--radius); padding: 22px; box-shadow: var(--shadow); }
    .feat .ico { width: 42px; height: 42px; border-radius: 11px; display: grid; place-items: center; font-size: 20px; background: color-mix(in srgb, var(--brand) 10%, transparent); color: var(--brand); margin-bottom: 14px; }
    .feat h3 { font-size: 16.5px; font-weight: 700; margin-bottom: 6px; }
    .feat p { color: var(--muted); font-size: 14px; }

    .sectors { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; }
    .sector { background: var(--surface); border: 1px solid var(--sand); border-radius: var(--radius); padding: 20px; box-shadow: var(--shadow); }
    .sector .ico { font-size: 26px; }
    .sector h3 { font-size: 15.5px; font-weight: 700; margin: 10px 0 6px; }
    .sector p { color: var(--muted); font-size: 13.5px; }

    .faq { max-width: 760px; }
    .faq details { border: 1px solid var(--sand); border-radius: 12px; background: var(--surface); margin-bottom: 10px; box-shadow: var(--shadow); }
    .faq summary { cursor: pointer; padding: 16px 18px; font-weight: 700; font-size: 15px; list-style: none; display: flex; justify-content: space-between; gap: 12px; }
    .faq summary::-webkit-details-marker { display: none; }
    .faq summary::after { content: '+'; color: var(--brand); font-weight: 800; }
    .faq details[open] summary::after { content: '−'; }
    .faq details p { padding: 0 18px 16px; color: var(--muted); font-size: 14.5px; }

    .signup { padding: 70px 0 10px; }
    .signup-card { background: var(--surface); border: 1px solid var(--sand); border-radius: 22px; box-shadow: var(--shadow-lg); overflow: hidden; display: grid; grid-template-columns: .8fr 1.2fr; }
    .signup-aside { background: linear-gradient(160deg, var(--brand), #24357e); color: #fff; padding: 38px 34px; }
    .signup-aside h2 { font-size: 26px; font-weight: 800; letter-spacing: -.02em; line-height: 1.12; text-wrap: balance; }
    .signup-aside p { color: rgba(255,255,255,.82); font-size: 14.5px; margin-top: 12px; }
    .signup-aside ul { list-style: none; margin-top: 22px; display: flex; flex-direction: column; gap: 12px; }
    .signup-aside li { display: flex; gap: 10px; font-size: 14px; align-items: flex-start; }
    .signup-aside li b { display: inline-grid; place-items: center; width: 20px; height: 20px; border-radius: 6px; background: rgba(255,255,255,.18); font-size: 12px; flex-shrink: 0; margin-top: 1px; }
    .form-body { padding: 34px; }
    .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    .fld { display: flex; flex-direction: column; gap: 6px; margin-bottom: 16px; }
    .fld label { font-size: 12.5px; font-weight: 650; color: var(--ink); }
    .fld .req { color: var(--brand); }
    .fld input, .fld select { font: inherit; font-size: 14.5px; padding: 11px 13px; border-radius: 10px; border: 1.5px solid var(--sand); background: var(--paper); color: var(--ink); width: 100%; transition: border-color .15s, box-shadow .15s; }
    .fld input:focus, .fld select:focus { outline: none; border-color: var(--brand); box-shadow: 0 0 0 3px color-mix(in srgb, var(--brand) 14%, transparent); }
    .subdomain-row { display: flex; align-items: stretch; border: 1.5px solid var(--sand); border-radius: 10px; overflow: hidden; background: var(--paper); }
    .subdomain-row:focus-within { border-color: var(--brand); box-shadow: 0 0 0 3px color-mix(in srgb, var(--brand) 14%, transparent); }
    .subdomain-row input { border: none; box-shadow: none; background: transparent; text-align: right; }
    .subdomain-row input:focus { box-shadow: none; }
    .subdomain-suf { display: flex; align-items: center; padding: 0 13px; font-size: 13.5px; color: var(--muted); background: color-mix(in srgb, var(--sand) 40%, transparent); white-space: nowrap; font-variant-numeric: tabular-nums; }
    .hp { position: absolute; left: -9999px; }

    @media (max-width: 900px) {
        .hero-grid { grid-template-columns: 1fr; gap: 36px; }
        .receipt { margin: 0 auto; }
        .feats { grid-template-columns: 1fr; }
        .sectors { grid-template-columns: 1fr 1fr; }
        .signup-card { grid-template-columns: 1fr; }
        .signup-aside { display: none; }
    }
</style>

<header class="hero">
    <div class="wrap hero-grid">
        <div>
            <span class="eyebrow">Caisse & gestion de stock · Maroc</span>
            <h1><span class="hl">Castl-it-POS</span>, le logiciel de caisse de votre commerce.</h1>
            <p class="lead">Encaissez, suivez votre stock et vos ventes depuis une tablette — en boutique, au comptoir, même sans internet. En français et en arabe.</p>
            <div class="hero-cta">
                <a href="#inscription" class="btn btn-primary">Créer ma boutique</a>
                <a href="#fonctionnalites" class="btn btn-ghost">Voir comment ça marche</a>
            </div>
            <div class="hero-note"><span class="dot"></span> Votre espace prêt en quelques minutes après validation.</div>
        </div>

        <div>
            <div class="receipt" role="img" aria-label="Exemple de ticket de caisse Castl-it-POS">
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

<section class="section" id="fonctionnalites" aria-labelledby="fonctionnalites-title">
    <div class="wrap">
        <div class="section-head">
            <span class="eyebrow">Tout au même endroit</span>
            <h2 id="fonctionnalites-title">Un logiciel de caisse pensé pour le rythme du comptoir</h2>
            <p>Castl-it-POS réunit l'encaissement, le stock et le suivi des ventes dans une seule application tactile, simple à prendre en main.</p>
        </div>
        <div class="feats">
            <article class="feat">
                <div class="ico">🧾</div>
                <h3>Encaissement rapide</h3>
                <p>Scannez, encaissez, imprimez le ticket. Espèces, carte, virement ou avance client — en quelques gestes.</p>
            </article>
            <article class="feat">
                <div class="ico">📦</div>
                <h3>Gestion de stock en temps réel</h3>
                <p>Suivi des quantités, alertes de rupture, inventaires et coûts d'achat calculés automatiquement.</p>
            </article>
            <article class="feat">
                <div class="ico">📶</div>
                <h3>Mode hors ligne</h3>
                <p>Une coupure internet ? Les ventes continuent et se synchronisent dès la reconnexion. Rien n'est perdu.</p>
            </article>
            <article class="feat">
                <div class="ico">🧮</div>
                <h3>Factures & devis</h3>
                <p>Générez factures et devis conformes (TVA, ICE), suivez les paiements et les échéances client.</p>
            </article>
            <article class="feat">
                <div class="ico">🏪</div>
                <h3>Multi-magasins</h3>
                <p>Plusieurs points de vente et dépôts, un stock centralisé et des rôles par utilisateur.</p>
            </article>
            <article class="feat">
                <div class="ico">📊</div>
                <h3>Tableau de bord</h3>
                <p>Chiffre d'affaires, marges, top produits et heures de pointe — vos indicateurs en un coup d'œil.</p>
            </article>
        </div>
    </div>
</section>

<section class="section" id="secteurs" aria-labelledby="secteurs-title">
    <div class="wrap">
        <div class="section-head">
            <span class="eyebrow">Adapté à votre métier</span>
            <h2 id="secteurs-title">Une caisse pour chaque commerce</h2>
            <p>Castl-it-POS s'adapte à votre activité dès la création de votre espace — catalogue, unités et libellés sur mesure.</p>
        </div>
        <div class="sectors">
            <article class="sector"><div class="ico">📚</div><h3>Librairies & papeteries</h3><p>Recherche par ISBN et code-barres, catalogue livres & fournitures scolaires.</p></article>
            <article class="sector"><div class="ico">☕</div><h3>Cafés & restaurants</h3><p>Menus, tickets cuisine, service au comptoir et en salle.</p></article>
            <article class="sector"><div class="ico">💊</div><h3>Pharmacies & parapharmacies</h3><p>Suivi des lots, péremptions et produits de parapharmacie.</p></article>
            <article class="sector"><div class="ico">🛒</div><h3>Commerces de détail</h3><p>Boutiques, drogueries et épiceries : stock, marques et fournisseurs.</p></article>
        </div>
    </div>
</section>

<section class="section" id="faq" aria-labelledby="faq-title">
    <div class="wrap">
        <div class="section-head">
            <span class="eyebrow">Questions fréquentes</span>
            <h2 id="faq-title">Tout ce que vous devez savoir sur Castl-it-POS</h2>
        </div>
        <div class="faq">
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
    <div class="wrap">
        <div class="signup-card">
            <aside class="signup-aside">
                <span class="eyebrow" style="color:#cfe0ff">Inscription</span>
                <h2 id="inscription-title">Créons votre espace Castl-it-POS.</h2>
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
                    <input class="hp" type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">

                    <div class="fld">
                        <label for="business_name">Nom du commerce <span class="req">*</span></label>
                        <input id="business_name" name="business_name" required maxlength="120" value="{{ old('business_name') }}" placeholder="Ex : Librairie Al Manara">
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
                            <input id="desired_subdomain" name="desired_subdomain" required pattern="[a-z0-9]{2,30}" maxlength="30" autocomplete="off" spellcheck="false" value="{{ old('desired_subdomain') }}" placeholder="almanara" oninput="this.value=this.value.toLowerCase().replace(/[^a-z0-9]/g,'')">
                            <span class="subdomain-suf">.{{ $mainDomain }}</span>
                        </div>
                        @error('desired_subdomain')<span class="field-error">{{ $message }}</span>@enderror
                    </div>

                    <div class="grid2">
                        <div class="fld">
                            <label for="contact_name">Votre nom <span class="req">*</span></label>
                            <input id="contact_name" name="contact_name" required maxlength="120" value="{{ old('contact_name') }}" placeholder="Prénom Nom">
                            @error('contact_name')<span class="field-error">{{ $message }}</span>@enderror
                        </div>
                        <div class="fld">
                            <label for="phone">Téléphone</label>
                            <input id="phone" name="phone" maxlength="40" value="{{ old('phone') }}" placeholder="06 00 00 00 00">
                            @error('phone')<span class="field-error">{{ $message }}</span>@enderror
                        </div>
                    </div>

                    <div class="fld">
                        <label for="email">Email <span class="req">*</span></label>
                        <input id="email" name="email" type="email" required maxlength="190" value="{{ old('email') }}" placeholder="vous@exemple.com">
                        @error('email')<span class="field-error">{{ $message }}</span>@enderror
                    </div>

                    <div class="fld">
                        <label for="heard_about">Comment nous avez-vous connus ?</label>
                        <input id="heard_about" name="heard_about" maxlength="120" value="{{ old('heard_about') }}" placeholder="Bouche-à-oreille, réseaux sociaux, recherche…">
                        @error('heard_about')<span class="field-error">{{ $message }}</span>@enderror
                    </div>

                    <button type="submit" class="btn btn-primary" style="width:100%; margin-top:6px">Envoyer ma demande →</button>
                    <p style="font-size:12px; color:var(--muted); margin-top:12px; text-align:center">En envoyant ce formulaire, vous acceptez d'être contacté au sujet de votre inscription.</p>
                </form>
            </div>
        </div>
    </div>
</section>
@endsection
