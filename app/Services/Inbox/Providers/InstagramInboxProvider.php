<?php

declare(strict_types=1);

namespace App\Services\Inbox\Providers;

use App\Dto\Inbox\InboxEntry;
use App\Dto\Inbox\InboxFetchResult;
use App\Dto\Inbox\InboxThread;
use App\Enums\Inbox\ConversationKind;
use App\Enums\SocialAccount\Platform;
use App\Models\InboxConversation;
use App\Models\SocialAccount;
use Illuminate\Support\Str;

/**
 * An Instagram professional account's direct messages and the comments on its
 * recent media, for both ways to connect it: Instagram Login (graph.instagram.com,
 * `instagram_business_*` scopes) and a Facebook Page (graph.facebook.com, the
 * Page's conversations with `platform=instagram`).
 *
 * @see https://developers.facebook.com/docs/instagram-platform/instagram-api-with-instagram-login/messaging-api
 * @see https://developers.facebook.com/docs/instagram-platform/instagram-graph-api/reference/ig-media/comments
 */
class InstagramInboxProvider extends AbstractInboxProvider
{
    public function fetch(SocialAccount $account): InboxFetchResult
    {
        $threads = [];
        $missing = [];

        if ($account->missingScope($this->scope($account, 'messages')) === null) {
            array_push($threads, ...$this->conversations($account));
        } else {
            $missing[] = ConversationKind::Message;
        }

        if ($account->missingScope($this->scope($account, 'comments')) === null) {
            array_push($threads, ...$this->comments($account));
        } else {
            $missing[] = ConversationKind::Comment;
        }

        return new InboxFetchResult($threads, $missing);
    }

    public function reply(InboxConversation $conversation, SocialAccount $account, string $text): InboxEntry
    {
        $base = $account->platform->instagramGraphBaseUrl();

        if ($conversation->kind === ConversationKind::Message) {
            $response = $this->ensureSuccessful($this->client($account)->post("{$base}/{$this->messagingNode($account)}/messages", [
                'recipient' => ['id' => data_get($conversation->meta, 'recipient_id')],
                'message' => ['text' => $text],
            ]), 'message reply');

            return $this->ownEntry($account, data_get($response->json(), 'message_id'), $text);
        }

        $response = $this->ensureSuccessful(
            $this->client($account)->asForm()->post("{$base}/{$conversation->remote_id}/replies", ['message' => $text]),
            'comment reply',
        );

        return $this->ownEntry($account, data_get($response->json(), 'id'), $text);
    }

    /**
     * @return list<InboxThread>
     */
    private function conversations(SocialAccount $account): array
    {
        $base = $account->platform->instagramGraphBaseUrl();

        $response = $this->ensureSuccessful($this->client($account)->get("{$base}/{$this->messagingNode($account)}/conversations", [
            'platform' => 'instagram',
            'fields' => 'id,updated_time,participants,messages.limit(20){id,message,created_time,from,attachments}',
            'limit' => 25,
        ]), 'conversations');

        return collect(data_get($response->json(), 'data', []))
            ->map(function (array $conversation) use ($account): InboxThread {
                $participant = collect(data_get($conversation, 'participants.data', []))
                    ->first(fn (array $person): bool => ! $this->isOwn($account, $person));

                $entries = collect(data_get($conversation, 'messages.data', []))
                    ->map(fn (array $message): InboxEntry => new InboxEntry(
                        remoteId: (string) data_get($message, 'id'),
                        body: data_get($message, 'message') ?: null,
                        sentAt: $this->time(data_get($message, 'created_time')),
                        isOwn: $this->isOwn($account, (array) data_get($message, 'from', [])),
                        authorUsername: data_get($message, 'from.username'),
                        attachments: $this->metaAttachments(data_get($message, 'attachments.data', [])),
                    ))
                    ->values()
                    ->all();

                return new InboxThread(
                    kind: ConversationKind::Message,
                    remoteId: (string) data_get($conversation, 'id'),
                    entries: $entries,
                    participantName: data_get($participant, 'username'),
                    participantUsername: data_get($participant, 'username'),
                    permalink: transform(data_get($participant, 'username'), fn (string $username): string => config('trypost.platforms.instagram.dm_link')."/{$username}"),
                    meta: ['recipient_id' => data_get($participant, 'id')],
                );
            })
            ->values()
            ->all();
    }

    /**
     * Every top-level comment on the account's recent media is a thread of its own.
     *
     * @return list<InboxThread>
     */
    private function comments(SocialAccount $account): array
    {
        $base = $account->platform->instagramGraphBaseUrl();
        $commentFields = 'id,text,timestamp,username,from';

        $response = $this->ensureSuccessful($this->client($account)->get("{$base}/{$account->platform_user_id}/media", [
            'fields' => "id,caption,permalink,comments.limit(25){{$commentFields},replies{{$commentFields}}}",
            'limit' => (int) config('trypost.inbox.recent_posts'),
        ]), 'comments');

        return collect(data_get($response->json(), 'data', []))
            ->flatMap(fn (array $media) => collect(data_get($media, 'comments.data', []))
                ->map(fn (array $comment): InboxThread => new InboxThread(
                    kind: ConversationKind::Comment,
                    remoteId: (string) data_get($comment, 'id'),
                    entries: collect([$comment, ...data_get($comment, 'replies.data', [])])
                        ->map(fn (array $item): InboxEntry => new InboxEntry(
                            remoteId: (string) data_get($item, 'id'),
                            body: data_get($item, 'text') ?: null,
                            sentAt: $this->time(data_get($item, 'timestamp')),
                            isOwn: $this->isOwn($account, ['id' => data_get($item, 'from.id'), 'username' => data_get($item, 'username')]),
                            authorName: data_get($item, 'username'),
                            authorUsername: data_get($item, 'username'),
                        ))
                        ->values()
                        ->all(),
                    participantName: data_get($comment, 'username'),
                    participantUsername: data_get($comment, 'username'),
                    subject: transform(data_get($media, 'caption'), fn (string $caption): string => Str::limit($caption, 140)),
                    permalink: data_get($media, 'permalink'),
                    meta: ['media_id' => data_get($media, 'id')],
                )))
            ->values()
            ->all();
    }

    /**
     * Instagram Login reads messages as the account itself; a Facebook-connected
     * account reads them through its Page.
     */
    private function messagingNode(SocialAccount $account): string
    {
        return $account->platform === Platform::InstagramFacebook
            ? (string) data_get($account->meta, 'page_id')
            : 'me';
    }

    /**
     * @param  array<string, mixed>  $person
     */
    private function isOwn(SocialAccount $account, array $person): bool
    {
        $ownIds = array_filter([
            (string) $account->platform_user_id,
            (string) data_get($account->meta, 'ig_user_id'),
            (string) data_get($account->meta, 'page_id'),
        ]);

        return in_array((string) data_get($person, 'id'), $ownIds, true)
            || (filled($account->username) && Str::lower((string) data_get($person, 'username')) === Str::lower((string) $account->username));
    }

    private function scope(SocialAccount $account, string $capability): string
    {
        return $account->platform === Platform::InstagramFacebook
            ? "instagram_manage_{$capability}"
            : "instagram_business_manage_{$capability}";
    }
}
