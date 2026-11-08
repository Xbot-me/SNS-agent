<?php

declare(strict_types=1);

namespace App\Enums\Inbox;

/**
 * What an inbox conversation is: a private message thread, a comment (with its
 * replies) on one of the channel's posts, a mention or reply on a network
 * without comment threads, or a review of a business location.
 */
enum ConversationKind: string
{
    case Message = 'message';
    case Comment = 'comment';
    case Mention = 'mention';
    case Review = 'review';
}
