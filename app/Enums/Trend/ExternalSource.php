<?php

declare(strict_types=1);

namespace App\Enums\Trend;

use App\Models\Workspace;
use App\Services\Trend\Sources\BlueskySource;
use App\Services\Trend\Sources\GoogleTrendsSource;
use App\Services\Trend\Sources\HackerNewsSource;
use App\Services\Trend\Sources\InstagramCompetitorSource;
use App\Services\Trend\Sources\InstagramHashtagSource;
use App\Services\Trend\Sources\MastodonSource;
use App\Services\Trend\Sources\ThreadsSearchSource;
use App\Services\Trend\Sources\TrendSource;
use App\Services\Trend\Sources\YouTubeSource;

/**
 * The places Trends can read besides the workspace's own feeds. A workspace
 * picks which ones it uses; the app decides which ones exist.
 */
enum ExternalSource: string
{
    case GoogleTrends = 'google_trends';
    case HackerNews = 'hacker_news';
    case YouTube = 'youtube';
    case InstagramHashtags = 'instagram_hashtags';
    case ThreadsSearch = 'threads_search';
    case Bluesky = 'bluesky';
    case Mastodon = 'mastodon';

    public function isAvailable(): bool
    {
        if (! config("trypost.trends.sources.{$this->value}.enabled")) {
            return false;
        }

        return $this !== self::YouTube || filled(config('trypost.trends.sources.youtube.api_key'));
    }

    /**
     * Offered to this workspace: the app has it on and the workspace has what it reads with
     * (an Instagram channel linked through Facebook, a Threads channel that was granted search).
     */
    public function isAvailableFor(Workspace $workspace): bool
    {
        if (! $this->isAvailable()) {
            return false;
        }

        return match ($this) {
            self::InstagramHashtags => InstagramCompetitorSource::usableAccount($workspace->id) !== null,
            self::ThreadsSearch => ThreadsSearchSource::usableAccount($workspace->id) !== null,
            default => true,
        };
    }

    /**
     * Whether a new setup starts with it on. YouTube spends a daily quota, and Bluesky and
     * Mastodon show general trends, so they are opt-in; the Meta sources are the point of the app.
     */
    public function isDefault(): bool
    {
        return ! in_array($this, [self::YouTube, self::Bluesky, self::Mastodon], true);
    }

    /**
     * @return class-string<TrendSource>
     */
    public function sourceClass(): string
    {
        return match ($this) {
            self::GoogleTrends => GoogleTrendsSource::class,
            self::HackerNews => HackerNewsSource::class,
            self::YouTube => YouTubeSource::class,
            self::InstagramHashtags => InstagramHashtagSource::class,
            self::ThreadsSearch => ThreadsSearchSource::class,
            self::Bluesky => BlueskySource::class,
            self::Mastodon => MastodonSource::class,
        };
    }

    /**
     * @return list<self>
     */
    public static function available(): array
    {
        return array_values(array_filter(self::cases(), fn (self $source): bool => $source->isAvailable()));
    }

    /**
     * @return list<self>
     */
    public static function availableFor(Workspace $workspace): array
    {
        return array_values(array_filter(self::cases(), fn (self $source): bool => $source->isAvailableFor($workspace)));
    }
}
