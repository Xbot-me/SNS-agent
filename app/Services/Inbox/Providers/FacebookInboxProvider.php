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
use Illuminate\Support\Str;

/**
 * A Facebook Page's Messenger conversations and the comments on its recent
 * posts, read with the Page token.
 *
 * @see https://developers.facebook.com/docs/messenger-platform/conversations
 * @see https://developers.facebook.com/docs/graph-api/reference/object/comments
 */
class FacebookInboxProvider extends AbstractInboxProvider
{
    public function fetch(SocialAccount $account): InboxFetchResult
    {
        $threads = [];
        $missing = [];

        if ($account->missingScope('pages_messaging') === null) {
            array_push($threads, ...$this->conversations($account));
        } else {
            $missing[] = ConversationKind::Message;
        }

        if ($account->missingScope('pages_read_user_content') === null) {
            array_push($threads, ...$this->comments($account));
        } else {
            $missing[] = ConversationKind::Comment;
        }

        return new InboxFetchResult($threads, $missing);
    }

    public function reply(InboxConversation $conversation, SocialAccount $account, string $text): InboxEntry
    {
        if ($conversation->kind === ConversationKind::Message) {
            $response = $this->ensureSuccessful($this->client($account)->post("{$this->graph()}/{$account->platform_user_id}/messages", [
                'recipient' => ['id' => data_get($conversation->meta, 'recipient_id')],
                'messaging_type' => 'RESPONSE',
                'message' => ['text' => $text],
            ]), 'message reply');

            return $this->ownEntry($account, (string) data_get($response->json(), 'message_id'), $text);
        }

        if ($account->missingScope('pages_manage_engagement') !== null) {
            throw InboxException::permission('the page grant lacks pages_manage_engagement');
        }

        $response = $this->ensureSuccessful(
            $this->client($account)->asForm()->post("{$this->graph()}/{$conversation->remote_id}/comments", ['message' => $text]),
            'comment reply',
        );

        return $this->ownEntry($account, (string) data_get($response->json(), 'id'), $text);
    }

    /**
     * @return list<InboxThread>
     */
    private function conversations(SocialAccount $account): array
    {
        $pageId = (string) $account->platform_user_id;

        $response = $this->ensureSuccessful($this->client($account)->get("{$this->graph()}/{$pageId}/conversations", [
            'fields' => 'id,updated_time,participants,messages.limit(20){id,message,created_time,from,attachments{image_data,video_data,file_url}}',
            'limit' => 25,
        ]), 'conversations');

        return collect(data_get($response->json(), 'data', []))
            ->map(function (array $conversation) use ($pageId): InboxThread {
                $participant = collect(data_get($conversation, 'participants.data', []))
                    ->first(fn (array $person): bool => (string) data_get($person, 'id') !== $pageId);

                $entries = collect(data_get($conversation, 'messages.data', []))
                    ->map(fn (array $message): InboxEntry => new InboxEntry(
                        remoteId: (string) data_get($message, 'id'),
                        body: data_get($message, 'message') ?: null,
                        sentAt: $this->time(data_get($message, 'created_time')),
                        isOwn: (string) data_get($message, 'from.id') === $pageId,
                        authorName: data_get($message, 'from.name'),
                        attachments: $this->metaAttachments(data_get($message, 'attachments.data', [])),
                    ))
                    ->values()
                    ->all();

                return new InboxThread(
                    kind: ConversationKind::Message,
                    remoteId: (string) data_get($conversation, 'id'),
                    entries: $entries,
                    participantName: data_get($participant, 'name'),
                    meta: ['recipient_id' => data_get($participant, 'id')],
                );
            })
            ->values()
            ->all();
    }

    /**
     * Every top-level comment on the page's recent posts is a thread of its own.
     *
     * @return list<InboxThread>
     */
    private function comments(SocialAccount $account): array
    {
        $pageId = (string) $account->platform_user_id;
        $commentFields = 'id,message,created_time,permalink_url,from{id,name,picture}';

        $response = $this->ensureSuccessful($this->client($account)->get("{$this->graph()}/{$pageId}/posts", [
            'fields' => "id,message,permalink_url,comments.filter(toplevel).order(reverse_chronological).limit(25){{$commentFields},comments.limit(25){{$commentFields}}}",
            'limit' => (int) config('trypost.inbox.recent_posts'),
        ]), 'comments');

        return collect(data_get($response->json(), 'data', []))
            ->flatMap(fn (array $post) => collect(data_get($post, 'comments.data', []))
                ->map(fn (array $comment): InboxThread => new InboxThread(
                    kind: ConversationKind::Comment,
                    remoteId: (string) data_get($comment, 'id'),
                    entries: collect([$comment, ...data_get($comment, 'comments.data', [])])
                        ->map(fn (array $item): InboxEntry => new InboxEntry(
                            remoteId: (string) data_get($item, 'id'),
                            body: data_get($item, 'message') ?: null,
                            sentAt: $this->time(data_get($item, 'created_time')),
                            isOwn: (string) data_get($item, 'from.id') === $pageId,
                            authorName: data_get($item, 'from.name'),
                            authorAvatarUrl: data_get($item, 'from.picture.data.url'),
                        ))
                        ->values()
                        ->all(),
                    participantName: data_get($comment, 'from.name'),
                    participantAvatarUrl: data_get($comment, 'from.picture.data.url'),
                    subject: transform(data_get($post, 'message'), fn (string $message): string => Str::limit($message, 140)),
                    permalink: data_get($comment, 'permalink_url') ?? data_get($post, 'permalink_url'),
                    meta: ['post_id' => data_get($post, 'id')],
                )))
            ->values()
            ->all();
    }

    private function graph(): string
    {
        return (string) config('trypost.platforms.facebook.graph_api');
    }
}
