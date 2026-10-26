<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the public disk (product photos, logos…) at /storage/… itself.
 *
 * The usual `storage:link` symlink is refused by LWS's Apache — every photo
 * answered 403, on the web and in the app alike — so the files are streamed
 * from storage/app/public instead. Registered outside the web group: no
 * session row per photo, and the app can load them without a cookie.
 */
class PublicStorageController
{
    public function __invoke(string $path): BinaryFileResponse
    {
        $root = realpath(storage_path('app/public'));
        $file = $root ? realpath($root.DIRECTORY_SEPARATOR.$path) : false;

        // realpath resolves "..": anything outside the public disk is absent.
        abort_unless(
            $file !== false && str_starts_with($file, $root.DIRECTORY_SEPARATOR) && is_file($file),
            404,
        );

        return response()->file($file, [
            // Names are unique (a new upload gets a new name), so a long
            // cache never shows a stale photo.
            'Cache-Control' => 'public, max-age=2592000',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
