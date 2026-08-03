<?php

namespace App\Http\Controllers\Castlit;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\TenantInstall;
use App\Support\BusinessMode;
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
        ]);

        $reserved = config('castlit.reserved_subdomains', []);

        $validated = $request->validate([
            'business_name'     => ['required', 'string', 'max:120'],
            'activity'          => ['nullable', 'string', Rule::in(array_keys(BusinessMode::all()))],
            'currency'          => ['required', 'string', 'size:3'],
            'contact_name'      => ['required', 'string', 'max:120'],
            'email'             => ['required', 'email', 'max:190'],
            'phone'             => ['nullable', 'string', 'max:40'],
            'desired_subdomain' => [
                'required', 'string', 'regex:/^[a-z0-9]{2,30}$/',
                Rule::notIn($reserved),
            ],
            'heard_about'       => ['nullable', 'string', 'max:120'],
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

        Subscription::create([
            'business_name'     => $validated['business_name'],
            'activity'          => $validated['activity'] ?? null,
            'currency'          => $validated['currency'],
            'contact_name'      => $validated['contact_name'],
            'email'             => $validated['email'],
            'phone'             => $validated['phone'] ?? null,
            'desired_subdomain' => $validated['desired_subdomain'],
            'heard_about'       => $validated['heard_about'] ?? null,
            'status'            => Subscription::STATUS_PENDING,
            'meta'              => [
                'ip'         => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
            ],
        ]);

        return redirect()
            ->route('castlit.subscribe.success')
            ->with('subscribed_subdomain', $validated['desired_subdomain']);
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
