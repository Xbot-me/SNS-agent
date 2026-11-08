<?php

declare(strict_types=1);

namespace App\Models;

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
        'onboarded_at',
        'analysis_requested_at',
        'analyzed_at',
    ];

    protected $attributes = [
        'goal' => Goal::DEFAULT->value,
    ];

    protected function casts(): array
    {
        return [
            'goal' => Goal::class,
            'keywords' => 'array',
            'excluded_keywords' => 'array',
            'formats' => 'array',
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
