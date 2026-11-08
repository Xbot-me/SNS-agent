<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Trend\Goal;
use App\Models\TrendProfile;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrendProfile>
 */
class TrendProfileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'niche' => fake()->words(2, true),
            'audience' => fake()->sentence(4),
            'goal' => Goal::DEFAULT,
            'keywords' => ['social media'],
            'excluded_keywords' => [],
            'formats' => ['text', 'image'],
            'posts_per_week' => 3,
            'onboarded_at' => now(),
        ];
    }

    public function notOnboarded(): static
    {
        return $this->state(fn (): array => ['onboarded_at' => null]);
    }
}
