<?php

declare(strict_types=1);

namespace App\Actions\Trend;

use App\Actions\Analytics\ReadWorkspacePostPerformance;
use App\Dto\Trend\PostPerformance;
use App\Dto\Trend\TrendSignal;
use App\Enums\Trend\PostFormat;
use App\Enums\Trend\TopicStatus;
use App\Exceptions\Trend\SourceUnavailableException;
use App\Models\TrendProfile;
use App\Models\TrendTopic;
use App\Services\Trend\Sources\InstagramCompetitorSource;
use App\Services\Trend\Sources\RssTrendSource;
use App\Services\Trend\Sources\TrendSource;
use App\Services\Trend\TrendScorer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class AnalyzeTrends
{
    private const int SUGGESTED_FORMATS = 2;

    /** A format leads a topic's suggestions once this many of its top posts used it. */
    private const int MIN_POSTS_FOR_TOP_FORMAT = 2;

    /**
     * Reads the sources, ranks the topics and stores them for the workspace.
     * A topic the user already used or dismissed keeps that answer when it comes back.
     */
    public static function execute(TrendProfile $profile): void
    {
        $now = CarbonImmutable::now();
        $since = $now->subDays((int) config('trypost.trends.window_days'));

        // A source that fails is skipped and noted for the user, so one outage never costs the others' topics.
        $issues = [];

        /** @var Collection<int, TrendSignal> $signals */
        $signals = collect(self::sources($profile))->flatMap(function (TrendSource $source, string $key) use ($profile, $since, &$issues): Collection {
            try {
                return $source->signals($profile, $since);
            } catch (SourceUnavailableException $exception) {
                $issues[$key] = $exception->reason;
            } catch (Throwable $exception) {
                report($exception);
                $issues[$key] = SourceUnavailableException::FAILED;
            }

            return collect();
        });

        // What already worked for this audience nudges both the topics and the formats.
        $performance = ReadWorkspacePostPerformance::execute($profile->workspace_id, $now);
        $ranked = app(TrendScorer::class)->rank($profile, $signals, $now, $performance);
        $formats = self::suggestedFormats($profile, $performance);

        DB::transaction(function () use ($profile, $ranked, $formats, $now, $issues): void {
            foreach ($ranked as $ranking) {
                $topic = TrendTopic::query()->firstOrNew([
                    'workspace_id' => $profile->workspace_id,
                    'key' => $ranking['key'],
                ]);

                $topic->fill([
                    'label' => $ranking['label'],
                    'score' => $ranking['score'],
                    'signal_count' => $ranking['signal_count'],
                    'outlet_count' => $ranking['outlet_count'],
                    'evidence' => $ranking['evidence'],
                    'formats' => self::topicFormats($profile, $formats, $ranking['top_formats']),
                    'top_formats' => $ranking['top_formats'] === [] ? null : $ranking['top_formats'],
                    'last_seen_at' => $ranking['last_seen_at'],
                    'own_posts' => $ranking['own_posts'],
                    'own_ratio' => $ranking['own_ratio'],
                ]);
                $topic->first_seen_at ??= $now;
                $topic->save();
            }

            TrendTopic::query()
                ->where('workspace_id', $profile->workspace_id)
                ->where('status', TopicStatus::New->value)
                ->whereNotIn('key', array_column($ranked, 'key'))
                ->delete();

            $profile->update(['analyzed_at' => $now, 'source_issues' => $issues === [] ? null : $issues]);
        });
    }

    /**
     * The sources to read, by the key a problem with them is recorded under.
     *
     * @return array<string, TrendSource>
     */
    private static function sources(TrendProfile $profile): array
    {
        $sources = ['rss' => app(RssTrendSource::class)];

        if ($profile->competitorHandles() !== [] && config('trypost.trends.sources.instagram_competitors.enabled')) {
            $sources['instagram_competitors'] = app(InstagramCompetitorSource::class);
        }

        foreach ($profile->externalSourceList() as $source) {
            if ($source->isAvailableFor($profile->workspace)) {
                $sources[$source->value] = app($source->sourceClass());
            }
        }

        return $sources;
    }

    /**
     * The formats the workspace said it makes, in the order its goal prefers them, with the
     * ones its own posts did clearly better (or worse) on moved up (or down).
     *
     * @return list<string>
     */
    private static function suggestedFormats(TrendProfile $profile, PostPerformance $performance): array
    {
        $preferred = $profile->goal->preferredFormats();
        $made = $profile->postFormats();
        $ordered = array_values(array_filter($preferred, fn (PostFormat $format): bool => in_array($format, $made, true)));
        $lifts = $performance->formatLifts();
        $position = array_flip(array_map(fn (PostFormat $format): string => $format->value, $ordered));

        usort($ordered, fn (PostFormat $a, PostFormat $b): int => [data_get($lifts, $b->value, 1.0), $position[$a->value]] <=> [data_get($lifts, $a->value, 1.0), $position[$b->value]]);

        return array_map(fn (PostFormat $format): string => $format->value, $ordered === [] ? $preferred : $ordered);
    }

    /**
     * The formats suggested for one topic: the one most of its top posts used goes first when the
     * workspace makes it and enough posts used it, then the workspace's own order.
     *
     * @param  list<string>  $formats  the workspace's formats, best first
     * @param  array<string, int>  $topFormats  format => posts, most used first
     * @return list<string>
     */
    private static function topicFormats(TrendProfile $profile, array $formats, array $topFormats): array
    {
        $made = array_map(fn (PostFormat $format): string => $format->value, $profile->postFormats());
        $lead = array_key_first($topFormats);

        if ($lead !== null && $topFormats[$lead] >= self::MIN_POSTS_FOR_TOP_FORMAT && in_array($lead, $made, true)) {
            $formats = [$lead, ...array_values(array_diff($formats, [$lead]))];
        }

        return array_slice($formats, 0, self::SUGGESTED_FORMATS);
    }
}
