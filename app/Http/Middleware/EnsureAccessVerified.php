<?php

namespace App\Http\Middleware;

use App\Support\AccessGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates a client install behind its access code: before reaching the web login
 * (or the app), a visitor must enter the code the admin communicated. Skipped
 * on the master (marketing/admin) and on the open demo space.
 *
 * Verified once per browser: a remembered browser is checked again with the
 * master at each new session (see AccessGate).
 */
class EnsureAccessVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        // The master install is the marketing + admin site — no client gate.
        if (config('castlit.is_master')) {
            return $next($request);
        }

        $sub = AccessGate::subdomain($request);

        // Demo is open to everyone; an unknown host must not lock people out.
        if ($sub === null || $sub === 'demo') {
            return $next($request);
        }

        // The gate itself, or a browser already through it (this session, or
        // remembered and checked again with the master, quietly).
        if ($request->routeIs('castlit.access.*') || AccessGate::passes($request, $sub)) {
            return $next($request);
        }

        return redirect()->route('castlit.access.show');
    }
}
