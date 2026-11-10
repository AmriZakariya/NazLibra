<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The activity journal's one way in, for the back office and the app alike:
 * what kind of entry it is, whether it worked and why not, written so that a
 * journal that cannot be written never breaks the request it describes.
 */
final class ActivityRecorder
{
    public const ACTION = 'action';

    public const VIEW = 'view';

    public const DOWNLOAD = 'download';

    public const AUTH = 'auth';

    public const KINDS = [self::ACTION, self::VIEW, self::DOWNLOAD, self::AUTH];

    public const OK = 'ok';

    public const FAILED = 'failed';

    /** Page views are for following a visit, not for keeping. */
    public const VIEW_RETENTION_DAYS = 30;

    private const EXCEPTION_ATTRIBUTE = 'activity_exception';

    /** Called from the exception handler: the crash a 500 came from. */
    public static function rememberException(Throwable $e): void
    {
        if (app()->bound('request')) {
            request()->attributes->set(self::EXCEPTION_ATTRIBUTE, $e);
        }
    }

    public static function isWrite(Request $request): bool
    {
        return in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    /** A file handed to the user: a PDF, an export, a template. */
    public static function isDownload(Request $request, Response $response): bool
    {
        if ($response instanceof BinaryFileResponse || $response instanceof StreamedResponse) {
            return true;
        }
        if (str_contains(strtolower((string) $response->headers->get('Content-Disposition')), 'attachment')) {
            return true;
        }
        $type = strtolower((string) $response->headers->get('Content-Type'));
        foreach (['application/pdf', 'spreadsheet', 'ms-excel', 'text/csv', 'application/zip'] as $file) {
            if (str_contains($type, $file)) {
                return true;
            }
        }

        return (bool) preg_match('/(\.pdf|\.export|labels|\.example|^legacy\.invoice)$/', (string) $request->route()?->getName());
    }

    /** A full page drawn for a person, not a fragment or data for a table. */
    public static function isPageView(Request $request, Response $response): bool
    {
        return $request->method() === 'GET'
            && $response->getStatusCode() === 200
            && ! $request->ajax()
            && ! $request->expectsJson()
            && ! $response instanceof JsonResponse
            && str_contains(strtolower((string) $response->headers->get('Content-Type', 'text/html')), 'text/html')
            && strtolower((string) $request->headers->get('Purpose', $request->headers->get('Sec-Purpose', ''))) !== 'prefetch';
    }

    /**
     * Whether it worked, and in words. A redirect "back with errors" is a
     * failure as much as a 422; the message the user saw is kept either way.
     *
     * @return array{0: string, 1: ?string, 2: array<string, mixed>}
     */
    public static function outcome(Request $request, Response $response): array
    {
        $status = $response->getStatusCode();
        $details = [];
        $flash = self::flashed($request);
        $json = $response instanceof JsonResponse ? $response->getData(true) : null;
        $json = is_array($json) ? $json : null;

        $errors = [];
        if (isset($flash['errors']) && $flash['errors'] instanceof \Illuminate\Support\ViewErrorBag) {
            $errors = $flash['errors']->getBag('default')->toArray();
        } elseif (is_array($json['errors'] ?? null)) {
            $errors = $json['errors'];
        }
        if ($errors !== []) {
            $details['errors'] = array_map(fn ($m) => array_slice((array) $m, 0, 3), array_slice($errors, 0, 20, true));
        }

        $exception = $request->attributes->get(self::EXCEPTION_ATTRIBUTE);
        if ($exception instanceof Throwable && $status >= 500) {
            $details['exception'] = [
                'class' => $exception::class,
                'message' => Str::limit($exception->getMessage(), 500),
                'file' => str_replace(base_path().DIRECTORY_SEPARATOR, '', $exception->getFile()).':'.$exception->getLine(),
            ];
        }

        $failed = $status >= 400 || $errors !== [] || (isset($flash['error']) && is_string($flash['error']));
        $message = match (true) {
            isset($details['exception']) => $details['exception']['class'].' : '.$details['exception']['message'],
            $errors !== [] => implode(' · ', array_map(fn ($m) => (string) (is_array($m) ? reset($m) : $m), array_slice($errors, 0, 3))),
            $failed && is_string($flash['error'] ?? null) => $flash['error'],
            is_string($json['message'] ?? null) && $json['message'] !== '' => $json['message'],
            $failed => $status.' '.(Response::$statusTexts[$status] ?? 'Erreur'),
            default => self::firstString($flash, ['status', 'success', 'message', 'warning']),
        };

        return [$failed ? self::FAILED : self::OK, $message !== null ? Str::limit(trim(strip_tags($message)), 490) : null, $details];
    }

    /**
     * Writes one entry. Columns a space has not migrated yet are left out
     * rather than losing the entry; nothing here ever throws.
     *
     * @param  array<string, mixed>  $row
     */
    public static function write(array $row): void
    {
        $row += ['created_at' => now(), 'updated_at' => now()];
        if (isset($row['properties']) && is_array($row['properties'])) {
            $row['properties'] = json_encode($row['properties'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }

        try {
            DB::table('audit_logs')->insert($row);
        } catch (Throwable $e) {
            try {
                $base = array_intersect_key($row, array_flip([
                    'tenant_id', 'user_id', 'action', 'friendly_action', 'subject_type', 'subject_id',
                    'subject_name_snapshot', 'subject_reference_snapshot', 'virtual_device_id',
                    'device_name_snapshot', 'device_code_snapshot', 'real_device_platform',
                    'real_device_browser', 'real_device_ip', 'real_device_user_agent',
                    'properties', 'created_at', 'updated_at',
                ]));
                DB::table('audit_logs')->insert($base);
            } catch (Throwable) {
                report($e);
            }
        }
    }

    /** Milliseconds since the request began. */
    public static function duration(Request $request): ?int
    {
        $start = $request->server('REQUEST_TIME_FLOAT');

        return is_numeric($start) ? max(0, (int) round((microtime(true) - (float) $start) * 1000)) : null;
    }

    /** Page views older than the retention, removed daily. Returns how many. */
    public static function pruneViews(): int
    {
        return DB::table('audit_logs')
            ->where('kind', self::VIEW)
            ->where('created_at', '<', now()->subDays(self::VIEW_RETENTION_DAYS))
            ->delete();
    }

    /** @return array<string, mixed> what this request flashed for the next page */
    private static function flashed(Request $request): array
    {
        if (! $request->hasSession()) {
            return [];
        }
        $session = $request->session();
        $out = [];
        foreach ((array) $session->get('_flash.new', []) as $key) {
            $out[$key] = $session->get($key);
        }

        return $out;
    }

    /** @param array<string, mixed> $values */
    private static function firstString(array $values, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (is_string($values[$key] ?? null) && $values[$key] !== '') {
                return $values[$key];
            }
        }

        return null;
    }
}
