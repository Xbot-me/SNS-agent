<?php

declare(strict_types=1);

use App\Actions\Inbox\SyncAccountInbox;
use App\Enums\Inbox\ConversationKind;
use App\Models\InboxConversation;
use App\Models\SocialAccount;
use App\Support\Inbox\InboxSyncStatus;
use Illuminate\Support\Facades\Http;

function inboxAccount(string $state, array $attributes = []): SocialAccount
{
    return SocialAccount::factory()->{$state}()->create($attributes);
}

test('a facebook page brings its messenger conversations and post comments', function () {
    $graph = config('trypost.platforms.facebook.graph_api');
    $account = inboxAccount('facebook', [
        'platform_user_id' => 'page-1',
        'scopes' => ['pages_messaging', 'pages_read_user_content', 'pages_manage_engagement'],
    ]);

    Http::fake([
        "{$graph}/page-1/conversations*" => Http::response(['data' => [[
            'id' => 't_1',
            'participants' => ['data' => [['id' => 'psid-9', 'name' => 'Ana'], ['id' => 'page-1', 'name' => 'My Page']]],
            'messages' => ['data' => [
                ['id' => 'm_2', 'message' => 'Thanks!', 'created_time' => now()->subMinutes(5)->toIso8601String(), 'from' => ['id' => 'page-1', 'name' => 'My Page']],
                ['id' => 'm_1', 'message' => 'Do you ship?', 'created_time' => now()->subMinutes(10)->toIso8601String(), 'from' => ['id' => 'psid-9', 'name' => 'Ana']],
            ]],
        ]]]),
        "{$graph}/page-1/posts*" => Http::response(['data' => [[
            'id' => 'post-1',
            'message' => 'Launch day',
            'comments' => ['data' => [[
                'id' => 'c_1',
                'message' => 'Congrats',
                'created_time' => now()->subMinute()->toIso8601String(),
                'from' => ['id' => 'u-2', 'name' => 'Bo'],
                'permalink_url' => 'https://facebook.com/c_1',
            ]]],
        ]]]),
    ]);

    SyncAccountInbox::execute($account);

    $message = InboxConversation::query()->where('kind', ConversationKind::Message)->sole();
    expect($message->participant_name)->toBe('Ana')
        ->and(data_get($message->meta, 'recipient_id'))->toBe('psid-9')
        ->and($message->messages()->count())->toBe(2)
        ->and($message->last_message_excerpt)->toBe('Thanks!')
        // The page answered after Ana wrote, so there is nothing left to read.
        ->and($message->isUnread())->toBeFalse();

    $comment = InboxConversation::query()->where('kind', ConversationKind::Comment)->sole();
    expect($comment->remote_id)->toBe('c_1')
        ->and($comment->subject)->toBe('Launch day')
        ->and($comment->isUnread())->toBeTrue()
        ->and(InboxSyncStatus::for($account)['missing'])->toBe([]);
});

test('a second read adds what is new without duplicating messages', function () {
    $api = config('trypost.platforms.mastodon.default_instance');
    $account = inboxAccount('mastodon', ['scopes' => ['read', 'write:statuses']]);
    $mention = fn (string $id, string $visibility) => [
        'id' => "n{$id}",
        'type' => 'mention',
        'account' => ['acct' => 'cleo@example.social', 'display_name' => 'Cleo', 'avatar' => null],
        'status' => [
            'id' => $id,
            'content' => '<p>Hello <span>@me</span></p><p>Second line</p>',
            'visibility' => $visibility,
            'created_at' => now()->subMinutes((int) $id)->toIso8601String(),
            'url' => "https://example.social/@cleo/{$id}",
            'media_attachments' => [],
        ],
    ];

    Http::fakeSequence("{$api}/api/v1/notifications*")
        ->push([$mention('1', 'public')])
        ->push([$mention('2', 'direct'), $mention('1', 'public')]);

    SyncAccountInbox::execute($account);
    SyncAccountInbox::execute($account);

    expect(InboxConversation::query()->count())->toBe(2)
        ->and(InboxConversation::query()->where('remote_id', '1')->sole()->kind)->toBe(ConversationKind::Mention)
        ->and(InboxConversation::query()->where('remote_id', '2')->sole()->kind)->toBe(ConversationKind::Message)
        ->and(InboxConversation::query()->where('remote_id', '1')->sole()->messages()->sole()->body)->toBe("Hello @me\n\nSecond line");
});

test('a channel whose login left out the inbox scopes asks for a reconnect without reading', function () {
    $account = inboxAccount('instagram');
    Http::fake();

    SyncAccountInbox::execute($account);

    Http::assertNothingSent();
    expect(InboxSyncStatus::for($account)['missing'])->toBe(['message', 'comment']);
});

test('a refused token is recorded so the inbox can ask for a reconnect', function () {
    $graph = config('trypost.platforms.threads.graph_api');
    $account = inboxAccount('threads', ['scopes' => ['threads_basic', 'threads_read_replies']]);

    Http::fake(["{$graph}/*" => Http::response(['error' => ['message' => 'Invalid token', 'code' => 190]], 400)]);

    SyncAccountInbox::execute($account);

    expect(InboxSyncStatus::for($account)['error'])->toBe('authentication')
        ->and(InboxConversation::query()->count())->toBe(0);
});

test('threads groups nested replies under the reply they answer', function () {
    $graph = config('trypost.platforms.threads.graph_api');
    $account = inboxAccount('threads', ['username' => 'me', 'scopes' => ['threads_read_replies']]);

    Http::fake([
        "{$graph}/me/threads*" => Http::response(['data' => [['id' => 'post-1', 'text' => 'My thread']]]),
        "{$graph}/post-1/conversation*" => Http::response(['data' => [
            ['id' => 'r1', 'text' => 'Nice', 'username' => 'dan', 'timestamp' => now()->subMinutes(3)->toIso8601String(), 'replied_to' => ['id' => 'post-1']],
            ['id' => 'r2', 'text' => 'Thanks', 'username' => 'me', 'timestamp' => now()->subMinutes(2)->toIso8601String(), 'replied_to' => ['id' => 'r1'], 'is_reply_owned_by_me' => true],
            ['id' => 'r3', 'text' => 'Agreed', 'username' => 'eve', 'timestamp' => now()->subMinute()->toIso8601String(), 'replied_to' => ['id' => 'r2']],
        ]]),
    ]);

    SyncAccountInbox::execute($account);

    $thread = InboxConversation::query()->sole();
    expect($thread->remote_id)->toBe('r1')
        ->and($thread->messages()->orderBy('sent_at')->pluck('is_own')->all())->toBe([false, true, false])
        ->and($thread->isUnread())->toBeTrue();
});

test('youtube reads the comment threads of the latest uploads', function () {
    $api = config('trypost.platforms.youtube.data_api');
    $account = inboxAccount('youtube', ['platform_user_id' => 'UC1']);

    Http::fake([
        "{$api}/channels*" => Http::response(['items' => [['contentDetails' => ['relatedPlaylists' => ['uploads' => 'UU1']]]]]),
        "{$api}/playlistItems*" => Http::response(['items' => [
            ['snippet' => ['title' => 'Video A', 'resourceId' => ['videoId' => 'vid-a']]],
            ['snippet' => ['title' => 'Video B', 'resourceId' => ['videoId' => 'vid-b']]],
        ]]),
        "{$api}/commentThreads?*videoId=vid-a*" => Http::response(['items' => [[
            'id' => 'thread-1',
            'snippet' => ['topLevelComment' => ['id' => 'thread-1', 'snippet' => [
                'textOriginal' => 'Great video',
                'authorDisplayName' => 'Fay',
                'authorChannelId' => ['value' => 'UC9'],
                'publishedAt' => now()->subHour()->toIso8601String(),
            ]]],
        ]]]),
        "{$api}/commentThreads?*videoId=vid-b*" => Http::response(['error' => ['errors' => [['reason' => 'commentsDisabled']]]], 403),
    ]);

    SyncAccountInbox::execute($account);

    $thread = InboxConversation::query()->sole();
    expect($thread->subject)->toBe('Video A')
        ->and($thread->permalink)->toContain('vid-a')
        ->and(InboxSyncStatus::for($account)['error'])->toBeNull();
});

test('bluesky keeps mentions when the app password may not read chats', function () {
    $account = inboxAccount('bluesky', ['platform_user_id' => 'did:plc:me']);

    Http::fake([
        '*/xrpc/app.bsky.notification.listNotifications*' => Http::response(['notifications' => [
            ['uri' => 'at://did:plc:x/app.bsky.feed.post/abc', 'cid' => 'cid1', 'reason' => 'reply', 'author' => ['handle' => 'gil.bsky.social'], 'record' => ['text' => 'Hi', 'createdAt' => now()->toIso8601String(), 'reply' => ['root' => ['uri' => 'at://root', 'cid' => 'rootcid']]]],
            ['uri' => 'at://did:plc:x/app.bsky.feed.like/zzz', 'cid' => 'cid2', 'reason' => 'like', 'author' => ['handle' => 'gil.bsky.social'], 'record' => []],
        ]]),
        '*/xrpc/chat.bsky.convo.listConvos*' => Http::response(['error' => 'InvalidToken', 'message' => 'Bad token scope'], 400),
    ]);

    SyncAccountInbox::execute($account);

    $mention = InboxConversation::query()->sole();
    expect($mention->kind)->toBe(ConversationKind::Mention)
        ->and(data_get($mention->meta, 'root.uri'))->toBe('at://root')
        ->and(InboxSyncStatus::for($account)['missing'])->toBe(['message']);
});

test('google business reviews keep the owner reply as the channel\'s message', function () {
    $api = config('trypost.platforms.google_business.local_posts_api');
    $account = inboxAccount('googleBusiness');

    Http::fake(["{$api}/accounts/123456789/locations/987654321/reviews*" => Http::response(['reviews' => [[
        'name' => 'accounts/123456789/locations/987654321/reviews/r1',
        'reviewId' => 'r1',
        'reviewer' => ['displayName' => 'Hal'],
        'starRating' => 'FOUR',
        'comment' => 'Good coffee',
        'updateTime' => now()->subDay()->toIso8601String(),
        'reviewReply' => ['comment' => 'Thank you!', 'updateTime' => now()->subHours(2)->toIso8601String()],
    ]]])]);

    SyncAccountInbox::execute($account);

    $review = InboxConversation::query()->sole();
    expect($review->kind)->toBe(ConversationKind::Review)
        ->and(data_get($review->meta, 'rating'))->toBe(4)
        ->and($review->messages()->where('is_own', true)->sole()->body)->toBe('Thank you!')
        ->and($review->isUnread())->toBeFalse();
});

test('a conversation first seen with nothing new for a week starts read', function () {
    $api = config('trypost.platforms.mastodon.default_instance');
    $account = inboxAccount('mastodon', ['scopes' => ['read:notifications']]);

    Http::fake(["{$api}/api/v1/notifications*" => Http::response([[
        'id' => 'n1',
        'account' => ['acct' => 'old@example.social'],
        'status' => ['id' => '7', 'content' => '<p>Old</p>', 'visibility' => 'public', 'created_at' => now()->subDays(10)->toIso8601String()],
    ]])]);

    SyncAccountInbox::execute($account);

    expect(InboxConversation::query()->sole()->isUnread())->toBeFalse();
});
