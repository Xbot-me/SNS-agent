<?php

declare(strict_types=1);

namespace App\Enums\Trend;

use App\Services\Trend\Sources\GoogleTrendsSource;
use App\Services\Trend\Sources\HackerNewsSource;
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

    public function isAvailable(): bool
    {
        if (! config("trypost.trends.sources.{$this->value}.enabled")) {
            return false;
        }

        return $this !== self::YouTube || filled(config('trypost.trends.sources.youtube.api_key'));
    }

    /**
     * Whether a new setup starts with it on. YouTube spends a daily quota, so it is opt-in.
     */
    public function isDefault(): bool
    {
        return $this !== self::YouTube;
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
        };
    }

    /**
     * @return list<self>
     */
    public static function available(): array
    {
        return array_values(array_filter(self::cases(), fn (self $source): bool => $source->isAvailable()));
    }
}
