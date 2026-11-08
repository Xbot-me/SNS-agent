<?php

declare(strict_types=1);

use App\Enums\Trend\TopicStatus;
use App\Models\Idea;
use App\Models\TrendProfile;
use App\Models\TrendTopic;
use App\Models\User;
use App\Models\Workspace;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('member'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->user = $this->user->fresh();
    subscribeAccount($this->user->account);
    $this->profile = TrendProfile::factory()->create(['workspace_id' => $this->workspace->id]);
});

test('the page lists the best topics first and leaves the dismissed ones out', function () {
    $low = TrendTopic::factory()->create(['workspace_id' => $this->workspace->id, 'score' => 20]);
    $high = TrendTopic::factory()->create(['workspace_id' => $this->workspace->id, 'score' => 90]);
    TrendTopic::factory()->dismissed()->create(['workspace_id' => $this->workspace->id, 'score' => 500]);
    TrendTopic::factory()->create(['score' => 400]);

    $this->actingAs($this->user)
        ->get(route('app.trends.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('trends/Index')
            ->where('profile.niche', $this->profile->niche)
            ->where('topics.0.id', $high->id)
            ->where('topics.1.id', $low->id)
            ->has('topics', 2)
            ->where('canManage', true));
});

test('a member who is not an admin sees the topics but cannot manage the setup', function () {
    TrendTopic::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs(workspaceMember($this->workspace))
        ->get(route('app.trends.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('trends/Index')
            ->has('topics', 1)
            ->where('canManage', false));
});

test('the page keeps only as many topics as the limit', function () {
    config()->set('trypost.trends.max_topics', 2);
    TrendTopic::factory()->count(3)->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->user)
        ->get(route('app.trends.index'))
        ->assertInertia(fn (Assert $page) => $page->has('topics', 2));
});

test('a topic can be dismissed and brought back', function () {
    $topic = TrendTopic::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->user)
        ->patch(route('app.trends.topics.update', $topic), ['status' => 'dismissed'])
        ->assertRedirect();

    expect($topic->fresh()->status)->toBe(TopicStatus::Dismissed);

    $this->actingAs($this->user)->patch(route('app.trends.topics.update', $topic), ['status' => 'new']);

    expect($topic->fresh()->status)->toBe(TopicStatus::New);
});

test('a topic cannot be marked used by hand', function () {
    $topic = TrendTopic::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->user)
        ->patchJson(route('app.trends.topics.update', $topic), ['status' => 'used'])
        ->assertJsonValidationErrors('status');
});

test('a topic of another workspace is not found', function () {
    $foreign = TrendTopic::factory()->create();

    $this->actingAs($this->user)->patch(route('app.trends.topics.update', $foreign), ['status' => 'dismissed'])->assertNotFound();
    $this->actingAs($this->user)->post(route('app.trends.topics.idea.store', $foreign))->assertNotFound();

    expect($foreign->fresh()->status)->toBe(TopicStatus::New)
        ->and(Idea::query()->count())->toBe(0);
});

test('making a post from a topic puts an idea on the board and marks the topic used', function () {
    $topic = TrendTopic::factory()->create([
        'workspace_id' => $this->workspace->id,
        'label' => 'Zephyr Drone',
        'outlet_count' => 3,
        'formats' => ['video', 'text'],
        'evidence' => [['title' => 'Zephyr drone unveiled', 'url' => 'https://example.com/a', 'source' => 'Feed', 'published_at' => now()->toIso8601String()]],
    ]);

    $response = $this->actingAs($this->user)->post(route('app.trends.topics.idea.store', $topic));

    $idea = Idea::query()->sole();

    $response->assertRedirect(route('app.create.ideas.show', $idea));

    expect($idea->title)->toBe('Zephyr Drone')
        ->and($idea->workspace_id)->toBe($this->workspace->id)
        ->and($idea->body)->toContain('3')
        ->and($idea->body)->toContain('Video, Text')
        ->and($idea->body)->toContain('https://example.com/a')
        ->and($topic->fresh()->status)->toBe(TopicStatus::Used);
});
