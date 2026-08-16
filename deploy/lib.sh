#!/bin/bash
# ============================================================================
#  Castl-it-POS — shared helpers for provision.sh / manage.sh
#  Sourced, not executed. Keeps the client .htaccess and the branded
#  "suspended" page identical across provisioning and later management.
# ============================================================================

# Write the client root .htaccess.
# Docroot is the client dir, so it forwards to public/ via FallbackResource and
# maps the static-asset folders. A ".suspended" marker file (toggled by
# manage.sh) short-circuits every request to the branded 503 page.
write_client_htaccess() {
  local DOC_ROOT="$1"
  cat > "$DOC_ROOT/.htaccess" <<'HT'
Options -Indexes
ErrorDocument 503 /suspended.html
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /
    # Suspended client: serve the branded 503 page until the marker is removed.
    RewriteCond %{DOCUMENT_ROOT}/.suspended -f
    RewriteCond %{REQUEST_URI} !/suspended\.html$
    RewriteRule ^ - [R=503,L]
    RewriteRule (^|/)\.(?!well-known) - [F,L]
    RewriteRule ^(app|bootstrap|config|database|lang|resources|routes|scripts|tests|vendor)(/|$) - [F,L]
    RewriteRule ^(composer\.(json|lock)|package(-lock)?\.json|artisan|phpunit\.xml|vite\.config\.js)$ - [F,L]
    RewriteRule \.(md|sqlite)$ - [F,L]
    RewriteRule ^(build|css|fonts|img|js|storage)/(.*)$ public/$1/$2 [L]
    RewriteRule ^favicon\.ico$ public/favicon.ico [L]
</IfModule>
FallbackResource /public/index.php
HT
}

# Write the branded, self-contained "account suspended" page (served as 503).
write_suspended_page() {
  local DOC_ROOT="$1"
  cat > "$DOC_ROOT/suspended.html" <<'HT'
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Compte suspendu — Castl-it-POS</title>
<style>
  :root { --brand:#3157D5; --ink:#0C1020; --muted:#5b6273; --sand:#e7e9f2; }
  * { box-sizing:border-box; }
  body { margin:0; min-height:100vh; display:grid; place-items:center; padding:24px;
         font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
         color:var(--ink); background:radial-gradient(1200px 600px at 50% -10%,#eef1fb,#f7f8fc); }
  .card { max-width:460px; width:100%; text-align:center; background:#fff; border:1px solid var(--sand);
          border-radius:18px; padding:40px 32px; box-shadow:0 20px 50px rgba(12,16,32,.10); }
  .mark { width:64px; height:64px; border-radius:16px; margin:0 auto 22px; display:grid; place-items:center;
          background:var(--brand); color:#fff; font-weight:800; font-size:30px; position:relative; }
  .mark::after { content:""; position:absolute; right:12px; bottom:12px; width:9px; height:9px;
                 border-radius:50%; background:#f5a623; }
  h1 { font-size:22px; font-weight:800; letter-spacing:-.02em; margin:0 0 10px; }
  p { color:var(--muted); font-size:15px; line-height:1.6; margin:0 0 8px; }
  .cta { display:inline-block; margin-top:22px; background:var(--brand); color:#fff; text-decoration:none;
         font-weight:700; padding:12px 22px; border-radius:12px; }
  .foot { margin-top:26px; font-size:12px; color:#98a0b3; }
</style>
</head>
<body>
  <div class="card">
    <div class="mark">C</div>
    <h1>Compte temporairement suspendu</h1>
    <p>L'accès à cet espace Castl-it-POS est momentanément indisponible.</p>
    <p>Pour réactiver votre compte ou en savoir plus, contactez-nous.</p>
    <a class="cta" href="mailto:contact@castlitpos.com">Nous contacter</a>
    <div class="foot">Castl-it-POS — Point de vente pour commerçants</div>
  </div>
</body>
</html>
HT
}
