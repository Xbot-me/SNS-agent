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
use Illuminate\Support\Facades\Cache;

/**
 * The comment threads on the channel's latest uploads. YouTube has no messages.
 * Uploads come from the uploads playlist (1 quota unit) rather than search
 * (100), and the channel is read every half hour to spare the daily quota.
 *
 * @see https://developers.google.com/youtube/v3/docs/commentThreads/list
 * @see https://developers.google.com/youtube/v3/docs/comments/insert
 */
class YouTubeInboxProvider extends AbstractInboxProvider
{
    private const string FORCE_SSL_SCOPE = 'https://www.googleapis.com/auth/youtube.force-ssl';

    public function syncIntervalMinutes(): int
    {
        return (int) config('trypost.inbox.youtube_sync_interval_minutes');
    }

    public function fetch(SocialAccount $account): InboxFetchResult
    {
        $channelId = (string) $account->platform_user_id;
        $uploads = $this->uploadsPlaylist($account, $channelId);

        if (blank($uploads)) {
            return new InboxFetchResult([]);
        }

        $videos = $this->ensureSuccessful($this->client($account)->get("{$this->api()}/playlistItems", [
            'part' => 'snippet',
            'playlistId' => $uploads,
            'maxResults' => (int) config('trypost.inbox.recent_posts'),
        ]), 'uploads');

        $threads = collect(data_get($videos->json(), 'items', []))
            ->flatMap(fn (array $video): array => $this->commentThreads(
                $account,
                $channelId,
                (string) data_get($video, 'snippet.resourceId.videoId'),
                data_get($video, 'snippet.title'),
            ))
            ->values()
            ->all();

        return new InboxFetchResult($threads);
    }

    public function reply(InboxConversation $conversation, SocialAccount $account, string $text): InboxEntry
    {
        if ($account->missingScope(self::FORCE_SSL_SCOPE) !== null) {
            throw InboxException::permission('the channel grant lacks youtube.force-ssl');
        }

        $response = $this->ensureSuccessful($this->client($account)->post("{$this->api()}/comments?part=snippet", [
            'snippet' => [
                'parentId' => $conversation->remote_id,
                'textOriginal' => $text,
            ],
        ]), 'comment reply');

        return $this->ownEntry(
            $account,
            data_get($response->json(), 'id'),
            $text,
            $this->time(data_get($response->json(), 'snippet.publishedAt')),
        );
    }

    /**
     * @return list<InboxThread>
     */
    private function commentThreads(SocialAccount $account, string $channelId, string $videoId, ?string $title): array
    {
        if (blank($videoId)) {
            return [];
        }

        $response = $this->client($account)->get("{$this->api()}/commentThreads", [
            'part' => 'snippet,replies',
            'videoId' => $videoId,
            'maxResults' => 20,
            'order' => 'time',
            'textFormat' => 'plainText',
        ]);

        // A video with comments turned off answers 403 commentsDisabled: nothing to read there.
        if ($response->status() === 403 && data_get($response->json(), 'error.errors.0.reason') === 'commentsDisabled') {
            return [];
        }

        $this->ensureSuccessful($response, 'comment threads');

        return collect(data_get($response->json(), 'items', []))
            ->map(function (array $thread) use ($channelId, $videoId, $title): InboxThread {
                $top = (array) data_get($thread, 'snippet.topLevelComment', []);
                $comments = collect([$top, ...array_reverse((array) data_get($thread, 'replies.comments', []))]);

                return new InboxThread(
                    kind: ConversationKind::Comment,
                    remoteId: (string) data_get($thread, 'id'),
                    entries: $comments
                        ->map(fn (array $comment): InboxEntry => new InboxEntry(
                            remoteId: (string) data_get($comment, 'id'),
                            body: data_get($comment, 'snippet.textOriginal') ?? data_get($comment, 'snippet.textDisplay'),
                            sentAt: $this->time(data_get($comment, 'snippet.publishedAt')),
                            isOwn: data_get($comment, 'snippet.authorChannelId.value') === $channelId,
                            authorName: data_get($comment, 'snippet.authorDisplayName'),
                            authorAvatarUrl: data_get($comment, 'snippet.authorProfileImageUrl'),
                        ))
                        ->values()
                        ->all(),
                    participantName: data_get($top, 'snippet.authorDisplayName'),
                    participantAvatarUrl: data_get($top, 'snippet.authorProfileImageUrl'),
                    subject: $title,
                    permalink: config('trypost.platforms.youtube.web_app')."/watch?v={$videoId}&lc=".data_get($thread, 'id'),
                    meta: ['video_id' => $videoId],
                );
            })
            ->values()
            ->all();
    }

    /**
     * The channel's uploads playlist never changes, so it is read once a day.
     */
    private function uploadsPlaylist(SocialAccount $account, string $channelId): ?string
    {
        return Cache::remember("inbox:youtube:uploads:{$account->id}", now()->addDay(), function () use ($account, $channelId): ?string {
            $response = $this->ensureSuccessful($this->client($account)->get("{$this->api()}/channels", [
                'part' => 'contentDetails',
                'id' => $channelId,
            ]), 'channel');

            return data_get($response->json(), 'items.0.contentDetails.relatedPlaylists.uploads');
        });
    }

    private function api(): string
    {
        return (string) config('trypost.platforms.youtube.data_api');
    }
}
