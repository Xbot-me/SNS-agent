<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Inbox\ConversationKind;
use App\Enums\SocialAccount\Platform;
use App\Models\InboxConversation;
use App\Models\SocialAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InboxConversation>
 */
class InboxConversationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'social_account_id' => SocialAccount::factory()->instagram(),
            'workspace_id' => fn (array $attributes) => SocialAccount::query()->find($attributes['social_account_id'])?->workspace_id,
            'platform' => fn (array $attributes) => SocialAccount::query()->find($attributes['social_account_id'])?->platform ?? Platform::Instagram,
            'kind' => ConversationKind::Message,
            'remote_id' => fake()->uuid(),
            'participant_name' => fake()->name(),
            'participant_username' => fake()->userName(),
            'participant_avatar_url' => null,
            'subject' => null,
            'permalink' => null,
            'last_message_excerpt' => fake()->sentence(),
            'last_message_at' => now()->subHour(),
            'last_inbound_at' => now()->subHour(),
            'read_at' => null,
            'meta' => [],
        ];
    }

    public function read(): static
    {
        return $this->state(fn (array $attributes) => [
            'read_at' => now(),
        ]);
    }

    public function comment(): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => ConversationKind::Comment,
            'subject' => fake()->sentence(),
        ]);
    }
}
