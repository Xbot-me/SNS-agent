<?php

declare(strict_types=1);

namespace App\Actions\Trend;

use App\Actions\Idea\CreateIdea;
use App\Enums\Trend\TopicStatus;
use App\Models\Idea;
use App\Models\TrendTopic;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

class CreateIdeaFromTrend
{
    /**
     * Puts a topic on the ideas board, with the formats worth trying and the
     * items that show why it is trending, and marks the topic as used.
     */
    public static function execute(Workspace $workspace, User $user, TrendTopic $topic): Idea
    {
        return DB::transaction(function () use ($workspace, $user, $topic): Idea {
            $idea = CreateIdea::execute($workspace, $user, [
                'title' => mb_substr($topic->label, 0, 255),
                'body' => self::body($topic, $user->locale->value),
                'idea_stage_id' => null,
            ]);

            $topic->update(['status' => TopicStatus::Used]);

            return $idea;
        });
    }

    private static function body(TrendTopic $topic, string $locale): string
    {
        $formats = collect($topic->formats)
            ->map(fn (string $format): string => __("trends.formats.{$format}", [], $locale))
            ->implode(', ');

        $sources = collect($topic->evidence)
            ->map(fn (array $item): string => '- '.data_get($item, 'title')."\n  ".data_get($item, 'url'))
            ->implode("\n");

        return collect([
            trans_choice('trends.idea.summary', $topic->outlet_count, ['count' => $topic->outlet_count], $locale),
            filled($formats) ? __('trends.idea.formats', ['formats' => $formats], $locale) : null,
            filled($sources) ? __('trends.idea.sources', [], $locale)."\n".$sources : null,
        ])->filter()->implode("\n\n");
    }
}
