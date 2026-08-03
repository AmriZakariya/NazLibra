<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the CastLit platform-admin area (subscription approvals). Only the
 * castlitpos.com master install exposes these routes, and only users flagged
 * `is_platform_admin` may reach them.
 */
class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('castlit.is_master')) {
            abort(404);
        }

        $user = $request->user();
        if (! $user || ! $user->is_platform_admin) {
            abort(403, 'Accès réservé à l\'administration CastLit.');
        }

        return $next($request);
    }
}
