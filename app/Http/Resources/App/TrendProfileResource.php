<?php

declare(strict_types=1);

namespace App\Http\Resources\App;

use App\Enums\Trend\ExternalSource;
use App\Models\TrendProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TrendProfile
 */
class TrendProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'niche' => $this->niche,
            'audience' => $this->audience,
            'goal' => $this->goal->value,
            'keywords' => $this->keywords,
            'excluded_keywords' => $this->excluded_keywords,
            'formats' => $this->formats,
            'posts_per_week' => $this->posts_per_week,
            'region' => $this->region,
            'competitors' => $this->competitorHandles(),
            'source_issues' => (object) ($this->source_issues ?? []),
            'external_sources' => array_map(fn (ExternalSource $source): string => $source->value, $this->externalSourceList()),
            'rss_feed_ids' => $this->rssFeeds()->pluck('rss_feeds.id')->all(),
            'analyzed_at' => $this->analyzed_at?->toIso8601String(),
            'analyzing' => $this->isAnalyzing(),
        ];
    }
}
