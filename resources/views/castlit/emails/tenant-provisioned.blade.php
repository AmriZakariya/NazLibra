{{--
    Welcome email for a client whose space just went live (and the admin's
    "Renvoyer les accès"). Tables and inline styles on purpose: mail clients
    ignore most of CSS, and Gmail drops <style> blocks in some views — the
    block below only refines dark mode where it is supported.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>Votre espace Castl-it-POS est prêt</title>
    <style>
        @media (prefers-color-scheme: dark) {
            .bg { background: #0E1330 !important; }
            .card { background: #161C3D !important; border-color: #2A3260 !important; }
            .ink { color: #F2F3F8 !important; }
            .muted { color: #A8AEC4 !important; }
            .panel { background: #1D2550 !important; border-color: #33407A !important; }
            .code { color: #B9C8FF !important; }
            .rule { border-color: #2A3260 !important; }
        }
        @media (max-width: 520px) {
            .pad { padding-left: 20px !important; padding-right: 20px !important; }
            .half { display: block !important; width: 100% !important; }
            .half + .half { padding-top: 16px !important; }
        }
    </style>
</head>
<body class="bg" style="margin:0; padding:0; background:#F3F1EC;">
{{-- Inbox preview line. --}}
<div style="display:none; max-height:0; overflow:hidden; opacity:0;">
    « {{ $businessName }} » est en ligne. Nom du client : {{ $clientName }} · Code de vérification : {{ $code }}
</div>

<table role="presentation" class="bg" width="100%" cellpadding="0" cellspacing="0" style="background:#F3F1EC;">
<tr><td align="center" style="padding:32px 12px;">

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;">

    {{-- Wordmark --}}
    <tr><td style="padding:0 4px 18px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
        <span style="display:inline-block; width:10px; height:10px; border-radius:3px; background:#3157D5; vertical-align:middle;"></span>
        <span class="ink" style="font-size:17px; font-weight:800; color:#0E1330; letter-spacing:-0.2px; vertical-align:middle;">&nbsp;Castl-it-POS</span>
    </td></tr>

    <tr><td class="card" style="background:#FFFFFF; border:1px solid #E7E2D8; border-radius:16px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">

        {{-- Greeting --}}
        <tr><td class="pad" style="padding:32px 36px 8px;">
            <p style="margin:0 0 6px; font-size:12px; font-weight:700; letter-spacing:1px; text-transform:uppercase; color:#16A34A;">● Espace en ligne</p>
            <h1 class="ink" style="margin:0 0 10px; font-size:24px; line-height:1.25; font-weight:800; color:#0E1330;">Bonjour {{ $contactName }},</h1>
            <p class="muted" style="margin:0; font-size:15px; line-height:1.6; color:#5C6270;">
                Votre boutique <strong class="ink" style="color:#0E1330;">« {{ $businessName }} »</strong> est prête.
                Voici tout ce qu'il faut pour vous connecter, sur ordinateur comme sur téléphone.
            </p>
        </td></tr>

        {{-- Credentials --}}
        <tr><td class="pad" style="padding:22px 36px 6px;">
        <table role="presentation" class="panel" width="100%" cellpadding="0" cellspacing="0" style="background:#F4F6FD; border:1px solid #DCE3FA; border-radius:12px;">
            <tr>
                <td class="half" width="50%" valign="top" style="padding:18px 20px 0;">
                    <p class="muted" style="margin:0 0 4px; font-size:11px; font-weight:700; letter-spacing:0.8px; text-transform:uppercase; color:#5C6270;">Nom du client</p>
                    <p class="ink" style="margin:0; font-family:ui-monospace,Menlo,Consolas,monospace; font-size:20px; font-weight:700; color:#0E1330;">{{ $clientName }}</p>
                </td>
                <td class="half" width="50%" valign="top" style="padding:18px 20px 0;">
                    <p class="muted" style="margin:0 0 4px; font-size:11px; font-weight:700; letter-spacing:0.8px; text-transform:uppercase; color:#5C6270;">Code de vérification</p>
                    <p class="code" style="margin:0; font-family:ui-monospace,Menlo,Consolas,monospace; font-size:26px; font-weight:800; letter-spacing:5px; color:#2743A8;">{{ $code }}</p>
                </td>
            </tr>
            <tr><td colspan="2" style="padding:16px 20px 0;"><div class="rule" style="border-top:1px solid #DCE3FA;"></div></td></tr>
            <tr><td colspan="2" style="padding:14px 20px 18px; font-size:14px; line-height:1.7;">
                <span class="muted" style="color:#5C6270;">Identifiant&nbsp;:</span>
                <strong class="ink" style="color:#0E1330;">{{ $login }}</strong><br>
                <span class="muted" style="color:#5C6270;">Mot de passe&nbsp;:</span>
                @if ($password)
                    <strong class="ink" style="font-family:ui-monospace,Menlo,Consolas,monospace; color:#0E1330;">{{ $password }}</strong>
                    <span class="muted" style="color:#5C6270;"> — provisoire, changez-le à la première connexion.</span>
                @else
                    <span class="ink" style="color:#0E1330;">celui choisi à l'inscription</span>
                    <span class="muted" style="color:#5C6270;"> (oublié&nbsp;? utilisez « Mot de passe oublié »).</span>
                @endif
            </td></tr>
        </table>
        </td></tr>

        {{-- On a computer --}}
        <tr><td class="pad" style="padding:26px 36px 0;">
            <h2 class="ink" style="margin:0 0 6px; font-size:16px; font-weight:800; color:#0E1330;">Sur ordinateur</h2>
            <p class="muted" style="margin:0 0 16px; font-size:14px; line-height:1.6; color:#5C6270;">
                Ouvrez votre espace et connectez-vous avec l'identifiant et le mot de passe ci-dessus.
            </p>
            <table role="presentation" cellpadding="0" cellspacing="0"><tr>
                <td style="border-radius:10px; background:#3157D5;">
                    <a href="{{ $url }}" style="display:inline-block; padding:13px 26px; font-size:15px; font-weight:700; color:#FFFFFF; text-decoration:none; border-radius:10px;">Ouvrir mon espace →</a>
                </td>
            </tr></table>
            <p class="muted" style="margin:10px 0 0; font-size:13px; color:#5C6270;">
                ou copiez : <a href="{{ $url }}" style="color:#3157D5; text-decoration:none;">{{ $domain }}</a>
            </p>
        </td></tr>

        <tr><td class="pad" style="padding:26px 36px 0;"><div class="rule" style="border-top:1px solid #EFEBE3;"></div></td></tr>

        {{-- On a phone or a POS terminal --}}
        <tr><td class="pad" style="padding:22px 36px 0;">
            <h2 class="ink" style="margin:0 0 14px; font-size:16px; font-weight:800; color:#0E1330;">Sur téléphone ou terminal de caisse</h2>
            @php
                $steps = [
                    "Installez l'application Castl-it-POS.",
                    "Touchez « J'ai déjà un compte ».",
                    'Saisissez le nom du client <strong>'.e($clientName).'</strong> et le code <strong>'.e($code).'</strong>, puis « Vérifier ».',
                    "Connectez-vous avec votre identifiant et votre mot de passe.",
                ];
            @endphp
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                @foreach ($steps as $i => $step)
                <tr>
                    <td width="34" valign="top" style="padding:0 0 12px;">
                        <span style="display:inline-block; width:24px; height:24px; line-height:24px; border-radius:12px; background:#EEF2FD; color:#2743A8; font-size:12px; font-weight:800; text-align:center;">{{ $i + 1 }}</span>
                    </td>
                    <td class="ink" valign="top" style="padding:2px 0 12px; font-size:14px; line-height:1.55; color:#0E1330;">{!! $step !!}</td>
                </tr>
                @endforeach
            </table>
            @if ($playStore || $appStore)
            <table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:4px;"><tr>
                @if ($playStore)
                <td style="border-radius:10px; background:#0E1330; padding:0;">
                    <a href="{{ $playStore }}" style="display:inline-block; padding:11px 18px; font-size:14px; font-weight:700; color:#FFFFFF; text-decoration:none;">▶&nbsp; Google Play</a>
                </td>
                @endif
                @if ($playStore && $appStore)<td width="10"></td>@endif
                @if ($appStore)
                <td style="border-radius:10px; background:#0E1330; padding:0;">
                    <a href="{{ $appStore }}" style="display:inline-block; padding:11px 18px; font-size:14px; font-weight:700; color:#FFFFFF; text-decoration:none;">App Store</a>
                </td>
                @endif
            </tr></table>
            @endif
        </td></tr>

        {{-- Sign-off --}}
        <tr><td class="pad" style="padding:28px 36px 32px;">
            <p class="muted" style="margin:0; font-size:14px; line-height:1.6; color:#5C6270;">
                Gardez cet email : le nom du client et le code servent à chaque nouvel appareil.<br>
                Merci de votre confiance et bienvenue à bord&nbsp;!
            </p>
            <p class="ink" style="margin:14px 0 0; font-size:14px; font-weight:700; color:#0E1330;">L'équipe Castl-it-POS</p>
        </td></tr>

    </table>
    </td></tr>

    {{-- Footer --}}
    <tr><td align="center" class="muted" style="padding:18px 12px 0; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:12px; line-height:1.6; color:#8A8F9C;">
        Une question&nbsp;? Répondez simplement à cet email
        @if ($support)
            ou écrivez à <a href="mailto:{{ $support }}" style="color:#5C6270;">{{ $support }}</a>
        @endif.
    </td></tr>

</table>
</td></tr>
</table>
</body>
</html>
