<?php

declare(strict_types=1);

namespace App\Dto\Trend;

use App\Enums\Trend\PostFormat;
use App\Services\Trend\TopicExtractor;

/**
 * How the workspace's own recent posts did: their text, their format and their
 * engagement rate (engagements per person reached). It lets Trends favour
 * subjects and formats that already worked for this audience.
 */
final readonly class PostPerformance
{
    /** A format needs this many measured posts before its results count. */
    public const int MIN_POSTS_PER_FORMAT = 3;

    /** Results this close to the average are treated as no difference. */
    private const float TOLERANCE = 0.1;

    /**
     * @param  list<array{excerpt: string, format: ?PostFormat, rate: float}>  $posts
     */
    public function __construct(public array $posts) {}

    public function isEmpty(): bool
    {
        return $this->posts === [];
    }

    public function averageRate(): float
    {
        return $this->isEmpty() ? 0.0 : array_sum(array_column($this->posts, 'rate')) / count($this->posts);
    }

    /**
     * What the posts that mention the term got, against the workspace's average.
     *
     * @return array{posts: int, ratio: int}|null null when no post mentions it (or nothing was measured)
     */
    public function forTerm(string $term, TopicExtractor $extractor): ?array
    {
        $average = $this->averageRate();

        if ($average <= 0.0) {
            return null;
        }

        $rates = array_column(
            array_filter($this->posts, fn (array $post): bool => $extractor->mentions($post['excerpt'], $term)),
            'rate',
        );

        if ($rates === []) {
            return null;
        }

        return [
            'posts' => count($rates),
            'ratio' => (int) round(array_sum($rates) / count($rates) / $average * 100),
        ];
    }

    /**
     * How much better (above 1) or worse (below 1) each format did than the average.
     * Only formats with enough posts appear, and a result within the tolerance counts as 1.
     *
     * @return array<string, float>
     */
    public function formatLifts(): array
    {
        $average = $this->averageRate();

        if ($average <= 0.0) {
            return [];
        }

        $lifts = [];

        foreach (collect($this->posts)->filter(fn (array $post): bool => $post['format'] !== null)->groupBy(fn (array $post): string => $post['format']->value) as $format => $posts) {
            if ($posts->count() < self::MIN_POSTS_PER_FORMAT) {
                continue;
            }

            $lift = $posts->avg('rate') / $average;
            $lifts[$format] = abs($lift - 1.0) <= self::TOLERANCE ? 1.0 : $lift;
        }

        return $lifts;
    }
}
