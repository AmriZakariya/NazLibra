@extends('castlit.layout')

@section('title', 'Conditions d’utilisation — Castl-it-POS')
@section('meta_description', 'Conditions d’utilisation du service et de l’application Castl-it-POS.')

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
</style>

@php $email = config('castlit.brand.email'); @endphp

<article class="legal">
    <h1>Conditions d’utilisation</h1>
    <p class="updated">Dernière mise à jour : 26 août 2026</p>

    <h2>1. Objet</h2>
    <p>Castl-it-POS fournit un logiciel de caisse et de gestion de stock, accessible via application mobile et navigateur. En utilisant le service, vous acceptez les présentes conditions.</p>

    <h2>2. Compte</h2>
    <p>Vous êtes responsable de l’exactitude des informations fournies et de la confidentialité de vos identifiants. Vous êtes responsable des activités réalisées depuis votre compte.</p>

    <h2>3. Usage acceptable</h2>
    <p>Vous vous engagez à utiliser le service conformément à la loi et à ne pas tenter d’y porter atteinte, de le contourner ou d’en perturber le fonctionnement.</p>

    <h2>4. Vos données</h2>
    <p>Les données commerciales que vous saisissez vous appartiennent. Leur traitement est décrit dans notre <a href="{{ route('castlit.privacy') }}">politique de confidentialité</a>.</p>

    <h2>5. Disponibilité</h2>
    <p>Nous nous efforçons d’assurer la disponibilité du service mais ne garantissons pas une absence totale d’interruption. Des maintenances peuvent avoir lieu.</p>

    <h2>6. Résiliation</h2>
    <p>Vous pouvez cesser d’utiliser le service à tout moment. Nous pouvons suspendre un compte en cas de non-respect des présentes conditions.</p>

    <h2>7. Contact</h2>
    <p>Pour toute question : <a href="mailto:{{ $email }}">{{ $email }}</a>.</p>
</article>
@endsection
