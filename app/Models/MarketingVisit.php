<?php

namespace App\Models;

use App\Support\CampaignAttribution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One visit to the castlitpos.com landing page (see App\Support\MarketingVisits). */
class MarketingVisit extends Model
{
    public const OPENED = 'opened';

    public const STARTED = 'started';

    public const TRIED = 'tried';

    public const REFUSED = 'refused';

    public const SENT = 'sent';

    /** How far along the way to a sign-up request: a visit only moves forward. */
    public const STAGES = [self::OPENED => 0, self::STARTED => 1, self::TRIED => 2, self::REFUSED => 3, self::SENT => 4];

    public const LABELS = [
        self::OPENED => 'Page ouverte',
        self::STARTED => 'Formulaire commencé',
        self::TRIED => 'Envoi tenté',
        self::REFUSED => 'Refusé',
        self::SENT => 'Demande envoyée',
    ];

    /** Why a visit looks automated; see MarketingVisits::serverRefused(). */
    public const ROBOT_REASONS = [
        'honeypot' => 'Champ anti-spam rempli',
        'impossible' => 'Valeur impossible depuis le formulaire',
        'untouched' => 'Envoyé sans toucher un champ',
    ];

    protected $fillable = [
        'ip', 'country', 'locale', 'device', 'campaign_source', 'campaign_medium', 'campaign_name',
        'referrer', 'landing', 'user_agent', 'stage', 'refused_fields', 'robot_reason', 'subscription_id', 'last_seen_at',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function campaignLabel(): ?string
    {
        if (! $this->campaign_source) {
            return null;
        }
        if ($this->campaign_source === CampaignAttribution::DIRECT) {
            return 'Direct';
        }

        return $this->campaign_source.' · '.($this->campaign_name ?: ($this->campaign_medium ?: '—'));
    }
}
