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
 * The public trends of one Mastodon server: trending hashtags (uses over the last days as
 * engagement) and trending links (accounts sharing them). They cover every subject, so only
 * a hashtag that contains one of the profile's words, or a link whose title does, is kept.
 *
 * @see https://docs.joinmastodon.org/methods/trends/
 */
class MastodonSource implements TrendSource
{
    private const int LIMIT = 20;

    /** A hashtag's recent days that count towards its engagement. */
    private const int TAG_DAYS = 3;

    public function __construct(private TopicExtractor $extractor) {}

    public function signals(TrendProfile $profile, CarbonImmutable $since): Collection
    {
        $words = $profile->watchedWords();

        if ($words === []) {
            return collect();
        }

        $failure = null;
        $signals = collect();

        foreach (['tags' => $this->tag(...), 'links' => $this->link(...)] as $kind => $map) {
            try {
                $items = Http::acceptJson()
                    ->timeout(10)
                    ->get(config('trypost.trends.sources.mastodon.host')."/api/v1/trends/{$kind}", ['limit' => self::LIMIT])
                    ->throw()
                    ->json();
            } catch (ConnectionException|RequestException $exception) {
                $failure ??= $exception;

                continue;
            }

            $signals = $signals->merge(collect((array) $items)->map(fn (array $item): ?TrendSignal => $map($item, $words, $since))->filter()->values());
        }

        // A server that answered neither list cannot be read; one list failing leaves the other.
        if ($signals->isEmpty() && $failure !== null) {
            throw SourceUnavailableException::from($failure);
        }

        return $signals;
    }

    /**
     * @param  array<string, mixed>  $tag
     * @param  list<string>  $words
     */
    private function tag(array $tag, array $words, CarbonImmutable $since): ?TrendSignal
    {
        $name = (string) data_get($tag, 'name');
        $url = (string) data_get($tag, 'url');
        $history = array_slice((array) data_get($tag, 'history', []), 0, self::TAG_DAYS);

        if (blank($name) || blank($url) || $history === []) {
            return null;
        }

        // A hashtag has no spaces, so "3d print" matches #3dprint: compare the letters and digits only.
        $flat = $this->flatten($name);

        if (! collect($words)->contains(fn (string $word): bool => mb_strlen($this->flatten($word)) >= 3 && str_contains($flat, $this->flatten($word)))) {
            return null;
        }

        $publishedAt = CarbonImmutable::createFromTimestampUTC((int) data_get($history, '0.day'));

        if ($publishedAt->lt($since->startOfDay())) {
            return null;
        }

        return new TrendSignal(
            outlet: 'mastodon',
            outletName: 'Mastodon',
            title: "#{$name}",
            url: $url,
            publishedAt: $publishedAt,
            engagement: (int) array_sum(array_map(fn (array $day): int => (int) data_get($day, 'uses'), $history)) ?: null,
        );
    }

    /**
     * @param  array<string, mixed>  $link
     * @param  list<string>  $words
     */
    private function link(array $link, array $words, CarbonImmutable $since): ?TrendSignal
    {
        $title = trim((string) data_get($link, 'title'));
        $url = (string) data_get($link, 'url');

        if (blank($title) || blank($url)) {
            return null;
        }

        if (! collect($words)->contains(fn (string $word): bool => $this->extractor->mentions($title, $word))) {
            return null;
        }

        $publishedAt = data_get($link, 'published_at') ? CarbonImmutable::parse((string) data_get($link, 'published_at')) : CarbonImmutable::now();

        if ($publishedAt->lt($since)) {
            return null;
        }

        return new TrendSignal(
            outlet: 'mastodon',
            outletName: 'Mastodon',
            title: Str::limit($title, 200, ''),
            url: $url,
            publishedAt: $publishedAt,
            engagement: (int) data_get($link, 'history.0.accounts') ?: null,
        );
    }

    private function flatten(string $text): string
    {
        return Str::of($text)->lower()->replaceMatches('/[^\p{L}\p{N}]+/u', '')->toString();
    }
}
