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
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The replies to the profile's recent threads. Threads has no messaging API,
 * so its inbox holds comments only: each top-level reply is a thread, with the
 * replies under it (read flat through `/conversation`, grouped by ancestor).
 *
 * @see https://developers.facebook.com/docs/threads/reply-management
 */
class ThreadsInboxProvider extends AbstractInboxProvider
{
    public function fetch(SocialAccount $account): InboxFetchResult
    {
        if ($account->missingScope('threads_read_replies') !== null) {
            return new InboxFetchResult([], [ConversationKind::Comment]);
        }

        $posts = $this->ensureSuccessful($this->client($account)->get("{$this->graph()}/me/threads", [
            'fields' => 'id,text,permalink',
            'limit' => (int) config('trypost.inbox.recent_posts'),
        ]), 'threads');

        $threads = collect(data_get($posts->json(), 'data', []))
            ->flatMap(fn (array $post): array => $this->repliesTo($account, $post))
            ->values()
            ->all();

        return new InboxFetchResult($threads);
    }

    public function reply(InboxConversation $conversation, SocialAccount $account, string $text): InboxEntry
    {
        if ($account->missingScope('threads_manage_replies') !== null) {
            throw InboxException::permission('the profile grant lacks threads_manage_replies');
        }

        $container = $this->ensureSuccessful($this->client($account)->asForm()->post("{$this->graph()}/me/threads", [
            'media_type' => 'TEXT',
            'text' => $text,
            'reply_to_id' => $conversation->remote_id,
        ]), 'reply container');

        $published = $this->ensureSuccessful($this->client($account)->asForm()->post("{$this->graph()}/me/threads_publish", [
            'creation_id' => data_get($container->json(), 'id'),
        ]), 'reply publish');

        return $this->ownEntry($account, data_get($published->json(), 'id'), $text);
    }

    /**
     * @param  array<string, mixed>  $post
     * @return list<InboxThread>
     */
    private function repliesTo(SocialAccount $account, array $post): array
    {
        $postId = (string) data_get($post, 'id');

        $response = $this->ensureSuccessful($this->client($account)->get("{$this->graph()}/{$postId}/conversation", [
            'fields' => 'id,text,username,timestamp,permalink,replied_to,is_reply_owned_by_me',
            'reverse' => 'false',
            'limit' => 100,
        ]), 'replies');

        $replies = collect(data_get($response->json(), 'data', []))->keyBy('id');

        return $replies
            ->groupBy(fn (array $reply): string => $this->topLevelId($replies, $reply, $postId))
            ->map(function (Collection $group, string $topLevelId) use ($account, $post, $replies): ?InboxThread {
                $top = $replies->get($topLevelId);

                if ($top === null) {
                    return null;
                }

                return new InboxThread(
                    kind: ConversationKind::Comment,
                    remoteId: $topLevelId,
                    entries: $group
                        ->map(fn (array $reply): InboxEntry => new InboxEntry(
                            remoteId: (string) data_get($reply, 'id'),
                            body: data_get($reply, 'text') ?: null,
                            sentAt: $this->time(data_get($reply, 'timestamp')),
                            isOwn: (bool) data_get($reply, 'is_reply_owned_by_me')
                                || Str::lower((string) data_get($reply, 'username')) === Str::lower((string) $account->username),
                            authorName: data_get($reply, 'username'),
                            authorUsername: data_get($reply, 'username'),
                        ))
                        ->values()
                        ->all(),
                    participantName: data_get($top, 'username'),
                    participantUsername: data_get($top, 'username'),
                    subject: transform(data_get($post, 'text'), fn (string $text): string => Str::limit($text, 140)),
                    permalink: data_get($top, 'permalink') ?? data_get($post, 'permalink'),
                    meta: ['post_id' => data_get($post, 'id')],
                );
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * The direct reply to the post that this reply sits under.
     *
     * @param  Collection<string, array<string, mixed>>  $replies
     * @param  array<string, mixed>  $reply
     */
    private function topLevelId(Collection $replies, array $reply, string $postId): string
    {
        $current = $reply;

        for ($depth = 0; $depth < 50; $depth++) {
            $parentId = (string) data_get($current, 'replied_to.id');

            if ($parentId === $postId || blank($parentId) || ! $replies->has($parentId)) {
                return (string) data_get($current, 'id');
            }

            $current = $replies->get($parentId);
        }

        return (string) data_get($current, 'id');
    }

    private function graph(): string
    {
        return (string) config('trypost.platforms.threads.graph_api');
    }
}
