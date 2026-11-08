<?php

declare(strict_types=1);

use App\Enums\Trend\TopicStatus;
use App\Jobs\Trend\AnalyzeWorkspaceTrends;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Trend\CreateIdeaFromTrendTool;
use App\Mcp\Tools\Trend\GetTrendBriefTool;
use App\Mcp\Tools\Trend\ListTrendsTool;
use App\Mcp\Tools\Trend\RunTrendAnalysisTool;
use App\Mcp\Tools\Trend\UpdateTrendTopicTool;
use App\Models\Idea;
use App\Models\Post;
use App\Models\TrendProfile;
use App\Models\TrendTopic;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\Fluent\AssertableJson;

beforeEach(function () {
    Queue::fake([AnalyzeWorkspaceTrends::class]);

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id, 'account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->profile = TrendProfile::factory()->create(['workspace_id' => $this->workspace->id, 'niche' => 'Home fitness']);
});

test('the brief gives the profile, the best topics, what worked and what was posted lately', function () {
    $low = TrendTopic::factory()->create(['workspace_id' => $this->workspace->id, 'score' => 20]);
    $high = TrendTopic::factory()->create(['workspace_id' => $this->workspace->id, 'score' => 90, 'own_posts' => 2, 'own_ratio' => 150]);
    TrendTopic::factory()->dismissed()->create(['workspace_id' => $this->workspace->id, 'score' => 500]);
    TrendTopic::factory()->create(['score' => 400]);
    Post::factory()->create(['workspace_id' => $this->workspace->id, 'content' => '<p>Our <strong>kettlebell</strong> routine &amp; more</p>']);

    TryPostServer::actingAs($this->user)
        ->tool(GetTrendBriefTool::class, [])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) use ($high, $low) {
            $json->where('profile.niche', 'Home fitness')
                ->has('topics', 2)
                ->where('topics.0.id', $high->id)
                ->where('topics.0.own_ratio', 150)
                ->where('topics.1.id', $low->id)
                ->where('what_worked.posts_measured', 0)
                ->has('what_worked.format_lift')
                ->where('recent_posts.0.text', 'Our kettlebell routine & more')
                ->has('how_to_use');
        });
});

test('the brief says so when the setup is not finished', function () {
    $this->profile->update(['onboarded_at' => null]);

    TryPostServer::actingAs($this->user)
        ->tool(GetTrendBriefTool::class, [])
        ->assertHasErrors(['Trends is not set up for this workspace yet.']);
});

test('the topics can be listed by status', function () {
    $new = TrendTopic::factory()->create(['workspace_id' => $this->workspace->id, 'score' => 50]);
    $used = TrendTopic::factory()->create(['workspace_id' => $this->workspace->id, 'score' => 80, 'status' => TopicStatus::Used]);
    $dismissed = TrendTopic::factory()->dismissed()->create(['workspace_id' => $this->workspace->id, 'score' => 99]);

    TryPostServer::actingAs($this->user)
        ->tool(ListTrendsTool::class, [])
        ->assertStructuredContent(fn (AssertableJson $json) => $json->has('topics', 2)->where('topics.0.id', $used->id)->where('topics.1.id', $new->id));

    TryPostServer::actingAs($this->user)
        ->tool(ListTrendsTool::class, ['status' => 'dismissed'])
        ->assertStructuredContent(fn (AssertableJson $json) => $json->has('topics', 1)->where('topics.0.id', $dismissed->id));

    TryPostServer::actingAs($this->user)
        ->tool(ListTrendsTool::class, ['limit' => 1])
        ->assertStructuredContent(fn (AssertableJson $json) => $json->has('topics', 1));

    TryPostServer::actingAs($this->user)
        ->tool(ListTrendsTool::class, ['status' => 'nonsense'])
        ->assertHasErrors();
});

test('an analysis can be requested, once at a time', function () {
    TryPostServer::actingAs($this->user)
        ->tool(RunTrendAnalysisTool::class, [])
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('queued', true)->where('analyzing', true)->etc());

    TryPostServer::actingAs($this->user->fresh())
        ->tool(RunTrendAnalysisTool::class, [])
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('queued', false)->etc());

    Queue::assertPushed(AnalyzeWorkspaceTrends::class, 1);
});

test('only a workspace admin can request an analysis', function () {
    $member = workspaceMember($this->workspace);

    TryPostServer::actingAs($member)
        ->tool(RunTrendAnalysisTool::class, [])
        ->assertHasErrors(['This action is unauthorized.']);

    Queue::assertNothingPushed();
});

test('a topic can be dismissed and brought back, and only its own workspace finds it', function () {
    $topic = TrendTopic::factory()->create(['workspace_id' => $this->workspace->id]);

    TryPostServer::actingAs($this->user)
        ->tool(UpdateTrendTopicTool::class, ['topic_id' => $topic->id, 'status' => 'dismissed'])
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('status', 'dismissed')->etc());

    expect($topic->fresh()->status)->toBe(TopicStatus::Dismissed);

    TryPostServer::actingAs($this->user)
        ->tool(UpdateTrendTopicTool::class, ['topic_id' => $topic->id, 'status' => 'new'])
        ->assertOk();

    expect($topic->fresh()->status)->toBe(TopicStatus::New);

    TryPostServer::actingAs($this->user)
        ->tool(UpdateTrendTopicTool::class, ['topic_id' => TrendTopic::factory()->create()->id, 'status' => 'dismissed'])
        ->assertHasErrors(['Trend topic not found.']);

    TryPostServer::actingAs($this->user)
        ->tool(UpdateTrendTopicTool::class, ['topic_id' => $topic->id, 'status' => 'used'])
        ->assertHasErrors();

    TryPostServer::actingAs($this->user)
        ->tool(UpdateTrendTopicTool::class, ['topic_id' => 'not-a-uuid', 'status' => 'dismissed'])
        ->assertHasErrors();
});

test('a topic becomes an idea and is marked used', function () {
    $topic = TrendTopic::factory()->create([
        'workspace_id' => $this->workspace->id,
        'label' => 'Zephyr Drone',
        'formats' => ['video'],
        'evidence' => [['title' => 'Zephyr drone unveiled', 'url' => 'https://example.com/a', 'source' => 'Feed', 'published_at' => now()->toIso8601String()]],
    ]);

    TryPostServer::actingAs($this->user)
        ->tool(CreateIdeaFromTrendTool::class, ['topic_id' => $topic->id])
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('idea.title', 'Zephyr Drone')
            ->where('topic.status', 'used')
            ->etc());

    $idea = Idea::query()->sole();

    expect($idea->workspace_id)->toBe($this->workspace->id)
        ->and($idea->body)->toContain('https://example.com/a')
        ->and($topic->fresh()->status)->toBe(TopicStatus::Used);

    TryPostServer::actingAs($this->user)
        ->tool(CreateIdeaFromTrendTool::class, ['topic_id' => TrendTopic::factory()->create()->id])
        ->assertHasErrors(['Trend topic not found.']);
});

test('a workspace that switched trends off gets a refusal from every tool', function () {
    $this->workspace->update(['modules' => ['trends' => false]]);
    $topic = TrendTopic::factory()->create(['workspace_id' => $this->workspace->id]);

    foreach ([
        [GetTrendBriefTool::class, []],
        [ListTrendsTool::class, []],
        [RunTrendAnalysisTool::class, []],
        [UpdateTrendTopicTool::class, ['topic_id' => $topic->id, 'status' => 'dismissed']],
        [CreateIdeaFromTrendTool::class, ['topic_id' => $topic->id]],
    ] as [$tool, $arguments]) {
        TryPostServer::actingAs($this->user->fresh())->tool($tool, $arguments)->assertHasErrors(['Trends is turned off for this workspace.']);
    }

    expect($topic->fresh()->status)->toBe(TopicStatus::New)
        ->and(Idea::query()->count())->toBe(0);

    Queue::assertNothingPushed();
});

test('the tools are not listed while the app has trends off', function () {
    expect(app(GetTrendBriefTool::class)->eligibleForRegistration())->toBeTrue();

    config()->set('trypost.trends.enabled', false);

    expect(app(GetTrendBriefTool::class)->eligibleForRegistration())->toBeFalse()
        ->and(app(CreateIdeaFromTrendTool::class)->eligibleForRegistration())->toBeFalse();
});
