<?php

declare(strict_types=1);

namespace App\Services\Trend\Sources;

use App\Dto\Trend\TrendSignal;
use App\Exceptions\Trend\SourceUnavailableException;
use App\Models\TrendProfile;
use App\Services\Trend\TopicExtractor;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * What Bluesky's own trending topics list says people are talking about, with its post
 * count as engagement. The endpoint is public but "unspecced" (Bluesky does not promise it
 * stays), and it covers every subject, so only topics whose name or description mention one
 * of the profile's words are kept.
 */
class BlueskySource implements TrendSource
{
    private const int TOPICS = 25;

    private const int TITLE_LENGTH = 200;

    public function __construct(private TopicExtractor $extractor) {}

    public function signals(TrendProfile $profile, CarbonImmutable $since): Collection
    {
        $words = $profile->watchedWords();

        if ($words === []) {
            return collect();
        }

        try {
            $trends = Http::acceptJson()
                ->timeout(10)
                ->get(config('trypost.trends.sources.bluesky.api').'/xrpc/app.bsky.unspecced.getTrends', ['limit' => self::TOPICS])
                ->throw()
                ->json('trends', []);
        } catch (ConnectionException|RequestException $exception) {
            throw SourceUnavailableException::from($exception);
        }

        return collect($trends)
            ->map(fn (array $trend): ?TrendSignal => $this->signal($trend, $words, $since))
            ->filter()
            ->values();
    }

    /**
     * @param  array<string, mixed>  $trend
     * @param  list<string>  $words
     */
    private function signal(array $trend, array $words, CarbonImmutable $since): ?TrendSignal
    {
        $name = trim((string) data_get($trend, 'displayName'));
        $description = trim((string) data_get($trend, 'description'));
        $link = (string) data_get($trend, 'link');
        $startedAt = data_get($trend, 'startedAt');

        if (blank($name) || blank($link) || blank($startedAt)) {
            return null;
        }

        $text = trim("{$name}: {$description}", ': ');

        if (! collect($words)->contains(fn (string $word): bool => $this->extractor->mentions($text, $word))) {
            return null;
        }

        $publishedAt = CarbonImmutable::parse((string) $startedAt);

        if ($publishedAt->lt($since)) {
            return null;
        }

        return new TrendSignal(
            outlet: 'bluesky',
            outletName: 'Bluesky',
            title: Str::limit($text, self::TITLE_LENGTH, ''),
            url: config('trypost.trends.sources.bluesky.web').$link,
            publishedAt: $publishedAt,
            engagement: (int) data_get($trend, 'postCount') ?: null,
        );
    }
}
