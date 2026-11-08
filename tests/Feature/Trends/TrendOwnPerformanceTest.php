<?php

declare(strict_types=1);

use App\Actions\Analytics\ReadWorkspacePostPerformance;
use App\Actions\Trend\AnalyzeTrends;
use App\Dto\Trend\PostPerformance;
use App\Dto\Trend\TrendSignal;
use App\Enums\Analytics\PublicationContentType;
use App\Enums\Trend\PostFormat;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\RssFeed;
use App\Models\RssFeedItem;
use App\Models\TrendProfile;
use App\Models\TrendTopic;
use App\Models\Workspace;
use App\Services\Trend\TopicExtractor;
use App\Services\Trend\TrendScorer;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

function trendsTestPost(Workspace|string $workspace, string $excerpt, int $engagement, int $reach, PublicationContentType $type = PublicationContentType::Image, int $daysAgo = 5): AnalyticsPublication
{
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $workspace instanceof Workspace ? $workspace->id : $workspace,
        'excerpt' => $excerpt,
        'content_type' => $type,
        'provider_published_at' => now()->subDays($daysAgo),
    ]);

    AnalyticsPublicationDailySnapshot::factory()->create([
        'publication_id' => $publication->id,
        'date' => today(),
        'engagement_count' => $engagement,
        'exposure_count' => $reach,
    ]);

    return $publication;
}

test('the performance read keeps recent posts with a measured reach, per workspace', function () {
    $profile = TrendProfile::factory()->create();
    trendsTestPost($profile->workspace, 'Zephyr drone first look', 50, 500);
    trendsTestPost($profile->workspace, 'No reach measured', 10, 0);
    trendsTestPost($profile->workspace, 'Too old to say anything', 90, 100, daysAgo: 200);
    trendsTestPost(Workspace::factory()->create(), 'Somebody else', 90, 100);

    $performance = ReadWorkspacePostPerformance::execute($profile->workspace_id, CarbonImmutable::now());

    expect($performance->posts)->toHaveCount(1)
        ->and($performance->posts[0]['excerpt'])->toBe('Zephyr drone first look')
        ->and($performance->posts[0]['rate'])->toBe(0.1)
        ->and($performance->posts[0]['format'])->toBe(PostFormat::Image);
});

test('a subject is compared with the average of the posts that mention it', function () {
    $performance = new PostPerformance([
        ['excerpt' => 'Zephyr drone first look', 'format' => PostFormat::Image, 'rate' => 0.30],
        ['excerpt' => 'Our Zephyr drone setup', 'format' => PostFormat::Image, 'rate' => 0.10],
        ['excerpt' => 'Weekend plans', 'format' => PostFormat::Text, 'rate' => 0.05],
        ['excerpt' => 'Quarterly numbers', 'format' => PostFormat::Text, 'rate' => 0.05],
    ]);
    $extractor = app(TopicExtractor::class);

    expect($performance->forTerm('zephyr drone', $extractor))->toBe(['posts' => 2, 'ratio' => 160])
        ->and($performance->forTerm('nimbus router', $extractor))->toBeNull()
        ->and((new PostPerformance([]))->forTerm('zephyr drone', $extractor))->toBeNull();
});

test('a format needs enough posts to count, and a small difference counts as none', function () {
    $post = fn (PostFormat $format, float $rate): array => ['excerpt' => 'x', 'format' => $format, 'rate' => $rate];

    $performance = new PostPerformance([
        $post(PostFormat::Video, 0.30), $post(PostFormat::Video, 0.30), $post(PostFormat::Video, 0.30),
        $post(PostFormat::Text, 0.10), $post(PostFormat::Text, 0.10), $post(PostFormat::Text, 0.10),
        $post(PostFormat::Image, 0.20), $post(PostFormat::Image, 0.20),
        $post(PostFormat::Carousel, 0.20), $post(PostFormat::Carousel, 0.20), $post(PostFormat::Carousel, 0.20),
    ]);

    $lifts = $performance->formatLifts();

    expect($lifts)->toHaveKeys(['video', 'text', 'carousel'])
        ->not->toHaveKey('image')
        ->and($lifts['video'])->toBeGreaterThan(1.1)
        ->and($lifts['text'])->toBeLessThan(0.9)
        ->and($lifts['carousel'])->toBe(1.0);
});

test('subjects the audience already liked rank above equally covered ones', function () {
    $profile = TrendProfile::factory()->create(['keywords' => []]);
    $now = CarbonImmutable::now();
    $signal = fn (string $outlet, string $title): TrendSignal => new TrendSignal($outlet, $outlet, $title, 'https://example.com/'.str()->uuid(), $now->subHours(2));
    $signals = collect([
        $signal('a', 'Nimbus router launched'), $signal('b', 'Nimbus router reviewed'),
        $signal('a', 'Zephyr drone unveiled'), $signal('b', 'Zephyr drone arrives'),
        $signal('a', 'Cobalt lamp launched'), $signal('b', 'Cobalt lamp reviewed'),
    ]);
    $performance = new PostPerformance([
        ['excerpt' => 'Our Zephyr drone', 'format' => PostFormat::Image, 'rate' => 0.40],
        ['excerpt' => 'Cobalt lamp thoughts', 'format' => PostFormat::Image, 'rate' => 0.02],
        ['excerpt' => 'Something else', 'format' => PostFormat::Image, 'rate' => 0.06],
    ]);

    $ranked = collect(app(TrendScorer::class)->rank($profile, $signals, $now, $performance))->keyBy('key');

    expect($ranked->keys()->all())->toBe(['zephyr drone', 'nimbus router', 'cobalt lamp'])
        ->and($ranked['zephyr drone']['own_posts'])->toBe(1)
        ->and($ranked['zephyr drone']['own_ratio'])->toBeGreaterThan(100)
        ->and($ranked['cobalt lamp']['own_ratio'])->toBeLessThan(100)
        ->and($ranked['nimbus router']['own_posts'])->toBe(0)
        ->and($ranked['nimbus router']['own_ratio'])->toBeNull();
});

test('the analysis stores how the audience did on a topic and reorders the formats by what worked', function () {
    $profile = TrendProfile::factory()->create(['keywords' => [], 'goal' => 'traffic', 'formats' => ['text', 'image']]);
    $a = RssFeed::factory()->create(['workspace_id' => $profile->workspace_id]);
    $b = RssFeed::factory()->create(['workspace_id' => $profile->workspace_id]);
    $profile->rssFeeds()->attach([$a->id, $b->id]);
    RssFeedItem::factory()->create(['rss_feed_id' => $a->id, 'title' => 'Zephyr drone unveiled', 'url' => 'https://example.com/a', 'published_at' => now()->subHours(2)]);
    RssFeedItem::factory()->create(['rss_feed_id' => $b->id, 'title' => 'Zephyr drone arrives', 'url' => 'https://example.com/b', 'published_at' => now()->subHours(2)]);

    foreach ([0.30, 0.34, 0.32] as $rate) {
        trendsTestPost($profile->workspace, 'A photo of the Zephyr drone', (int) ($rate * 1000), 1000);
    }
    foreach ([0.05, 0.04, 0.06] as $rate) {
        trendsTestPost($profile->workspace, 'Quarterly update', (int) ($rate * 1000), 1000, PublicationContentType::Text);
    }

    AnalyzeTrends::execute($profile);

    $topic = TrendTopic::query()->where('key', 'zephyr drone')->sole();

    expect($topic->own_posts)->toBe(3)
        ->and($topic->own_ratio)->toBeGreaterThan(150)
        // Traffic prefers text first, but this audience did far better on images.
        ->and($topic->formats)->toBe(['image', 'text']);
});

test('without measured posts nothing changes', function () {
    $profile = TrendProfile::factory()->create(['keywords' => [], 'goal' => 'traffic', 'formats' => ['text', 'image']]);
    $a = RssFeed::factory()->create(['workspace_id' => $profile->workspace_id]);
    $b = RssFeed::factory()->create(['workspace_id' => $profile->workspace_id]);
    $profile->rssFeeds()->attach([$a->id, $b->id]);
    RssFeedItem::factory()->create(['rss_feed_id' => $a->id, 'title' => 'Zephyr drone unveiled', 'url' => 'https://example.com/a', 'published_at' => now()->subHours(2)]);
    RssFeedItem::factory()->create(['rss_feed_id' => $b->id, 'title' => 'Zephyr drone arrives', 'url' => 'https://example.com/b', 'published_at' => now()->subHours(2)]);

    AnalyzeTrends::execute($profile);

    $topic = TrendTopic::query()->sole();

    expect($topic->own_posts)->toBe(0)
        ->and($topic->own_ratio)->toBeNull()
        ->and($topic->formats)->toBe(['text', 'image']);
});

test('the trends page tells the user when a topic did well before', function () {
    $profile = TrendProfile::factory()->create();
    $user = workspaceMember($profile->workspace);
    TrendTopic::factory()->create(['workspace_id' => $profile->workspace_id, 'own_posts' => 2, 'own_ratio' => 180]);

    $this->actingAs($user)
        ->get(route('app.trends.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('topics.0.own_posts', 2)
            ->where('topics.0.own_ratio', 180));
});
