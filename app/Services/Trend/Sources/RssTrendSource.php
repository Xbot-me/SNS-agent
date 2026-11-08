<?php

declare(strict_types=1);

namespace App\Services\Trend\Sources;

use App\Dto\Trend\TrendSignal;
use App\Models\RssFeedItem;
use App\Models\TrendProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Reads the items the workspace's feeds already fetched (`rss-feeds:poll`), so
 * analysing a niche never calls out to the network.
 */
class RssTrendSource implements TrendSource
{
    private const int MAX_ITEMS = 2000;

    public function signals(TrendProfile $profile, CarbonImmutable $since): Collection
    {
        return RssFeedItem::query()
            ->with('feed')
            ->whereIn('rss_feed_id', $profile->rssFeeds()->select('rss_feeds.id'))
            ->where('published_at', '>=', $since)
            ->latest('published_at')
            ->limit(self::MAX_ITEMS)
            ->get()
            ->map(fn (RssFeedItem $item): TrendSignal => new TrendSignal(
                outlet: $item->rss_feed_id,
                outletName: $item->feed->display_title,
                title: $item->title,
                url: $item->url,
                publishedAt: CarbonImmutable::instance($item->published_at),
            ));
    }
}
