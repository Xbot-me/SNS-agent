<?php

declare(strict_types=1);

use App\Actions\Trend\AnalyzeTrends;
use App\Enums\SocialAccount\Platform;
use App\Enums\Trend\ExternalSource;
use App\Exceptions\Trend\SourceUnavailableException;
use App\Models\RssFeed;
use App\Models\RssFeedItem;
use App\Models\SocialAccount;
use App\Models\TrendProfile;
use App\Models\TrendTopic;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Trend\Sources\BlueskySource;
use App\Services\Trend\Sources\InstagramHashtagSource;
use App\Services\Trend\Sources\MastodonSource;
use App\Services\Trend\Sources\ThreadsSearchSource;
use App\Support\Trend\TrendScopes;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

function trendsTestIgAccount(Workspace|string $workspace): SocialAccount
{
    return SocialAccount::factory()->create([
        'workspace_id' => $workspace instanceof Workspace ? $workspace->id : $workspace,
        'platform' => Platform::InstagramFacebook,
        'platform_user_id' => '17841400000',
        'scopes' => ['instagram_basic'],
        'access_token' => 'page-token',
    ]);
}

function trendsTestThreadsAccount(Workspace|string $workspace, array $scopes = ['threads_basic', 'threads_keyword_search']): SocialAccount
{
    return SocialAccount::factory()->threads()->create([
        'workspace_id' => $workspace instanceof Workspace ? $workspace->id : $workspace,
        'scopes' => $scopes,
        'access_token' => 'threads-token',
    ]);
}

function trendsTestIgPost(string $caption, int $likes = 100, int $comments = 10, ?int $daysAgo = 1): array
{
    return ['caption' => $caption, 'like_count' => $likes, 'comments_count' => $comments, 'timestamp' => now()->subDays($daysAgo)->format('Y-m-d\TH:i:sO'), 'permalink' => 'https://www.instagram.com/p/'.str()->random(6).'/'];
}

/**
 * Why a source says it cannot be read: the reason of the exception it throws.
 */
function trendsTestReason(Closure $read): string
{
    try {
        $read();
    } catch (SourceUnavailableException $exception) {
        return $exception->reason;
    }

    return 'did not throw';
}

beforeEach(function () {
    $this->since = CarbonImmutable::now()->subDays(7);
});

test('instagram hashtags searches the first words as hashtags and reads their top posts', function () {
    config()->set('trypost.trends.sources.instagram_hashtags.max_queries', 2);
    $profile = TrendProfile::factory()->create(['keywords' => ['3D Print', 'Blender!', 'unused word']]);
    trendsTestIgAccount($profile->workspace);
    Http::fake([
        '*ig_hashtag_search*' => Http::response(['data' => [['id' => 'tag-1']]]),
        '*top_media*' => Http::response(['data' => [
            trendsTestIgPost("Zephyr print   timelapse\n#3dprint", 300, 20),
            ['caption' => 'Hidden likes', 'comments_count' => 7, 'timestamp' => now()->subDay()->format('Y-m-d\TH:i:sO'), 'permalink' => 'https://www.instagram.com/p/HIDDEN/'],
            trendsTestIgPost('Too old', 1, 1, 30),
            ['caption' => '', 'timestamp' => now()->format('Y-m-d\TH:i:sO'), 'permalink' => 'https://www.instagram.com/p/EMPTY/'],
        ]]),
    ]);

    $signals = app(InstagramHashtagSource::class)->signals($profile, $this->since);

    expect($signals)->toHaveCount(4)
        ->and($signals->pluck('outlet')->unique()->values()->all())->toBe(['instagram_hashtag:3dprint', 'instagram_hashtag:blender'])
        ->and($signals->first()->title)->toBe('Zephyr print timelapse #3dprint')
        ->and($signals->first()->engagement)->toBe(320)
        ->and($signals->first()->outletName)->toBe('#3dprint')
        ->and($signals[1]->engagement)->toBe(7);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'ig_hashtag_search') && $request['q'] === '3dprint' && $request['user_id'] === '17841400000' && $request->hasHeader('Authorization', 'Bearer page-token'));
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'tag-1/top_media') && str_contains($request['fields'], 'like_count'));
    Http::assertNotSent(fn (Request $request) => ($request['q'] ?? null) === 'unusedword');
});

test('instagram hashtags reports a refused permission', function () {
    $profile = TrendProfile::factory()->create(['keywords' => ['blender']]);
    trendsTestIgAccount($profile->workspace);
    Http::fake(['*' => Http::response(['error' => ['code' => 10, 'message' => '(#10) Application does not have permission for this action']], 400)]);

    expect(trendsTestReason(fn () => app(InstagramHashtagSource::class)->signals($profile, $this->since)))->toBe('permission');
});

test('instagram hashtags reports any other failure as failed', function () {
    $profile = TrendProfile::factory()->create(['keywords' => ['blender']]);
    trendsTestIgAccount($profile->workspace);
    Http::fake(['*' => Http::response(['error' => ['code' => 4, 'message' => 'Rate limit']], 400)]);

    expect(trendsTestReason(fn () => app(InstagramHashtagSource::class)->signals($profile, $this->since)))->toBe('failed');
});

test('instagram hashtags keeps the posts of the hashtags that worked', function () {
    $profile = TrendProfile::factory()->create(['keywords' => ['blender', 'zbrush']]);
    trendsTestIgAccount($profile->workspace);
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'ig_hashtag_search')) {
            return $request['q'] === 'blender' ? Http::response(['error' => ['code' => 4]], 400) : Http::response(['data' => [['id' => 'tag-2']]]);
        }

        return Http::response(['data' => [trendsTestIgPost('Zbrush sculpt')]]);
    });

    expect(app(InstagramHashtagSource::class)->signals($profile, $this->since)->pluck('outlet')->all())->toBe(['instagram_hashtag:zbrush']);
});

test('instagram hashtags asks for nothing without a channel or words', function () {
    $profile = TrendProfile::factory()->create(['keywords' => ['blender']]);
    $empty = TrendProfile::factory()->create(['keywords' => []]);
    trendsTestIgAccount($empty->workspace);
    Http::fake();

    expect(app(InstagramHashtagSource::class)->signals($profile, $this->since))->toBeEmpty()
        ->and(app(InstagramHashtagSource::class)->signals($empty, $this->since))->toBeEmpty();

    Http::assertNothingSent();
});

test('threads search reads the most popular posts through a channel that was granted search', function () {
    $profile = TrendProfile::factory()->create(['keywords' => ['blender', 'zbrush']]);
    trendsTestThreadsAccount($profile->workspace);
    Http::fake([config('trypost.platforms.threads.graph_api').'/keyword_search*' => Http::response(['data' => [
        ['id' => '1', 'text' => "Blender 5   is out\nfinally", 'permalink' => 'https://www.threads.net/@a/post/1', 'timestamp' => now()->subHours(3)->format('Y-m-d\TH:i:sO'), 'username' => 'a'],
        ['id' => '2', 'text' => 'Old post', 'permalink' => 'https://www.threads.net/@a/post/2', 'timestamp' => now()->subDays(30)->format('Y-m-d\TH:i:sO')],
        ['id' => '3', 'text' => '', 'permalink' => 'https://www.threads.net/@a/post/3', 'timestamp' => now()->format('Y-m-d\TH:i:sO')],
    ]])]);

    $signals = app(ThreadsSearchSource::class)->signals($profile, $this->since);

    expect($signals)->toHaveCount(2)
        ->and($signals->first()->title)->toBe('Blender 5 is out finally')
        ->and($signals->first()->outlet)->toBe('threads')
        ->and($signals->first()->engagement)->toBeNull();

    Http::assertSent(fn (Request $request) => $request['q'] === 'blender' && $request['search_type'] === 'TOP' && $request->hasHeader('Authorization', 'Bearer threads-token'));
});

test('threads search needs a channel that was granted the search permission', function () {
    $profile = TrendProfile::factory()->create(['keywords' => ['blender']]);
    trendsTestThreadsAccount($profile->workspace, ['threads_basic']);
    Http::fake();

    expect(ThreadsSearchSource::usableAccount($profile->workspace_id))->toBeNull()
        ->and(app(ThreadsSearchSource::class)->signals($profile, $this->since))->toBeEmpty()
        ->and(ExternalSource::ThreadsSearch->isAvailableFor($profile->workspace))->toBeFalse();

    Http::assertNothingSent();

    trendsTestThreadsAccount($profile->workspace);

    expect(ExternalSource::ThreadsSearch->isAvailableFor($profile->workspace))->toBeTrue();
});

test('threads search reports a refusal as a missing permission', function () {
    $profile = TrendProfile::factory()->create(['keywords' => ['blender']]);
    trendsTestThreadsAccount($profile->workspace);
    Http::fake(['*' => Http::response(['error' => ['message' => 'forbidden']], 403)]);

    expect(trendsTestReason(fn () => app(ThreadsSearchSource::class)->signals($profile, $this->since)))->toBe('permission');
});

test('threads asks for the search permission only when the env lists it', function () {
    expect(TrendScopes::for(Platform::Threads))->toBe([]);

    config()->set('trypost.trends.sources.threads_search.scopes', ['threads_keyword_search']);

    expect(TrendScopes::for(Platform::Threads))->toBe(['threads_keyword_search'])
        ->and(TrendScopes::for(Platform::Instagram))->toBe([]);
});

test('bluesky keeps the trending topics that match the words, with their post count', function () {
    $profile = TrendProfile::factory()->create(['keywords' => ['blender']]);
    Http::fake(['public.api.bsky.app/*' => Http::response(['trends' => [
        ['displayName' => 'Blender 5 released', 'description' => 'The 3D tool adds a new renderer.', 'link' => '/profile/did:plc:x/feed/abc', 'startedAt' => now()->subHours(5)->toIso8601String(), 'postCount' => 1200, 'status' => 'hot'],
        ['displayName' => 'Election night', 'description' => 'Results come in.', 'link' => '/profile/did:plc:x/feed/def', 'startedAt' => now()->subHours(5)->toIso8601String(), 'postCount' => 90000],
        ['displayName' => 'Blender old news', 'description' => '', 'link' => '/profile/did:plc:x/feed/ghi', 'startedAt' => now()->subDays(30)->toIso8601String(), 'postCount' => 10],
    ]])]);

    $signals = app(BlueskySource::class)->signals($profile, $this->since);

    expect($signals)->toHaveCount(1)
        ->and($signals->first()->title)->toBe('Blender 5 released: The 3D tool adds a new renderer.')
        ->and($signals->first()->url)->toBe('https://bsky.app/profile/did:plc:x/feed/abc')
        ->and($signals->first()->engagement)->toBe(1200)
        ->and($signals->first()->outletName)->toBe('Bluesky');
});

test('bluesky is unavailable when the endpoint does not answer', function () {
    $profile = TrendProfile::factory()->create(['keywords' => ['blender']]);
    Http::fake(['*' => Http::response('down', 500)]);

    expect(trendsTestReason(fn () => app(BlueskySource::class)->signals($profile, $this->since)))->toBe('failed');
});

test('mastodon keeps hashtags and links that match the words', function () {
    $profile = TrendProfile::factory()->create(['keywords' => ['3d print', 'ai']]);
    $day = fn (int $daysAgo, int $uses): array => ['day' => (string) now()->subDays($daysAgo)->startOfDay()->getTimestamp(), 'uses' => (string) $uses, 'accounts' => '1'];
    Http::fake([
        'mastodon.social/api/v1/trends/tags*' => Http::response([
            ['name' => '3DPrinting', 'url' => 'https://mastodon.social/tags/3DPrinting', 'history' => [$day(0, 50), $day(1, 30), $day(2, 20), $day(3, 999)]],
            ['name' => 'SilentSunday', 'url' => 'https://mastodon.social/tags/SilentSunday', 'history' => [$day(0, 900)]],
            ['name' => 'ai', 'url' => 'https://mastodon.social/tags/ai', 'history' => [$day(0, 10)]],
        ]),
        'mastodon.social/api/v1/trends/links*' => Http::response([
            ['url' => 'https://example.com/a', 'title' => 'A new 3d print slicer', 'history' => [['day' => '1', 'accounts' => '140', 'uses' => '200']]],
            ['url' => 'https://example.com/b', 'title' => 'Unrelated story', 'history' => [['day' => '1', 'accounts' => '300', 'uses' => '400']]],
        ]),
    ]);

    $signals = app(MastodonSource::class)->signals($profile, $this->since);

    expect($signals->pluck('title')->all())->toBe(['#3DPrinting', 'A new 3d print slicer'])
        ->and($signals->first()->engagement)->toBe(100)
        ->and($signals->last()->engagement)->toBe(140)
        ->and($signals->first()->outlet)->toBe('mastodon');
});

test('mastodon keeps one list when the other fails', function () {
    $profile = TrendProfile::factory()->create(['keywords' => ['3d print']]);
    Http::fake([
        'mastodon.social/api/v1/trends/tags*' => Http::response('down', 500),
        'mastodon.social/api/v1/trends/links*' => Http::response([['url' => 'https://example.com/a', 'title' => 'A new 3d print slicer', 'history' => [['accounts' => '5']]]]),
    ]);

    expect(app(MastodonSource::class)->signals($profile, $this->since))->toHaveCount(1);
});

test('mastodon is unavailable when neither list can be read', function () {
    $profile = TrendProfile::factory()->create(['keywords' => ['3d print']]);
    Http::fake(['*' => Http::response('down', 500)]);

    expect(trendsTestReason(fn () => app(MastodonSource::class)->signals($profile, $this->since)))->toBe('failed');
});

test('the meta sources are offered only to a workspace that can use them, and start on', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['account_id' => $user->account_id, 'user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    $keys = fn () => collect(ExternalSource::availableFor($workspace))->map->value->all();

    expect($keys())->toBe(['google_trends', 'hacker_news', 'bluesky', 'mastodon']);

    trendsTestIgAccount($workspace);
    trendsTestThreadsAccount($workspace);

    expect($keys())->toBe(['google_trends', 'hacker_news', 'instagram_hashtags', 'threads_search', 'bluesky', 'mastodon']);

    $this->actingAs($user->fresh())
        ->get(route('app.trends.profile.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('externalSources.2', ['key' => 'instagram_hashtags', 'default' => true])
            ->where('externalSources.3', ['key' => 'threads_search', 'default' => true]));

    $input = ['niche' => 'Drones', 'goal' => 'grow', 'keywords' => ['drone'], 'formats' => ['image'], 'posts_per_week' => 3, 'region' => 'US'];

    $this->actingAs($user->fresh())
        ->put(route('app.trends.profile.update'), [...$input, 'external_sources' => ['instagram_hashtags', 'bluesky']])
        ->assertRedirect(route('app.trends.index'));

    expect($workspace->fresh()->trendProfile->external_sources)->toBe(['instagram_hashtags', 'bluesky']);

    $other = Workspace::factory()->create(['account_id' => $user->account_id, 'user_id' => $user->id]);
    $other->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $other->id]);

    $this->actingAs($user->fresh())
        ->putJson(route('app.trends.profile.update'), [...$input, 'external_sources' => ['instagram_hashtags']])
        ->assertJsonValidationErrors('external_sources.0');
});

test('a source that cannot be read is noted for the user and the others still give topics', function () {
    $profile = TrendProfile::factory()->create(['keywords' => [], 'external_sources' => ['instagram_hashtags', 'bluesky']]);
    trendsTestIgAccount($profile->workspace);
    $a = RssFeed::factory()->create(['workspace_id' => $profile->workspace_id]);
    $b = RssFeed::factory()->create(['workspace_id' => $profile->workspace_id]);
    $profile->rssFeeds()->attach([$a->id, $b->id]);
    RssFeedItem::factory()->create(['rss_feed_id' => $a->id, 'title' => 'Zephyr drone unveiled', 'url' => 'https://example.com/a', 'published_at' => now()->subHours(2)]);
    RssFeedItem::factory()->create(['rss_feed_id' => $b->id, 'title' => 'Zephyr drone arrives', 'url' => 'https://example.com/b', 'published_at' => now()->subHours(2)]);
    $profile->update(['keywords' => ['zephyr']]);
    Http::fake(function (Request $request) {
        return str_contains($request->url(), 'graph.facebook.com')
            ? Http::response(['error' => ['code' => 10]], 400)
            : Http::response('down', 500);
    });

    AnalyzeTrends::execute($profile);

    expect(TrendTopic::query()->pluck('key')->all())->toContain('zephyr drone')
        ->and($profile->fresh()->source_issues)->toEqual(['instagram_hashtags' => 'permission', 'bluesky' => 'failed']);
});

test('a source that reads again clears its note', function () {
    $profile = TrendProfile::factory()->create(['external_sources' => ['bluesky'], 'source_issues' => ['bluesky' => 'failed']]);
    Http::fake(['public.api.bsky.app/*' => Http::response(['trends' => []])]);

    AnalyzeTrends::execute($profile);

    expect($profile->fresh()->source_issues)->toBeNull();
});

test('the trends page lists the sources that could not be read', function () {
    $profile = TrendProfile::factory()->create(['source_issues' => ['threads_search' => 'permission']]);
    $user = workspaceMember($profile->workspace);

    $this->actingAs($user)
        ->get(route('app.trends.index'))
        ->assertInertia(fn (Assert $page) => $page->where('profile.source_issues', ['threads_search' => 'permission']));

    $profile->update(['source_issues' => null]);

    $this->actingAs($user->fresh())
        ->get(route('app.trends.index'))
        ->assertInertia(fn (Assert $page) => $page->where('profile.source_issues', []));
});
