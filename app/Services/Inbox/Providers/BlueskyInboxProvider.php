<?php

declare(strict_types=1);

namespace App\Services\Inbox\Providers;

use App\Dto\Inbox\InboxEntry;
use App\Dto\Inbox\InboxFetchResult;
use App\Dto\Inbox\InboxThread;
use App\Enums\Inbox\ConversationKind;
use App\Models\InboxConversation;
use App\Models\SocialAccount;
use App\Services\Social\BlueskyLexicon;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Str;

/**
 * Replies, mentions and quotes from the account's notifications, plus its
 * chats. Chats go through the PDS to the chat service (`atproto-proxy`), and
 * only work when the app password was created with direct-message access;
 * without it the chats are reported missing and the mentions still load.
 *
 * @see https://docs.bsky.app/docs/api/app-bsky-notification-list-notifications
 * @see https://docs.bsky.app/docs/api/chat-bsky-convo-list-convos
 */
class BlueskyInboxProvider extends AbstractInboxProvider
{
    private const array MENTION_REASONS = ['reply', 'mention', 'quote'];

    private const int CHATS_READ = 10;

    public function fetch(SocialAccount $account): InboxFetchResult
    {
        $threads = $this->mentions($account);
        $chats = $this->chats($account);

        return new InboxFetchResult(
            [...$threads, ...($chats ?? [])],
            $chats === null ? [ConversationKind::Message] : [],
        );
    }

    public function reply(InboxConversation $conversation, SocialAccount $account, string $text): InboxEntry
    {
        if ($conversation->kind === ConversationKind::Message) {
            $response = $this->ensureSuccessful($this->chat($account)->post($this->xrpc($account, BlueskyLexicon::CHAT_SEND_MESSAGE), [
                'convoId' => $conversation->remote_id,
                'message' => ['text' => $text],
            ]), 'chat reply');

            return $this->ownEntry($account, data_get($response->json(), 'id'), $text, $this->time(data_get($response->json(), 'sentAt')));
        }

        $parent = ['uri' => (string) data_get($conversation->meta, 'uri'), 'cid' => (string) data_get($conversation->meta, 'cid')];

        $response = $this->ensureSuccessful($this->client($account)->post($this->xrpc($account, BlueskyLexicon::CREATE_RECORD), [
            'repo' => $account->platform_user_id,
            'collection' => BlueskyLexicon::FEED_POST,
            'record' => [
                '$type' => BlueskyLexicon::FEED_POST,
                'text' => $text,
                'createdAt' => now()->toIso8601ZuluString(),
                'reply' => [
                    'root' => (array) (data_get($conversation->meta, 'root') ?: $parent),
                    'parent' => $parent,
                ],
            ],
        ]), 'reply');

        return $this->ownEntry($account, data_get($response->json(), 'uri'), $text);
    }

    /**
     * Each reply, mention or quote is a thread of its own, answered in place.
     *
     * @return list<InboxThread>
     */
    private function mentions(SocialAccount $account): array
    {
        $response = $this->ensureSuccessful(
            $this->client($account)->get($this->xrpc($account, BlueskyLexicon::LIST_NOTIFICATIONS), ['limit' => 50]),
            'notifications',
        );

        return collect(data_get($response->json(), 'notifications', []))
            ->filter(fn (array $notification): bool => in_array(data_get($notification, 'reason'), self::MENTION_REASONS, true))
            ->map(fn (array $notification): InboxThread => new InboxThread(
                kind: ConversationKind::Mention,
                remoteId: (string) data_get($notification, 'uri'),
                entries: [new InboxEntry(
                    remoteId: (string) data_get($notification, 'uri'),
                    body: data_get($notification, 'record.text'),
                    sentAt: $this->time(data_get($notification, 'record.createdAt') ?? data_get($notification, 'indexedAt')),
                    isOwn: false,
                    authorName: data_get($notification, 'author.displayName') ?: data_get($notification, 'author.handle'),
                    authorUsername: data_get($notification, 'author.handle'),
                    authorAvatarUrl: data_get($notification, 'author.avatar'),
                )],
                participantName: data_get($notification, 'author.displayName') ?: data_get($notification, 'author.handle'),
                participantUsername: data_get($notification, 'author.handle'),
                participantAvatarUrl: data_get($notification, 'author.avatar'),
                permalink: config('trypost.platforms.bluesky.web_app').'/profile/'.data_get($notification, 'author.handle').'/post/'.Str::afterLast((string) data_get($notification, 'uri'), '/'),
                meta: [
                    'reason' => data_get($notification, 'reason'),
                    'uri' => data_get($notification, 'uri'),
                    'cid' => data_get($notification, 'cid'),
                    'root' => data_get($notification, 'record.reply.root'),
                ],
            ))
            ->values()
            ->all();
    }

    /**
     * The latest chats, or null when the app password may not read them.
     *
     * @return list<InboxThread>|null
     */
    private function chats(SocialAccount $account): ?array
    {
        $response = $this->chat($account)->get($this->xrpc($account, BlueskyLexicon::CHAT_LIST_CONVOS), ['limit' => self::CHATS_READ]);

        if (in_array($response->status(), [400, 401, 403], true)) {
            return null;
        }

        $this->ensureSuccessful($response, 'chats');

        return collect(data_get($response->json(), 'convos', []))
            ->map(function (array $convo) use ($account): InboxThread {
                $members = collect(data_get($convo, 'members', []))->keyBy('did');
                $other = $members->first(fn (array $member): bool => data_get($member, 'did') !== $account->platform_user_id);

                $messages = $this->ensureSuccessful($this->chat($account)->get($this->xrpc($account, BlueskyLexicon::CHAT_GET_MESSAGES), [
                    'convoId' => data_get($convo, 'id'),
                    'limit' => 20,
                ]), 'chat messages');

                return new InboxThread(
                    kind: ConversationKind::Message,
                    remoteId: (string) data_get($convo, 'id'),
                    entries: collect(data_get($messages->json(), 'messages', []))
                        ->filter(fn (array $message): bool => filled(data_get($message, 'id')) && filled(data_get($message, 'sentAt')))
                        ->map(function (array $message) use ($account, $members): InboxEntry {
                            $sender = $members->get(data_get($message, 'sender.did'));

                            return new InboxEntry(
                                remoteId: (string) data_get($message, 'id'),
                                body: data_get($message, 'text'),
                                sentAt: $this->time(data_get($message, 'sentAt')),
                                isOwn: data_get($message, 'sender.did') === $account->platform_user_id,
                                authorName: data_get($sender, 'displayName') ?: data_get($sender, 'handle'),
                                authorUsername: data_get($sender, 'handle'),
                                authorAvatarUrl: data_get($sender, 'avatar'),
                            );
                        })
                        ->values()
                        ->all(),
                    participantName: data_get($other, 'displayName') ?: data_get($other, 'handle'),
                    participantUsername: data_get($other, 'handle'),
                    participantAvatarUrl: data_get($other, 'avatar'),
                );
            })
            ->values()
            ->all();
    }

    private function chat(SocialAccount $account): PendingRequest
    {
        return $this->client($account)->withHeaders([
            'atproto-proxy' => (string) config('trypost.platforms.bluesky.chat_service'),
        ]);
    }

    private function xrpc(SocialAccount $account, string $method): string
    {
        $service = rtrim((string) data_get($account->meta, 'service', config('trypost.platforms.bluesky.default_service')), '/');

        return "{$service}/xrpc/{$method}";
    }
}
