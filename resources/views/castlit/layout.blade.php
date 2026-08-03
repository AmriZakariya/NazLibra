<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'CastLit POS — La caisse simple pour votre commerce')</title>
    <meta name="description" content="@yield('meta_description', 'CastLit POS : la caisse tactile et la gestion de stock pour librairies, cafés, pharmacies et commerces au Maroc. Fonctionne même hors ligne.')">
    <style>
        :root {
            --brand: #3157D5;
            --brand-strong: #2743a8;
            --accent: #F59E0B;
            --ink: #0E1330;
            --paper: #FBFAF7;
            --surface: #FFFFFF;
            --sand: #E7E2D8;
            --muted: #5C6270;
            --ok: #16A34A;
            --ok-bg: #ECFDF3;
            --warn: #B45309;
            --warn-bg: #FEF6E7;
            --err: #DC2626;
            --err-bg: #FEF2F2;
            --radius: 16px;
            --shadow: 0 1px 2px rgba(14,19,48,.04), 0 12px 32px rgba(14,19,48,.07);
            --shadow-lg: 0 24px 60px rgba(14,19,48,.16);
            --font: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --paper: #0B0F1F;
                --surface: #141A2E;
                --sand: #263049;
                --ink: #F3F5FA;
                --muted: #9AA3B8;
                --shadow: 0 1px 2px rgba(0,0,0,.3), 0 12px 32px rgba(0,0,0,.4);
                --shadow-lg: 0 24px 60px rgba(0,0,0,.55);
                --ok-bg: #08281a; --warn-bg: #2a1d05; --err-bg: #2a0f0f;
            }
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        html { scroll-behavior: smooth; }
        body {
            font-family: var(--font);
            background: var(--paper);
            color: var(--ink);
            line-height: 1.55;
            -webkit-font-smoothing: antialiased;
        }
        a { color: inherit; text-decoration: none; }
        .wrap { max-width: 1140px; margin: 0 auto; padding: 0 24px; }

        .eyebrow {
            font-size: 12px; font-weight: 700; letter-spacing: .14em;
            text-transform: uppercase; color: var(--brand);
        }
        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            font-weight: 650; font-size: 15px; padding: 13px 22px; border-radius: 12px;
            border: 1px solid transparent; cursor: pointer; transition: transform .12s ease, box-shadow .18s ease, background .18s ease;
        }
        .btn-primary { background: var(--brand); color: #fff; box-shadow: 0 6px 20px color-mix(in srgb, var(--brand) 32%, transparent); }
        .btn-primary:hover { background: var(--brand-strong); transform: translateY(-1px); }
        .btn-ghost { background: transparent; color: var(--ink); border-color: var(--sand); }
        .btn-ghost:hover { border-color: var(--brand); color: var(--brand); }

        /* ── Nav ── */
        .nav { position: sticky; top: 0; z-index: 40; backdrop-filter: blur(12px);
               background: color-mix(in srgb, var(--paper) 82%, transparent);
               border-bottom: 1px solid color-mix(in srgb, var(--sand) 60%, transparent); }
        .nav-inner { display: flex; align-items: center; gap: 16px; height: 66px; }
        .brand { display: flex; align-items: center; gap: 10px; font-weight: 800; font-size: 18px; letter-spacing: -.02em; }
        .brand-mark { width: 34px; height: 34px; border-radius: 9px; display: grid; place-items: center;
                      background: linear-gradient(135deg, var(--brand), #5b7bf0); color: #fff; font-size: 17px; box-shadow: var(--shadow); }
        .brand-mark span { transform: translateY(-1px); }
        .nav .spacer { margin-left: auto; }

        footer { border-top: 1px solid var(--sand); margin-top: 80px; padding: 32px 0; color: var(--muted); font-size: 13px; }
        .footer-inner { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; }

        .field-error { color: var(--err); font-size: 12.5px; margin-top: 5px; font-weight: 500; }
        .flash { border-radius: 12px; padding: 12px 16px; font-size: 14px; font-weight: 500; margin-bottom: 18px; }
        .flash-ok { background: var(--ok-bg); color: var(--ok); }
        .flash-err { background: var(--err-bg); color: var(--err); }

        @media (prefers-reduced-motion: reduce) { * { transition: none !important; scroll-behavior: auto; } }
    </style>
    @yield('head')
</head>
<body>
    <nav class="nav">
        <div class="wrap nav-inner">
            <a href="{{ route('castlit.landing') }}" class="brand">
                <span class="brand-mark"><span>◲</span></span> CastLit<span style="color:var(--brand)">POS</span>
            </a>
            <div class="spacer"></div>
            <a href="{{ route('castlit.landing') }}#fonctionnalites" class="btn btn-ghost" style="padding:9px 16px">Fonctionnalités</a>
            <a href="{{ route('castlit.landing') }}#inscription" class="btn btn-primary" style="padding:9px 18px">Commencer</a>
        </div>
    </nav>

    @yield('content')

    <footer>
        <div class="wrap footer-inner">
            <a href="{{ route('castlit.landing') }}" class="brand" style="font-size:15px">
                <span class="brand-mark" style="width:26px;height:26px;font-size:13px"><span>◲</span></span> CastLitPOS
            </a>
            <span class="spacer" style="margin-left:auto"></span>
            <span>© {{ date('Y') }} CastLit — La caisse des commerces marocains.</span>
        </div>
    </footer>
    @stack('scripts')
</body>
</html>
