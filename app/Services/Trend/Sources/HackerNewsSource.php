<?php

declare(strict_types=1);

namespace App\Services\Trend\Sources;

use App\Dto\Trend\TrendSignal;
use App\Models\TrendProfile;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * Stories Hacker News readers voted on, found by searching the profile's first
 * words. Points and comments are the engagement.
 */
class HackerNewsSource implements TrendSource
{
    private const int HITS_PER_WORD = 20;

    public function signals(TrendProfile $profile, CarbonImmutable $since): Collection
    {
        $api = (string) config('trypost.trends.sources.hacker_news.api');
        $words = array_slice($profile->watchedWords(), 0, (int) config('trypost.trends.sources.hacker_news.max_queries'));

        return collect($words)->flatMap(function (string $word) use ($api, $since): Collection {
            try {
                $hits = Http::timeout(10)
                    ->get("{$api}/search", [
                        'query' => $word,
                        'tags' => 'story',
                        'numericFilters' => 'created_at_i>'.$since->getTimestamp(),
                        'hitsPerPage' => self::HITS_PER_WORD,
                    ])
                    ->throw()
                    ->json('hits', []);
            } catch (ConnectionException|RequestException) {
                // One failed search leaves the other words' stories.
                return collect();
            }

            return collect($hits)
                ->filter(fn (array $hit): bool => filled(data_get($hit, 'title')))
                ->map(fn (array $hit): TrendSignal => new TrendSignal(
                    outlet: 'hacker_news',
                    outletName: 'Hacker News',
                    title: (string) data_get($hit, 'title'),
                    url: (string) (data_get($hit, 'url') ?: 'https://news.ycombinator.com/item?id='.data_get($hit, 'objectID')),
                    publishedAt: CarbonImmutable::createFromTimestampUTC((int) data_get($hit, 'created_at_i')),
                    engagement: (int) data_get($hit, 'points', 0) + (int) data_get($hit, 'num_comments', 0),
                ));
        });
    }
}
