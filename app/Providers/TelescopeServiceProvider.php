<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\EntryType;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Telescope::night();

        $this->hideSensitiveRequestDetails();

        $isLocal = $this->app->environment('local');

        $recordAll = (bool) config('telescope.record_all', false);

        Telescope::filter(
            fn (IncomingEntry $entry) => self::shouldRecord($entry, $isLocal, $recordAll),
        );
    }

    /**
     * Whether an entry is worth keeping on a deployed install.
     *
     * `isFailedRequest()` means status >= 500 in Telescope, so the previous
     * filter dropped every 4xx — a 422 from a validation mismatch between the
     * app and the API never appeared, which is exactly the failure someone
     * goes to Telescope to find. Client errors are now kept: on a first-party
     * client a 4xx is almost always a real defect, not a caller's mistake.
     *
     * 2xx traffic is still discarded unless TELESCOPE_RECORD_ALL is set,
     * because that is the volume that fills a shared host's disk. Whatever is
     * recorded is pruned nightly — see the `telescope:prune` schedule.
     */
    public static function shouldRecord(
        IncomingEntry $entry,
        bool $isLocal,
        bool $recordAll = false,
    ): bool {
        if ($isLocal || $recordAll) {
            return true;
        }

        return $entry->isReportableException()
            || $entry->isFailedRequest()
            || self::isClientError($entry)
            || self::isFailedOutgoingRequest($entry)
            || $entry->isFailedJob()
            || $entry->isScheduledTask()
            || $entry->hasMonitoredTag();
    }

    /**
     * A failed call this application made to somebody else.
     *
     * Telescope's own `isFailedRequest()` covers only INCOMING requests, so a
     * gateway or provider refusing our call left nothing behind either. Kept
     * explicitly rather than by letting [isClientError] see every entry type,
     * so the two cases stay separately named and separately changeable.
     */
    private static function isFailedOutgoingRequest(IncomingEntry $entry): bool
    {
        if ($entry->type !== EntryType::CLIENT_REQUEST) {
            return false;
        }

        return (int) ($entry->content['response_status'] ?? 200) >= 400;
    }

    /** A 4xx response: the app asked for something the API refused. */
    private static function isClientError(IncomingEntry $entry): bool
    {
        if ($entry->type !== EntryType::REQUEST) {
            return false;
        }

        $status = (int) ($entry->content['response_status'] ?? 200);

        return $status >= 400 && $status < 500;
    }

    /**
     * Prevent sensitive request details from being logged by Telescope.
     */
    protected function hideSensitiveRequestDetails(): void
    {
        Telescope::hideRequestParameters([
            '_token',
            'password',
            'password_confirmation',
            'current_password',
            'pin',
            'token',
            'api_token',
            'access_token',
            'refresh_token',
            'sms_api_key',
            'whatsapp_token',
        ]);

        Telescope::hideRequestHeaders([
            'authorization',
            'cookie',
            'x-csrf-token',
            'x-xsrf-token',
            'x-api-key',
        ]);
    }

    /**
     * Register the Telescope gate.
     *
     * This gate determines who can access Telescope in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewTelescope', function (User $user) {
            if ($this->app->environment('local')) {
                return true;
            }

            $allowedEmails = config('telescope.allowed_emails', []);
            if (in_array($user->email, $allowedEmails, true)) {
                return true;
            }

            if (! config('telescope.allow_owner_role', true)) {
                return false;
            }

            $tenant = $user->currentTenant;
            if (! $tenant) {
                return false;
            }

            return $tenant->users()
                ->whereKey($user->id)
                ->wherePivot('role', 'owner')
                ->exists();
        });
    }
}
