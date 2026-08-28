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

    // Free-trial length (months) applied to each new client at provisioning.
    'trial_months' => (int) env('CASTLIT_TRIAL_MONTHS', 3),

    // ── Product brand + marketing SEO ───────────────────────────────────────
    'brand' => [
        'name'      => 'Castl-it-POS',                 // product display name
        'legal'     => 'Castl-it-POS',
        'tagline'   => 'Le point de vente et la gestion de stock pour votre commerce',
        'email'     => env('CASTLIT_CONTACT_EMAIL', 'contact@castlitpos.com'),
        'locale'    => 'fr_MA',
        // App store links (empty = show a "coming soon" badge instead of a link).
        'play_store' => env('CASTLIT_PLAY_STORE_URL', ''),
        'app_store'  => env('CASTLIT_APP_STORE_URL', ''),
        // Social profile URLs → JSON-LD sameAs (helps entity/knowledge-graph SEO).
        // Empty ones are dropped; fill via env as accounts go live.
        'social' => array_values(array_filter([
            env('CASTLIT_FACEBOOK_URL', ''),
            env('CASTLIT_INSTAGRAM_URL', ''),
            env('CASTLIT_LINKEDIN_URL', ''),
            env('CASTLIT_YOUTUBE_URL', ''),
        ])),
        // Meta description used on the landing page (≤160 chars, keyword-rich).
        'description' => 'Castl-it-POS : logiciel de caisse tactile et gestion de stock pour librairies, cafés, restaurants, pharmacies et tous types de commerces. Fonctionne hors ligne, multi-postes, multi-devises, en français, arabe et anglais.',
        // Comma-separated focus keywords (used in meta + copy).
        'keywords'  => 'logiciel de caisse, point de vente, POS, caisse tactile, gestion de stock, caisse librairie, caisse restaurant, caisse pharmacie, logiciel de caisse, TPV, inventaire',
    ],

    // Public demo credentials shown on the login page. Set ONLY on the public
    // demo install (e.g. demo.castlitpos.com) so its visitors can sign straight
    // in; left blank everywhere else so no real client leaks a login. The demo
    // box appears only when BOTH values are filled.
    'demo' => [
        // Public demo client visitors can try from the marketing site. Defaults to
        // demo.<main_domain>; override with CASTLIT_DEMO_URL. Set to empty to hide
        // the demo links entirely.
        'url'      => env('CASTLIT_DEMO_URL', 'https://demo.'.env('CASTLIT_MAIN_DOMAIN', 'castlitpos.com')),
        'email'    => env('CASTLIT_DEMO_EMAIL'),
        'password' => env('CASTLIT_DEMO_PASSWORD'),
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

        // A deterministic super-admin created inside every new client install,
        // in addition to the subscription owner — a known login for support.
        // {sub} is replaced by the client subdomain (e.g. admin@smar.com).
        // ⚠️ The default password is weak on purpose (early phase); change the
        // pattern/password here, or disable, before real customers go live.
        'default_admin' => [
            'enabled'       => (bool) env('CASTLIT_DEFAULT_ADMIN', true),
            'name'          => env('CASTLIT_DEFAULT_ADMIN_NAME', 'Administrateur'),
            'email_pattern' => env('CASTLIT_DEFAULT_ADMIN_EMAIL', 'admin@{sub}.com'),
            'password'      => env('CASTLIT_DEFAULT_ADMIN_PASSWORD', 'admin'),
        ],

        // Terminals (virtual devices) created for every new client so they can
        // connect POS stations right away. "web" → computer/browser terminals,
        // "mobile" → phone/tablet app terminals.
        'default_devices' => [
            'web'    => (int) env('CASTLIT_DEFAULT_WEB_TERMINALS', 2),
            'mobile' => (int) env('CASTLIT_DEFAULT_MOBILE_TERMINALS', 2),
        ],

        // Per-client database engine: 'sqlite' (default, zero-config, ideal for
        // the early low-volume phase) or 'mysql' (created via cPanel uapi).
        // Switch to MySQL later by setting CASTLIT_DB_DRIVER=mysql — no code change.
        'db_driver'   => env('CASTLIT_DB_DRIVER', 'sqlite'),

        // With the wildcard *.castlitpos.com in place, subdomains need no manual
        // step — the docroot dispatcher routes each Host to its client folder.
        // Set CASTLIT_SUBDOMAIN_MANUAL=true only if you drop the wildcard and
        // create each subdomain by hand in the panel.
        'subdomain_manual' => (bool) env('CASTLIT_SUBDOMAIN_MANUAL', false),

        // Parent directory of the per-client subdomain folders. On LWS every
        // subdomain is a folder INSIDE the master's own htdocs (~/htdocs/<sub>.<domain>),
        // so this is ALWAYS the master install dir = base_path(). We derive it from
        // the app itself (never from env) because the provisioning process runs in a
        // chroot where htdocs resolves to /htdocs — any hand-set /home/... path would
        // land the copy in the wrong directory (not the one the subdomain serves).
        'public_html' => base_path(),
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
