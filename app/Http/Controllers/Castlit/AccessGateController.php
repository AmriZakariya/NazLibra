<?php

namespace App\Http\Controllers\Castlit;

use App\Http\Controllers\Controller;
use App\Support\AccessGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Access-code gate for a client web install. Verifies the code against the
 * master registry (same endpoint the mobile app uses), then flags the session
 * so the visitor can proceed to login.
 */
class AccessGateController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        $sub = $this->subdomain($request);

        // Opened straight from the address bar's history or a bookmark: a
        // browser already through the gate is not asked for the code again.
        if ($sub !== null && ! config('castlit.is_master')
            && AccessGate::passes($request, $sub)) {
            return redirect()->route('login');
        }

        return view('castlit.access', ['subdomain' => $sub]);
    }

    public function verify(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32'],
        ]);

        $sub = $this->subdomain($request);
        if ($sub === null) {
            return back()->withErrors(['code' => 'Client introuvable.']);
        }

        [$outcome, $message] = AccessGate::check($sub, $data['code'], $request, $this->browserId($request));

        if ($outcome === AccessGate::VALID) {
            $request->session()->put('access_verified', true);

            // This browser is remembered: no code to type again tomorrow.
            return redirect()->route('login')
                ->withCookie(cookie()->forever(self::BROWSER_COOKIE, $this->browserId($request)))
                ->withCookie(AccessGate::rememberCookie($sub, $data['code']));
        }

        return back()->withErrors([
            'code' => $outcome === AccessGate::INVALID
                ? ($message ?? 'Code de vérification invalide.')
                : 'Vérification indisponible pour le moment. Réessayez.',
        ]);
    }

    private const BROWSER_COOKIE = 'castlit_browser';

    /**
     * A random id kept in this browser, so the journal can tell one computer
     * entering the code ten times from ten computers. Not tied to a person.
     */
    private function browserId(Request $request): string
    {
        $id = (string) $request->cookie(self::BROWSER_COOKIE);

        return preg_match('/^[a-f0-9]{32}$/', $id) ? $id : ($this->freshBrowserId ??= bin2hex(random_bytes(16)));
    }

    private ?string $freshBrowserId = null;

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
