<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Inbox\ConversationKind;
use App\Enums\SocialAccount\Platform;
use Database\Factories\InboxConversationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One thread of the unified inbox: a DM conversation, a top-level comment with
 * its replies, a mention, or a review. It is unread while someone else wrote
 * after the last time it was opened (`last_inbound_at` after `read_at`).
 */
class InboxConversation extends Model
{
    /** @use HasFactory<InboxConversationFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'workspace_id',
        'social_account_id',
        'platform',
        'kind',
        'remote_id',
        'participant_name',
        'participant_username',
        'participant_avatar_url',
        'subject',
        'permalink',
        'last_message_excerpt',
        'last_message_at',
        'last_inbound_at',
        'read_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'kind' => ConversationKind::class,
            'last_message_at' => 'datetime',
            'last_inbound_at' => 'datetime',
            'read_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(InboxMessage::class);
    }

    public function isUnread(): bool
    {
        return $this->last_inbound_at !== null
            && ($this->read_at === null || $this->last_inbound_at->greaterThan($this->read_at));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNotNull('last_inbound_at')
            ->where(fn (Builder $query) => $query->whereNull('read_at')->orWhereColumn('last_inbound_at', '>', 'read_at'));
    }
}
