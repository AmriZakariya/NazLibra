<?php

namespace App\Notifications;

use App\Models\Subscription;
use App\Models\TenantInstall;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to a new client once their Castl-it-POS install is live, with the URL and
 * first-login credentials. Delivered on-demand to the subscription email.
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
        $mail = (new MailMessage)
            ->subject('Votre espace Castl-it-POS est prêt 🎉')
            ->greeting('Bonjour '.$this->subscription->contact_name.',')
            ->line('Votre boutique « '.$this->subscription->business_name.' » est maintenant en ligne sur Castl-it-POS.')
            ->line('Adresse de connexion : '.$this->install->url())
            ->action('Ouvrir mon espace', $this->install->url());

        $mail->line('**Identifiant :** '.$this->install->owner_email);
        if ($this->password) {
            $mail->line('**Mot de passe provisoire :** '.$this->password)
                ->line('Pensez à le changer après votre première connexion.');
        } else {
            $mail->line('Utilisez le mot de passe défini lors de votre inscription, ou la fonction « mot de passe oublié ».');
        }

        return $mail->line('Merci de votre confiance et bienvenue à bord !');
    }
}
