Bonjour {!! $contactName !!},

Votre boutique « {!! $businessName !!} » est prête sur Castl-it-POS.

VOS ACCÈS
  Nom du client        : {!! $clientName !!}
  Code de vérification : {!! $code !!}

COMPTE PRINCIPAL (le vôtre, pour vendre et gérer la boutique)
  Identifiant          : {!! $login !!}
@if ($password)
  Mot de passe         : {!! $password !!}
                         {!! $isResend ? "(mot de passe initial — si vous l'avez changé, gardez le vôtre)" : '(à changer après la première connexion)' !!}
@else
  Mot de passe         : défini à la création de votre espace (oublié ? « Mot de passe oublié »)
@endif
@if ($adminLogin)

COMPTE ADMINISTRATEUR (tous les droits : à garder de côté)
  Identifiant          : {!! $adminLogin !!}
@if ($adminPassword)
  Mot de passe         : {!! $adminPassword !!}
@else
  Mot de passe         : défini à la création de votre espace (oublié ? « Mot de passe oublié »)
@endif
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
  4. Connectez-vous avec l'un des comptes ci-dessus.

Gardez cet email : le nom du client et le code servent à chaque nouvel appareil.

Merci de votre confiance et bienvenue à bord !
L'équipe Castl-it-POS
@if ($support)
{!! $support !!}
@endif
