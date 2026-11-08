<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Trend\TopicStatus;
use App\Models\TrendTopic;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TrendTopic>
 */
class TrendTopicFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $label = fake()->unique()->words(2, true);

        return [
            'workspace_id' => Workspace::factory(),
            'key' => Str::lower($label),
            'label' => Str::title($label),
            'score' => fake()->numberBetween(10, 200),
            'signal_count' => 3,
            'outlet_count' => 2,
            'evidence' => [],
            'formats' => ['text'],
            'status' => TopicStatus::DEFAULT,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }

    public function dismissed(): static
    {
        return $this->state(fn (): array => ['status' => TopicStatus::Dismissed]);
    }
}
