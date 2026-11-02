<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A phone's logs, sent to support with « Envoyer les journaux ». */
class DeviceReport extends Model
{
    protected $fillable = [
        'reference', 'tenant_install_id', 'support_conversation_id', 'user_name',
        'platform', 'device', 'app_version', 'note', 'file_path', 'size',
    ];

    protected $hidden = ['file_path'];

    public function install(): BelongsTo
    {
        return $this->belongsTo(TenantInstall::class, 'tenant_install_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(SupportConversation::class, 'support_conversation_id');
    }
}
