<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Which app versions are current on one platform (android, ios). */
class AppUpdatePolicy extends Model
{
    public const PLATFORMS = ['android', 'ios'];

    protected $fillable = [
        'platform', 'latest_version', 'min_version', 'message',
        'apk_path', 'apk_url', 'apk_version', 'apk_size',
    ];

    public static function for(string $platform): self
    {
        return self::firstOrNew(['platform' => $platform]);
    }
}
