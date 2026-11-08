<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\InboxConversation;
use App\Models\InboxMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InboxMessage>
 */
class InboxMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'inbox_conversation_id' => InboxConversation::factory(),
            'remote_id' => fake()->uuid(),
            'author_name' => fake()->name(),
            'author_username' => fake()->userName(),
            'author_avatar_url' => null,
            'is_own' => false,
            'body' => fake()->sentence(),
            'attachments' => null,
            'sent_at' => now()->subHour(),
        ];
    }

    public function own(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_own' => true,
        ]);
    }
}
