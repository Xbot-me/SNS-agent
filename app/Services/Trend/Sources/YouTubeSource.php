<?php

declare(strict_types=1);

namespace App\Services\Trend\Sources;

use App\Dto\Trend\TrendSignal;
use App\Models\TrendProfile;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * The most-watched recent videos for the profile's first words, with their view
 * counts as engagement. A search costs 100 of the project's 10,000 daily quota
 * units (a statistics read costs 1), so only the first few words are searched.
 */
class YouTubeSource implements TrendSource
{
    private const int VIDEOS_PER_WORD = 10;

    public function signals(TrendProfile $profile, CarbonImmutable $since): Collection
    {
        $words = array_slice($profile->watchedWords(), 0, (int) config('trypost.trends.sources.youtube.max_queries'));

        return collect($words)->flatMap(function (string $word) use ($profile, $since): Collection {
            try {
                return $this->videosFor($word, $profile, $since);
            } catch (ConnectionException|RequestException) {
                // One failed search leaves the other words' videos.
                return collect();
            }
        });
    }

    /**
     * @return Collection<int, TrendSignal>
     */
    private function videosFor(string $word, TrendProfile $profile, CarbonImmutable $since): Collection
    {
        $api = (string) config('trypost.platforms.youtube.data_api');

        $ids = collect($this->client()->get("{$api}/search", [
            'part' => 'id',
            'type' => 'video',
            'order' => 'viewCount',
            'q' => $word,
            'publishedAfter' => $since->toIso8601ZuluString(),
            'regionCode' => $profile->region,
            'maxResults' => self::VIDEOS_PER_WORD,
        ])->throw()->json('items', []))
            ->map(fn (array $item): ?string => data_get($item, 'id.videoId'))
            ->filter()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $videos = $this->client()->get("{$api}/videos", [
            'part' => 'snippet,statistics',
            'id' => $ids->implode(','),
        ])->throw()->json('items', []);

        return collect($videos)->map(fn (array $video): TrendSignal => new TrendSignal(
            outlet: 'youtube',
            outletName: 'YouTube',
            title: (string) data_get($video, 'snippet.title'),
            url: config('trypost.platforms.youtube.web_app').'/watch?v='.data_get($video, 'id'),
            publishedAt: CarbonImmutable::parse((string) data_get($video, 'snippet.publishedAt')),
            engagement: (int) data_get($video, 'statistics.viewCount', 0),
        ));
    }

    /**
     * The key goes in a header, not the URL, so a failed request never logs it.
     */
    private function client(): PendingRequest
    {
        return Http::timeout(10)->withHeaders(['x-goog-api-key' => (string) config('trypost.trends.sources.youtube.api_key')]);
    }
}
