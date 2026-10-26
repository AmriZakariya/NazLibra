<?php
// Usage: php env-set.php <path/to/client/.env>
// Applies the master's mail settings (CLIENT_MAIL_* in the environment) to a
// client's .env. Values come from the environment, never the command line:
// argv is visible to every user of the host in `ps`, and this carries a password.

require __DIR__.'/env-lib.php';

$file = $argv[1] ?? '';
if ($file === '' || ! is_file($file)) {
    fwrite(STDERR, "env-set: no .env at '{$file}'\n");
    exit(1);
}

$settings = castlit_client_mail_settings(getenv());
castlit_env_set($file, $settings);

fwrite(STDERR, '  mail: '.$settings['MAIL_MAILER']
    .(isset($settings['MAIL_HOST']) ? ' via '.$settings['MAIL_HOST'].':'.($settings['MAIL_PORT'] ?? '?') : '')."\n");
