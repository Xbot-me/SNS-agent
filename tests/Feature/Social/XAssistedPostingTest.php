<?php

declare(strict_types=1);

use App\Enums\Post\PublishStatus;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Jobs\PublishToSocialPlatform;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\ConnectionVerifier;
use App\Support\PostCompositionValidator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    Mail::fake();
    Http::preventStrayRequests();
    config()->set('trypost.platforms.x.assisted', true);

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->account = SocialAccount::factory()->assisted()->create([
        'workspace_id' => $this->workspace->id,
        'username' => 'formaurastudio',
    ]);
});

test('connecting X shows the handle form instead of OAuth when assisted posting is on', function () {
    $this->actingAs($this->user)
        ->get(route('app.social.x.connect'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('accounts/XAssistedConnect'));
});

test('an X account added by handle is stored as an assisted channel without calling X', function () {
    $this->actingAs($this->user)
        ->post(route('app.social.x.assisted.store'), ['handle' => '@StudioTwo'])
        ->assertRedirect(route('app.social.connect.show', Platform::X));

    finishSocialConnect(Platform::X)->assertRedirect();

    $account = SocialAccount::query()->where('platform_user_id', 'assisted:studiotwo')->sole();

    expect($account->isAssisted())->toBeTrue()
        ->and($account->username)->toBe('StudioTwo')
        ->and($account->status)->toBe(Status::Connected)
        ->and($account->access_token)->toBe('');
});

test('an invalid X handle is refused', function (string $handle) {
    $this->actingAs($this->user)
        ->post(route('app.social.x.assisted.store'), ['handle' => $handle])
        ->assertSessionHasErrors(['handle' => __('accounts.x_assisted.handle_invalid')]);
})->with([
    'too long' => ['abcdefghijklmnop'],
    'a dot' => ['studio.two'],
    'empty' => [''],
]);

test('the handle form is not reachable when assisted posting is off', function () {
    config()->set('trypost.platforms.x.assisted', false);

    $this->actingAs($this->user)
        ->post(route('app.social.x.assisted.store'), ['handle' => 'studiotwo'])
        ->assertNotFound();
});

test('a due assisted post waits for the user without calling X', function () {
    $post = Post::factory()->forAccount($this->account)->publishing()->create([
        'user_id' => $this->user->id,
        'content' => '<p>New character sculpt</p>',
    ]);

    (new PublishToSocialPlatform($post))->handle();

    $post->refresh();

    expect($post->publish_status)->toBe(PublishStatus::PendingReview)
        ->and($post->status)->toBe(PostStatus::Publishing)
        ->and($post->submitted_at)->not->toBeNull()
        ->and($post->platform_post_id)->toBeNull();
});

test('a stored assisted thread or poll fails at publish instead of waiting', function (array $attributes) {
    $post = Post::factory()->forAccount($this->account)->publishing()->create([
        'user_id' => $this->user->id,
        ...$attributes,
    ]);

    (new PublishToSocialPlatform($post))->handle();

    expect($post->fresh()->publish_status)->toBe(PublishStatus::Failed)
        ->and($post->fresh()->error_message)->toBe(__('posts.errors.assisted_single_post_only'));
})->with([
    'thread' => [['content' => 'Root', 'meta' => ['thread_replies' => [['text' => 'Reply', 'media' => []]]]]],
    'poll' => [['content' => 'Which one?', 'content_type' => ContentType::XPoll, 'meta' => ['poll' => ['options' => ['A', 'B'], 'duration_minutes' => 1440]]]],
]);

test('scheduling a thread on an assisted account is refused, while a draft may keep it', function () {
    $composition = fn (string $status): array => [
        'status' => $status,
        'content' => 'Root',
        'media' => [],
        'scheduled_at' => $status === 'scheduled' ? now()->addDay()->toIso8601String() : null,
        'destinations' => [[
            'social_account_id' => $this->account->id,
            'content_type' => ContentType::XPost->value,
            'meta' => ['thread_replies' => [['text' => 'Reply', 'media' => []]]],
        ]],
    ];

    PostCompositionValidator::validate($this->workspace, $composition('draft'));

    expect(fn () => PostCompositionValidator::validate($this->workspace, $composition('scheduled')))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors())
            ->toHaveKey('destinations.0.content_type', [__('posts.errors.assisted_single_post_only')]));
});

test('an assisted account passes the connection check without calling X', function () {
    expect(app(ConnectionVerifier::class)->verify($this->account))->toBeTrue();
});

test('assisted accounts are left out of analytics', function () {
    $apiAccount = SocialAccount::factory()->x()->create(['workspace_id' => $this->workspace->id]);

    expect(SocialAccount::query()->includedInAnalytics()->pluck('id')->all())
        ->toContain($apiAccount->id)
        ->not->toContain($this->account->id);
});

test('the helper lists posts waiting for the user with their text and local media', function () {
    Storage::fake(config('filesystems.default'));
    Storage::put('medias/sculpt.png', 'png');
    $waiting = Post::factory()->forAccount($this->account)->pendingReview()->create([
        'user_id' => $this->user->id,
        'content' => '<p>Sculpted by hand</p>',
    ]);
    $waiting->forceFill(['media' => [[
        'id' => fake()->uuid(),
        'path' => 'medias/sculpt.png',
        'url' => Storage::url('medias/sculpt.png'),
        'mime_type' => 'image/png',
        'type' => 'image',
    ]]])->save();
    Post::factory()->forAccount($this->account)->scheduled()->create(['user_id' => $this->user->id]);

    expect(Artisan::call('x-assist:pending'))->toBe(0);

    expect(json_decode(Artisan::output(), true))->toBe([[
        'id' => $waiting->id,
        'handle' => 'formaurastudio',
        'text' => 'Sculpted by hand',
        'media' => [[
            'type' => 'image',
            'mime_type' => 'image/png',
            'path' => Storage::path('medias/sculpt.png'),
            'url' => Storage::url('medias/sculpt.png'),
        ]],
    ]]);
});

test('completing an assisted post records its X link and publishes it', function () {
    $post = Post::factory()->forAccount($this->account)->pendingReview()->create(['user_id' => $this->user->id]);

    $this->artisan('x-assist:complete', ['post' => $post->id, 'url' => 'https://x.com/formaurastudio/status/1850000000000000001?s=20'])
        ->assertSuccessful();

    $post->refresh();

    expect($post->publish_status)->toBe(PublishStatus::Published)
        ->and($post->status)->toBe(PostStatus::Published)
        ->and($post->platform_post_id)->toBe('1850000000000000001')
        ->and($post->platform_url)->toBe('https://x.com/formaurastudio/status/1850000000000000001');
});

test('completing refuses a link that is not an X post', function () {
    $post = Post::factory()->forAccount($this->account)->pendingReview()->create(['user_id' => $this->user->id]);

    $this->artisan('x-assist:complete', ['post' => $post->id, 'url' => 'https://example.com/formaurastudio/status/1'])
        ->assertFailed();

    expect($post->fresh()->publish_status)->toBe(PublishStatus::PendingReview);
});

test('completing leaves a post that is not waiting for the user alone', function () {
    $apiAccount = SocialAccount::factory()->x()->create(['workspace_id' => $this->workspace->id]);
    $post = Post::factory()->forAccount($apiAccount)->pendingReview()->create(['user_id' => $this->user->id]);

    $this->artisan('x-assist:complete', ['post' => $post->id, 'url' => 'https://x.com/someone/status/1'])
        ->assertFailed();

    expect($post->fresh()->publish_status)->toBe(PublishStatus::PendingReview);
});

test('closing the window marks the post as not posted', function () {
    $post = Post::factory()->forAccount($this->account)->pendingReview()->create(['user_id' => $this->user->id]);

    $this->artisan('x-assist:cancel', ['post' => $post->id])->assertSuccessful();

    expect($post->fresh()->publish_status)->toBe(PublishStatus::Failed)
        ->and($post->fresh()->status)->toBe(PostStatus::Failed)
        ->and($post->fresh()->error_message)->toBe(__('posts.errors.assisted_window_closed'));
});

test('an assisted post left unposted for a day fails with its own message', function () {
    $post = Post::factory()->forAccount($this->account)->create([
        'user_id' => $this->user->id,
        'status' => PostStatus::Publishing,
        'publish_status' => PublishStatus::PendingReview,
        'submitted_at' => now()->subHours(25),
        'updated_at' => now()->subHours(25),
        'publication_updated_at' => now()->subHours(25),
    ]);

    $this->artisan('social:recover-stuck-posts')->assertSuccessful();

    expect($post->fresh()->status)->toBe(PostStatus::Failed)
        ->and($post->fresh()->error_message)->toBe(__('posts.errors.assisted_not_posted'));
});
