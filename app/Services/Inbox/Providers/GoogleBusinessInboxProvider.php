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
 * The location's reviews. A review takes one reply from the owner: answering
 * again replaces it, which is why the reply is stored under a fixed id.
 *
 * @see https://developers.google.com/my-business/reference/rest/v4/accounts.locations.reviews
 */
class GoogleBusinessInboxProvider extends AbstractInboxProvider
{
    private const string REPLY_ID = 'owner-reply';

    private const array STARS = ['ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5];

    public function fetch(SocialAccount $account): InboxFetchResult
    {
        $location = (string) data_get($account->meta, 'location_id');

        if (blank($location)) {
            throw InboxException::unsupported('the location id is missing; reconnect the location');
        }

        $response = $this->ensureSuccessful($this->client($account)->get("{$this->api()}/{$location}/reviews", [
            'pageSize' => 50,
            'orderBy' => 'updateTime desc',
        ]), 'reviews');

        $threads = collect(data_get($response->json(), 'reviews', []))
            ->map(function (array $review) use ($account): InboxThread {
                $reviewer = data_get($review, 'reviewer.isAnonymous') ? null : data_get($review, 'reviewer.displayName');
                $entries = [new InboxEntry(
                    remoteId: (string) data_get($review, 'reviewId'),
                    body: data_get($review, 'comment'),
                    sentAt: $this->time(data_get($review, 'updateTime') ?? data_get($review, 'createTime')),
                    isOwn: false,
                    authorName: $reviewer,
                    authorAvatarUrl: data_get($review, 'reviewer.profilePhotoUrl'),
                )];

                if (filled(data_get($review, 'reviewReply.comment'))) {
                    $entries[] = $this->ownEntry(
                        $account,
                        self::REPLY_ID,
                        (string) data_get($review, 'reviewReply.comment'),
                        $this->time(data_get($review, 'reviewReply.updateTime')),
                    );
                }

                return new InboxThread(
                    kind: ConversationKind::Review,
                    remoteId: (string) data_get($review, 'reviewId'),
                    entries: $entries,
                    participantName: $reviewer,
                    participantAvatarUrl: data_get($review, 'reviewer.profilePhotoUrl'),
                    permalink: data_get($account->meta, 'maps_uri'),
                    meta: [
                        'review_name' => data_get($review, 'name'),
                        'rating' => self::STARS[(string) data_get($review, 'starRating')] ?? null,
                    ],
                );
            })
            ->values()
            ->all();

        return new InboxFetchResult($threads);
    }

    public function reply(InboxConversation $conversation, SocialAccount $account, string $text): InboxEntry
    {
        $response = $this->ensureSuccessful(
            $this->client($account)->put("{$this->api()}/".data_get($conversation->meta, 'review_name').'/reply', ['comment' => $text]),
            'review reply',
        );

        return $this->ownEntry($account, self::REPLY_ID, $text, $this->time(data_get($response->json(), 'updateTime')));
    }

    private function api(): string
    {
        return (string) config('trypost.platforms.google_business.local_posts_api');
    }
}
