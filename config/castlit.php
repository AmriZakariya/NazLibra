<?php

// ============================================================================
//  Castl-it-POS — SaaS marketing + tenant auto-provisioning.
//  Only the "master" install (castlitpos.com) sets CASTLIT_MASTER=true; that
//  flag turns on the public marketing site, the subscription form and the
//  platform-admin approval area. Client installs leave it false so those
//  routes stay dormant and `/` remains the normal POS app.
// ============================================================================

return [

    // Is THIS install the marketing/admin master (castlitpos.com)?
    'is_master' => (bool) env('CASTLIT_MASTER', false),

    // Root domain new client subdomains hang off of.
    'main_domain' => env('CASTLIT_MAIN_DOMAIN', 'castlitpos.com'),

    // ── Product brand + marketing SEO ───────────────────────────────────────
    'brand' => [
        'name'      => 'Castl-it-POS',                 // product display name
        'legal'     => 'Castl-it-POS',
        'tagline'   => 'La caisse et la gestion de stock des commerces marocains',
        'email'     => env('CASTLIT_CONTACT_EMAIL', 'contact@castlitpos.com'),
        'locale'    => 'fr_MA',
        // App store links (empty = show a "coming soon" badge instead of a link).
        'play_store' => env('CASTLIT_PLAY_STORE_URL', ''),
        'app_store'  => env('CASTLIT_APP_STORE_URL', ''),
        // Meta description used on the landing page (≤160 chars, keyword-rich).
        'description' => 'Castl-it-POS : logiciel de caisse tactile et gestion de stock pour librairies, cafés, restaurants, pharmacies et commerces au Maroc. Fonctionne hors ligne, multi-postes, en français et en arabe.',
        // Comma-separated focus keywords (used in meta + copy).
        'keywords'  => 'logiciel de caisse, point de vente, POS Maroc, caisse tactile, gestion de stock, caisse librairie, caisse restaurant, caisse pharmacie, logiciel caisse Maroc, TPV',
    ],

    // Google Search Console verification token (paste into .env once claimed).
    // Rendered as <meta name="google-site-verification"> only when set.
    'gsc_verification' => env('CASTLIT_GSC_VERIFICATION'),

    // Reserved subdomains a PUBLIC subscriber may never claim (broad list).
    'reserved_subdomains' => [
        'www', 'admin', 'api', 'app', 'mail', 'email', 'ftp', 'cpanel',
        'webmail', 'ns1', 'ns2', 'mx', 'smtp', 'staging', 'dev', 'test',
        'demo', 'portal', 'dashboard', 'castlit', 'castlitpos', 'pos',
        'static', 'cdn', 'assets', 'billing', 'support', 'help', 'status',
        'blog', 'shop', 'store', 'my', 'account', 'accounts', 'auth', 'login',
    ],

    // System subdomains blocked even for admin manual creation — these would
    // break the platform / DNS / mail. (Admin CAN use demo, test, staging, …)
    'system_subdomains' => [
        'www', 'api', 'admin', 'mail', 'email', 'webmail', 'ftp', 'cpanel',
        'ns1', 'ns2', 'mx', 'smtp', 'castlit', 'castlitpos',
    ],

    // ── Provisioning (cPanel/LWS host) ──────────────────────────────────────
    // These describe the shell environment the ProvisionTenantJob runs in.
    // Only meaningful on the master install.
    'provision' => [
        // The shell script that performs the actual provisioning.
        'script' => base_path('deploy/provision.sh'),

        // The shell script that updates / suspends / reactivates a client.
        'manage_script' => base_path('deploy/manage.sh'),

        // Per-client database engine: 'sqlite' (default, zero-config, ideal for
        // the early low-volume phase) or 'mysql' (created via cPanel uapi).
        // Switch to MySQL later by setting CASTLIT_DB_DRIVER=mysql — no code change.
        'db_driver'   => env('CASTLIT_DB_DRIVER', 'sqlite'),

        // This LWS plan has no cPanel `uapi`, so the subdomain is created by hand
        // in the panel; the job fills the directory. Set false once `uapi` exists
        // (or a wildcard vhost) to hide the manual-step reminder in the admin.
        'subdomain_manual' => (bool) env('CASTLIT_SUBDOMAIN_MANUAL', true),

        // Parent directory of the per-client subdomain folders. On LWS each
        // subdomain lives INSIDE the master's own htdocs (~/htdocs/<sub>.<domain>),
        // and the provisioning process sees that dir as base_path() (a chroot may
        // resolve it to /htdocs, not /home/<acct>/htdocs). Defaulting to base_path()
        // guarantees SOURCE_DIR and BASE_DIR share the same filesystem view, so
        // clients are written to the exact path the subdomain serves from.
        // Only override CASTLIT_PUBLIC_HTML on hosts where clients live elsewhere.
        'public_html' => env('CASTLIT_PUBLIC_HTML') ?: base_path(),
        'db_prefix'   => env('CASTLIT_DB_PREFIX', 'castlit_'),

        // Git code cache (a clone of the repo, with a prebuilt vendor/).
        'repo_dir'    => env('CASTLIT_REPO_DIR', base_path('../repo')),
        'repo_owner'  => env('CASTLIT_REPO_OWNER', 'AmriZakariya'),
        'repo_name'   => env('CASTLIT_REPO_NAME', 'NazLibra'),
        'repo_branch' => env('CASTLIT_REPO_BRANCH', 'main'),
        'github_token' => env('CASTLIT_GITHUB_TOKEN', ''),

        // Binaries (adjust per host).
        'php_bin' => env('CASTLIT_PHP_BIN', 'php'),

        // Wall-clock ceiling for one provision run (seconds).
        'timeout' => (int) env('CASTLIT_PROVISION_TIMEOUT', 600),
    ],
];
