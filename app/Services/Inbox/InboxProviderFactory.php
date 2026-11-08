<?php

declare(strict_types=1);

namespace App\Services\Inbox;

use App\Enums\SocialAccount\Platform;
use App\Exceptions\Inbox\InboxException;
use App\Services\Inbox\Providers\AbstractInboxProvider;
use App\Services\Inbox\Providers\BlueskyInboxProvider;
use App\Services\Inbox\Providers\FacebookInboxProvider;
use App\Services\Inbox\Providers\GoogleBusinessInboxProvider;
use App\Services\Inbox\Providers\InstagramInboxProvider;
use App\Services\Inbox\Providers\MastodonInboxProvider;
use App\Services\Inbox\Providers\ThreadsInboxProvider;
use App\Services\Inbox\Providers\YouTubeInboxProvider;

/**
 * Which networks the unified inbox reads. X (paid reads), LinkedIn (Community
 * Management API approval), TikTok and Pinterest (no API) are left out.
 */
class InboxProviderFactory
{
    public function supports(Platform $platform): bool
    {
        return config('trypost.inbox.enabled') && in_array($platform, self::platforms(), true);
    }

    /**
     * @return list<Platform>
     */
    public static function platforms(): array
    {
        return [
            Platform::Facebook,
            Platform::Instagram,
            Platform::InstagramFacebook,
            Platform::Threads,
            Platform::YouTube,
            Platform::Bluesky,
            Platform::Mastodon,
            Platform::GoogleBusiness,
        ];
    }

    public function for(Platform $platform): AbstractInboxProvider
    {
        return match ($platform) {
            Platform::Facebook => app(FacebookInboxProvider::class),
            Platform::Instagram, Platform::InstagramFacebook => app(InstagramInboxProvider::class),
            Platform::Threads => app(ThreadsInboxProvider::class),
            Platform::YouTube => app(YouTubeInboxProvider::class),
            Platform::Bluesky => app(BlueskyInboxProvider::class),
            Platform::Mastodon => app(MastodonInboxProvider::class),
            Platform::GoogleBusiness => app(GoogleBusinessInboxProvider::class),
            default => throw InboxException::unsupported("{$platform->value} has no inbox"),
        };
    }
}
