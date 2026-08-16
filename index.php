<?php

/**
 * ============================================================================
 *  Castl-it-POS — wildcard front dispatcher (master docroot only).
 * ----------------------------------------------------------------------------
 *  With a single wildcard subdomain *.castlitpos.com pointing at ~/htdocs, this
 *  file (reached via the docroot .htaccess "FallbackResource /index.php") routes
 *  each request to the right Laravel install by inspecting the Host header:
 *
 *    <sub>.castlitpos.com  → ~/htdocs/<sub>.castlitpos.com/public/index.php  (client)
 *    castlitpos.com / www  → ~/htdocs/public/index.php                       (master)
 *
 *  So provisioning only has to create the client folder — no per-client
 *  subdomain or vhost is needed. A ".suspended" marker in a client folder makes
 *  its subdomain serve the branded 503 page.
 *
 *  Runs BEFORE any framework boots, so it relies only on plain PHP + the folder
 *  layout. Static assets never reach here — the .htaccess serves them directly
 *  from each client's public/.
 * ============================================================================
 */

$docroot    = __DIR__;
$mainDomain = getenv('CASTLIT_MAIN_DOMAIN') ?: 'castlitpos.com';

$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$host = (string) preg_replace('/:\d+$/', '', $host); // strip any :port

// ── Client subdomain?  <sub>.<main-domain> (not www) ────────────────────────
$pattern = '/^([a-z0-9](?:[a-z0-9-]{0,28}[a-z0-9])?)\.'.preg_quote($mainDomain, '/').'$/';

if (preg_match($pattern, $host, $m) && $m[1] !== 'www') {
    $client = $docroot.'/'.$m[1].'.'.$mainDomain;

    // Suspended by the platform admin → branded 503, nothing else runs.
    if (is_file($client.'/.suspended')) {
        http_response_code(503);
        header('Retry-After: 3600');
        header('Content-Type: text/html; charset=utf-8');
        $page = $client.'/suspended.html';
        if (is_file($page)) {
            readfile($page);
        } else {
            echo '<!doctype html><meta charset="utf-8"><title>Compte suspendu</title>'
                .'<p style="font-family:sans-serif;padding:40px">Cet espace Castl-it-POS est temporairement suspendu.</p>';
        }
        return;
    }

    $front = $client.'/public/index.php';
    if (is_file($front)) {
        // The client's index.php resolves its own vendor/, .env and bootstrap via
        // __DIR__, so requiring it here boots the CLIENT app, not the master.
        chdir($client.'/public');
        require $front;
        return;
    }

    // Provisioned folder missing / not ready yet.
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Espace introuvable</title>'
        .'<p style="font-family:sans-serif;padding:40px">Cet espace Castl-it-POS n’existe pas ou n’est pas encore prêt.</p>';
    return;
}

// ── Apex / www / anything else → the master (marketing + admin) ─────────────
chdir($docroot.'/public');
require $docroot.'/public/index.php';
