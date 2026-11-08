<?php

declare(strict_types=1);

use App\Jobs\Trend\AnalyzeWorkspaceTrends;
use App\Models\RssFeed;
use App\Models\TrendProfile;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Queue::fake([AnalyzeWorkspaceTrends::class]);

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->user = $this->user->fresh();
    subscribeAccount($this->user->account);
    $this->feed = RssFeed::factory()->create(['workspace_id' => $this->workspace->id]);
});

function trendsTestProfileInput(array $overrides = []): array
{
    return [
        'niche' => 'Home fitness',
        'audience' => 'Busy parents',
        'goal' => 'sell',
        'keywords' => ['home workout', 'Kettlebell'],
        'excluded_keywords' => ['giveaway'],
        'formats' => ['video', 'text'],
        'posts_per_week' => 4,
        'region' => 'GB',
        'external_sources' => ['hacker_news'],
        ...$overrides,
    ];
}

test('the trends page sends a workspace without a setup to the setup', function () {
    $this->actingAs($this->user)
        ->get(route('app.trends.index'))
        ->assertRedirect(route('app.trends.profile.edit'));

    TrendProfile::factory()->notOnboarded()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->user)
        ->get(route('app.trends.index'))
        ->assertRedirect(route('app.trends.profile.edit'));
});

test('the setup page offers the niches, goals, formats and the feeds to read', function () {
    $this->actingAs($this->user)
        ->get(route('app.trends.profile.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('trends/Setup')
            ->where('profile', null)
            ->has('niches', count(config('trypost.trends.niches')))
            ->where('goals', ['grow', 'traffic', 'sell', 'authority'])
            ->where('formats', ['text', 'image', 'carousel', 'video'])
            ->where('feeds.0.id', $this->feed->id)
            ->has('directory')
            ->where('limits.keywords', 20)
            ->where('externalSources', [['key' => 'google_trends', 'default' => true], ['key' => 'hacker_news', 'default' => true], ['key' => 'bluesky', 'default' => false], ['key' => 'mastodon', 'default' => false]])
            ->has('regions'));
});

test('finishing the setup saves the profile, marks it done and queues the first analysis', function () {
    $this->actingAs($this->user)
        ->put(route('app.trends.profile.update'), trendsTestProfileInput(['rss_feed_ids' => [$this->feed->id]]))
        ->assertRedirect(route('app.trends.index'));

    $profile = $this->workspace->fresh()->trendProfile;

    expect($profile->niche)->toBe('Home fitness')
        ->and($profile->goal->value)->toBe('sell')
        ->and($profile->keywords)->toBe(['home workout', 'Kettlebell'])
        ->and($profile->formats)->toBe(['video', 'text'])
        ->and($profile->posts_per_week)->toBe(4)
        ->and($profile->region)->toBe('GB')
        ->and($profile->external_sources)->toBe(['hacker_news'])
        ->and($profile->onboarded_at)->not->toBeNull()
        ->and($profile->rssFeeds->pluck('id')->all())->toBe([$this->feed->id]);

    Queue::assertPushed(AnalyzeWorkspaceTrends::class, fn (AnalyzeWorkspaceTrends $job) => $job->profile->is($profile));
});

test('saving again edits the same profile and keeps the first finish date', function () {
    $this->actingAs($this->user)->put(route('app.trends.profile.update'), trendsTestProfileInput());
    $first = $this->workspace->fresh()->trendProfile;
    $finishedAt = $first->onboarded_at;

    $this->travel(2)->days();

    $this->actingAs($this->user)->put(route('app.trends.profile.update'), trendsTestProfileInput(['niche' => 'Yoga']));

    expect(TrendProfile::query()->count())->toBe(1)
        ->and($first->fresh()->niche)->toBe('Yoga')
        ->and($first->fresh()->onboarded_at->equalTo($finishedAt))->toBeTrue();
});

test('words are trimmed and repeats dropped', function () {
    $this->actingAs($this->user)->put(route('app.trends.profile.update'), trendsTestProfileInput([
        'keywords' => ['  yoga ', 'pilates'],
        'excluded_keywords' => null,
    ]));

    $profile = $this->workspace->fresh()->trendProfile;

    expect($profile->keywords)->toBe(['yoga', 'pilates'])
        ->and($profile->excluded_keywords)->toBe([]);

    $this->actingAs($this->user)
        ->putJson(route('app.trends.profile.update'), trendsTestProfileInput(['keywords' => ['Yoga', 'yoga']]))
        ->assertJsonValidationErrors('keywords.0');
});

test('a feed of another workspace cannot be read', function () {
    $foreign = RssFeed::factory()->create();

    $this->actingAs($this->user)
        ->putJson(route('app.trends.profile.update'), trendsTestProfileInput(['rss_feed_ids' => [$foreign->id]]))
        ->assertJsonValidationErrors('rss_feed_ids.0');

    expect(TrendProfile::query()->count())->toBe(0);
});

test('the setup needs a niche, a keyword and a format', function () {
    $this->actingAs($this->user)
        ->putJson(route('app.trends.profile.update'), trendsTestProfileInput(['niche' => '', 'keywords' => [], 'formats' => [], 'goal' => 'fame', 'posts_per_week' => 0]))
        ->assertJsonValidationErrors(['niche', 'keywords', 'formats', 'goal', 'posts_per_week']);
});

test('only an admin changes the setup', function () {
    $member = workspaceMember($this->workspace);

    $this->actingAs($member)->get(route('app.trends.profile.edit'))->assertForbidden();
    $this->actingAs($member)->put(route('app.trends.profile.update'), trendsTestProfileInput())->assertForbidden();

    expect(TrendProfile::query()->count())->toBe(0);
});

test('an analysis already running is not queued twice', function () {
    $this->actingAs($this->user)->put(route('app.trends.profile.update'), trendsTestProfileInput());
    $this->actingAs($this->user)->post(route('app.trends.analysis.store'))->assertRedirect();

    Queue::assertPushed(AnalyzeWorkspaceTrends::class, 1);

    $profile = $this->workspace->fresh()->trendProfile;
    $profile->update(['analyzed_at' => now()]);
    Queue::releaseUniqueJobLocks();
    $this->actingAs($this->user->fresh())->post(route('app.trends.analysis.store'));

    Queue::assertPushed(AnalyzeWorkspaceTrends::class, 2);
});

test('analysing needs a finished setup', function () {
    $this->actingAs($this->user)->post(route('app.trends.analysis.store'))->assertNotFound();
});

test('a source the app does not offer cannot be chosen', function () {
    $this->actingAs($this->user)
        ->putJson(route('app.trends.profile.update'), trendsTestProfileInput(['external_sources' => ['youtube']]))
        ->assertJsonValidationErrors('external_sources.0');

    config()->set('trypost.trends.sources.youtube.api_key', 'test-key');

    $this->actingAs($this->user->fresh())
        ->putJson(route('app.trends.profile.update'), trendsTestProfileInput(['external_sources' => ['youtube']]))
        ->assertRedirect(route('app.trends.index'));

    expect($this->workspace->fresh()->trendProfile->external_sources)->toBe(['youtube']);
});

test('YouTube is offered once it has a key, and starts off', function () {
    config()->set('trypost.trends.sources.youtube.api_key', 'test-key');

    $this->actingAs($this->user)
        ->get(route('app.trends.profile.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('externalSources.2', ['key' => 'youtube', 'default' => false]));
});

test('the country must be one Google Trends is read for', function () {
    $this->actingAs($this->user)
        ->putJson(route('app.trends.profile.update'), trendsTestProfileInput(['region' => 'ZZ']))
        ->assertJsonValidationErrors('region');
});

test('a source that is switched off in the app is not offered', function () {
    config()->set('trypost.trends.sources.hacker_news.enabled', false);

    $this->actingAs($this->user)
        ->get(route('app.trends.profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('externalSources', [['key' => 'google_trends', 'default' => true], ['key' => 'bluesky', 'default' => false], ['key' => 'mastodon', 'default' => false]]));
});
