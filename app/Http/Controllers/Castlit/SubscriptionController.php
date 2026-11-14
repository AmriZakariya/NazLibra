<?php

namespace App\Http\Controllers\Castlit;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\TenantInstall;
use App\Support\BusinessMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SubscriptionController extends Controller
{
    /** Store a public subscription request from the marketing site. */
    public function store(Request $request): RedirectResponse
    {
        // Normalize the subdomain before validating so rules see the final value.
        $request->merge([
            'desired_subdomain' => $this->normalizeSubdomain((string) $request->input('desired_subdomain')),
            'currency'          => strtoupper(trim((string) $request->input('currency', 'MAD'))),
            'country'           => strtoupper(trim((string) $request->input('country', \App\Support\Countries::DEFAULT))),
        ]);

        $reserved = config('castlit.reserved_subdomains', []);

        // A refused form reopens ON the form, not at the top of the page: the
        // visitor would otherwise have to scroll back down to find out why.
        try {
            $validated = $request->validate([
                'business_name'     => ['required', 'string', 'max:120'],
                'activity'          => ['nullable', 'string', Rule::in(array_keys(BusinessMode::all()))],
                'currency'          => ['required', 'string', Rule::in(\App\Support\Countries::currencyCodes())],
                'contact_name'      => ['required', 'string', 'max:120'],
                'email'             => ['required', 'email', 'max:190'],
                'phone'             => ['nullable', 'string', 'max:40'],
                'desired_subdomain' => [
                    'required', 'string', 'regex:/^[a-z0-9]{2,30}$/',
                    Rule::notIn($reserved),
                ],
                'heard_about'       => ['nullable', 'string', 'max:120'],
                'country'           => ['required', 'string', Rule::in(\App\Support\Countries::codes())],
                'city'              => ['nullable', 'string', 'max:120'],
                'address'           => ['nullable', 'string', 'max:190'],
                // Honeypot — must stay empty (bots fill it).
                'website'           => ['nullable', 'size:0'],
            ], [
                'desired_subdomain.regex' => 'Le sous-domaine doit contenir 2 à 30 caractères (lettres minuscules et chiffres).',
                'desired_subdomain.not_in' => 'Ce sous-domaine est réservé, choisissez-en un autre.',
                'website.size' => 'Requête invalide.',
            ]);

            // Availability across pending/approved requests and live installs.
            if (! $this->subdomainAvailable($validated['desired_subdomain'])) {
                throw ValidationException::withMessages([
                    'desired_subdomain' => 'Ce sous-domaine est déjà pris. Essayez une variante.',
                ]);
            }
        } catch (ValidationException $e) {
            \App\Support\MarketingVisits::serverRefused($request, array_keys($e->errors()));
            throw $e->redirectTo($this->formUrl());
        }

        $campaign = \App\Support\CampaignAttribution::forSubscription($request);

        $subscription = Subscription::create([
            'business_name'     => $validated['business_name'],
            'activity'          => $validated['activity'] ?? null,
            'currency'          => $validated['currency'],
            'contact_name'      => $validated['contact_name'],
            'email'             => $validated['email'],
            'phone'             => $validated['phone'] ?? null,
            'desired_subdomain' => $validated['desired_subdomain'],
            'heard_about'       => $validated['heard_about'] ?? null,
            'source'            => Subscription::SOURCE_WEBSITE,
            'country'           => $validated['country'],
            'city'              => filled($validated['city'] ?? null) ? trim($validated['city']) : null,
            'address'           => filled($validated['address'] ?? null) ? trim($validated['address']) : null,
            'status'            => Subscription::STATUS_PENDING,
            ...$campaign['columns'],
            'meta'              => [
                // utm_content/term, click id, referring site, first page seen.
                'campaign'   => $campaign['meta'] ?: null,
                // The browser's zone: a shop in Montréal is not on Toronto
                // time just because that is Canada's main zone.
                'timezone'   => \App\Support\Countries::timezone($validated['country'], (string) $request->input('timezone')),
                'ip'         => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
            ],
        ]);
        \App\Support\SubscriptionAlerts::newSubscription($subscription);
        \App\Support\MarketingVisits::serverSent($request, $subscription);

        return redirect()
            ->route('castlit.subscribe.success')
            ->with('subscribed_subdomain', $validated['desired_subdomain']);
    }

    /**
     * GET /inscription/disponible?adresse=… — whether an address can still be
     * asked for, so the form says so while the visitor types, not after.
     */
    public function availability(Request $request): JsonResponse
    {
        $subdomain = $this->normalizeSubdomain((string) $request->query('adresse', ''));

        $reason = match (true) {
            preg_match('/^[a-z0-9]{2,30}$/', $subdomain) !== 1 => 'format',
            in_array($subdomain, config('castlit.reserved_subdomains', []), true) => 'reserved',
            ! $this->subdomainAvailable($subdomain) => 'taken',
            default => null,
        };

        return response()->json([
            'subdomain' => $subdomain,
            'available' => $reason === null,
            'reason' => $reason,
        ]);
    }

    /**
     * The page the form was on (its language kept), opened at the form. Only
     * a page of this site: the previous URL can come from the Referer header.
     */
    private function formUrl(): string
    {
        $previous = strtok(url()->previous(), '#') ?: '';
        $sameSite = parse_url($previous, PHP_URL_HOST) === request()->getHost();

        return ($sameSite ? $previous : route('castlit.landing')).'#inscription';
    }

    private function normalizeSubdomain(string $raw): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(trim($raw))) ?? '';
    }

    private function subdomainAvailable(string $subdomain): bool
    {
        $takenInSubs = Subscription::whereIn('status', [Subscription::STATUS_PENDING, Subscription::STATUS_APPROVED])
            ->where('desired_subdomain', $subdomain)
            ->exists();

        $takenInInstalls = TenantInstall::where('subdomain', $subdomain)->exists();

        return ! $takenInSubs && ! $takenInInstalls;
    }
}
