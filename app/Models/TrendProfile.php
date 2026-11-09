<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Trend\ExternalSource;
use App\Enums\Trend\Goal;
use App\Enums\Trend\PostFormat;
use Database\Factories\TrendProfileFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * What a workspace posts about and who for: the answers of the Trends setup.
 * Everything the analysis looks for and suggests comes from here.
 */
class TrendProfile extends Model
{
    /** @use HasFactory<TrendProfileFactory> */
    use HasFactory, HasUuids;

    /** A requested analysis that never finished stops counting as running after this long. */
    public const int ANALYSIS_TIMEOUT_MINUTES = 10;

    protected $fillable = [
        'workspace_id',
        'niche',
        'audience',
        'goal',
        'keywords',
        'excluded_keywords',
        'formats',
        'posts_per_week',
        'region',
        'external_sources',
        'competitors',
        'threads_topics',
        'threads_creators',
        'source_issues',
        'onboarded_at',
        'analysis_requested_at',
        'analyzed_at',
    ];

    protected $attributes = [
        'goal' => Goal::DEFAULT->value,
        'region' => 'US',
    ];

    protected function casts(): array
    {
        return [
            'goal' => Goal::class,
            'keywords' => 'array',
            'excluded_keywords' => 'array',
            'formats' => 'array',
            'external_sources' => 'array',
            'competitors' => 'array',
            'threads_topics' => 'array',
            'threads_creators' => 'array',
            'source_issues' => 'array',
            'posts_per_week' => 'integer',
            'onboarded_at' => 'datetime',
            'analysis_requested_at' => 'datetime',
            'analyzed_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function rssFeeds(): BelongsToMany
    {
        return $this->belongsToMany(RssFeed::class, 'trend_profile_rss_feed');
    }

    /**
     * @return list<PostFormat>
     */
    public function postFormats(): array
    {
        return collect($this->formats)
            ->map(fn (string $format): ?PostFormat => PostFormat::tryFrom($format))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * The words the workspace watches, trimmed and in the order it gave them.
     *
     * @return list<string>
     */
    public function watchedWords(): array
    {
        return collect($this->keywords)
            ->map(fn (string $word): string => trim($word))
            ->filter(fn (string $word): bool => filled($word))
            ->unique(fn (string $word): string => mb_strtolower($word))
            ->values()
            ->all();
    }

    /**
     * The Instagram usernames the workspace watches.
     *
     * @return list<string>
     */
    public function competitorHandles(): array
    {
        return collect($this->competitors ?? [])
            ->map(fn (string $handle): string => mb_strtolower(ltrim(trim($handle), '@')))
            ->filter(fn (string $handle): bool => filled($handle))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The Threads topic tags the workspace follows (a community's tag), without the #.
     *
     * @return list<string>
     */
    public function threadsTopicTags(): array
    {
        return collect($this->threads_topics ?? [])
            ->map(fn (string $tag): string => trim(ltrim(trim($tag), '#')))
            ->filter(fn (string $tag): bool => filled($tag))
            ->unique(fn (string $tag): string => mb_strtolower($tag))
            ->values()
            ->all();
    }

    /**
     * The Threads usernames the workspace watches.
     *
     * @return list<string>
     */
    public function threadsCreatorHandles(): array
    {
        return collect($this->threads_creators ?? [])
            ->map(fn (string $handle): string => mb_strtolower(ltrim(trim($handle), '@')))
            ->filter(fn (string $handle): bool => filled($handle))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The external sources the workspace chose (a profile saved before they existed has none).
     *
     * @return list<ExternalSource>
     */
    public function externalSourceList(): array
    {
        return collect($this->external_sources ?? [])
            ->map(fn (string $source): ?ExternalSource => ExternalSource::tryFrom($source))
            ->filter()
            ->values()
            ->all();
    }

    public function isAnalyzing(): bool
    {
        if ($this->analysis_requested_at === null) {
            return false;
        }

        return $this->analysis_requested_at->gt(now()->subMinutes(self::ANALYSIS_TIMEOUT_MINUTES))
            && ($this->analyzed_at === null || $this->analysis_requested_at->gt($this->analyzed_at));
    }

    /**
     * @param  Builder<TrendProfile>  $query
     */
    public function scopeOnboarded(Builder $query): void
    {
        $query->whereNotNull('onboarded_at');
    }

    /**
     * Profiles whose last analysis is older than the refresh interval, or that never had one.
     *
     * @param  Builder<TrendProfile>  $query
     */
    public function scopeDue(Builder $query): void
    {
        $query->where(fn (Builder $due) => $due
            ->whereNull('analyzed_at')
            ->orWhere('analyzed_at', '<=', now()->subHours((int) config('trypost.trends.analyze_interval_hours'))));
    }
}
