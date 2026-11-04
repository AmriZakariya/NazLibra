<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Notifications\NewSubscriptionNotification;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Concerns\ActsAsMasterInstall;
use Tests\TestCase;

/**
 * A shop signs up, on the website or in the app, and the team hears about it
 * at the addresses set in CASTLIT_SIGNUP_NOTIFY.
 */
class SignupAlertTest extends TestCase
{
    use ActsAsMasterInstall;
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function request(array $overrides = []): array
    {
        return array_merge([
            'business_name' => 'Spa Paris', 'contact_name' => 'Majda', 'email' => 'majda@example.com',
            'phone' => '+33612345678', 'desired_subdomain' => 'spa'.Str::lower(Str::random(6)),
            'currency' => 'EUR', 'country' => 'FR', 'city' => 'Paris', 'activity' => 'beauty',
        ], $overrides);
    }

    public function test_a_website_signup_emails_every_address_set(): void
    {
        Notification::fake();
        config(['castlit.notify.signups' => 'team@example.com, second@example.com']);

        $this->post('/inscription', $this->request())->assertRedirect();

        $subscription = Subscription::sole();
        foreach (['team@example.com', 'second@example.com'] as $address) {
            Notification::assertSentOnDemand(
                NewSubscriptionNotification::class,
                fn (NewSubscriptionNotification $n, array $channels, AnonymousNotifiable $to): bool => $to->routes['mail'] === $address
                    && $n->subscription->is($subscription),
            );
        }
    }

    public function test_an_app_signup_emails_the_team_too_and_says_where_it_came_from(): void
    {
        Notification::fake();
        config(['castlit.notify.signups' => 'team@example.com']);

        $this->postJson('/api/v1/public/subscribe', $this->request(['platform' => 'android']))->assertOk();

        Notification::assertSentOnDemandTimes(NewSubscriptionNotification::class, 1);
        $mail = (new NewSubscriptionNotification(Subscription::sole()))->toMail(new AnonymousNotifiable);
        $this->assertSame('Nouvelle inscription : Spa Paris', $mail->subject);
        $body = implode("\n", $mail->introLines);
        $this->assertStringContainsString('majda@example.com', $body);
        $this->assertStringContainsString('l’application Android', $body);
        $this->assertStringContainsString('France · Paris', $body);
        $this->assertSame(route('castlit.admin.show', Subscription::sole()), $mail->actionUrl);
    }

    public function test_nothing_is_sent_while_no_address_is_set(): void
    {
        Notification::fake();
        config(['castlit.notify.signups' => '']);

        $this->post('/inscription', $this->request())->assertRedirect();

        Notification::assertNothingSent();
        $this->assertSame(1, Subscription::count());
    }

    public function test_a_mail_that_cannot_leave_does_not_lose_the_signup(): void
    {
        config(['castlit.notify.signups' => 'team@example.com']);
        $this->app->instance(Dispatcher::class, new class implements Dispatcher
        {
            public function send($notifiables, $notification): void
            {
                throw new \RuntimeException('SMTP down');
            }

            public function sendNow($notifiables, $notification, ?array $channels = null): void
            {
                throw new \RuntimeException('SMTP down');
            }
        });

        Log::spy();

        $this->post('/inscription', $this->request())->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, Subscription::count());
        // It was tried, and the failure is on record rather than silent.
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => $message === 'CastLit: signup alert failed');
    }
}
