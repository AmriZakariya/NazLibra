<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\Subscription;
use App\Models\ClientAccessEvent;
use App\Models\TenantInstall;
use App\Support\BusinessMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PublicController extends Controller
{
    /**
     * Public server info — no authentication required.
     *
     * Resolves tenant (optional) from the X-Tenant-Slug header.
     * Used by the mobile login screen to verify the server URL and preview
     * the tenant name before the user submits credentials.
     */
    public function info(Request $request): JsonResponse
    {
        $tenantName = null;
        $tenantSlug = $request->header('X-Tenant-Slug');

        if ($tenantSlug) {
            $tenant = Tenant::where('slug', $tenantSlug)->first();
            if ($tenant) {
                $tenantName = $tenant->name;
            }
        }

        return response()->json([
            'ok'          => true,
            'app'         => 'NazLibra',
            'tenant_name' => $tenantName,
        ]);
    }

    /**
     * Verify a client name + access code (mobile "already registered" flow).
     * Lives on the master install, which holds the client registry. On success
     * returns the client's base URL so the app can point login at it.
     */
    public function clientAccess(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subdomain' => ['required', 'string', 'max:63'],
            'code'      => ['required', 'string', 'max:32'],
            // What the journal records; optional, older apps send none.
            'channel'           => ['nullable', 'string', 'max:8'],
            'platform'          => ['nullable', 'string', 'max:20'],
            'os'                => ['nullable', 'string', 'max:80'],
            'app_version'       => ['nullable', 'string', 'max:20'],
            'device_id'         => ['nullable', 'string', 'max:64'],
            'client_ip'         => ['nullable', 'string', 'max:45'],
            'client_user_agent' => ['nullable', 'string', 'max:500'],
        ]);

        // The install registry exists only on the master.
        if (! config('castlit.is_master')) {
            return response()->json(['ok' => false, 'message' => 'Service indisponible.'], 404);
        }

        // Accept a bare subdomain, a full domain, or a URL — keep the first label.
        $subdomain = strtolower(trim($data['subdomain']));
        $subdomain = (string) preg_replace('#^https?://#', '', $subdomain);
        $subdomain = explode('.', $subdomain)[0];
        $subdomain = explode('/', $subdomain)[0];
        $code = strtoupper(trim($data['code']));

        $install = TenantInstall::where('subdomain', $subdomain)->first();

        // Every check is journaled, right or wrong, for the platform admin
        // (castlit-admin): who reached their space, from what, and whether
        // someone is guessing codes.
        if (! $install || ! $install->isLive()) {
            ClientAccessEvent::record($request, $install, $subdomain, ClientAccessEvent::OUTCOME_UNKNOWN);

            return response()->json(['ok' => false, 'message' => 'Client introuvable.'], 404);
        }
        if ($install->isBlocked()) {
            ClientAccessEvent::record($request, $install, $subdomain, ClientAccessEvent::OUTCOME_BLOCKED);

            return response()->json([
                'ok' => false, 'message' => 'Cet espace est suspendu. Contactez le support.',
            ], 403);
        }
        if (empty($install->access_code) || ! hash_equals($install->access_code, $code)) {
            ClientAccessEvent::record($request, $install, $subdomain, ClientAccessEvent::OUTCOME_INVALID);

            return response()->json(['ok' => false, 'message' => 'Code de vérification invalide.'], 422);
        }

        ClientAccessEvent::record($request, $install, $subdomain, ClientAccessEvent::OUTCOME_OK);

        return response()->json([
            'ok'        => true,
            'subdomain' => $install->subdomain,
            'name'      => $install->subdomain,
            'base_url'  => $install->url(),
        ]);
    }

    /**
     * Public subscription request from the mobile app — mirrors the web
     * /inscription form: same validation, same reserved/availability checks,
     * and the same pending Subscription row the platform admin then approves.
     */
    public function subscribe(Request $request): JsonResponse
    {
        if (! config('castlit.is_master')) {
            return response()->json(['ok' => false, 'message' => 'Service indisponible.'], 404);
        }

        $request->merge([
            'desired_subdomain' => strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '', (string) $request->input('desired_subdomain'))),
            'currency' => strtoupper(trim((string) $request->input('currency', 'MAD'))),
            'country' => strtoupper(trim((string) $request->input('country', \App\Support\Countries::DEFAULT))),
            'platform' => strtolower(trim((string) $request->input('platform', ''))),
        ]);

        $reserved = config('castlit.reserved_subdomains', []);

        $validated = $request->validate([
            'business_name'     => ['required', 'string', 'max:120'],
            'activity'          => ['nullable', 'string', Rule::in(array_keys(BusinessMode::all()))],
            'currency'          => ['required', 'string', Rule::in(\App\Support\Countries::currencyCodes())],
            'contact_name'      => ['required', 'string', 'max:120'],
            'email'             => ['required', 'email', 'max:190'],
            'phone'             => ['nullable', 'string', 'max:40'],
            'desired_subdomain' => ['required', 'string', 'regex:/^[a-z0-9]{2,30}$/', Rule::notIn($reserved)],
            'heard_about'       => ['nullable', 'string', 'max:120'],
            'country'           => ['required', 'string', Rule::in(\App\Support\Countries::codes())],
            'city'              => ['nullable', 'string', 'max:120'],
            // Optional: builds released before this field existed do not
            // send it, and must still be able to sign a shop up.
            'platform'          => ['nullable', 'string', Rule::in(['', 'android', 'ios', 'windows', 'macos', 'linux', 'web'])],
        ], [
            'desired_subdomain.regex' => 'Le sous-domaine doit contenir 2 à 30 caractères (lettres minuscules et chiffres).',
            'desired_subdomain.not_in' => 'Ce sous-domaine est réservé, choisissez-en un autre.',
        ]);

        $sub = $validated['desired_subdomain'];
        $taken = Subscription::whereIn('status', [Subscription::STATUS_PENDING, Subscription::STATUS_APPROVED])
                ->where('desired_subdomain', $sub)->exists()
            || TenantInstall::where('subdomain', $sub)->exists();

        if ($taken) {
            return response()->json([
                'ok' => false,
                'message' => 'Ce sous-domaine est déjà pris. Essayez une variante.',
                'errors' => ['desired_subdomain' => ['Ce sous-domaine est déjà pris. Essayez une variante.']],
            ], 422);
        }

        Subscription::create([
            'business_name'     => $validated['business_name'],
            'activity'          => $validated['activity'] ?? null,
            'currency'          => $validated['currency'],
            'contact_name'      => $validated['contact_name'],
            'email'             => $validated['email'],
            'phone'             => $validated['phone'] ?? null,
            'desired_subdomain' => $sub,
            'heard_about'       => $validated['heard_about'] ?? null,
            'source'            => match ($validated['platform'] ?? '') {
                'android' => Subscription::SOURCE_ANDROID,
                'ios'     => Subscription::SOURCE_IOS,
                'windows', 'macos', 'linux' => Subscription::SOURCE_DESKTOP,
                'web'     => Subscription::SOURCE_WEBSITE,
                default   => Subscription::SOURCE_MOBILE,
            },
            'country'           => $validated['country'],
            'city'              => filled($validated['city'] ?? null) ? trim($validated['city']) : null,
            'status'            => Subscription::STATUS_PENDING,
            'meta'              => [
                'ip'         => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
            ],
        ]);

        return response()->json([
            'ok'        => true,
            'subdomain' => $sub,
            'url'       => 'https://'.$sub.'.'.config('castlit.main_domain'),
            'message'   => 'Demande envoyée. Vous recevrez vos accès par email après validation.',
        ]);
    }
}
