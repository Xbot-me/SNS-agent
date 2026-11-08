<?php

declare(strict_types=1);

namespace App\Services\Inbox\Providers;

use App\Dto\Inbox\InboxEntry;
use App\Dto\Inbox\InboxFetchResult;
use App\Dto\Inbox\InboxThread;
use App\Enums\Inbox\ConversationKind;
use App\Exceptions\Inbox\InboxException;
use App\Models\InboxConversation;
use App\Models\SocialAccount;

/**
 * Mentions from the account's notifications. A mention with `direct`
 * visibility is Mastodon's private message, so it lands with the messages;
 * a reply keeps the visibility of what it answers.
 *
 * @see https://docs.joinmastodon.org/methods/notifications/
 */
class MastodonInboxProvider extends AbstractInboxProvider
{
    public function fetch(SocialAccount $account): InboxFetchResult
    {
        if ($account->missingScope(['read', 'read:notifications']) !== null) {
            return new InboxFetchResult([], [ConversationKind::Message, ConversationKind::Mention]);
        }

        // The type filter is a Rails array (`types[]=`), which a query array would encode as `types[0]=`.
        $response = $this->ensureSuccessful(
            $this->client($account)->get("{$account->mastodonInstance()}/api/v1/notifications?types[]=mention&limit=40"),
            'notifications',
        );

        $threads = collect($response->json() ?? [])
            ->filter(fn (array $notification): bool => filled(data_get($notification, 'status.id')))
            ->map(function (array $notification): InboxThread {
                $status = (array) data_get($notification, 'status');
                $author = (array) data_get($notification, 'account', data_get($status, 'account', []));
                $visibility = (string) data_get($status, 'visibility', 'public');

                return new InboxThread(
                    kind: $visibility === 'direct' ? ConversationKind::Message : ConversationKind::Mention,
                    remoteId: (string) data_get($status, 'id'),
                    entries: [new InboxEntry(
                        remoteId: (string) data_get($status, 'id'),
                        body: $this->plainText(data_get($status, 'content')),
                        sentAt: $this->time(data_get($status, 'created_at')),
                        isOwn: false,
                        authorName: data_get($author, 'display_name') ?: data_get($author, 'acct'),
                        authorUsername: data_get($author, 'acct'),
                        authorAvatarUrl: data_get($author, 'avatar'),
                        attachments: $this->attachments((array) data_get($status, 'media_attachments', [])),
                    )],
                    participantName: data_get($author, 'display_name') ?: data_get($author, 'acct'),
                    participantUsername: data_get($author, 'acct'),
                    participantAvatarUrl: data_get($author, 'avatar'),
                    permalink: data_get($status, 'url'),
                    meta: [
                        'status_id' => data_get($status, 'id'),
                        'acct' => data_get($author, 'acct'),
                        'visibility' => $visibility,
                    ],
                );
            })
            ->values()
            ->all();

        return new InboxFetchResult($threads);
    }

    public function reply(InboxConversation $conversation, SocialAccount $account, string $text): InboxEntry
    {
        $acct = (string) data_get($conversation->meta, 'acct');
        $status = filled($acct) && ! str_contains($text, "@{$acct}") ? "@{$acct} {$text}" : $text;

        $response = $this->client($account)->asForm()->post("{$account->mastodonInstance()}/api/v1/statuses", [
            'status' => $status,
            'in_reply_to_id' => data_get($conversation->meta, 'status_id', $conversation->remote_id),
            'visibility' => data_get($conversation->meta, 'visibility', 'public'),
        ]);

        if ($response->status() === 403) {
            throw InboxException::permission('the token may not write statuses');
        }

        $this->ensureSuccessful($response, 'reply');

        return $this->ownEntry($account, data_get($response->json(), 'id'), $status, $this->time(data_get($response->json(), 'created_at')));
    }

    /**
     * @param  array<int, array<string, mixed>>  $attachments
     * @return list<array{type: string, url: string}>|null
     */
    private function attachments(array $attachments): ?array
    {
        $items = collect($attachments)
            ->filter(fn (array $attachment): bool => filled(data_get($attachment, 'url')))
            ->map(fn (array $attachment): array => [
                'type' => in_array(data_get($attachment, 'type'), ['video', 'gifv'], true) ? 'video' : (data_get($attachment, 'type') === 'image' ? 'image' : 'file'),
                'url' => (string) data_get($attachment, 'url'),
            ])
            ->values()
            ->all();

        return filled($items) ? $items : null;
    }
}
