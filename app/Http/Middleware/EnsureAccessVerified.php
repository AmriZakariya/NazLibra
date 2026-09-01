<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates a client install behind its access code: before reaching the web login
 * (or the app), a visitor must enter the code the admin communicated. Verified
 * once per browser session. Skipped on the master (marketing/admin) and on the
 * open demo space.
 */
class EnsureAccessVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        // The master install is the marketing + admin site — no client gate.
        if (config('castlit.is_master')) {
            return $next($request);
        }

        $sub = $this->subdomain($request);

        // Demo is open to everyone; an unknown host must not lock people out.
        if ($sub === null || $sub === 'demo') {
            return $next($request);
        }

        // Already cleared this session, or currently on the gate itself.
        if ($request->session()->get('access_verified') === true
            || $request->routeIs('castlit.access.*')) {
            return $next($request);
        }

        return redirect()->route('castlit.access.show');
    }

    /** Client subdomain from the request host (demo.castlitpos.com → demo). */
    private function subdomain(Request $request): ?string
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
