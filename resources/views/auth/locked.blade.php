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
                <div class="lock-brand">
                    <span>{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($tenant?->name ?? 'LP', 0, 2)) }}</span>
                    <div>
                        <p>{{ $tenant?->name ?? 'LibrairePro' }}</p>
                        <h1>Session verrouillée</h1>
                    </div>
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
                        <input name="pin" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code" required minlength="4" maxlength="8" autofocus placeholder="••••">
                    </label>
                    @error('pin')
                        <p class="lock-error">{{ $message }}</p>
                    @enderror
                    @unless ($hasPin)
                        <p class="lock-warning">Aucun PIN n’est configuré pour ce compte. Utilisez le mot de passe ou demandez au propriétaire de définir un PIN.</p>
                    @endunless
                    <button>Déverrouiller</button>
                </form>

                <details class="lock-forgot">
                    <summary>PIN oublié</summary>
                    <form action="{{ route('session.forgot-pin') }}" method="POST">
                        @csrf
                        <p>Déverrouillage de secours avec le mot de passe du compte. Le propriétaire pourra ensuite réinitialiser le PIN.</p>
                        <input name="password" type="password" autocomplete="current-password" required placeholder="Mot de passe du compte">
                        @error('password')
                            <p class="lock-error">{{ $message }}</p>
                        @enderror
                        <button type="submit">Déverrouiller avec mot de passe</button>
                    </form>
                </details>

                <form action="{{ route('logout') }}" method="POST" class="lock-logout">
                    @csrf
                    <button type="submit">Se déconnecter</button>
                </form>
            </section>
        </main>
    </body>
</html>
