<?php

declare(strict_types=1);

use App\Actions\Trend\AnalyzeTrends;
use App\Enums\Trend\PostFormat;
use App\Models\SocialAccount;
use App\Models\TrendProfile;
use App\Models\TrendTopic;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Trend\Sources\ThreadsSearchSource;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function threadsTrackingAccount(Workspace|string $workspace): SocialAccount
{
    return SocialAccount::factory()->threads()->create([
        'workspace_id' => $workspace instanceof Workspace ? $workspace->id : $workspace,
        'scopes' => ['threads_basic', 'threads_keyword_search'],
        'access_token' => 'threads-token',
    ]);
}

/**
 * @return array<string, mixed>
 */
function threadsTrackingPost(string $id, string $text, string $mediaType, int $hoursAgo = 3): array
{
    return [
        'id' => $id,
        'text' => $text,
        'permalink' => "https://www.threads.net/@a/post/{$id}",
        'timestamp' => now()->subHours($hoursAgo)->format('Y-m-d\TH:i:sO'),
        'username' => 'a',
        'media_type' => $mediaType,
    ];
}

beforeEach(function () {
    $this->since = CarbonImmutable::now()->subDays(7);
});

test('threads search reads topic tags and watched creators, each its own outlet, with the post formats', function () {
    $profile = TrendProfile::factory()->create([
        'keywords' => ['3d printing'],
        'threads_topics' => ['3D Printing'],
        'threads_creators' => ['XunoDesign'],
    ]);
    threadsTrackingAccount($profile->workspace);
    Http::fake([config('trypost.platforms.threads.graph_api').'/keyword_search*' => function (Request $request) {
        return Http::response(['data' => match (true) {
            data_get($request->data(), 'search_mode') === 'TAG' => [threadsTrackingPost('tag', 'Lattice planter print', 'CAROUSEL_ALBUM')],
            filled(data_get($request->data(), 'author_username')) => [threadsTrackingPost('creator', 'New planter files', 'IMAGE')],
            default => [threadsTrackingPost('word', 'Big printer news', 'TEXT_POST')],
        }]);
    }]);

    $signals = app(ThreadsSearchSource::class)->signals($profile, $this->since)->keyBy('outlet');

    expect($signals->keys()->all())->toBe(['threads', 'threads_tag:3d printing', 'threads:xunodesign'])
        ->and($signals['threads']->format)->toBe(PostFormat::Text)
        ->and($signals['threads_tag:3d printing']->format)->toBe(PostFormat::Carousel)
        ->and($signals['threads_tag:3d printing']->outletName)->toBe('Threads #3D Printing')
        ->and($signals['threads:xunodesign']->format)->toBe(PostFormat::Image);

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request) => $request['q'] === '3D Printing' && data_get($request->data(), 'search_mode') === 'TAG' && $request['search_type'] === 'TOP');
    Http::assertSent(fn (Request $request) => $request['q'] === '3d printing' && data_get($request->data(), 'author_username') === 'xunodesign');
    Http::assertSent(fn (Request $request) => (int) $request['since'] === $this->since->getTimestamp());
});

test('threads search sends no more topic and creator queries than the config allows', function () {
    config()->set('trypost.trends.sources.threads_search.max_topics', 1);
    config()->set('trypost.trends.sources.threads_search.max_creators', 1);
    $profile = TrendProfile::factory()->create([
        'keywords' => ['printing'],
        'threads_topics' => ['3D Printing', 'Product Design'],
        'threads_creators' => ['one', 'two'],
    ]);
    threadsTrackingAccount($profile->workspace);
    Http::fake([config('trypost.platforms.threads.graph_api').'/keyword_search*' => Http::response(['data' => []])]);

    app(ThreadsSearchSource::class)->signals($profile, $this->since);

    Http::assertSentCount(3);
});

test('a topic leads with the format most of its top posts used, when the workspace makes it', function () {
    $profile = TrendProfile::factory()->create([
        'keywords' => ['3d printing'],
        'goal' => 'traffic',
        'formats' => ['text', 'image', 'video'],
        'external_sources' => ['threads_search'],
    ]);
    threadsTrackingAccount($profile->workspace);
    Http::fake([config('trypost.platforms.threads.graph_api').'/keyword_search*' => Http::response(['data' => [
        threadsTrackingPost('1', '3d printing a planter', 'VIDEO'),
        threadsTrackingPost('2', 'More 3d printing tips', 'VIDEO', 5),
        threadsTrackingPost('3', '3d printing on a budget', 'CAROUSEL_ALBUM', 6),
    ]])]);

    AnalyzeTrends::execute($profile);

    $topic = TrendTopic::query()->where('key', '3d printing')->sole();

    expect($topic->top_formats)->toEqual(['video' => 2, 'carousel' => 1])
        ->and($topic->formats)->toBe(['video', 'text']);
});

test('a format the workspace does not make never leads', function () {
    $profile = TrendProfile::factory()->create([
        'keywords' => ['3d printing'],
        'goal' => 'traffic',
        'formats' => ['text', 'image'],
        'external_sources' => ['threads_search'],
    ]);
    threadsTrackingAccount($profile->workspace);
    Http::fake([config('trypost.platforms.threads.graph_api').'/keyword_search*' => Http::response(['data' => [
        threadsTrackingPost('1', '3d printing a planter', 'VIDEO'),
        threadsTrackingPost('2', 'More 3d printing tips', 'VIDEO', 5),
    ]])]);

    AnalyzeTrends::execute($profile);

    expect(TrendTopic::query()->where('key', '3d printing')->sole()->formats)->toBe(['text', 'image']);
});

test('threads topics and creators are saved cleaned up, and must be valid', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['account_id' => $user->account_id, 'user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $input = ['niche' => '3D printing', 'goal' => 'grow', 'keywords' => ['3d printing'], 'formats' => ['image'], 'posts_per_week' => 3, 'region' => 'US'];

    $this->actingAs($user->fresh())
        ->putJson(route('app.trends.profile.update'), [...$input, 'threads_topics' => ['#3D Printing', 'Product Design'], 'threads_creators' => ['@XunoDesign']])
        ->assertRedirect(route('app.trends.index'));

    $profile = $workspace->fresh()->trendProfile;

    expect($profile->threads_topics)->toBe(['3D Printing', 'Product Design'])
        ->and($profile->threads_creators)->toBe(['xunodesign']);

    $this->actingAs($user->fresh())
        ->putJson(route('app.trends.profile.update'), [...$input, 'threads_topics' => ['Tools & Tips', 'a.b'], 'threads_creators' => ['not a handle!']])
        ->assertJsonValidationErrors(['threads_topics.0', 'threads_topics.1', 'threads_creators.0']);

    $this->actingAs($user->fresh())
        ->putJson(route('app.trends.profile.update'), [...$input, 'threads_topics' => array_map(fn (int $n): string => "topic {$n}", range(1, 6))])
        ->assertJsonValidationErrors('threads_topics');
});
