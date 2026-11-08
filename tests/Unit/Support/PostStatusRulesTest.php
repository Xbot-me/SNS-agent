<?php

declare(strict_types=1);

use App\Enums\Post\PublishStatus;
use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Support\PostStatusRules;

test('blocks editing for terminal statuses', function (PostStatus $status) {
    $post = Post::factory()->make(['status' => $status]);

    expect(PostStatusRules::blocksEditing($post))->toBeTrue();
})->with([
    PostStatus::Publishing,
    PostStatus::Published,
    PostStatus::Failed,
]);

test('allows editing for non terminal statuses', function (PostStatus $status) {
    $post = Post::factory()->make(['status' => $status]);

    expect(PostStatusRules::blocksEditing($post))->toBeFalse();
})->with([
    PostStatus::Draft,
    PostStatus::Scheduled,
]);

test('blocks deletion for sent and failed statuses', function (PostStatus $status) {
    $post = Post::factory()->make(['status' => $status]);

    expect(PostStatusRules::blocksDeletion($post))->toBeTrue();
})->with([
    PostStatus::Publishing,
    PostStatus::Published,
    PostStatus::Failed,
]);

test('allows deleting a publishing post that waits on an assisted channel', function () {
    $account = SocialAccount::factory()->assisted()->create();
    $post = Post::factory()->forAccount($account)->create([
        'status' => PostStatus::Publishing,
        'publish_status' => PublishStatus::PendingReview,
    ]);

    expect(PostStatusRules::blocksDeletion($post))->toBeFalse();
});

test('allows deleting a publishing post whose attempt stopped writing, but not a live one', function () {
    $post = Post::factory()->make([
        'status' => PostStatus::Publishing,
        'publish_status' => PublishStatus::Publishing,
        'retry_at' => null,
        'publication_updated_at' => now()->subMinutes(30),
    ]);

    expect(PostStatusRules::blocksDeletion($post))->toBeFalse();

    $post->publication_updated_at = now()->subMinute();

    expect(PostStatusRules::blocksDeletion($post))->toBeTrue();
});

test('allows deletion for draft, scheduled and failed statuses', function (PostStatus $status) {
    $post = Post::factory()->make(['status' => $status]);

    expect(PostStatusRules::blocksDeletion($post))->toBeFalse();
})->with([
    PostStatus::Draft,
    PostStatus::Scheduled,
]);

test('requires explicit schedule when status is scheduled and post has no usable schedule', function (?string $scheduledAt, bool $expected) {
    $post = Post::factory()->make([
        'scheduled_at' => $scheduledAt,
    ]);

    expect(PostStatusRules::requiresExplicitSchedule($post, PostStatus::Scheduled->value))->toBe($expected);
})->with([
    'missing schedule' => [null, true],
    'past schedule' => [now()->subHour()->toDateTimeString(), true],
    'future schedule' => [now()->addDay()->toDateTimeString(), false],
]);

test('does not require explicit schedule for non scheduled statuses', function () {
    $post = Post::factory()->make(['scheduled_at' => null]);

    expect(PostStatusRules::requiresExplicitSchedule($post, PostStatus::Draft->value))->toBeFalse()
        ->and(PostStatusRules::requiresExplicitSchedule(null, PostStatus::Scheduled->value))->toBeTrue();
});
