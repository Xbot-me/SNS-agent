<?php

declare(strict_types=1);

namespace App\Services\Trend\Sources;

use App\Dto\Trend\TrendSignal;
use App\Models\TrendProfile;
use App\Services\Trend\TopicExtractor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;

/**
 * Google's daily "trending searches" feed for the profile's country. It covers
 * every subject, so only the trends whose search term or headlines mention one
 * of the workspace's words are kept. Each headline becomes a signal carrying the
 * trend's approximate search traffic.
 */
class GoogleTrendsSource implements TrendSource
{
    private const int HEADLINES_PER_TREND = 3;

    public function __construct(private TopicExtractor $extractor) {}

    public function signals(TrendProfile $profile, CarbonImmutable $since): Collection
    {
        $words = $profile->watchedWords();

        if ($words === []) {
            return collect();
        }

        $body = Http::timeout(10)
            ->get(config('trypost.trends.sources.google_trends.host').'/trending/rss', ['geo' => $profile->region])
            ->throw()
            ->body();

        $feed = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);

        if ($feed === false) {
            return collect();
        }

        $namespace = (string) data_get($feed->getNamespaces(true), 'ht');

        return collect($this->nodes($feed->channel->item))
            ->flatMap(fn (SimpleXMLElement $item): array => $this->signalsOf($item, $namespace, $words, $since));
    }

    /**
     * @param  list<string>  $words
     * @return list<TrendSignal>
     */
    private function signalsOf(SimpleXMLElement $item, string $namespace, array $words, CarbonImmutable $since): array
    {
        $term = trim((string) $item->title);
        $publishedAt = CarbonImmutable::parse((string) $item->pubDate);
        $traffic = (int) preg_replace('/\D/', '', (string) $item->children($namespace)->approx_traffic);

        $headlines = collect($this->nodes($item->children($namespace)->news_item))
            ->map(fn (SimpleXMLElement $news): array => [
                'title' => trim((string) $news->children($namespace)->news_item_title),
                'url' => trim((string) $news->children($namespace)->news_item_url),
            ])
            ->filter(fn (array $news): bool => filled($news['title']) && filled($news['url']))
            ->take(self::HEADLINES_PER_TREND)
            ->values();

        if ($publishedAt->lt($since) || ! $this->touchesWords($term, $headlines, $words)) {
            return [];
        }

        if ($headlines->isEmpty()) {
            $headlines = collect([['title' => $term, 'url' => (string) $item->link]]);
        }

        return $headlines
            ->map(fn (array $news): TrendSignal => new TrendSignal(
                outlet: 'google_trends',
                outletName: 'Google Trends',
                title: $news['title'],
                url: $news['url'],
                publishedAt: $publishedAt,
                engagement: $traffic > 0 ? $traffic : null,
            ))
            ->all();
    }

    /**
     * The elements of a node list. iterator_to_array() would keep only the last of
     * several siblings, since they all share one name.
     *
     * @return list<SimpleXMLElement>
     */
    private function nodes(SimpleXMLElement $list): array
    {
        $nodes = [];

        foreach ($list as $node) {
            $nodes[] = $node;
        }

        return $nodes;
    }

    /**
     * @param  Collection<int, array{title: string, url: string}>  $headlines
     * @param  list<string>  $words
     */
    private function touchesWords(string $term, Collection $headlines, array $words): bool
    {
        $text = $headlines->pluck('title')->prepend($term)->implode(' ');

        return collect($words)->contains(fn (string $word): bool => $this->extractor->mentions($text, $word));
    }
}
