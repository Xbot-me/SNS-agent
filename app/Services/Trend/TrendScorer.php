<?php

declare(strict_types=1);

namespace App\Services\Trend;

use App\Dto\Trend\TrendSignal;
use App\Models\TrendProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Turns what the sources published into a ranked list of topics.
 *
 * A topic is worth posting about when several different outlets cover it
 * (corroboration), the coverage is recent (freshness) and it touches what the
 * workspace said it cares about (relevance). Nothing here calls a model.
 */
class TrendScorer
{
    /** Coverage loses half its weight every three days. */
    private const float HALF_LIFE_HOURS = 72.0;

    /** Topics sharing this much of their coverage are one subject said twice. */
    private const float DUPLICATE_OVERLAP = 0.6;

    private const int EVIDENCE_ITEMS = 5;

    public function __construct(private TopicExtractor $extractor) {}

    /**
     * @param  Collection<int, TrendSignal>  $signals
     * @return list<array{key: string, label: string, score: int, signal_count: int, outlet_count: int, evidence: list<array{title: string, url: string, source: string, published_at: string}>, last_seen_at: CarbonImmutable}>
     */
    public function rank(TrendProfile $profile, Collection $signals, CarbonImmutable $now): array
    {
        $keywords = $this->normalize($profile->keywords);
        $labels = collect($profile->keywords)->mapWithKeys(fn (string $keyword): array => [mb_strtolower(trim($keyword)) => trim($keyword)]);
        $excluded = $this->normalize($profile->excluded_keywords);
        $minOutlets = max(1, (int) config('trypost.trends.min_outlets'));

        $groups = [];

        $signals
            ->reject(fn (TrendSignal $signal): bool => $this->mentionsAny($signal->title, $excluded))
            ->unique('url')
            ->each(function (TrendSignal $signal) use (&$groups, $keywords): void {
                foreach ($this->extractor->terms($signal->title, $keywords) as $term) {
                    $groups[$term][] = $signal;
                }
            });

        return collect($groups)
            ->map(fn (array $members, string $term): ?array => $this->topic($term, collect($members), $keywords, $labels, $minOutlets, $now))
            ->filter()
            ->sortBy([['score', 'desc'], ['key', 'asc']])
            ->pipe(fn (Collection $topics): Collection => $this->withoutRepeats($topics))
            ->take((int) config('trypost.trends.max_topics'))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, TrendSignal>  $members
     * @param  list<string>  $keywords
     * @param  Collection<string, string>  $labels
     * @return array{key: string, label: string, score: int, signal_count: int, outlet_count: int, evidence: list<array{title: string, url: string, source: string, published_at: string}>, last_seen_at: CarbonImmutable, urls: list<string>}|null
     */
    private function topic(string $term, Collection $members, array $keywords, Collection $labels, int $minOutlets, CarbonImmutable $now): ?array
    {
        $outlets = $members->pluck('outlet')->unique()->count();
        $isKeyword = in_array($term, $keywords, true);

        if ($isKeyword ? $members->count() < 2 : $outlets < $minOutlets) {
            return null;
        }

        $freshness = $members->sum(fn (TrendSignal $signal): float => 0.5 ** (abs($signal->publishedAt->diffInHours($now)) / self::HALF_LIFE_HOURS));
        $relevance = $isKeyword || $this->touchesKeyword($term, $keywords) ? 1.5 : 1.0;
        $newest = $members->sortByDesc(fn (TrendSignal $signal): int => $signal->publishedAt->getTimestamp())->values();

        return [
            'key' => $term,
            'label' => $labels->get($term) ?? Str::title($term),
            'score' => (int) round(10 * $relevance * ($outlets * 2 + $freshness)),
            'signal_count' => $members->count(),
            'outlet_count' => $outlets,
            'evidence' => $newest
                ->take(self::EVIDENCE_ITEMS)
                ->map(fn (TrendSignal $signal): array => [
                    'title' => $signal->title,
                    'url' => $signal->url,
                    'source' => $signal->outletName,
                    'published_at' => $signal->publishedAt->toIso8601String(),
                ])
                ->all(),
            'last_seen_at' => $newest->first()->publishedAt,
            'urls' => $members->pluck('url')->all(),
        ];
    }

    /**
     * Drops a topic whose coverage is mostly the same items as a better-ranked one
     * ("apple vision" and "vision pro" over the same headlines are one subject).
     *
     * @param  Collection<int, array<string, mixed>>  $topics  best first
     * @return Collection<int, array<string, mixed>>
     */
    private function withoutRepeats(Collection $topics): Collection
    {
        $kept = collect();

        foreach ($topics as $topic) {
            $repeat = $kept->contains(function (array $other) use ($topic): bool {
                $union = count(array_unique([...$other['urls'], ...$topic['urls']]));

                return $union > 0 && count(array_intersect($other['urls'], $topic['urls'])) / $union >= self::DUPLICATE_OVERLAP;
            });

            if (! $repeat) {
                $kept->push($topic);
            }
        }

        return $kept->map(fn (array $topic): array => Arr::except($topic, 'urls'));
    }

    /**
     * @param  list<string>  $words
     * @return list<string>
     */
    private function normalize(array $words): array
    {
        return collect($words)
            ->map(fn (string $word): string => mb_strtolower(trim($word)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $words
     */
    private function mentionsAny(string $text, array $words): bool
    {
        return collect($words)->contains(fn (string $word): bool => $this->extractor->mentions($text, $word));
    }

    /**
     * @param  list<string>  $keywords
     */
    private function touchesKeyword(string $term, array $keywords): bool
    {
        return collect($keywords)->contains(fn (string $keyword): bool => $this->extractor->mentions($term, $keyword));
    }
}
