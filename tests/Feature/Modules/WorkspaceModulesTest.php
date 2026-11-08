<?php

declare(strict_types=1);

use App\Enums\Workspace\Module;
use App\Jobs\Inbox\SyncInbox;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->user = $this->user->fresh();
    subscribeAccount($this->user->account);
});

test('every module is on in a workspace that never chose', function () {
    expect($this->workspace->hasModule(Module::Inbox))->toBeTrue()
        ->and($this->workspace->hasModule(Module::Trends))->toBeTrue();
});

test('a workspace can switch a module off without touching the others', function () {
    $this->actingAs($this->user)
        ->patch(route('app.workspace.modules.update'), ['trends' => false])
        ->assertNoContent();

    $workspace = $this->workspace->fresh();

    expect($workspace->hasModule(Module::Trends))->toBeFalse()
        ->and($workspace->hasModule(Module::Inbox))->toBeTrue();

    $this->actingAs($this->user)
        ->patch(route('app.workspace.modules.update'), ['inbox' => false])
        ->assertNoContent();

    expect($this->workspace->fresh()->modules)->toEqual(['trends' => false, 'inbox' => false]);
});

test('a module the app switched off stays off whatever the workspace chose', function () {
    config()->set('trypost.trends.enabled', false);

    expect($this->workspace->hasModule(Module::Trends))->toBeFalse()
        ->and($this->workspace->moduleSettings())->not->toHaveKey('trends')
        ->and($this->workspace->moduleStates())->toMatchArray(['trends' => false, 'inbox' => true]);
});

test('only an admin changes the modules', function () {
    $member = workspaceMember($this->workspace);

    $this->actingAs($member)
        ->patch(route('app.workspace.modules.update'), ['trends' => false])
        ->assertForbidden();

    expect($this->workspace->fresh()->hasModule(Module::Trends))->toBeTrue();
});

test('the modules must be on or off', function () {
    $this->actingAs($this->user)
        ->patchJson(route('app.workspace.modules.update'), ['trends' => 'maybe'])
        ->assertJsonValidationErrors('trends');
});

test('the workspace settings page lists the modules the app offers', function () {
    config()->set('trypost.inbox.enabled', false);
    $this->workspace->update(['modules' => ['trends' => false]]);

    $this->actingAs($this->user)
        ->get(route('app.workspace.settings'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/workspace/Workspace')
            ->where('modules', ['trends' => false]));
});

test('the active modules are shared with every page', function () {
    $this->workspace->update(['modules' => ['inbox' => false]]);

    $this->actingAs($this->user)
        ->get(route('app.workspace.settings'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.currentWorkspace.modules', ['inbox' => false, 'trends' => true]));
});

test('the inbox and the trends pages do not exist while their module is off', function () {
    $this->workspace->update(['modules' => ['inbox' => false, 'trends' => false]]);

    $this->actingAs($this->user)->get(route('app.inbox.index'))->assertNotFound();
    $this->actingAs($this->user)->get(route('app.trends.index'))->assertNotFound();
    $this->actingAs($this->user)->get(route('app.trends.profile.edit'))->assertNotFound();
});

test('the inbox page opens while its module is on', function () {
    $this->actingAs($this->user)->get(route('app.inbox.index'))->assertOk();
});

test('the inbox is only read for workspaces that kept it on', function () {
    Queue::fake([SyncInbox::class]);

    $on = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
    $other = Workspace::factory()->create(['modules' => ['inbox' => false]]);
    SocialAccount::factory()->instagram()->create(['workspace_id' => $other->id]);

    $this->artisan('inbox:sync')->assertSuccessful();

    Queue::assertPushed(SyncInbox::class, 1);
    Queue::assertPushed(SyncInbox::class, fn (SyncInbox $job) => $job->account->is($on));
});
