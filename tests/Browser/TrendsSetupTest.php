<?php

declare(strict_types=1);

use App\Enums\Workspace\Module;
use App\Models\RssFeed;
use App\Models\TrendProfile;
use App\Models\User;
use App\Models\Workspace;

beforeEach(function () {
    config(['trypost.self_hosted' => true]);

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->user->account_id, 'user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->user = $this->user->fresh();
    connectAiTo($this->user);
    $this->actingAs($this->user);
});

test('the setup walks through four steps and saves the profile', function () {
    $feed = RssFeed::factory()->create(['workspace_id' => $this->workspace->id]);

    $page = visit(route('app.trends.profile.edit'));
    waitFor($page, '@trends-step-niche');

    $page->click('@trends-niche-technology');
    waitForState($page, '@trends-continue', 'element.disabled === false');
    $page->click('@trends-continue');

    waitFor($page, '@trends-step-goal');
    $page->click('@trends-goal-sell')->click('@trends-continue');

    waitFor($page, '@trends-step-formats');
    $page->click('@trends-format-video')->click('@trends-cadence-5')->click('@trends-continue');

    waitFor($page, '@trends-step-sources');
    $page->assertSee($feed->display_title)->click('@trends-continue');

    waitForPath($page, parse_url(route('app.trends.index'), PHP_URL_PATH));
    waitForDatabase($page, fn (): bool => TrendProfile::query()->exists());

    $profile = TrendProfile::query()->sole();

    expect($profile->niche)->toBe('Technology')
        ->and($profile->goal->value)->toBe('sell')
        ->and($profile->posts_per_week)->toBe(5)
        ->and($profile->formats)->toContain('video')
        ->and($profile->keywords)->toContain('ai')
        ->and($profile->rssFeeds->pluck('id')->all())->toBe([$feed->id]);

    $page->assertNoJavaScriptErrors();
});

test('the first step cannot continue without a niche and a word to watch', function () {
    $page = visit(route('app.trends.profile.edit'));
    waitFor($page, '@trends-step-niche');

    waitForState($page, '@trends-continue', 'element.disabled === true');

    $page->assertNoJavaScriptErrors();
});

test('switching trends off in the workspace settings takes the page away', function () {
    $page = visit(route('app.workspace.settings'));
    waitFor($page, '@module-trends');

    $page->click('@module-trends');
    waitForDatabase($page, fn (): bool => ! $this->workspace->fresh()->hasModule(Module::Trends));

    expect($this->workspace->fresh()->hasModule(Module::Inbox))->toBeTrue();

    $this->get(route('app.trends.index'))->assertNotFound();

    $page->assertNoJavaScriptErrors();
});
