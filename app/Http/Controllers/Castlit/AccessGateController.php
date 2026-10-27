<?php

namespace App\Http\Controllers\Castlit;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;

/**
 * Access-code gate for a client web install. Verifies the code against the
 * master registry (same endpoint the mobile app uses), then flags the session
 * so the visitor can proceed to login.
 */
class AccessGateController extends Controller
{
    public function show(Request $request): View
    {
        return view('castlit.access', ['subdomain' => $this->subdomain($request)]);
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

        try {
            $base = 'https://'.strtolower((string) config('castlit.main_domain'));
            $res = Http::acceptJson()->asJson()->timeout(12)->post(
                $base.'/api/v1/public/client-access',
                [
                    'subdomain' => $sub,
                    'code' => strtoupper(trim($data['code'])),
                    // For the master's access journal: the visitor, not
                    // this server, is the one entering the code.
                    'channel' => 'web',
                    'client_ip' => $request->ip(),
                    'client_user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
                    'device_id' => $this->browserId($request),
                ],
            );

            if ($res->successful() && $res->json('ok') === true) {
                $request->session()->put('access_verified', true);

                return redirect()->route('login')
                    ->withCookie(cookie()->forever(self::BROWSER_COOKIE, $this->browserId($request)));
            }

            return back()->withErrors([
                'code' => $res->json('message') ?? 'Code de vérification invalide.',
            ]);
        } catch (\Throwable) {
            return back()->withErrors([
                'code' => 'Vérification indisponible pour le moment. Réessayez.',
            ]);
        }
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
