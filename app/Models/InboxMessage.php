<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\InboxMessageFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message of an inbox conversation, as the network reported it. `is_own`
 * marks what the connected channel itself wrote (a reply sent from here or
 * from the network's own app).
 */
class InboxMessage extends Model
{
    /** @use HasFactory<InboxMessageFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'inbox_conversation_id',
        'remote_id',
        'author_name',
        'author_username',
        'author_avatar_url',
        'is_own',
        'body',
        'attachments',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'is_own' => 'boolean',
            'attachments' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(InboxConversation::class, 'inbox_conversation_id');
    }
}
