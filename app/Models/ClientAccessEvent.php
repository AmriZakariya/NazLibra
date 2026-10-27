<?php

namespace App\Models;

use App\Support\DeviceLabel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Someone entered a client's verification code (see the migration). Read by
 * the platform admin: has this client ever reached their space, from what,
 * when — and is someone guessing codes?
 */
class ClientAccessEvent extends Model
{
    public const OUTCOME_OK      = 'ok';
    public const OUTCOME_INVALID = 'invalid';
    public const OUTCOME_BLOCKED = 'blocked';
    public const OUTCOME_UNKNOWN = 'unknown';

    public const CHANNEL_APP = 'app';
    public const CHANNEL_WEB = 'web';

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_install_id', 'subdomain', 'outcome', 'channel', 'platform', 'os',
        'app_version', 'device_id', 'device_label', 'ip', 'user_agent', 'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function install(): BelongsTo
    {
        return $this->belongsTo(TenantInstall::class, 'tenant_install_id');
    }

    /**
     * Records one check of the code at POST /api/v1/public/client-access.
     *
     * The app calls it itself and says what it runs on. The web gate calls it
     * from the client's server, so it passes on the visitor's browser and
     * address — without that every web entry would read as the server.
     * Self-reported, so good for monitoring, not as proof.
     */
    public static function record(Request $request, ?TenantInstall $install, string $subdomain, string $outcome): ?self
    {
        try {
            $channel = $request->input('channel') === self::CHANNEL_WEB ? self::CHANNEL_WEB : self::CHANNEL_APP;
            $clean = fn ($v, int $max) => ($v = trim(Str::limit(strip_tags((string) $v), $max, ''))) === '' ? null : $v;

            $userAgent = $channel === self::CHANNEL_WEB
                ? $clean($request->input('client_user_agent'), 255)
                : $clean($request->userAgent(), 255);
            $ip = $channel === self::CHANNEL_WEB
                ? (filter_var($request->input('client_ip'), FILTER_VALIDATE_IP) ?: null)
                : $request->ip();

            $platform = $clean($request->input('platform'), 20);
            $os = $clean($request->input('os'), 80);
            $version = $clean($request->input('app_version'), 20);

            return self::create([
                'tenant_install_id' => $install?->id,
                'subdomain'         => Str::limit($subdomain, 63, ''),
                'outcome'           => $outcome,
                'channel'           => $channel,
                'platform'          => $platform ?? ($channel === self::CHANNEL_WEB ? 'web' : null),
                'os'                => $os,
                'app_version'       => $version,
                'device_id'         => $clean($request->input('device_id'), 64),
                'device_label'      => DeviceLabel::describe($channel, $platform, $os, $version, $userAgent),
                'ip'                => $ip,
                'user_agent'        => $userAgent,
                'created_at'        => now(),
            ]);
        } catch (\Throwable $e) {
            // The journal must never cost a client their way in.
            report($e);

            return null;
        }
    }
}
