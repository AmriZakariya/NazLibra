<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Failed password attempts, counted for both sign-in doors (the back office
 * and the app). Neither had a limit: anyone could try passwords as fast as
 * the server answered.
 *
 * Two counters: one per account and address, so a cashier who mistypes does
 * not lock out the shop; and a looser one per address, so one machine cannot
 * walk through many accounts. Only failures count, and a successful sign-in
 * clears its account's counter.
 */
final class LoginThrottle
{
    public const PER_ACCOUNT = 5;

    public const PER_ADDRESS = 30;

    public const DECAY_SECONDS = 60;

    /** Seconds to wait before another attempt is accepted, or 0. */
    public static function wait(Request $request, string $email): int
    {
        foreach (self::limits($request, $email) as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                return max(1, RateLimiter::availableIn($key));
            }
        }

        return 0;
    }

    public static function failed(Request $request, string $email): void
    {
        foreach (array_keys(self::limits($request, $email)) as $key) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
        }
    }

    public static function succeeded(Request $request, string $email): void
    {
        RateLimiter::clear(self::accountKey($request, $email));
    }

    /**
     * One fixed sentence: the back office translates by exact French text, so
     * a sentence with the seconds in it would stay French. The API sends the
     * seconds apart, as `retry_after` and the Retry-After header.
     */
    public const MESSAGE = 'Trop de tentatives de connexion. Patientez une minute avant de réessayer.';

    /** @return array<string,int> */
    private static function limits(Request $request, string $email): array
    {
        return [
            self::accountKey($request, $email) => self::PER_ACCOUNT,
            'login:ip:'.$request->ip() => self::PER_ADDRESS,
        ];
    }

    private static function accountKey(Request $request, string $email): string
    {
        return 'login:'.Str::transliterate(Str::lower(trim($email))).'|'.$request->ip();
    }
}
