<?php

namespace App\Http\Middleware;

use App\Support\AccessGate;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
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

        // Already cleared this session, or currently on the gate itself.
        if ($request->session()->get('access_verified') === true
            || $request->routeIs('castlit.access.*')) {
            return $next($request);
        }

        // A browser that entered the code before: checked again, quietly.
        if ($code = AccessGate::rememberedCode($request, $sub)) {
            [$outcome] = AccessGate::check($sub, $code, $request, $this->browserId($request), remembered: true);
            if ($outcome !== AccessGate::INVALID) {
                $request->session()->put('access_verified', true);

                return $next($request);
            }

            // Regenerated or blocked: forget this browser.
            Cookie::queue(Cookie::forget(AccessGate::COOKIE));
        }

        return redirect()->route('castlit.access.show');
    }

    private function browserId(Request $request): ?string
    {
        $id = (string) $request->cookie('castlit_browser');

        return preg_match('/^[a-f0-9]{32}$/', $id) ? $id : null;
    }
}
