<?php

declare(strict_types=1);

namespace App\Jobs\Inbox;

use App\Actions\Inbox\SyncAccountInbox;
use App\Models\SocialAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncInbox implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 300;

    public int $tries = 1;

    public int $timeout = 120;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public SocialAccount $account)
    {
        $this->onQueue('inbox');
    }

    public function uniqueId(): string
    {
        return $this->account->id;
    }

    public function handle(): void
    {
        SyncAccountInbox::execute($this->account);
    }
}
