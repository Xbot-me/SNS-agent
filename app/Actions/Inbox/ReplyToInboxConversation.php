<?php

declare(strict_types=1);

namespace App\Actions\Inbox;

use App\Exceptions\Inbox\InboxException;
use App\Models\InboxConversation;
use App\Services\Inbox\InboxProviderFactory;

/**
 * Sends a reply through the conversation's network and keeps it in the
 * conversation at once, read, without waiting for the next sync.
 */
class ReplyToInboxConversation
{
    /**
     * @throws InboxException
     */
    public static function execute(InboxConversation $conversation, string $text): InboxConversation
    {
        $account = $conversation->socialAccount;

        if ($account === null) {
            throw InboxException::unsupported('the channel was disconnected');
        }

        $entry = app(InboxProviderFactory::class)->for($account->platform)->reply($conversation, $account, $text);

        SyncAccountInbox::storeEntries($conversation, [$entry]);
        $conversation->read_at = now();
        $conversation->save();

        return SyncAccountInbox::refresh($conversation);
    }
}
