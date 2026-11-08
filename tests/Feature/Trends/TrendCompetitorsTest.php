<?php

declare(strict_types=1);

use App\Actions\Trend\AnalyzeTrends;
use App\Enums\SocialAccount\Platform;
use App\Models\RssFeed;
use App\Models\RssFeedItem;
use App\Models\SocialAccount;
use App\Models\TrendProfile;
use App\Models\TrendTopic;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Trend\Sources\InstagramCompetitorSource;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

function trendsTestInstagramAccount(Workspace|string $workspace, array $overrides = []): SocialAccount
{
    return SocialAccount::factory()->create([
        'workspace_id' => $workspace instanceof Workspace ? $workspace->id : $workspace,
        'platform' => Platform::InstagramFacebook,
        'platform_user_id' => '17841400000',
        'scopes' => ['instagram_basic', 'pages_read_engagement'],
        ...$overrides,
    ]);
}

function trendsTestDiscovery(array $posts): array
{
    return ['business_discovery' => ['username' => 'rival', 'media' => ['data' => $posts]]];
}

function trendsTestRivalPost(string $caption, int $likes = 100, int $comments = 10, ?string $timestamp = null, string $permalink = 'https://www.instagram.com/p/AAA/'): array
{
    return [
        'caption' => $caption,
        'like_count' => $likes,
        'comments_count' => $comments,
        'timestamp' => $timestamp ?? now()->subHours(5)->format('Y-m-d\TH:i:sO'),
        'permalink' => $permalink,
    ];
}

beforeEach(function () {
    $this->since = CarbonImmutable::now()->subDays(7);
});

test('the watched accounts are read through the workspace channel, one outlet each', function () {
    $profile = TrendProfile::factory()->create(['competitors' => ['@Rival', 'other.brand']]);
    trendsTestInstagramAccount($profile->workspace, ['access_token' => 'page-token']);
    Http::fake([
        '*' => Http::response(trendsTestDiscovery([
            trendsTestRivalPost("Zephyr drone   first look\n#drones", 400, 20),
            trendsTestRivalPost('Too old', 1, 1, now()->subDays(30)->format('Y-m-d\TH:i:sO'), 'https://www.instagram.com/p/OLD/'),
            trendsTestRivalPost('', 1, 1, null, 'https://www.instagram.com/p/EMPTY/'),
        ])),
    ]);

    $signals = app(InstagramCompetitorSource::class)->signals($profile, $this->since);

    expect($signals)->toHaveCount(2)
        ->and($signals->pluck('outlet')->unique()->sort()->values()->all())->toBe(['instagram:other.brand', 'instagram:rival'])
        ->and($signals->first()->outletName)->toBe('@rival')
        ->and($signals->first()->title)->toBe('Zephyr drone first look #drones')
        ->and($signals->first()->engagement)->toBe(420)
        ->and($signals->first()->url)->toBe('https://www.instagram.com/p/AAA/');

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer page-token')
        && str_contains($request->url(), '/17841400000')
        && str_contains($request['fields'], 'business_discovery.username(rival)'));
});

test('an account that is refused leaves the other watched accounts', function () {
    $profile = TrendProfile::factory()->create(['competitors' => ['personal.account', 'rival']]);
    trendsTestInstagramAccount($profile->workspace);
    Http::fake(function (Request $request) {
        return str_contains($request['fields'], 'personal.account')
            ? Http::response(['error' => ['message' => 'Unsupported get request']], 400)
            : Http::response(trendsTestDiscovery([trendsTestRivalPost('Zephyr drone first look')]));
    });

    $signals = app(InstagramCompetitorSource::class)->signals($profile, $this->since);

    expect($signals->pluck('outlet')->unique()->all())->toBe(['instagram:rival']);
});

test('nothing is asked without a usable channel or without accounts to watch', function () {
    $profile = TrendProfile::factory()->create(['competitors' => ['rival']]);
    $empty = TrendProfile::factory()->create(['competitors' => []]);

    trendsTestInstagramAccount($profile->workspace, ['scopes' => ['pages_read_engagement']]);
    trendsTestInstagramAccount(Workspace::factory()->create());
    SocialAccount::factory()->disconnected()->create(['workspace_id' => $profile->workspace_id, 'platform' => Platform::InstagramFacebook, 'scopes' => ['instagram_basic']]);
    trendsTestInstagramAccount($empty->workspace);

    // Creating a channel may call out on its own; only what the source asks counts.
    Http::fake();

    expect(InstagramCompetitorSource::usableAccount($profile->workspace_id))->toBeNull()
        ->and(app(InstagramCompetitorSource::class)->signals($profile, $this->since))->toBeEmpty()
        ->and(app(InstagramCompetitorSource::class)->signals($empty, $this->since))->toBeEmpty();

    Http::assertNothingSent();
});

test('only as many accounts as the limit are read', function () {
    config()->set('trypost.trends.sources.instagram_competitors.max_accounts', 2);
    $profile = TrendProfile::factory()->create(['competitors' => ['one', 'two', 'three']]);
    trendsTestInstagramAccount($profile->workspace);
    Http::fake(['*' => Http::response(trendsTestDiscovery([]))]);

    app(InstagramCompetitorSource::class)->signals($profile, $this->since);

    Http::assertSentCount(2);
});

test('watched accounts corroborate a topic next to a feed', function () {
    $profile = TrendProfile::factory()->create(['keywords' => [], 'competitors' => ['rival']]);
    trendsTestInstagramAccount($profile->workspace);
    $feed = RssFeed::factory()->create(['workspace_id' => $profile->workspace_id]);
    $profile->rssFeeds()->attach($feed->id);
    RssFeedItem::factory()->create(['rss_feed_id' => $feed->id, 'title' => 'Zephyr drone unveiled in Berlin', 'url' => 'https://example.com/a', 'published_at' => now()->subHours(2)]);
    Http::fake(['*' => Http::response(trendsTestDiscovery([trendsTestRivalPost('Our Zephyr drone review')]))]);

    AnalyzeTrends::execute($profile);

    $topic = TrendTopic::query()->where('key', 'zephyr drone')->sole();

    expect($topic->outlet_count)->toBe(2)
        ->and(collect($topic->evidence)->pluck('source')->all())->toContain('@rival');
});

test('the switch in the app turns the watched accounts off', function () {
    config()->set('trypost.trends.sources.instagram_competitors.enabled', false);
    $profile = TrendProfile::factory()->create(['competitors' => ['rival']]);
    trendsTestInstagramAccount($profile->workspace);
    Http::fake();

    AnalyzeTrends::execute($profile);

    Http::assertNothingSent();
});

test('the setup offers watching accounts only to a workspace with a usable channel', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['account_id' => $user->account_id, 'user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    $this->actingAs($user->fresh())
        ->get(route('app.trends.profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('canWatchCompetitors', false)->where('maxCompetitors', 10));

    trendsTestInstagramAccount($workspace);

    $this->actingAs($user->fresh())
        ->get(route('app.trends.profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('canWatchCompetitors', true));
});

test('watched accounts are saved without the @ and in lowercase, and must look like usernames', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['account_id' => $user->account_id, 'user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $input = ['niche' => 'Drones', 'goal' => 'grow', 'keywords' => ['drone'], 'formats' => ['image'], 'posts_per_week' => 3, 'region' => 'US'];

    $this->actingAs($user->fresh())
        ->putJson(route('app.trends.profile.update'), [...$input, 'competitors' => ['@Rival.Brand', 'other_1']])
        ->assertRedirect(route('app.trends.index'));

    expect($workspace->fresh()->trendProfile->competitors)->toBe(['rival.brand', 'other_1']);

    $this->actingAs($user->fresh())
        ->putJson(route('app.trends.profile.update'), [...$input, 'competitors' => ['not a handle!', 'Rival', 'rival']])
        ->assertJsonValidationErrors(['competitors.0', 'competitors.2']);

    $this->actingAs($user->fresh())
        ->putJson(route('app.trends.profile.update'), [...$input, 'competitors' => array_map(fn (int $n): string => "account{$n}", range(1, 11))])
        ->assertJsonValidationErrors('competitors');
});
