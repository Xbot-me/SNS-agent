<?php

declare(strict_types=1);

use App\Actions\Trend\AnalyzeTrends;
use App\Enums\Trend\TopicStatus;
use App\Models\RssFeed;
use App\Models\RssFeedItem;
use App\Models\TrendProfile;
use App\Models\TrendTopic;

beforeEach(function () {
    $this->profile = TrendProfile::factory()->create(['keywords' => [], 'formats' => ['text', 'image']]);
    $this->workspace = $this->profile->workspace;
    $this->feedA = RssFeed::factory()->create(['workspace_id' => $this->workspace->id]);
    $this->feedB = RssFeed::factory()->create(['workspace_id' => $this->workspace->id]);
    $this->profile->rssFeeds()->attach([$this->feedA->id, $this->feedB->id]);
});

function trendsTestItem(RssFeed $feed, string $title, int $hoursAgo = 3): RssFeedItem
{
    return RssFeedItem::factory()->create([
        'rss_feed_id' => $feed->id,
        'title' => $title,
        'url' => 'https://example.com/'.str()->uuid(),
        'published_at' => now()->subHours($hoursAgo),
    ]);
}

function trendsTestKeys(): array
{
    return TrendTopic::query()->ranked()->pluck('key')->all();
}

test('a subject covered by two different feeds becomes a topic with its evidence', function () {
    trendsTestItem($this->feedA, 'Zephyr drone unveiled in Berlin');
    trendsTestItem($this->feedB, 'Zephyr drone arrives next month');

    AnalyzeTrends::execute($this->profile);

    $topic = TrendTopic::query()->where('key', 'zephyr drone')->sole();

    expect($topic->label)->toBe('Zephyr Drone')
        ->and($topic->outlet_count)->toBe(2)
        ->and($topic->signal_count)->toBe(2)
        ->and($topic->evidence)->toHaveCount(2)
        ->and($topic->evidence[0])->toHaveKeys(['title', 'url', 'source', 'published_at'])
        ->and($this->profile->fresh()->analyzed_at)->not->toBeNull();
});

test('one feed repeating itself is not a trend', function () {
    trendsTestItem($this->feedA, 'Quantum widgets spotted');
    trendsTestItem($this->feedA, 'Quantum widgets again');

    AnalyzeTrends::execute($this->profile);

    expect(trendsTestKeys())->not->toContain('quantum widgets');
});

test('more feeds and fresher coverage rank higher', function () {
    $feedC = RssFeed::factory()->create(['workspace_id' => $this->workspace->id]);
    $this->profile->rssFeeds()->attach($feedC->id);

    trendsTestItem($this->feedA, 'Zephyr drone unveiled', 2);
    trendsTestItem($this->feedB, 'Zephyr drone arrives', 2);
    trendsTestItem($feedC, 'Zephyr drone sells out', 2);
    trendsTestItem($this->feedA, 'Nimbus router launched', 150);
    trendsTestItem($this->feedB, 'Nimbus router reviewed', 150);

    AnalyzeTrends::execute($this->profile);

    expect(trendsTestKeys())->toBe(['zephyr drone', 'nimbus router']);
});

test('items older than the window are ignored', function () {
    trendsTestItem($this->feedA, 'Zephyr drone unveiled', 24 * 30);
    trendsTestItem($this->feedB, 'Zephyr drone arrives', 24 * 30);

    AnalyzeTrends::execute($this->profile);

    expect(trendsTestKeys())->toBe([]);
});

test('headlines that mention an excluded word are left out', function () {
    $this->profile->update(['excluded_keywords' => ['Giveaway']]);
    trendsTestItem($this->feedA, 'Zephyr drone giveaway announced');
    trendsTestItem($this->feedB, 'Zephyr drone giveaway ends today');

    AnalyzeTrends::execute($this->profile);

    expect(trendsTestKeys())->toBe([]);
});

test('a keyword of the workspace is a topic from two items of a single feed', function () {
    $this->profile->update(['keywords' => ['AI']]);
    trendsTestItem($this->feedA, 'AI chip demand keeps growing');
    trendsTestItem($this->feedA, 'What AI means for small teams');

    AnalyzeTrends::execute($this->profile);

    $topic = TrendTopic::query()->where('key', 'ai')->sole();

    expect($topic->label)->toBe('AI')
        ->and($topic->outlet_count)->toBe(1)
        ->and($topic->signal_count)->toBe(2);
});

test('a keyword is matched as a whole word', function () {
    $this->profile->update(['keywords' => ['ai']]);
    trendsTestItem($this->feedA, 'Mayor said nothing about the plan');
    trendsTestItem($this->feedA, 'Rain again ruins the festival');

    AnalyzeTrends::execute($this->profile);

    expect(trendsTestKeys())->not->toContain('ai');
});

test('two topics over the same headlines are shown once', function () {
    trendsTestItem($this->feedA, 'Zephyr drone unveiled');
    trendsTestItem($this->feedB, 'Zephyr drone unveiled again');

    AnalyzeTrends::execute($this->profile);

    expect(TrendTopic::query()->count())->toBe(1);
});

test('analysing again updates the topics instead of repeating them', function () {
    trendsTestItem($this->feedA, 'Zephyr drone unveiled');
    trendsTestItem($this->feedB, 'Zephyr drone arrives');

    AnalyzeTrends::execute($this->profile);
    $firstSeen = TrendTopic::query()->sole()->first_seen_at;

    trendsTestItem($this->feedA, 'Zephyr drone sells out');
    AnalyzeTrends::execute($this->profile);

    $topic = TrendTopic::query()->sole();

    expect($topic->signal_count)->toBe(3)
        ->and($topic->first_seen_at->equalTo($firstSeen))->toBeTrue();
});

test('a topic the user dismissed or used keeps that answer, and stale new ones go', function () {
    trendsTestItem($this->feedA, 'Zephyr drone unveiled');
    trendsTestItem($this->feedB, 'Zephyr drone arrives');
    trendsTestItem($this->feedA, 'Nimbus router launched');
    trendsTestItem($this->feedB, 'Nimbus router reviewed');
    TrendTopic::factory()->dismissed()->create(['workspace_id' => $this->workspace->id, 'key' => 'zephyr drone']);
    TrendTopic::factory()->create(['workspace_id' => $this->workspace->id, 'key' => 'nimbus router', 'status' => TopicStatus::Used]);
    TrendTopic::factory()->create(['workspace_id' => $this->workspace->id, 'key' => 'gone topic']);
    TrendTopic::factory()->create(['workspace_id' => $this->workspace->id, 'key' => 'used gone', 'status' => TopicStatus::Used]);

    AnalyzeTrends::execute($this->profile);

    $statuses = TrendTopic::query()->pluck('status', 'key')->map->value->all();

    expect($statuses)->toEqual([
        'zephyr drone' => 'dismissed',
        'nimbus router' => 'used',
        'used gone' => 'used',
    ]);
});

test('the suggested formats are the ones the workspace makes, in the order its goal prefers', function () {
    $this->profile->update(['goal' => 'sell', 'formats' => ['text', 'video', 'image']]);
    trendsTestItem($this->feedA, 'Zephyr drone unveiled');
    trendsTestItem($this->feedB, 'Zephyr drone arrives');

    AnalyzeTrends::execute($this->profile);

    expect(TrendTopic::query()->sole()->formats)->toBe(['image', 'video']);
});

test('a workspace only reads its own feeds', function () {
    $other = RssFeed::factory()->create();
    trendsTestItem($other, 'Zephyr drone unveiled');
    trendsTestItem($this->feedA, 'Zephyr drone arrives');

    AnalyzeTrends::execute($this->profile);

    expect(trendsTestKeys())->toBe([]);
});
