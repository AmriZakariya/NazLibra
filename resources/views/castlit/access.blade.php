<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Accès sécurisé · Castl-it POS</title>
    <style>
        :root { --brand: #3157D5; --ink: #0E1330; --muted: #6b7280; --sand: #e5e7eb; --err: #dc2626; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
               background: linear-gradient(135deg, #EEF0FF, #F8F9FF 60%, #fff); color: var(--ink);
               min-height: 100vh; display: grid; place-items: center; padding: 24px; }
        .card { width: 100%; max-width: 400px; background: #fff; border-radius: 22px; padding: 32px 28px;
                box-shadow: 0 24px 60px rgba(48,63,159,.10), 0 4px 16px rgba(0,0,0,.05); }
        .mark { width: 64px; height: 64px; border-radius: 18px; margin: 0 auto 18px;
                background: linear-gradient(135deg, var(--brand), #5C6BC0); display: grid; place-items: center;
                color: #fff; font-size: 30px; font-weight: 800; }
        h1 { font-size: 21px; margin: 0 0 6px; text-align: center; }
        p.sub { margin: 0 0 22px; text-align: center; color: var(--muted); font-size: 14px; line-height: 1.5; }
        .client { display: inline-block; margin: 0 auto 20px; padding: 4px 12px; border-radius: 20px;
                  background: rgba(49,87,213,.08); color: var(--brand); font-weight: 700; font-size: 13px; }
        .client-wrap { text-align: center; }
        label { display: block; font-size: 12px; font-weight: 700; color: var(--muted); margin-bottom: 6px;
                text-transform: uppercase; letter-spacing: .4px; }
        input { width: 100%; height: 54px; border: 1px solid var(--sand); border-radius: 12px; padding: 0 16px;
                font-size: 22px; font-weight: 700; letter-spacing: 6px; text-align: center; text-transform: uppercase;
                outline: none; }
        input:focus { border-color: var(--brand); box-shadow: 0 0 0 3px rgba(49,87,213,.15); }
        .err { background: #fef2f2; color: var(--err); border-radius: 10px; padding: 10px 12px; font-size: 13px;
               margin-top: 14px; }
        button { width: 100%; height: 52px; margin-top: 20px; border: 0; border-radius: 12px; cursor: pointer;
                 background: var(--brand); color: #fff; font-size: 16px; font-weight: 700; }
        button:hover { background: #2647c0; }
        .foot { margin-top: 18px; text-align: center; font-size: 12px; color: var(--muted); }
    </style>
</head>
<body>
    <form class="card" method="POST" action="{{ route('castlit.access.verify') }}">
        @csrf
        <div class="mark">C</div>
        <h1>Accès sécurisé</h1>
        <p class="sub">Entrez le code de vérification communiqué par votre administrateur pour accéder à votre espace.</p>
        @if ($subdomain)
            <div class="client-wrap"><span class="client">{{ $subdomain }}.{{ config('castlit.main_domain') }}</span></div>
        @endif
        <label for="code">Code de vérification</label>
        <input id="code" name="code" autocomplete="one-time-code" autocapitalize="characters"
               autofocus maxlength="12" placeholder="A1B2C3" value="{{ old('code') }}">
        @error('code')
            <div class="err">{{ $message }}</div>
        @enderror
        <button type="submit">Vérifier</button>
        <p class="foot">Castl-it POS</p>
    </form>
</body>
</html>
