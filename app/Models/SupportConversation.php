<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One thread between a shop (or a visitor) and the support team. */
class SupportConversation extends Model
{
    protected $fillable = [
        'public_id', 'token_hash', 'tenant_install_id', 'name', 'phone', 'email',
        'user_name', 'channel', 'platform', 'app_version', 'locale', 'status',
        'last_message_at', 'last_message_preview',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }

    public function install(): BelongsTo
    {
        return $this->belongsTo(TenantInstall::class, 'tenant_install_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class);
    }

    /** Messages the other side has not read yet, for [$reader] ("client" or "support"). */
    public function unreadFor(string $reader): int
    {
        return $this->messages()
            ->where('sender', $reader === 'support' ? 'client' : 'support')
            ->whereNull('read_at')
            ->count();
    }
}
