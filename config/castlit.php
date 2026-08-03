<?php

// ============================================================================
//  CastLit POS — SaaS marketing + tenant auto-provisioning.
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

    // Reserved subdomains a subscriber may never claim.
    'reserved_subdomains' => [
        'www', 'admin', 'api', 'app', 'mail', 'email', 'ftp', 'cpanel',
        'webmail', 'ns1', 'ns2', 'mx', 'smtp', 'staging', 'dev', 'test',
        'demo', 'portal', 'dashboard', 'castlit', 'castlitpos', 'pos',
        'static', 'cdn', 'assets', 'billing', 'support', 'help', 'status',
        'blog', 'shop', 'store', 'my', 'account', 'accounts', 'auth', 'login',
    ],

    // ── Provisioning (cPanel/LWS host) ──────────────────────────────────────
    // These describe the shell environment the ProvisionTenantJob runs in.
    // Only meaningful on the master install.
    'provision' => [
        // The shell script that performs the actual provisioning.
        'script' => base_path('deploy/provision.sh'),

        // cPanel account layout.
        'public_html' => env('CASTLIT_PUBLIC_HTML', '/home/castlit/public_html'),
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
