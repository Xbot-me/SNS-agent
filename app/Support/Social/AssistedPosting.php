<?php

declare(strict_types=1);

namespace App\Support\Social;

use App\Enums\PostPlatform\ContentType;
use App\Models\Post;
use App\Support\ThreadReplies;

/**
 * Rules for assisted channels: X accounts connected by handle, whose posts the
 * user publishes in a browser window `npm run x:assist` fills in.
 *
 * The helper fills one composer: the text and up to four images or one video.
 * Threads and polls need more of X's composer and are refused.
 */
class AssistedPosting
{
    public static function enabled(): bool
    {
        return (bool) config('trypost.platforms.x.assisted');
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    public static function supports(?ContentType $contentType, ?array $meta): bool
    {
        return $contentType !== ContentType::XPoll && blank(ThreadReplies::of($meta ?? []));
    }

    public static function supportsPost(Post $post): bool
    {
        return self::supports($post->content_type, $post->meta);
    }

    public static function unsupportedMessage(): string
    {
        return __('posts.errors.assisted_single_post_only');
    }
}
