<?php

declare(strict_types=1);

namespace App\Http\Resources\App;

use App\Models\TrendTopic;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TrendTopic
 */
class TrendTopicResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'score' => $this->score,
            'signal_count' => $this->signal_count,
            'outlet_count' => $this->outlet_count,
            'own_posts' => $this->own_posts,
            'own_ratio' => $this->own_ratio,
            'evidence' => $this->evidence,
            'formats' => $this->formats,
            'status' => $this->status->value,
            'last_seen_at' => $this->last_seen_at->toIso8601String(),
        ];
    }
}
