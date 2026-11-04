<?php

namespace App\Support;

use App\Models\Subscription;
use App\Notifications\NewSubscriptionNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Tells the team a shop signed up. After the response, so the applicant is
 * never kept waiting on a mail server, and never failing the signup: a mail
 * that cannot leave is logged, the subscription is already saved.
 */
class SubscriptionAlerts
{
    /** @return list<string> the addresses in CASTLIT_SIGNUP_NOTIFY */
    public static function recipients(): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', (string) config('castlit.notify.signups'))),
            fn (string $email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false,
        ));
    }

    public static function newSubscription(Subscription $subscription): void
    {
        $recipients = self::recipients();
        if ($recipients === []) {
            return;
        }

        dispatch(function () use ($subscription, $recipients): void {
            foreach ($recipients as $email) {
                try {
                    Notification::route('mail', $email)->notify(new NewSubscriptionNotification($subscription));
                } catch (\Throwable $e) {
                    Log::warning('CastLit: signup alert failed', ['subscription' => $subscription->id, 'error' => $e->getMessage()]);
                }
            }
        })->afterResponse();
    }
}
