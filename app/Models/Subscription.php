<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Subscription extends Model
{
    public const STATUS_PENDING  = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    // Where the request came in. Android and iOS are told apart because they
    // are two stores, two review queues and two audiences.
    public const SOURCE_WEBSITE = 'website';
    public const SOURCE_ANDROID = 'android';
    public const SOURCE_IOS     = 'ios';
    public const SOURCE_DESKTOP = 'desktop';  // the Windows (or other desktop) till build
    public const SOURCE_MOBILE  = 'mobile';   // an app build too old to say which
    public const SOURCE_ADMIN   = 'admin';    // created by hand in the platform admin

    protected $fillable = [
        'business_name', 'activity', 'currency', 'contact_name', 'email',
        'phone', 'desired_subdomain', 'heard_about', 'source', 'country', 'city', 'status',
        'rejection_reason', 'reviewed_by', 'reviewed_at', 'meta',
    ];

    protected $casts = [
        'meta'        => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function install(): HasOne
    {
        return $this->hasOne(TenantInstall::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Where the request came from.
     *
     * The column for anything created from now on. Requests that arrived
     * earlier still say enough to be read: the app tagged its own as
     * `mobile`, the admin tagged its own as `manual`, and the website said
     * nothing — but a browser announces itself as Mozilla, while the app's
     * HTTP client announces itself as Dart.
     */
    public function origin(): ?string
    {
        if ($this->source) {
            return $this->source;
        }

        $tagged = $this->meta['source'] ?? null;
        if ($tagged === 'manual') {
            return self::SOURCE_ADMIN;
        }
        if ($tagged === 'mobile') {
            return self::SOURCE_MOBILE;
        }

        $agent = (string) ($this->meta['user_agent'] ?? '');

        return match (true) {
            str_starts_with($agent, 'Dart/') => self::SOURCE_MOBILE,
            str_contains($agent, 'Mozilla/') => self::SOURCE_WEBSITE,
            default => null,
        };
    }

    public function originLabel(): string
    {
        return match ($this->origin()) {
            self::SOURCE_WEBSITE => 'Site web',
            self::SOURCE_ANDROID => 'App Android',
            self::SOURCE_IOS => 'App iOS',
            self::SOURCE_DESKTOP => 'App Windows',
            self::SOURCE_MOBILE => 'App mobile',
            self::SOURCE_ADMIN => 'Ajout manuel',
            default => 'Inconnue',
        };
    }

    public function countryName(): ?string
    {
        return \App\Support\Countries::name($this->country);
    }

    /** "Casablanca, Maroc" — whatever is known, or null. */
    public function place(): ?string
    {
        $parts = array_filter([$this->city, $this->countryName()]);

        return $parts === [] ? null : implode(', ', $parts);
    }
}
