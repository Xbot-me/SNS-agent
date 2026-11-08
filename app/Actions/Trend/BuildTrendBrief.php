<?php

declare(strict_types=1);

namespace App\Actions\Trend;

use App\Actions\Analytics\ReadWorkspacePostPerformance;
use App\Http\Resources\App\TrendProfileResource;
use App\Http\Resources\App\TrendTopicResource;
use App\Models\Post;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class BuildTrendBrief
{
    private const int TOPICS = 10;

    private const int RECENT_POSTS = 15;

    private const int POST_TEXT_LENGTH = 160;

    /**
     * Everything an agent needs to write posts for the workspace's niche: who it is for,
     * what is trending (best first, with proof), what has worked for this audience, and
     * what was posted lately so it does not repeat itself. Null when Trends is not set up.
     *
     * @return array<string, mixed>|null
     */
    public static function execute(Workspace $workspace): ?array
    {
        $profile = $workspace->trendProfile;

        if ($profile === null || $profile->onboarded_at === null) {
            return null;
        }

        $performance = ReadWorkspacePostPerformance::execute($workspace->id, CarbonImmutable::now());

        return [
            'profile' => TrendProfileResource::make($profile)->resolve(),
            'topics' => TrendTopicResource::collection(
                $workspace->trendTopics()->ranked()->limit(self::TOPICS)->get(),
            )->resolve(),
            'what_worked' => [
                'posts_measured' => count($performance->posts),
                // Above 1 means the format did better than the workspace's average, below 1 worse.
                'format_lift' => array_map(fn (float $lift): float => round($lift, 2), $performance->formatLifts()),
            ],
            'recent_posts' => $workspace->posts()
                ->whereNotNull('content')
                ->latest()
                ->limit(self::RECENT_POSTS)
                ->get(['id', 'content', 'platform', 'status', 'created_at'])
                ->map(fn (Post $post): array => [
                    'platform' => $post->platform?->value,
                    'status' => $post->status->value,
                    'text' => Str::of(html_entity_decode(strip_tags((string) $post->content)))->squish()->limit(self::POST_TEXT_LENGTH)->toString(),
                    'created_at' => $post->created_at->toIso8601String(),
                ])
                ->all(),
            'how_to_use' => 'Write posts about the highest-scoring topics. A topic covered by several sources (outlet_count) is more reliable than one source repeating itself. Use the formats listed on the topic, in that order. Where own_ratio is above 100 the audience liked this subject before; where status is used an idea already exists. Do not repeat anything in recent_posts. For a Reel, call search-instagram-audio-tool without q to see the sounds trending on Instagram. Save a topic you will use with create-idea-from-trend-tool, or dismiss one that does not fit with update-trend-topic-tool.',
        ];
    }
}
