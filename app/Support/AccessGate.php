<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * The access code in front of a client space, on the web.
 *
 * Accepted once, a browser is remembered for [REMEMBER_DAYS] — the session
 * alone ended after two hours, so the code was asked again every morning.
 * The remembered code is checked again with the master at each new visit, so
 * regenerating it or blocking the space still locks the browser out; if the
 * master cannot be reached, the browser is let in rather than locked out.
 */
final class AccessGate
{
    public const COOKIE = 'castlit_access';

    public const REMEMBER_DAYS = 180;

    public const VALID = 'valid';

    public const INVALID = 'invalid';

    public const UNREACHABLE = 'unreachable';

    /** @return array{0: string, 1: ?string} VALID|INVALID|UNREACHABLE, and the master's message */
    public static function check(string $subdomain, string $code, Request $request, ?string $deviceId, bool $remembered = false): array
    {
        try {
            $base = 'https://'.strtolower((string) config('castlit.main_domain'));
            $res = Http::acceptJson()->asJson()->timeout($remembered ? 6 : 12)->post(
                $base.'/api/v1/public/client-access',
                [
                    'subdomain' => $subdomain,
                    'code' => strtoupper(trim($code)),
                    // For the master's access journal: the visitor, not
                    // this server, is the one entering the code.
                    'channel' => 'web',
                    'client_ip' => $request->ip(),
                    'client_user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
                    'device_id' => $deviceId,
                ],
            );
        } catch (\Throwable) {
            return [self::UNREACHABLE, null];
        }

        if ($res->successful() && $res->json('ok') === true) {
            return [self::VALID, null];
        }
        // The master answered, and said no: the code changed or the space is
        // off. Too many attempts is not a no.
        if ($res->clientError() && $res->status() !== 429) {
            return [self::INVALID, $res->json('message')];
        }

        return [self::UNREACHABLE, null];
    }

    /** The cookie that remembers this browser for this space (encrypted by the framework). */
    public static function rememberCookie(string $subdomain, string $code): Cookie
    {
        return cookie(
            self::COOKIE,
            (string) json_encode(['sub' => $subdomain, 'code' => strtoupper(trim($code))]),
            self::REMEMBER_DAYS * 24 * 60,
        );
    }

    /** The code this browser was remembered with, for this space only. */
    public static function rememberedCode(Request $request, string $subdomain): ?string
    {
        $data = json_decode((string) $request->cookie(self::COOKIE), true);

        return is_array($data) && ($data['sub'] ?? null) === $subdomain && is_string($data['code'] ?? null) && $data['code'] !== ''
            ? $data['code']
            : null;
    }

    /** Client subdomain from the request host (demo.castlitpos.com → demo). */
    public static function subdomain(Request $request): ?string
    {
        $host = strtolower($request->getHost());
        $suffix = '.'.strtolower((string) config('castlit.main_domain'));
        if (str_ends_with($host, $suffix)) {
            $sub = substr($host, 0, -strlen($suffix));

            return ($sub === '' || $sub === 'www') ? null : $sub;
        }

        return null;
    }
}
