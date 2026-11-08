<?php

declare(strict_types=1);

use App\Jobs\Trend\AnalyzeWorkspaceTrends;
use App\Models\TrendProfile;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake([AnalyzeWorkspaceTrends::class]);
});

test('only finished setups that are due get analysed', function () {
    $due = TrendProfile::factory()->create(['analyzed_at' => now()->subHours(7)]);
    $never = TrendProfile::factory()->create(['analyzed_at' => null]);
    TrendProfile::factory()->create(['analyzed_at' => now()->subHour()]);
    TrendProfile::factory()->notOnboarded()->create();

    $this->artisan('trends:analyze')->assertSuccessful();

    Queue::assertPushed(AnalyzeWorkspaceTrends::class, 2);
    Queue::assertPushed(AnalyzeWorkspaceTrends::class, fn (AnalyzeWorkspaceTrends $job) => $job->profile->is($due));
    Queue::assertPushed(AnalyzeWorkspaceTrends::class, fn (AnalyzeWorkspaceTrends $job) => $job->profile->is($never));
});

test('force reads every finished setup', function () {
    TrendProfile::factory()->create(['analyzed_at' => now()->subHour()]);

    $this->artisan('trends:analyze --force')->assertSuccessful();

    Queue::assertPushed(AnalyzeWorkspaceTrends::class, 1);
});

test('a workspace that switched trends off is left alone', function () {
    TrendProfile::factory()->create(['workspace_id' => Workspace::factory()->create(['modules' => ['trends' => false]])]);

    $this->artisan('trends:analyze')->assertSuccessful();

    Queue::assertNothingPushed();
});

test('nothing is read while the app has trends off', function () {
    config()->set('trypost.trends.enabled', false);
    TrendProfile::factory()->create();

    $this->artisan('trends:analyze --force')->assertSuccessful();

    Queue::assertNothingPushed();
});

test('the queued job analyses the workspace and does nothing once trends is off', function () {
    $profile = TrendProfile::factory()->create(['analyzed_at' => null]);

    (new AnalyzeWorkspaceTrends($profile))->handle();

    expect($profile->fresh()->analyzed_at)->not->toBeNull();

    $off = TrendProfile::factory()->create(['workspace_id' => Workspace::factory()->create(['modules' => ['trends' => false]]), 'analyzed_at' => null]);

    (new AnalyzeWorkspaceTrends($off))->handle();

    expect($off->fresh()->analyzed_at)->toBeNull();
});
