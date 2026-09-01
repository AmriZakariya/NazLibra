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
                ['subdomain' => $sub, 'code' => strtoupper(trim($data['code']))],
            );

            if ($res->successful() && $res->json('ok') === true) {
                $request->session()->put('access_verified', true);

                return redirect()->route('login');
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
