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
        <title>{{ $tr('Session verrouillée') }} · LibrairePro</title>
        <link rel="icon" type="image/png" sizes="32x32" href="{{ route('app.icon', 32) }}">
        <link rel="shortcut icon" href="{{ route('app.icon', 32) }}" type="image/x-icon">
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-slate-950 text-white antialiased">
        <main class="lock-screen">
            {{-- Animated background orbs --}}
            <div class="lock-orb lock-orb-1"></div>
            <div class="lock-orb lock-orb-2"></div>
            <div class="lock-orb lock-orb-3"></div>

            <section class="lock-card">
                {{-- Brand / Icon --}}
                <div class="lock-header">
                    <div class="lock-icon-wrap">
                        <svg class="lock-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                            <circle cx="12" cy="16" r="1"/>
                            <line x1="12" y1="16" x2="12" y2="13"/>
                        </svg>
                    </div>
                    <h1 class="lock-title">{{ $tr('Session verrouillée') }}</h1>
                    <p class="lock-subtitle">LibrairePro</p>
                </div>

                {{-- User info --}}
                <div class="lock-user">
                    <x-user-avatar :user="$user" size="lg" rounded="rounded-2xl" />
                    <div class="lock-user-info">
                        <strong>{{ $user?->name }}</strong>
                        <span>{{ $tr('Session verrouillée par cet utilisateur') }}</span>
                    </div>
                </div>

                {{-- PIN form --}}
                <form action="{{ route('session.unlock') }}" method="POST" class="lock-form">
                    @csrf
                    <label class="lock-field">
                        <span>{{ $tr('Entrez votre PIN pour déverrouiller') }}</span>
                        <input
                            name="pin"
                            type="password"
                            inputmode="numeric"
                            pattern="[0-9]{4}"
                            autocomplete="one-time-code"
                            required
                            minlength="4"
                            maxlength="4"
                            autofocus
                            placeholder="••••"
                            class="lock-input"
                        >
                    </label>
                    @error('pin')
                        <p class="lock-error">{{ $message }}</p>
                    @enderror
                    @unless ($hasPin)
                        <p class="lock-warning">{{ $tr('Aucun PIN n\'est configuré. Utilisez le mot de passe pour déverrouiller ou demandez au propriétaire de définir un PIN.') }}</p>
                    @endunless
                    <button type="submit" class="lock-btn-primary">
                        <span>{{ $tr('Déverrouiller') }}</span>
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                    </button>
                </form>

                {{-- Forgot PIN --}}
                <details class="lock-forgot">
                    <summary class="lock-forgot-summary">
                        <span>{{ $tr('PIN oublié') }}</span>
                        <svg class="lock-forgot-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                    </summary>
                    <div class="lock-forgot-body">
                        <p class="lock-forgot-text">{{ $tr('Déverrouillage de secours avec le mot de passe du compte. Le propriétaire pourra ensuite réinitialiser le PIN.') }}</p>

                        <form action="{{ route('session.forgot-pin') }}" method="POST" class="lock-forgot-form">
                            @csrf
                            <label class="lock-field">
                                <input
                                    name="password"
                                    type="password"
                                    autocomplete="current-password"
                                    required
                                    placeholder="{{ $tr('Mot de passe du compte') }}"
                                    class="lock-input lock-input-sm"
                                >
                            </label>
                            @error('password')
                                <p class="lock-error">{{ $message }}</p>
                            @enderror
                            <button type="submit" class="lock-btn-secondary">{{ $tr('Déverrouiller avec mot de passe') }}</button>
                        </form>

                        <hr class="lock-forgot-divider">

                        <p class="lock-forgot-text">{{ $tr('Ou réinitialisez votre PIN par email :') }}</p>
                        <form action="{{ route('session.send-pin-reset') }}" method="POST">
                            @csrf
                            <button type="submit" class="lock-btn-ghost">{{ $tr('Envoyer le lien de réinitialisation') }}</button>
                        </form>
                        @if (session('status'))
                            <p class="lock-success">{{ session('status') }}</p>
                        @endif
                    </div>
                </details>

                {{-- Logout --}}
                <form action="{{ route('logout') }}" method="POST" class="lock-logout">
                    @csrf
                    <button type="submit" class="lock-logout-btn">
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                        <span>{{ $tr('Se déconnecter') }}</span>
                    </button>
                </form>
            </section>
        </main>
    </body>
</html>
