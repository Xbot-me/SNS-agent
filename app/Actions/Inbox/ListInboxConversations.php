<?php

declare(strict_types=1);

namespace App\Actions\Inbox;

use App\Enums\Inbox\ConversationKind;
use App\Models\InboxConversation;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;

/**
 * The workspace's inbox, newest activity first, narrowed by kind, channel,
 * unread state and a search over the person and the last message.
 */
class ListInboxConversations
{
    /**
     * @param  array{kind?: ?string, channel?: ?string, unread?: mixed, search?: ?string}  $filters
     * @return Builder<InboxConversation>
     */
    public static function execute(Workspace $workspace, array $filters = []): Builder
    {
        return InboxConversation::query()
            ->where('workspace_id', $workspace->id)
            ->whereHas('socialAccount')
            ->with('socialAccount:id,platform,display_name,username,avatar_url')
            ->when(data_get($filters, 'kind'), fn (Builder $query, string $kind) => $query->where('kind', ConversationKind::from($kind)))
            ->when(data_get($filters, 'channel'), fn (Builder $query, string $channel) => $query->where('social_account_id', $channel))
            ->when(filter_var(data_get($filters, 'unread'), FILTER_VALIDATE_BOOLEAN), fn (Builder $query) => $query->unread())
            ->when(data_get($filters, 'search'), fn (Builder $query, string $search) => $query->where(fn (Builder $query) => $query
                ->whereLike('participant_name', "%{$search}%")
                ->orWhereLike('participant_username', "%{$search}%")
                ->orWhereLike('last_message_excerpt', "%{$search}%")
                ->orWhereLike('subject', "%{$search}%")))
            ->orderByDesc('last_message_at')
            ->orderByDesc('id');
    }
}
