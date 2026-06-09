<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPinNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $token,
        public string $email,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('pin.reset', ['token' => $this->token, 'email' => $this->email]);

        return (new MailMessage)
            ->subject('Réinitialisation de votre PIN caisse')
            ->greeting('Bonjour,')
            ->line('Vous recevez cet email suite à une demande de réinitialisation de votre PIN de caisse.')
            ->action('Réinitialiser le PIN', $url)
            ->line('Ce lien expirera dans 60 minutes.')
            ->line('Si vous n\'avez pas demandé cette réinitialisation, ignorez cet email.');
    }
}
