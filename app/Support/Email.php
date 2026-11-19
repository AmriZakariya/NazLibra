<?php

namespace App\Support;

/**
 * One spelling for an email address: trimmed and lowercase.
 *
 * A space created from a sign-up typed « Soundous…@gmail.com » (a phone
 * keyboard capitalises the first letter) kept those capitals, and a client
 * install runs on SQLite, where `email = ?` is case-sensitive: the owner
 * typing the address in lowercase was refused with the right password.
 */
final class Email
{
    public static function normalize(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        return mb_strtolower(trim($email));
    }
}
