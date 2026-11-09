<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Trend\TopicStatus;
use Database\Factories\TrendTopicFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A subject several of the workspace's sources are covering right now,
 * ranked by `score`, with the items that prove it.
 */
class TrendTopic extends Model
{
    /** @use HasFactory<TrendTopicFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'workspace_id',
        'key',
        'label',
        'score',
        'signal_count',
        'outlet_count',
        'evidence',
        'formats',
        'top_formats',
        'own_posts',
        'own_ratio',
        'status',
        'first_seen_at',
        'last_seen_at',
    ];

    protected $attributes = [
        'status' => TopicStatus::DEFAULT->value,
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'signal_count' => 'integer',
            'outlet_count' => 'integer',
            'own_posts' => 'integer',
            'own_ratio' => 'integer',
            'evidence' => 'array',
            'formats' => 'array',
            'top_formats' => 'array',
            'status' => TopicStatus::class,
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * What the Trends page lists: everything the user has not dismissed, best first.
     *
     * @param  Builder<TrendTopic>  $query
     */
    public function scopeRanked(Builder $query): void
    {
        $query->where('status', '!=', TopicStatus::Dismissed->value)
            ->orderByDesc('score')
            ->orderBy('id');
    }
}
