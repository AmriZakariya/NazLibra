<?php

namespace App\Notifications;

use App\Models\Subscription;
use App\Support\BusinessMode;
use App\Support\Countries;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To the Castl-it-POS team: someone just signed up and waits for approval.
 * Sent to the addresses in CASTLIT_SIGNUP_NOTIFY (see SubscriptionAlerts).
 */
class NewSubscriptionNotification extends Notification
{
    use Queueable;

    public function __construct(public Subscription $subscription) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $s = $this->subscription;
        $activity = $s->activity ? (BusinessMode::all()[$s->activity]['label'] ?? $s->activity) : '—';
        $country = $s->country ? (Countries::name($s->country) ?? $s->country) : '—';

        return (new MailMessage)
            ->subject('Nouvelle inscription : '.$s->business_name)
            ->greeting('Nouvelle inscription à valider')
            ->line('**Boutique :** '.$s->business_name)
            ->line('**Contact :** '.$s->contact_name)
            ->line('**Email :** '.$s->email)
            ->line('**Téléphone :** '.($s->phone ?: '—'))
            ->line('**Adresse demandée :** '.$s->desired_subdomain.'.'.config('castlit.main_domain'))
            ->line('**Pays :** '.$country.($s->city ? ' · '.$s->city : ''))
            ->line('**Activité :** '.$activity.' · **Devise :** '.$s->currency)
            ->line('**Inscrit depuis :** '.self::sourceLabel($s->source))
            ->action('Ouvrir dans castlit-admin', route('castlit.admin.show', $s))
            ->line('La demande attend votre validation : rien n’est créé tant qu’elle n’est pas approuvée.');
    }

    public static function sourceLabel(?string $source): string
    {
        return match ($source) {
            Subscription::SOURCE_WEBSITE => 'le site castlitpos.com',
            Subscription::SOURCE_ANDROID => 'l’application Android',
            Subscription::SOURCE_IOS => 'l’application iPhone',
            Subscription::SOURCE_DESKTOP => 'la caisse Windows',
            Subscription::SOURCE_MOBILE => 'l’application',
            default => $source ?? '—',
        };
    }
}
