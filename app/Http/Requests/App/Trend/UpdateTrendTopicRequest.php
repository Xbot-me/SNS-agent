<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Trend;

use App\Enums\Trend\TopicStatus;
use App\Models\Idea;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTrendTopicRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Idea::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([TopicStatus::Dismissed->value, TopicStatus::New->value])],
        ];
    }
}
