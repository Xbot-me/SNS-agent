<?php

declare(strict_types=1);

namespace App\Actions\Trend;

use App\Actions\Analytics\ReadWorkspacePostPerformance;
use App\Dto\Trend\PostPerformance;
use App\Dto\Trend\TrendSignal;
use App\Enums\Trend\ExternalSource;
use App\Enums\Trend\PostFormat;
use App\Enums\Trend\TopicStatus;
use App\Models\TrendProfile;
use App\Models\TrendTopic;
use App\Services\Trend\Sources\InstagramCompetitorSource;
use App\Services\Trend\Sources\RssTrendSource;
use App\Services\Trend\Sources\TrendSource;
use App\Services\Trend\TrendScorer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AnalyzeTrends
{
    private const int SUGGESTED_FORMATS = 2;

    /**
     * Reads the sources, ranks the topics and stores them for the workspace.
     * A topic the user already used or dismissed keeps that answer when it comes back.
     */
    public static function execute(TrendProfile $profile): void
    {
        $now = CarbonImmutable::now();
        $since = $now->subDays((int) config('trypost.trends.window_days'));

        /** @var Collection<int, TrendSignal> $signals */
        // A source that fails is reported and skipped, so one outage never costs the others' topics.
        $signals = collect(self::sources($profile))
            ->flatMap(fn (TrendSource $source): Collection => rescue(fn (): Collection => $source->signals($profile, $since), collect()));

        // What already worked for this audience nudges both the topics and the formats.
        $performance = ReadWorkspacePostPerformance::execute($profile->workspace_id, $now);
        $ranked = app(TrendScorer::class)->rank($profile, $signals, $now, $performance);
        $formats = self::suggestedFormats($profile, $performance);

        DB::transaction(function () use ($profile, $ranked, $formats, $now): void {
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
                    'formats' => $formats,
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

            $profile->update(['analyzed_at' => $now]);
        });
    }

    /**
     * @return list<TrendSource>
     */
    private static function sources(TrendProfile $profile): array
    {
        return [
            app(RssTrendSource::class),
            ...($profile->competitorHandles() !== [] && config('trypost.trends.sources.instagram_competitors.enabled') ? [app(InstagramCompetitorSource::class)] : []),
            ...array_map(
                fn (ExternalSource $source): TrendSource => app($source->sourceClass()),
                array_filter($profile->externalSourceList(), fn (ExternalSource $source): bool => $source->isAvailable()),
            ),
        ];
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

        return array_map(
            fn (PostFormat $format): string => $format->value,
            array_slice($ordered === [] ? $preferred : $ordered, 0, self::SUGGESTED_FORMATS),
        );
    }
}
