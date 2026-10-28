<?php

namespace App\Http\Controllers\Castlit;

use App\Http\Controllers\Controller;
use App\Jobs\ProvisionTenantJob;
use App\Models\ClientAccessEvent;
use App\Models\Subscription;
use App\Models\TenantInstall;
use App\Notifications\SubscriptionRejectedNotification;
use App\Notifications\TenantProvisionedNotification;
use App\Support\BusinessMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SubscriptionAdminController extends Controller
{
    /** List subscriptions, filterable by status. */
    public function index(Request $request): View
    {
        $status = $request->query('status', 'pending');
        $query = Subscription::with('install')->latest();

        if (in_array($status, [Subscription::STATUS_PENDING, Subscription::STATUS_APPROVED, Subscription::STATUS_REJECTED], true)) {
            $query->where('status', $status);
        } else {
            $status = 'all';
        }

        // Counted in PHP rather than SQL: requests from before the `source`
        // column are read from what they did record (see origin()), and the
        // table is a few hundred rows at most.
        $origins = Subscription::query()->get(['id', 'source', 'meta'])
            ->groupBy(fn (Subscription $s): string => $s->originLabel())
            ->map->count()
            ->sortDesc();

        $subscriptions = $query->paginate(20)->withQueryString();

        return view('castlit.admin.index', [
            'subscriptions' => $subscriptions,
            'access'        => $this->accessSummaries($subscriptions->getCollection()->pluck('install.id')->filter()->all()),
            'status'        => $status,
            'origins'       => $origins,
            'counts'        => [
                'pending'  => Subscription::where('status', Subscription::STATUS_PENDING)->count(),
                'approved' => Subscription::where('status', Subscription::STATUS_APPROVED)->count(),
                'rejected' => Subscription::where('status', Subscription::STATUS_REJECTED)->count(),
            ],
        ]);
    }

    public function show(Subscription $subscription): View
    {
        $subscription->load('install', 'reviewer');
        $installId = $subscription->install?->id;

        return view('castlit.admin.show', [
            'subscription' => $subscription,
            'access'       => $installId ? ($this->accessSummaries([$installId])[$installId] ?? null) : null,
            'accessEvents' => $installId
                ? ClientAccessEvent::where('tenant_install_id', $installId)->latest('id')->limit(100)->get()
                : collect(),
        ]);
    }

    /**
     * For each install: how many times the right code was entered, from how
     * many devices, the last such entry, and the wrong codes of the last 30
     * days. Keyed by install id; an install never reached is absent.
     *
     * @param  list<int>  $installIds
     * @return array<int, array{count: int, devices: int, last: ClientAccessEvent|null, failed: int}>
     */
    private function accessSummaries(array $installIds): array
    {
        if ($installIds === []) {
            return [];
        }

        $ok = ClientAccessEvent::query()
            ->whereIn('tenant_install_id', $installIds)
            ->where('outcome', ClientAccessEvent::OUTCOME_OK)
            ->selectRaw('tenant_install_id, COUNT(*) AS n, MAX(id) AS last_id')
            ->groupBy('tenant_install_id')
            ->get()
            ->keyBy('tenant_install_id');

        // Distinct devices, counted here: string concatenation is spelled
        // differently on SQLite and MySQL. A device without an id (an older
        // app) counts by its label.
        $devices = ClientAccessEvent::query()
            ->whereIn('tenant_install_id', $installIds)
            ->where('outcome', ClientAccessEvent::OUTCOME_OK)
            ->distinct()
            ->get(['tenant_install_id', 'device_id', 'channel', 'device_label'])
            ->groupBy('tenant_install_id')
            ->map(fn ($rows) => $rows->map(fn ($e) => $e->device_id ?: $e->channel.':'.$e->device_label)->unique()->count());

        $last = ClientAccessEvent::whereIn('id', $ok->pluck('last_id'))->get()->keyBy('tenant_install_id');

        $failed = ClientAccessEvent::query()
            ->whereIn('tenant_install_id', $installIds)
            ->where('outcome', ClientAccessEvent::OUTCOME_INVALID)
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('tenant_install_id, COUNT(*) AS n')
            ->groupBy('tenant_install_id')
            ->pluck('n', 'tenant_install_id');

        $out = [];
        foreach ($installIds as $id) {
            if (! isset($ok[$id]) && ! isset($failed[$id])) {
                continue;
            }
            $out[$id] = [
                'count'   => (int) ($ok[$id]->n ?? 0),
                'devices' => (int) ($devices[$id] ?? 0),
                'last'    => $last[$id] ?? null,
                'failed'  => (int) ($failed[$id] ?? 0),
            ];
        }

        return $out;
    }

    /** Manual client creation form (for WhatsApp / Google Form leads). */
    public function create(): View
    {
        return view('castlit.admin.create', [
            'activities' => collect(BusinessMode::all())->map(fn (array $m) => $m['label'] ?? '')->toArray(),
            'currencies' => \App\Support\Countries::currencyOptions(),
            'countries'  => \App\Support\Countries::all(),
        ]);
    }

    /** Create a client manually (pre-approved) and provision it immediately. */
    public function store(Request $request): RedirectResponse
    {
        $request->merge([
            'desired_subdomain' => preg_replace('/[^a-z0-9]/', '', strtolower(trim((string) $request->input('desired_subdomain')))) ?? '',
            'currency'          => strtoupper(trim((string) $request->input('currency', 'MAD'))),
            'country'           => strtoupper(trim((string) $request->input('country', \App\Support\Countries::DEFAULT))),
        ]);

        $data = $request->validate([
            'business_name'     => ['required', 'string', 'max:120'],
            'activity'          => ['nullable', 'string', Rule::in(array_keys(BusinessMode::all()))],
            'currency'          => ['required', 'string', Rule::in(\App\Support\Countries::currencyCodes())],
            'contact_name'      => ['required', 'string', 'max:120'],
            'email'             => ['required', 'email', 'max:190'],
            'phone'             => ['nullable', 'string', 'max:40'],
            // Admin may use demo/test/staging etc. — only truly system names are blocked.
            'desired_subdomain' => ['required', 'string', 'regex:/^[a-z0-9]{2,30}$/', Rule::notIn(config('castlit.system_subdomains', []))],
            'heard_about'       => ['nullable', 'string', 'max:120'],
            'country'           => ['required', 'string', Rule::in(\App\Support\Countries::codes())],
            'city'              => ['nullable', 'string', 'max:120'],
        ], [
            'desired_subdomain.regex'  => 'Le sous-domaine doit contenir 2 à 30 caractères (lettres minuscules et chiffres).',
            'desired_subdomain.not_in' => 'Ce sous-domaine est réservé au système (www, api, admin, mail…).',
        ]);

        if (! $this->subdomainAvailable($data['desired_subdomain'])) {
            throw ValidationException::withMessages([
                'desired_subdomain' => 'Ce sous-domaine est déjà pris ou provisionné.',
            ]);
        }

        $subscription = Subscription::create([
            'business_name'     => $data['business_name'],
            'activity'          => $data['activity'] ?? null,
            'currency'          => $data['currency'],
            'contact_name'      => $data['contact_name'],
            'email'             => $data['email'],
            'phone'             => $data['phone'] ?? null,
            'desired_subdomain' => $data['desired_subdomain'],
            // `??` before `?:`: a field left out of the request is absent from
            // $data, not null, and reading it bare is a 500.
            'heard_about'       => ($data['heard_about'] ?? null) ?: 'Ajout manuel (admin)',
            'source'            => Subscription::SOURCE_ADMIN,
            'country'           => $data['country'],
            'city'              => filled($data['city'] ?? null) ? trim($data['city']) : null,
            'status'            => Subscription::STATUS_APPROVED,
            'reviewed_by'       => $request->user()->id,
            'reviewed_at'       => now(),
            'meta'              => ['source' => 'manual', 'created_by' => $request->user()->id],
        ]);

        $install = $this->createInstallAndDispatch($subscription);

        return redirect()
            ->route('castlit.admin.show', $subscription)
            ->with('success', "Client créé. Provisioning de « {$install->domain} » lancé.");
    }

    /** Approve → create the install record and dispatch provisioning. */
    public function approve(Request $request, Subscription $subscription): RedirectResponse
    {
        if (! $subscription->isPending()) {
            return back()->with('error', 'Cette demande a déjà été traitée.');
        }

        // Guard the subdomain one more time at approval time.
        $subdomain = $subscription->desired_subdomain;
        if (TenantInstall::where('subdomain', $subdomain)->exists()) {
            return back()->with('error', "Le sous-domaine « {$subdomain} » est déjà provisionné.");
        }

        $subscription->update([
            'status'      => Subscription::STATUS_APPROVED,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        $install = $this->createInstallAndDispatch($subscription);

        return redirect()
            ->route('castlit.admin.show', $subscription)
            ->with('success', "Demande approuvée. Provisioning de « {$install->domain} » lancé.");
    }

    /** Reject → record reason and notify the applicant. */
    public function reject(Request $request, Subscription $subscription): RedirectResponse
    {
        if (! $subscription->isPending()) {
            return back()->with('error', 'Cette demande a déjà été traitée.');
        }

        $validated = $request->validate([
            'rejection_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $subscription->update([
            'status'           => Subscription::STATUS_REJECTED,
            'rejection_reason' => $validated['rejection_reason'] ?? null,
            'reviewed_by'      => $request->user()->id,
            'reviewed_at'      => now(),
        ]);

        try {
            Notification::route('mail', $subscription->email)
                ->notify(new SubscriptionRejectedNotification($subscription));
        } catch (\Throwable $e) {
            // Non-fatal: rejection is recorded even if the email fails.
        }

        return redirect()
            ->route('castlit.admin.index')
            ->with('success', 'Demande rejetée.');
    }

    /** Re-run provisioning for a failed install. */
    public function retry(Subscription $subscription): RedirectResponse
    {
        $install = $subscription->install;
        if (! $install || $install->status === TenantInstall::STATUS_LIVE) {
            return back()->with('error', 'Aucun provisioning à relancer.');
        }

        $install->update(['status' => TenantInstall::STATUS_QUEUED, 'provision_log' => null]);
        ProvisionTenantJob::dispatch($install->id);

        return back()->with('success', 'Provisioning relancé.');
    }

    /** Re-send the welcome email (URL + login) for a live install. */
    public function resendAccess(Subscription $subscription): RedirectResponse
    {
        $install = $subscription->install;
        if (! $install || $install->status !== TenantInstall::STATUS_LIVE) {
            return back()->with('error', "Aucun espace en ligne : impossible de renvoyer les accès.");
        }

        try {
            // The one-time password isn't stored — the email points the client to
            // "mot de passe oublié" to set a new one.
            Notification::route('mail', $subscription->email)
                ->notify(new TenantProvisionedNotification($install, $subscription, null));
        } catch (\Throwable $e) {
            return back()->with('error', "Échec de l'envoi : ".$e->getMessage());
        }

        return back()->with('success', "Accès renvoyés à {$subscription->email}.");
    }

    /** Create the install row for an approved subscription and queue provisioning. */
    private function createInstallAndDispatch(Subscription $subscription): TenantInstall
    {
        $subdomain = $subscription->desired_subdomain;

        $install = DB::transaction(fn (): TenantInstall => TenantInstall::create([
            'subscription_id' => $subscription->id,
            'subdomain'       => $subdomain,
            'domain'          => $subdomain.'.'.config('castlit.main_domain'),
            'status'          => TenantInstall::STATUS_QUEUED,
            'owner_email'     => $subscription->email,
            'access_code'     => TenantInstall::generateAccessCode(),
        ]));

        ProvisionTenantJob::dispatch($install->id);

        return $install;
    }

    /** A subdomain is free when no live/pending request or install already holds it. */
    private function subdomainAvailable(string $subdomain): bool
    {
        $inSubs = Subscription::whereIn('status', [Subscription::STATUS_PENDING, Subscription::STATUS_APPROVED])
            ->where('desired_subdomain', $subdomain)
            ->exists();

        return ! $inSubs && ! TenantInstall::where('subdomain', $subdomain)->exists();
    }
}
