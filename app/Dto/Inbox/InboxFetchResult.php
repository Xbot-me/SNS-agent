<?php

declare(strict_types=1);

namespace App\Dto\Inbox;

use App\Enums\Inbox\ConversationKind;

/**
 * What one read of a channel's inbox found, and which kinds it could not read
 * because the login did not grant their scope (the channel needs a reconnect).
 */
final readonly class InboxFetchResult
{
    /**
     * @param  list<InboxThread>  $threads
     * @param  list<ConversationKind>  $missing
     */
    public function __construct(
        public array $threads,
        public array $missing = [],
    ) {}
}
