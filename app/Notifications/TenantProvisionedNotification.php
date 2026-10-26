<?php

namespace App\Notifications;

use App\Models\Subscription;
use App\Models\TenantInstall;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to a new client once their Castl-it-POS install is live — and again by
 * the admin's "Renvoyer les accès". Delivered on-demand to the subscription
 * email.
 *
 * It carries everything the client needs on either side: the address and
 * login for the web, and the client name + verification code the mobile app
 * asks for before it shows a login screen at all. Without the code the email
 * only got a client halfway — they could open the site but not the app.
 */
class TenantProvisionedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public TenantInstall $install,
        public Subscription $subscription,
        public ?string $password = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Votre espace Castl-it-POS est prêt 🎉')
            ->view(
                ['castlit.emails.tenant-provisioned', 'castlit.emails.tenant-provisioned-text'],
                $this->details(),
            );
    }

    /** @return array<string, string|null> */
    public function details(): array
    {
        return [
            'contactName'  => $this->subscription->contact_name,
            'businessName' => $this->subscription->business_name,
            'url'          => $this->install->url(),
            'domain'       => $this->install->domain,
            // What the app's "Nom du client" field expects.
            'clientName'   => $this->install->subdomain,
            // An install created before codes existed gets one now, so a
            // resend never goes out with a blank where the code should be.
            'code'         => $this->install->ensureAccessCode(),
            'login'        => $this->install->owner_email,
            'password'     => $this->password,
            'playStore'    => (string) config('castlit.brand.play_store'),
            'appStore'     => (string) config('castlit.brand.app_store'),
            'support'      => (string) config('castlit.brand.email'),
        ];
    }
}
