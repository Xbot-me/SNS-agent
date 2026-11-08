<?php

declare(strict_types=1);

use App\Enums\Inbox\ConversationKind;
use App\Jobs\Inbox\SyncInbox;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('member'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);

    $this->facebook = SocialAccount::factory()->facebook()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'page-1',
        'scopes' => ['pages_messaging', 'pages_read_user_content', 'pages_manage_engagement'],
    ]);
});

test('the inbox lists the workspace conversations and filters them', function () {
    $message = InboxConversation::factory()->create(['social_account_id' => $this->facebook->id]);
    $comment = InboxConversation::factory()->comment()->read()->create(['social_account_id' => $this->facebook->id]);
    InboxConversation::factory()->create();

    $this->actingAs($this->user)
        ->get(route('app.inbox.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('inbox/Index')
            ->where('unreadCount', 1)
            ->where('hasData', true)
            ->where('conversation', null)
            ->has('conversations.data', 2)
            ->has('channels', 1));

    $this->actingAs($this->user)
        ->get(route('app.inbox.index', ['kind' => ConversationKind::Comment->value]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('conversations.data', 1)
            ->where('conversations.data.0.id', $comment->id));

    $this->actingAs($this->user)
        ->get(route('app.inbox.index', ['unread' => '1']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('conversations.data', 1)
            ->where('conversations.data.0.id', $message->id));
});

test('opening a conversation shows its messages and marks it read', function () {
    $conversation = InboxConversation::factory()->create(['social_account_id' => $this->facebook->id]);
    InboxMessage::factory()->create(['inbox_conversation_id' => $conversation->id, 'body' => 'Hello']);

    $this->actingAs($this->user)
        ->get(route('app.inbox.show', $conversation))
        ->assertInertia(fn (Assert $page) => $page
            ->where('conversation.id', $conversation->id)
            ->where('conversation.messages.0.body', 'Hello')
            ->where('unreadCount', 0));

    expect($conversation->fresh()->isUnread())->toBeFalse();
});

test('a conversation of another workspace is not found', function () {
    $other = InboxConversation::factory()->create();

    $this->actingAs($this->user)->get(route('app.inbox.show', $other))->assertNotFound();
    $this->actingAs($this->user)->post(route('app.inbox.replies.store', $other), ['body' => 'Hi'])->assertNotFound();
});

test('a reply is sent through the network and kept in the conversation', function () {
    $graph = config('trypost.platforms.facebook.graph_api');
    $conversation = InboxConversation::factory()->create([
        'social_account_id' => $this->facebook->id,
        'meta' => ['recipient_id' => 'psid-9'],
    ]);

    Http::fake(["{$graph}/page-1/messages" => Http::response(['recipient_id' => 'psid-9', 'message_id' => 'm_99'])]);

    $this->actingAs($this->user)
        ->post(route('app.inbox.replies.store', $conversation), ['body' => 'We do!'])
        ->assertSessionHasNoErrors();

    Http::assertSent(fn (Request $request) => $request->url() === "{$graph}/page-1/messages"
        && data_get($request->data(), 'recipient.id') === 'psid-9'
        && data_get($request->data(), 'message.text') === 'We do!'
        && data_get($request->data(), 'messaging_type') === 'RESPONSE');

    $conversation->refresh();
    expect($conversation->messages()->where('remote_id', 'm_99')->sole()->is_own)->toBeTrue()
        ->and($conversation->last_message_excerpt)->toBe('We do!')
        ->and($conversation->isUnread())->toBeFalse();
});

test('a reply outside meta\'s 24-hour window explains it', function () {
    $graph = config('trypost.platforms.facebook.graph_api');
    $conversation = InboxConversation::factory()->create([
        'social_account_id' => $this->facebook->id,
        'meta' => ['recipient_id' => 'psid-9'],
    ]);

    Http::fake(["{$graph}/page-1/messages" => Http::response(['error' => ['code' => 10, 'error_subcode' => 2018278, 'message' => 'outside window']], 400)]);

    $this->actingAs($this->user)
        ->post(route('app.inbox.replies.store', $conversation), ['body' => 'Late'])
        ->assertSessionHasErrors(['body' => __('inbox.errors.reply_window', ['network' => 'Facebook'])]);

    expect($conversation->messages()->count())->toBe(0);
});

test('a mastodon reply mentions the person and keeps the visibility', function () {
    $mastodon = SocialAccount::factory()->mastodon()->create(['workspace_id' => $this->workspace->id]);
    $instance = $mastodon->mastodonInstance();
    $conversation = InboxConversation::factory()->create([
        'social_account_id' => $mastodon->id,
        'kind' => ConversationKind::Message,
        'remote_id' => '42',
        'meta' => ['status_id' => '42', 'acct' => 'cleo@example.social', 'visibility' => 'direct'],
    ]);

    Http::fake(["{$instance}/api/v1/statuses" => Http::response(['id' => '43', 'created_at' => now()->toIso8601String()])]);

    $this->actingAs($this->user)
        ->post(route('app.inbox.replies.store', $conversation), ['body' => 'Sure'])
        ->assertSessionHasNoErrors();

    Http::assertSent(fn (Request $request) => $request['status'] === '@cleo@example.social Sure'
        && $request['in_reply_to_id'] === '42'
        && $request['visibility'] === 'direct');
});

test('a reply needs text', function () {
    $conversation = InboxConversation::factory()->create(['social_account_id' => $this->facebook->id]);

    $this->actingAs($this->user)
        ->post(route('app.inbox.replies.store', $conversation), ['body' => ''])
        ->assertSessionHasErrors('body');
});

test('checking now queues a read of every inbox channel of the workspace', function () {
    Queue::fake([SyncInbox::class]);
    SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id]);
    SocialAccount::factory()->facebook()->create();

    $this->actingAs($this->user)->post(route('app.inbox.sync.store'))->assertRedirect();

    Queue::assertPushed(SyncInbox::class, 1);
    Queue::assertPushed(SyncInbox::class, fn (SyncInbox $job) => $job->account->is($this->facebook));
});
