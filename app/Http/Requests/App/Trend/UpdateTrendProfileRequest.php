<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Trend;

use App\Enums\Trend\Goal;
use App\Enums\Trend\PostFormat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTrendProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        $workspace = $this->user()->currentWorkspace;

        return $workspace !== null && $this->user()->can('update', $workspace);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'niche' => ['required', 'string', 'max:120'],
            'audience' => ['nullable', 'string', 'max:255'],
            'goal' => ['required', Rule::enum(Goal::class)],
            'keywords' => ['required', 'array', 'min:1', 'max:'.(int) config('trypost.trends.max_keywords')],
            'keywords.*' => ['string', 'max:60', 'distinct:ignore_case'],
            'excluded_keywords' => ['nullable', 'array', 'max:'.(int) config('trypost.trends.max_keywords')],
            'excluded_keywords.*' => ['string', 'max:60', 'distinct:ignore_case'],
            'formats' => ['required', 'array', 'min:1'],
            'formats.*' => [Rule::enum(PostFormat::class), 'distinct'],
            'posts_per_week' => ['required', 'integer', 'between:1,21'],
            'rss_feed_ids' => ['nullable', 'array', 'max:'.(int) config('trypost.trends.max_feeds')],
            'rss_feed_ids.*' => [
                'uuid',
                'distinct',
                Rule::exists('rss_feeds', 'id')->where('workspace_id', $this->user()->currentWorkspace->id),
            ],
        ];
    }
}
