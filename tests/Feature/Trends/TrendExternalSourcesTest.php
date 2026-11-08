<?php

declare(strict_types=1);

use App\Actions\Trend\AnalyzeTrends;
use App\Dto\Trend\TrendSignal;
use App\Enums\Trend\ExternalSource;
use App\Models\RssFeed;
use App\Models\RssFeedItem;
use App\Models\TrendProfile;
use App\Models\TrendTopic;
use App\Services\Trend\Sources\GoogleTrendsSource;
use App\Services\Trend\Sources\HackerNewsSource;
use App\Services\Trend\Sources\YouTubeSource;
use App\Services\Trend\TrendScorer;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->since = CarbonImmutable::now()->subDays(7);
});

function trendsTestGoogleFeed(): string
{
    $published = now()->subHour()->toRfc2822String();
    $old = now()->subDays(30)->toRfc2822String();

    return <<<XML
    <?xml version="1.0" encoding="UTF-8"?>
    <rss xmlns:ht="https://trends.google.com/trending/rss" version="2.0"><channel>
      <item>
        <title>zephyr</title>
        <ht:approx_traffic>20000+</ht:approx_traffic>
        <link>https://trends.google.com/trending/rss?geo=GB</link>
        <pubDate>{$published}</pubDate>
        <ht:news_item><ht:news_item_title>Zephyr drone unveiled in Berlin</ht:news_item_title><ht:news_item_url>https://news.example.com/a</ht:news_item_url></ht:news_item>
        <ht:news_item><ht:news_item_title>Why the zephyr drone matters</ht:news_item_title><ht:news_item_url>https://news.example.com/b</ht:news_item_url></ht:news_item>
        <ht:news_item><ht:news_item_title>Zephyr drone price</ht:news_item_title><ht:news_item_url>https://news.example.com/c</ht:news_item_url></ht:news_item>
        <ht:news_item><ht:news_item_title>Zephyr drone review</ht:news_item_title><ht:news_item_url>https://news.example.com/d</ht:news_item_url></ht:news_item>
      </item>
      <item>
        <title>football score</title>
        <ht:approx_traffic>500000+</ht:approx_traffic>
        <link>https://trends.google.com/trending/rss?geo=GB</link>
        <pubDate>{$published}</pubDate>
        <ht:news_item><ht:news_item_title>Late goal decides the derby</ht:news_item_title><ht:news_item_url>https://news.example.com/e</ht:news_item_url></ht:news_item>
      </item>
      <item>
        <title>zephyr old</title>
        <ht:approx_traffic>100+</ht:approx_traffic>
        <link>https://trends.google.com/trending/rss?geo=GB</link>
        <pubDate>{$old}</pubDate>
        <ht:news_item><ht:news_item_title>Zephyr drone, a month ago</ht:news_item_title><ht:news_item_url>https://news.example.com/f</ht:news_item_url></ht:news_item>
      </item>
    </channel></rss>
    XML;
}

test('google trends keeps the trends that match the words, with their headlines and traffic', function () {
    Http::fake(['trends.google.com/*' => Http::response(trendsTestGoogleFeed())]);
    $profile = TrendProfile::factory()->create(['keywords' => ['zephyr drone'], 'region' => 'GB']);

    $signals = app(GoogleTrendsSource::class)->signals($profile, $this->since);

    expect($signals)->toHaveCount(3)
        ->and($signals->pluck('title')->all())->toBe(['Zephyr drone unveiled in Berlin', 'Why the zephyr drone matters', 'Zephyr drone price'])
        ->and($signals->first()->outlet)->toBe('google_trends')
        ->and($signals->first()->engagement)->toBe(20000)
        ->and($signals->first()->url)->toBe('https://news.example.com/a');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'geo=GB'));
});

test('google trends asks for nothing when the profile has no words', function () {
    Http::fake();
    $profile = TrendProfile::factory()->create(['keywords' => []]);

    expect(app(GoogleTrendsSource::class)->signals($profile, $this->since))->toBeEmpty();

    Http::assertNothingSent();
});

test('hacker news searches the first words and counts points and comments', function () {
    config()->set('trypost.trends.sources.hacker_news.max_queries', 2);
    Http::fake(['hn.algolia.com/*' => Http::response(['hits' => [
        ['title' => 'Zephyr drone ships', 'url' => 'https://example.com/zephyr', 'objectID' => '1', 'created_at_i' => now()->subHours(2)->getTimestamp(), 'points' => 120, 'num_comments' => 30],
        ['title' => 'Ask HN: zephyr drone?', 'url' => null, 'objectID' => '2', 'created_at_i' => now()->subHours(3)->getTimestamp(), 'points' => 5, 'num_comments' => 1],
    ]])]);
    $profile = TrendProfile::factory()->create(['keywords' => ['zephyr', 'drone', 'unused']]);

    $signals = app(HackerNewsSource::class)->signals($profile, $this->since);

    expect($signals)->toHaveCount(4)
        ->and($signals->first()->engagement)->toBe(150)
        ->and($signals[1]->url)->toBe('https://news.ycombinator.com/item?id=2')
        ->and($signals->first()->outletName)->toBe('Hacker News');

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => $request['query'] === 'zephyr' && $request['tags'] === 'story');
});

test('hacker news keeps the stories of the searches that worked', function () {
    Http::fake([
        'hn.algolia.com/*query=zephyr*' => Http::response([], 500),
        'hn.algolia.com/*' => Http::response(['hits' => [['title' => 'Drone story', 'url' => 'https://example.com/d', 'objectID' => '3', 'created_at_i' => now()->getTimestamp(), 'points' => 1, 'num_comments' => 0]]]),
    ]);
    $profile = TrendProfile::factory()->create(['keywords' => ['zephyr', 'drone']]);

    expect(app(HackerNewsSource::class)->signals($profile, $this->since)->pluck('title')->all())->toBe(['Drone story']);
});

test('youtube searches by views, reads the statistics and keeps the key out of the url', function () {
    config()->set('trypost.trends.sources.youtube.api_key', 'secret-key');
    Http::fake([
        '*/search*' => Http::response(['items' => [['id' => ['videoId' => 'abc']], ['id' => ['videoId' => 'def']]]]),
        '*/videos*' => Http::response(['items' => [
            ['id' => 'abc', 'snippet' => ['title' => 'Zephyr drone test', 'publishedAt' => now()->subDay()->toIso8601ZuluString()], 'statistics' => ['viewCount' => '90000']],
            ['id' => 'def', 'snippet' => ['title' => 'Zephyr drone unboxing', 'publishedAt' => now()->subDay()->toIso8601ZuluString()], 'statistics' => ['viewCount' => '1200']],
        ]]),
    ]);
    $profile = TrendProfile::factory()->create(['keywords' => ['zephyr drone'], 'region' => 'GB']);

    $signals = app(YouTubeSource::class)->signals($profile, $this->since);

    expect($signals)->toHaveCount(2)
        ->and($signals->first()->engagement)->toBe(90000)
        ->and($signals->first()->url)->toBe('https://www.youtube.com/watch?v=abc')
        ->and($signals->first()->outletName)->toBe('YouTube');

    Http::assertSent(fn (Request $request) => $request->hasHeader('x-goog-api-key', 'secret-key') && ! str_contains($request->url(), 'secret-key'));
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'search') && $request['order'] === 'viewCount' && $request['regionCode'] === 'GB');
});

test('youtube is not available without a key, or when switched off', function () {
    expect(ExternalSource::YouTube->isAvailable())->toBeFalse();

    config()->set('trypost.trends.sources.youtube.api_key', 'test-key');

    expect(ExternalSource::YouTube->isAvailable())->toBeTrue();

    config()->set('trypost.trends.sources.youtube.enabled', false);

    expect(ExternalSource::YouTube->isAvailable())->toBeFalse()
        ->and(ExternalSource::GoogleTrends->isAvailable())->toBeTrue();
});

test('a topic that draws more attention ranks above an equally covered one', function () {
    $profile = TrendProfile::factory()->create(['keywords' => []]);
    $now = CarbonImmutable::now();
    $signal = fn (string $outlet, string $title, int $engagement): TrendSignal => new TrendSignal($outlet, $outlet, $title, 'https://example.com/'.str()->uuid(), $now->subHours(2), $engagement);

    $ranked = app(TrendScorer::class)->rank($profile, collect([
        $signal('hacker_news', 'Nimbus router launched', 10),
        $signal('google_trends', 'Nimbus router reviewed', 10),
        $signal('hacker_news', 'Zephyr drone unveiled', 5000),
        $signal('google_trends', 'Zephyr drone arrives', 5000),
    ]), $now);

    expect(array_column($ranked, 'key'))->toBe(['zephyr drone', 'nimbus router'])
        ->and($ranked[0]['score'])->toBeGreaterThan($ranked[1]['score']);
});

test('a topic covered by a feed and by an external source counts as two outlets', function () {
    Http::fake(['hn.algolia.com/*' => Http::response(['hits' => [['title' => 'Zephyr drone ships', 'url' => 'https://example.com/zephyr', 'objectID' => '1', 'created_at_i' => now()->subHour()->getTimestamp(), 'points' => 80, 'num_comments' => 10]]])]);
    $profile = TrendProfile::factory()->create(['keywords' => ['zephyr'], 'external_sources' => ['hacker_news']]);
    $feed = RssFeed::factory()->create(['workspace_id' => $profile->workspace_id]);
    $profile->rssFeeds()->attach($feed->id);
    RssFeedItem::factory()->create(['rss_feed_id' => $feed->id, 'title' => 'Zephyr drone unveiled in Berlin', 'url' => 'https://example.com/a', 'published_at' => now()->subHours(2)]);

    AnalyzeTrends::execute($profile);

    $topic = TrendTopic::query()->where('key', 'zephyr drone')->sole();

    expect($topic->outlet_count)->toBe(2)
        ->and(collect($topic->evidence)->pluck('source')->all())->toEqualCanonicalizing(['Hacker News', $feed->display_title]);
});

test('an external source that fails does not cost the feeds their topics', function () {
    Http::fake(['*' => Http::response('down', 500)]);
    $profile = TrendProfile::factory()->create(['keywords' => [], 'external_sources' => ['hacker_news', 'google_trends']]);
    $a = RssFeed::factory()->create(['workspace_id' => $profile->workspace_id]);
    $b = RssFeed::factory()->create(['workspace_id' => $profile->workspace_id]);
    $profile->rssFeeds()->attach([$a->id, $b->id]);
    RssFeedItem::factory()->create(['rss_feed_id' => $a->id, 'title' => 'Zephyr drone unveiled', 'url' => 'https://example.com/a', 'published_at' => now()->subHours(2)]);
    RssFeedItem::factory()->create(['rss_feed_id' => $b->id, 'title' => 'Zephyr drone arrives', 'url' => 'https://example.com/b', 'published_at' => now()->subHours(2)]);

    AnalyzeTrends::execute($profile);

    expect(TrendTopic::query()->pluck('key')->all())->toBe(['zephyr drone'])
        ->and($profile->fresh()->analyzed_at)->not->toBeNull();
});

test('a source the app no longer offers is not read', function () {
    Http::fake();
    config()->set('trypost.trends.sources.hacker_news.enabled', false);
    $profile = TrendProfile::factory()->create(['external_sources' => ['hacker_news', 'youtube']]);

    AnalyzeTrends::execute($profile);

    Http::assertNothingSent();
});
