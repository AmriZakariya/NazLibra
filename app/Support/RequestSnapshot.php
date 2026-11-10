<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * What the activity journal keeps of a request: enough to tell, months later,
 * what was sent and from where, never a secret.
 *
 * Shared by the back office (RecordUserActivity) and the app's API actions
 * (ResolveApiActionContext), so a log reads the same whichever sent it.
 */
final class RequestSnapshot
{
    /** A key holding one of these words is masked, at any depth ("clear_pin", "access_token", but not "shipping"). */
    private const SECRET_WORDS = ['password', 'passwd', 'pwd', 'pin', 'token', 'secret', 'cvv', 'authorization', 'apikey'];

    /** Never kept at all: form plumbing. */
    private const DROPPED_KEYS = ['_token', '_method'];

    private const MAX_STRING = 500;

    private const MAX_LIST = 25;

    private const MAX_DEPTH = 5;

    /** A sync batch can be large: past this, the payload is kept as its outline. */
    private const MAX_BYTES = 32_000;

    public const MASK = '••••';

    /**
     * Headers worth reading in a log. The app's own say which build and which
     * device sent the request; the auth header never appears.
     */
    private const HEADERS = [
        'host', 'referer', 'origin', 'user-agent', 'accept', 'accept-language', 'content-type',
        'x-requested-with', 'x-forwarded-for', 'x-real-ip',
        'x-app-version', 'x-app-platform', 'x-device', 'x-virtual-device-id', 'x-location-id',
        'x-tenant-slug', 'idempotency-key',
    ];

    /** @return array<string, mixed> */
    public static function payload(Request $request): array
    {
        $data = self::clean($request->all(), 0);
        if (! is_array($data)) {
            return [];
        }

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        if (strlen($json) <= self::MAX_BYTES) {
            return $data;
        }

        // Too big to keep whole: what was in it, not the whole of it.
        return ['_tronqué' => 'Données trop volumineuses ('.number_format(strlen($json) / 1024, 0, ',', ' ').' Ko) : aperçu seulement.']
            + array_map(fn ($value) => is_array($value) ? '['.count($value).' élément(s)]' : $value, $data);
    }

    /** @return array<string, mixed> */
    public static function query(Request $request): array
    {
        $data = self::clean($request->query(), 0);

        return is_array($data) ? $data : [];
    }

    /** @return array<string, string> */
    public static function headers(Request $request): array
    {
        $out = [];
        foreach (self::HEADERS as $name) {
            $value = $request->headers->get($name);
            if ($value !== null && $value !== '') {
                $out[$name] = mb_substr((string) $value, 0, self::MAX_STRING);
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public static function routeParameters(Request $request): array
    {
        $out = [];
        foreach ($request->route()?->parameters() ?? [] as $key => $value) {
            $out[$key] = $value instanceof \Illuminate\Database\Eloquent\Model
                ? ['type' => $value::class, 'id' => $value->getKey()]
                : (is_scalar($value) || $value === null ? $value : (string) json_encode($value));
        }

        return $out;
    }

    /**
     * Which build of the app sent this, and from what device. Read from the
     * app's headers, or from its User-Agent ("CastlitPOS/1.0.13 (Android 8.1 ·
     * PAX A920)") when a proxy dropped them. Nulls for a browser.
     *
     * @return array{version: ?string, platform: ?string, device: ?string, install: ?string}
     */
    public static function appClient(Request $request): array
    {
        $clean = fn (?string $value, int $max) => ($v = trim(strip_tags((string) $value))) === '' ? null : mb_substr($v, 0, $max);

        $version = $clean($request->header('X-App-Version'), 20);
        // Percent-encoded by the app: a model name is not always ASCII.
        $device = $clean(rawurldecode((string) $request->header('X-Device')), 120);
        $ua = (string) $request->userAgent();
        if (preg_match('#^CastlitPOS/([0-9][0-9A-Za-z.+\-]*)(?:\s*\((.+)\))?#u', $ua, $m)) {
            $version ??= mb_substr($m[1], 0, 20);
            $device ??= isset($m[2]) ? $clean($m[2], 120) : null;
        }
        if ($version !== null && ! preg_match('/^[0-9][0-9A-Za-z.+\-]*$/', $version)) {
            $version = null;
        }

        $platform = strtolower((string) $clean($request->header('X-App-Platform'), 20));
        $install = (string) $request->header('X-Castlit-Install-Id', '');

        return [
            'version' => $version,
            'platform' => $platform !== '' && preg_match('/^[a-z]+$/', $platform) ? $platform : null,
            'device' => $device,
            'install' => preg_match('/^[A-Za-z0-9\-]{8,64}$/', $install) ? $install : null,
        ];
    }

    private static function clean(mixed $value, int $depth): mixed
    {
        if ($value instanceof UploadedFile) {
            return '[fichier '.$value->getClientOriginalName().']';
        }
        if (is_string($value)) {
            // A photo sent inline is not worth keeping in a log.
            if (str_starts_with($value, 'data:') && strlen($value) > 200) {
                return '[image '.number_format(strlen($value) / 1024, 0, ',', ' ').' Ko]';
            }

            return mb_strlen($value) > self::MAX_STRING ? mb_substr($value, 0, self::MAX_STRING).'…' : $value;
        }
        if (! is_array($value)) {
            return $value;
        }
        if ($depth >= self::MAX_DEPTH) {
            return '['.count($value).' élément(s)]';
        }

        $out = [];
        $i = 0;
        foreach ($value as $key => $item) {
            if (in_array($key, self::DROPPED_KEYS, true)) {
                continue;
            }
            if ($i++ >= self::MAX_LIST && array_is_list($value)) {
                $out[] = '… +'.(count($value) - self::MAX_LIST).' autre(s)';
                break;
            }
            $out[$key] = is_string($key) && self::isSecret($key) && $item !== null && $item !== ''
                ? self::MASK
                : self::clean($item, $depth + 1);
        }

        return $out;
    }

    private static function isSecret(string $key): bool
    {
        $key = strtolower((string) preg_replace('/(?<=[a-z])(?=[A-Z])/', '_', $key));
        if (str_contains($key, 'password') || preg_match('/api[_\-]?key/', $key)) {
            return true;
        }

        return array_intersect(preg_split('/[^a-z0-9]+/', $key) ?: [], self::SECRET_WORDS) !== [];
    }
}
