<?php

declare(strict_types=1);

namespace App\Actions\Post\Assisted;

use App\Actions\Post\FinalizePostPublication;
use App\Enums\Post\PublishStatus;
use App\Events\PostStatusUpdated;
use App\Models\Post;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Uri;
use InvalidArgumentException;

/**
 * The user published an assisted post on X: record its status URL and settle
 * the post like any publication. Only a post still waiting for the user
 * (pending review on an assisted channel) is touched.
 */
class CompleteAssistedPost
{
    private const array X_HOSTS = ['x.com', 'www.x.com', 'twitter.com', 'www.twitter.com', 'mobile.x.com', 'mobile.twitter.com'];

    public function __construct(private readonly FinalizePostPublication $finalize) {}

    public function handle(Post $post, string $statusUrl): bool
    {
        [$handle, $statusId] = $this->parseStatusUrl($statusUrl);

        $completed = DB::transaction(function () use ($post, $handle, $statusId): bool {
            $locked = Post::query()->with('socialAccount')->whereKey($post->id)->lockForUpdate()->first();

            if (! $locked instanceof Post || ! $this->isWaitingForUser($locked)) {
                return false;
            }

            $locked->markPublicationPublished($statusId, "https://x.com/{$handle}/status/{$statusId}");

            return true;
        });

        if (! $completed) {
            return false;
        }

        $this->finalize->handle($post);
        PostStatusUpdated::dispatch($post->fresh());

        return true;
    }

    private function isWaitingForUser(Post $post): bool
    {
        return $post->publish_status === PublishStatus::PendingReview
            && $post->socialAccount?->isAssisted() === true;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function parseStatusUrl(string $statusUrl): array
    {
        $uri = Uri::of($statusUrl);
        $segments = $uri->pathSegments()->values();

        if (! in_array(mb_strtolower((string) $uri->host()), self::X_HOSTS, true)
            || $segments->count() < 3
            || $segments->get(1) !== 'status'
            || ! ctype_digit((string) $segments->get(2))) {
            throw new InvalidArgumentException("Not an X post URL: {$statusUrl}");
        }

        return [(string) $segments->get(0), (string) $segments->get(2)];
    }
}
