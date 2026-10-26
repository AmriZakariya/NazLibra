<?php

namespace App\Support;

/**
 * The master's outgoing mail settings, as the deploy scripts receive them.
 *
 * Client spaces send through the master's mailbox: provisioning and every
 * update write these into the client's .env (deploy/env-set.php). Passed as
 * environment variables, never as arguments, because argv is visible to every
 * user of a shared host in `ps` and this carries the mailbox password.
 */
final class ClientMailSettings
{
    /** @return array<string, string> CLIENT_MAIL_* => value */
    public static function forScripts(): array
    {
        $mailer = (string) config('mail.default');

        if ($mailer === '' || $mailer === 'log' || $mailer === 'array') {
            return ['CLIENT_MAIL_MAILER' => 'log'];
        }

        $smtp = (array) config("mail.mailers.{$mailer}", []);

        return array_filter([
            'CLIENT_MAIL_MAILER' => $mailer,
            'CLIENT_MAIL_SCHEME' => (string) ($smtp['scheme'] ?? ''),
            'CLIENT_MAIL_HOST' => (string) ($smtp['host'] ?? ''),
            'CLIENT_MAIL_PORT' => (string) ($smtp['port'] ?? ''),
            'CLIENT_MAIL_USERNAME' => (string) ($smtp['username'] ?? ''),
            'CLIENT_MAIL_PASSWORD' => (string) ($smtp['password'] ?? ''),
            'CLIENT_MAIL_FROM_ADDRESS' => (string) config('mail.from.address', ''),
        ], fn (string $value): bool => $value !== '');
    }
}
