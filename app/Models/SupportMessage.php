<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A message in a support conversation, with at most one attachment. */
class SupportMessage extends Model
{
    protected $fillable = [
        'support_conversation_id', 'sender', 'author_name', 'body',
        'attachment_path', 'attachment_name', 'attachment_mime', 'attachment_size',
        'attachment_kind', 'duration_seconds', 'read_at',
    ];

    protected $hidden = ['attachment_path'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime', 'attachment_size' => 'integer', 'duration_seconds' => 'integer'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(SupportConversation::class, 'support_conversation_id');
    }
}
