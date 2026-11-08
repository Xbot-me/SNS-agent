<?php

declare(strict_types=1);

namespace App\Console\Commands\Inbox;

use App\Enums\Workspace\Module;
use App\Jobs\Inbox\SyncInbox;
use App\Models\SocialAccount;
use App\Services\Inbox\InboxProviderFactory;
use App\Support\Inbox\InboxSyncStatus;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class DispatchInboxSync extends Command
{
    protected $signature = 'inbox:sync {--force : Read every channel, due or not}';

    protected $description = 'Queue an inbox read for every connected channel that is due';

    public function handle(InboxProviderFactory $providers): int
    {
        if (! config('trypost.inbox.enabled')) {
            return self::SUCCESS;
        }

        SocialAccount::query()
            ->with('workspace')
            ->connected()
            ->apiBacked()
            ->whereIn('platform', InboxProviderFactory::platforms())
            ->chunkById(200, function (Collection $accounts) use ($providers): void {
                foreach ($accounts as $account) {
                    if (! $account->workspace?->hasModule(Module::Inbox)) {
                        continue;
                    }

                    $interval = $providers->for($account->platform)->syncIntervalMinutes();

                    if ($this->option('force') || InboxSyncStatus::isDue($account, $interval)) {
                        SyncInbox::dispatch($account);
                    }
                }
            });

        return self::SUCCESS;
    }
}
