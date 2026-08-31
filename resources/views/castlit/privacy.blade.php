@extends('castlit.layout')

@section('title', 'Politique de confidentialité — Castl-it-POS')
@section('meta_description', 'Politique de confidentialité de l’application Castl-it-POS : données collectées, autorisations, sécurité, conservation et vos droits.')

@section('content')
<style>
    .legal { max-width: 820px; margin: 56px auto 0; padding: 0 24px 40px; }
    .legal h1 { font-size: clamp(28px, 4vw, 40px); font-weight: 800; letter-spacing: -.02em; }
    .legal .updated { color: var(--muted); font-size: 14px; margin-top: 8px; }
    .legal h2 { font-size: 20px; font-weight: 800; margin: 34px 0 10px; letter-spacing: -.01em; }
    .legal p, .legal li { color: var(--ink); font-size: 15.5px; line-height: 1.7; }
    .legal p { margin-top: 10px; }
    .legal ul { margin: 10px 0 0; padding-inline-start: 22px; }
    .legal li { margin-top: 6px; }
    .legal a { color: var(--brand); font-weight: 600; }
    .legal .lead { color: var(--muted); font-size: 17px; margin-top: 14px; }
    .legal .box { margin-top: 16px; background: var(--surface); border: 1px solid var(--sand); border-radius: 14px; padding: 16px 18px; box-shadow: var(--shadow); }
    [dir="rtl"] .legal ul { padding-inline-start: 22px; }
</style>

@php $email = config('castlit.brand.email'); @endphp

<article class="legal">
    <h1>Politique de confidentialité</h1>
    <p class="updated">Dernière mise à jour : 26 août 2026</p>

    <p class="lead">
        Castl-it-POS (« l’application », « nous ») est un logiciel de caisse et de gestion de stock
        destiné aux commerçants. Cette politique explique quelles données nous traitons, pourquoi,
        et quels sont vos droits. Elle s’applique à l’application mobile et au service web
        accessibles via <strong>{{ $mainDomain }}</strong>.
    </p>

    <h2>1. Responsable du traitement</h2>
    <p>
        Castl-it-POS — Contact : <a href="mailto:{{ $email }}">{{ $email }}</a>.
        Pour toute question relative à vos données, écrivez-nous à cette adresse.
    </p>

    <h2>2. Données que nous traitons</h2>
    <ul>
        <li><strong>Compte &amp; identité :</strong> nom, adresse e-mail, numéro de téléphone et rôle de l’utilisateur, pour créer et sécuriser votre accès.</li>
        <li><strong>Données commerciales que vous saisissez :</strong> produits, prix, stock, ventes, tickets, factures, clients et fournisseurs. Ces données vous appartiennent et sont gérées pour votre compte.</li>
        <li><strong>Données techniques :</strong> informations d’appareil et de session, journaux d’activité et d’erreurs, nécessaires au bon fonctionnement et à la sécurité.</li>
        <li><strong>Abonnement :</strong> informations de contact liées à votre demande. Les paiements éventuels sont traités par des prestataires de paiement ; nous ne stockons pas les numéros de carte.</li>
    </ul>
    <p>Nous ne vendons pas vos données et ne les utilisons pas à des fins publicitaires.</p>

    <h2>3. Autorisations de l’application</h2>
    <ul>
        <li><strong>Appareil photo :</strong> uniquement pour scanner les codes-barres des articles. Aucune image n’est conservée ni transmise.</li>
        <li><strong>Stockage / fichiers :</strong> pour le fonctionnement hors-ligne, l’export de documents (PDF, tickets) et les images de produits.</li>
        <li><strong>Réseau / Internet :</strong> pour synchroniser vos données lorsque vous êtes en ligne.</li>
    </ul>
    <p>Ces autorisations sont demandées au moment de l’usage et peuvent être révoquées à tout moment dans les réglages de votre appareil.</p>

    <h2>4. Comment nous utilisons les données</h2>
    <ul>
        <li>Fournir la caisse, la gestion de stock et les fonctionnalités associées ;</li>
        <li>Synchroniser vos données entre vos appareils (en ligne et hors-ligne) ;</li>
        <li>Sécuriser les comptes et prévenir les fraudes ou abus ;</li>
        <li>Fournir le support et améliorer le service.</li>
    </ul>

    <h2>5. Stockage &amp; sécurité</h2>
    <p>
        Les données de chaque commerce sont conservées dans un espace isolé qui lui est propre.
        Les échanges avec le service sont chiffrés en transit (HTTPS). Nous appliquons des mesures
        techniques et organisationnelles raisonnables pour protéger vos données contre l’accès non
        autorisé, la perte ou l’altération.
    </p>

    <h2>6. Partage des données</h2>
    <p>
        Nous ne partageons vos données qu’avec des prestataires strictement nécessaires au service
        (hébergement, envoi d’e-mails, paiement), tenus à la confidentialité, ou lorsque la loi
        l’exige. Aucune revente à des tiers.
    </p>

    <h2>7. Conservation &amp; suppression</h2>
    <p>
        Vos données sont conservées tant que votre compte est actif. Vous pouvez demander la
        suppression de votre compte et des données associées en nous écrivant à
        <a href="mailto:{{ $email }}">{{ $email }}</a> ; nous y donnons suite dans un délai
        raisonnable, sous réserve des obligations légales de conservation (par ex. comptables).
    </p>

    <h2>8. Vos droits</h2>
    <p>
        Selon votre pays, vous disposez de droits d’accès, de rectification, d’effacement, de
        limitation et de portabilité de vos données. Pour les exercer, contactez-nous à
        <a href="mailto:{{ $email }}">{{ $email }}</a>.
    </p>

    <h2>9. Enfants</h2>
    <p>L’application est destinée aux professionnels et n’est pas conçue pour les mineurs de moins de 16 ans.</p>

    <h2>10. Modifications</h2>
    <p>
        Nous pouvons mettre à jour cette politique. La date de « dernière mise à jour » ci-dessus
        indique la version en vigueur ; les changements importants seront signalés dans l’application
        ou sur le site.
    </p>

    <div class="box">
        <strong>Contact</strong>
        <p style="margin-top:6px">Une question sur vos données ? Écrivez-nous : <a href="mailto:{{ $email }}">{{ $email }}</a></p>
    </div>
</article>
@endsection
