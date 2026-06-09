@php
    $locale = \App\Support\Locale::current($tenant);
    $direction = \App\Support\Locale::dir($locale);
    $tr = fn (string $text): string => \App\Support\Locale::t($text, $locale);
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $direction }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>Session verrouillée · LibrairePro</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-slate-950 text-white antialiased">
        <main class="lock-screen">
            <section class="lock-card">
                <div class="lock-icon-wrap">
                    <svg class="lock-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                        <circle cx="12" cy="16" r="1"/>
                        <line x1="12" y1="16" x2="12" y2="13"/>
                    </svg>
                </div>

                <div class="lock-user">
                    <x-user-avatar :user="$user" size="lg" rounded="rounded-2xl" />
                    <div>
                        <strong>{{ $user?->name }}</strong>
                        <span>{{ $user?->email }}</span>
                    </div>
                </div>

                <form action="{{ route('session.unlock') }}" method="POST" class="lock-form">
                    @csrf
                    <label>
                        <span>PIN caisse</span>
                        <input name="pin" type="password" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code" required minlength="4" maxlength="8" autofocus placeholder="&#xb7;&#xb7;&#xb7;&#xb7;">
                    </label>
                    @error('pin')
                        <p class="lock-error">{{ $message }}</p>
                    @enderror
                    @unless ($hasPin)
                        <p class="lock-warning">Aucun PIN n&rsquo;est configur&eacute; pour ce compte. Utilisez le mot de passe ou demandez au propri&eacute;taire de d&eacute;finir un PIN.</p>
                    @endunless
                    <button type="submit">D&eacute;verrouiller</button>
                </form>

                <details class="lock-forgot">
                    <summary>PIN oubli&eacute;</summary>
                    <div class="lock-forgot-body">
                        <p>D&eacute;verrouillage de secours avec le mot de passe du compte. Le propri&eacute;taire pourra ensuite r&eacute;initialiser le PIN.</p>
                        <form action="{{ route('session.forgot-pin') }}" method="POST">
                            @csrf
                            <input name="password" type="password" autocomplete="current-password" required placeholder="Mot de passe du compte">
                            @error('password')
                                <p class="lock-error">{{ $message }}</p>
                            @enderror
                            <button type="submit">D&eacute;verrouiller avec mot de passe</button>
                        </form>
                        <hr class="lock-forgot-divider">
                        <p>Ou r&eacute;initialisez votre PIN par email :</p>
                        <form action="{{ route('session.send-pin-reset') }}" method="POST">
                            @csrf
                            <button type="submit" class="lock-forgot-email-btn">Envoyer le lien de r&eacute;initialisation</button>
                        </form>
                        @if (session('status'))
                            <p class="lock-success">{{ session('status') }}</p>
                        @endif
                    </div>
                </details>

                <form action="{{ route('logout') }}" method="POST" class="lock-logout">
                    @csrf
                    <button type="submit">Se d&eacute;connecter</button>
                </form>
            </section>
        </main>
    </body>
</html>
