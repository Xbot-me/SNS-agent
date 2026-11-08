<?php

declare(strict_types=1);

namespace App\Actions\Inbox;

use App\Dto\Inbox\InboxEntry;
use App\Dto\Inbox\InboxThread;
use App\Exceptions\Inbox\InboxException;
use App\Models\InboxConversation;
use App\Models\SocialAccount;
use App\Services\Inbox\InboxProviderFactory;
use App\Support\Inbox\InboxSyncStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Reads one channel's inbox and stores what is new. Messages are only added or
 * updated, never removed, so a reply sent from here stays even when the
 * network's next read no longer lists it.
 */
class SyncAccountInbox
{
    /**
     * A conversation first seen with nothing newer than this is history, not
     * something to answer: it starts read.
     */
    private const int STALE_AFTER_DAYS = 7;

    private const array UPDATED_COLUMNS = ['body', 'author_name', 'author_username', 'author_avatar_url', 'attachments', 'sent_at', 'is_own', 'updated_at'];

    public static function execute(SocialAccount $account): void
    {
        try {
            $result = app(InboxProviderFactory::class)->for($account->platform)->fetch($account);
        } catch (InboxException $exception) {
            InboxSyncStatus::record($account, error: $exception->category);

            return;
        }

        foreach ($result->threads as $thread) {
            self::store($account, $thread);
        }

        InboxSyncStatus::record($account, $result->missing);
    }

    public static function store(SocialAccount $account, InboxThread $thread): InboxConversation
    {
        $conversation = InboxConversation::query()->firstOrNew([
            'social_account_id' => $account->id,
            'kind' => $thread->kind,
            'remote_id' => $thread->remoteId,
        ]);

        $conversation->fill(array_filter([
            'workspace_id' => $account->workspace_id,
            'platform' => $account->platform,
            'participant_name' => $thread->participantName,
            'participant_username' => $thread->participantUsername,
            'participant_avatar_url' => $thread->participantAvatarUrl,
            'subject' => $thread->subject,
            'permalink' => $thread->permalink,
        ], filled(...)));
        $conversation->meta = [...($conversation->meta ?? []), ...$thread->meta];
        $conversation->save();

        self::storeEntries($conversation, $thread->entries);

        return self::refresh($conversation);
    }

    /**
     * @param  list<InboxEntry>  $entries
     */
    public static function storeEntries(InboxConversation $conversation, array $entries): void
    {
        $now = now();

        $rows = collect($entries)
            ->filter(fn (InboxEntry $entry): bool => filled($entry->remoteId))
            ->unique(fn (InboxEntry $entry): string => $entry->remoteId)
            ->map(fn (InboxEntry $entry): array => [
                'id' => (string) Str::uuid7(),
                'inbox_conversation_id' => $conversation->id,
                'remote_id' => $entry->remoteId,
                'author_name' => $entry->authorName,
                'author_username' => $entry->authorUsername,
                'author_avatar_url' => $entry->authorAvatarUrl,
                'is_own' => $entry->isOwn,
                'body' => $entry->body,
                'attachments' => $entry->attachments === null ? null : json_encode($entry->attachments),
                'sent_at' => $entry->sentAt->toDateTimeString(),
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->values()
            ->all();

        if (filled($rows)) {
            $conversation->messages()->upsert($rows, ['inbox_conversation_id', 'remote_id'], self::UPDATED_COLUMNS);
        }
    }

    /**
     * Recomputes the list columns from the stored messages. Answering from the
     * network's own app counts as reading the conversation.
     */
    public static function refresh(InboxConversation $conversation): InboxConversation
    {
        $latest = $conversation->messages()->latest('sent_at')->first();
        $lastInbound = $conversation->messages()->where('is_own', false)->max('sent_at');
        $lastOwn = $conversation->messages()->where('is_own', true)->max('sent_at');

        $conversation->last_message_at = $latest?->sent_at;
        $conversation->last_message_excerpt = transform($latest?->body, fn (string $body): string => Str::limit(Str::squish($body), 160));
        $conversation->last_inbound_at = $lastInbound;

        $readAt = collect([
            $conversation->read_at,
            $lastOwn,
            $conversation->wasRecentlyCreated && $conversation->last_inbound_at?->lessThan(now()->subDays(self::STALE_AFTER_DAYS))
                ? $conversation->last_inbound_at
                : null,
        ])->filter()->map(fn (mixed $value): CarbonImmutable => CarbonImmutable::parse($value))->max();

        $conversation->read_at = $readAt;
        $conversation->save();

        return $conversation;
    }
}
