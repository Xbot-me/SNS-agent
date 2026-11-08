<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Dto\Trend\PostPerformance;
use App\Enums\Analytics\PublicationContentType;
use App\Enums\Trend\PostFormat;
use Carbon\CarbonImmutable;

class ReadWorkspacePostPerformance
{
    /** How far back the workspace's posts still say something about its audience. */
    private const int WINDOW_DAYS = 90;

    private const int MAX_POSTS = 300;

    /**
     * The workspace's recent posts that have text and a measured reach, with the
     * engagement rate of each (engagements divided by people reached).
     */
    public static function execute(string $workspaceId, CarbonImmutable $now): PostPerformance
    {
        $rows = (new QueryLatestPublicationSnapshots)
            ->execute($workspaceId, null, $now->subDays(self::WINDOW_DAYS), $now)
            ->whereNotNull('publication.excerpt')
            ->where('metric.exposure_count', '>', 0)
            ->whereNotNull('metric.engagement_count')
            ->select('publication.excerpt', 'publication.content_type', 'metric.engagement_count', 'metric.exposure_count')
            ->limit(self::MAX_POSTS)
            ->get();

        return new PostPerformance($rows
            ->map(fn (object $row): array => [
                'excerpt' => (string) $row->excerpt,
                'format' => PostFormat::fromPublication(PublicationContentType::tryFrom((string) $row->content_type)),
                'rate' => (int) $row->engagement_count / (int) $row->exposure_count,
            ])
            ->all());
    }
}
