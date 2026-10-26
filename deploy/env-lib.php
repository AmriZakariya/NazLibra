<?php
// Writing keys into a client's .env — shared by provision.sh and manage.sh,
// and required directly by the tests. Standalone on purpose: it runs from the
// master's deploy/ folder against a client's file, with no autoloader.

/**
 * One value as phpdotenv will read it back unchanged.
 *
 * A mailbox password is the hard case: a space, a #, a quote or a $ is
 * enough to cut it short or have it interpolated as a variable, and the
 * symptom is not an error but SMTP rejecting the login.
 */
function castlit_env_format(string $value): string
{
    if ($value === '') {
        return '';
    }
    // Plain enough to write bare.
    if (preg_match('/^[A-Za-z0-9_.@:\/+-]+$/', $value)) {
        return $value;
    }
    // Single quotes: taken literally — no escapes, no ${…} interpolation.
    if (strpos($value, "'") === false) {
        return "'".$value."'";
    }
    // Only a value containing a single quote needs double quotes, where a
    // backslash, a double quote and a dollar sign must each be escaped.
    return '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value).'"';
}

/**
 * Sets [$pairs] in the .env at [$file], replacing a key in place when it is
 * already there and appending it otherwise. Every other line is left alone.
 *
 * @param  array<string, string>  $pairs
 */
function castlit_env_set(string $file, array $pairs): void
{
    $content = is_file($file) ? (string) file_get_contents($file) : '';

    foreach ($pairs as $key => $value) {
        $line = $key.'='.castlit_env_format((string) $value);
        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

        if (preg_match($pattern, $content)) {
            // A callback, not a replacement string: "$1" or "\\" inside a
            // password would otherwise be read as a back-reference.
            $content = preg_replace_callback($pattern, fn () => $line, $content, 1);
        } else {
            $content .= ($content === '' || str_ends_with($content, "\n") ? '' : "\n").$line."\n";
        }
    }

    file_put_contents($file, $content);
}

/**
 * The mail settings a client space should carry, from the CLIENT_MAIL_*
 * variables the master passes to the deploy scripts.
 *
 * With none — the master itself on the log mailer — the client gets "log"
 * too, which is what it had before: nothing is sent that the master could
 * not have sent.
 *
 * @param  array<string, string|false>  $env  normally getenv()
 * @return array<string, string>
 */
function castlit_client_mail_settings(array $env): array
{
    $mailer = trim((string) ($env['CLIENT_MAIL_MAILER'] ?? ''));

    if ($mailer === '' || $mailer === 'log') {
        return ['MAIL_MAILER' => 'log'];
    }

    $settings = ['MAIL_MAILER' => $mailer];
    foreach (['SCHEME', 'HOST', 'PORT', 'USERNAME', 'PASSWORD', 'FROM_ADDRESS'] as $suffix) {
        $value = $env['CLIENT_MAIL_'.$suffix] ?? false;
        if ($value !== false && $value !== '') {
            $settings['MAIL_'.$suffix] = (string) $value;
        }
    }

    return $settings;
}
