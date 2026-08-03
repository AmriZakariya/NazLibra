<?php

namespace App\Http\Controllers\Castlit;

use App\Http\Controllers\Controller;
use App\Jobs\ProvisionTenantJob;
use App\Models\Subscription;
use App\Models\TenantInstall;
use App\Notifications\SubscriptionRejectedNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
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

        return view('castlit.admin.index', [
            'subscriptions' => $query->paginate(20)->withQueryString(),
            'status'        => $status,
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

        return view('castlit.admin.show', compact('subscription'));
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

        $install = DB::transaction(function () use ($subscription, $subdomain, $request): TenantInstall {
            $subscription->update([
                'status'      => Subscription::STATUS_APPROVED,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

            return TenantInstall::create([
                'subscription_id' => $subscription->id,
                'subdomain'       => $subdomain,
                'domain'          => $subdomain.'.'.config('castlit.main_domain'),
                'status'          => TenantInstall::STATUS_QUEUED,
                'owner_email'     => $subscription->email,
            ]);
        });

        ProvisionTenantJob::dispatch($install->id);

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
}
