<?php

declare(strict_types=1);

use App\Jobs\Inbox\SyncInbox;
use App\Models\SocialAccount;
use App\Support\Inbox\InboxSyncStatus;
use Illuminate\Support\Facades\Queue;

test('only connected inbox channels that are due get read', function () {
    Queue::fake([SyncInbox::class]);

    $due = SocialAccount::factory()->instagram()->create();
    $fresh = SocialAccount::factory()->instagram()->create();
    InboxSyncStatus::record($fresh);
    SocialAccount::factory()->instagram()->tokenExpired()->create();
    SocialAccount::factory()->x()->create();

    $this->artisan('inbox:sync')->assertSuccessful();

    Queue::assertPushed(SyncInbox::class, 1);
    Queue::assertPushed(SyncInbox::class, fn (SyncInbox $job) => $job->account->is($due));
});

test('the inbox can be turned off', function () {
    Queue::fake([SyncInbox::class]);
    config()->set('trypost.inbox.enabled', false);
    SocialAccount::factory()->instagram()->create();

    $this->artisan('inbox:sync')->assertSuccessful();

    Queue::assertNothingPushed();
});
