Bonjour {!! $contactName !!},

Votre boutique « {!! $businessName !!} » est prête sur Castl-it-POS.

VOS ACCÈS
  Nom du client        : {!! $clientName !!}
  Code de vérification : {!! $code !!}
  Identifiant          : {!! $login !!}
@if ($password)
  Mot de passe         : {!! $password !!} (provisoire, changez-le à la première connexion)
@else
  Mot de passe         : celui choisi à l'inscription (oublié ? « Mot de passe oublié »)
@endif

SUR ORDINATEUR
  {!! $url !!}

SUR TÉLÉPHONE OU TERMINAL DE CAISSE
  1. Installez l'application Castl-it-POS{!! $playStore ? ' : '.$playStore : '' !!}
@if ($appStore)
     iPhone : {!! $appStore !!}
@endif
  2. Touchez « J'ai déjà un compte ».
  3. Saisissez le nom du client {!! $clientName !!} et le code {!! $code !!}, puis « Vérifier ».
  4. Connectez-vous avec votre identifiant et votre mot de passe.

Gardez cet email : le nom du client et le code servent à chaque nouvel appareil.

Merci de votre confiance et bienvenue à bord !
L'équipe Castl-it-POS
@if ($support)
{!! $support !!}
@endif
