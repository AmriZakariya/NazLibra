<?php

namespace App\Notifications;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to an applicant when their CastLit POS subscription request is declined.
 */
class SubscriptionRejectedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Subscription $subscription,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Votre demande CastLit POS')
            ->greeting('Bonjour '.$this->subscription->contact_name.',')
            ->line('Merci de l\'intérêt que vous portez à CastLit POS.')
            ->line('Nous ne pouvons pas donner suite à votre demande d\'inscription pour le moment.');

        if (! empty($this->subscription->rejection_reason)) {
            $mail->line('Motif : '.$this->subscription->rejection_reason);
        }

        return $mail
            ->line('Pour toute question, répondez simplement à cet email.')
            ->line('Bien cordialement, l\'équipe CastLit.');
    }
}
