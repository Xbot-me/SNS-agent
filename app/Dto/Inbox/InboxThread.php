<?php

declare(strict_types=1);

namespace App\Dto\Inbox;

use App\Enums\Inbox\ConversationKind;

/**
 * One conversation of a channel's inbox as a network reported it: a DM thread,
 * a top-level comment with its replies, a mention, or a review.
 */
final readonly class InboxThread
{
    /**
     * @param  list<InboxEntry>  $entries
     * @param  array<string, mixed>  $meta  What replying needs (recipient id, parent uri, visibility).
     */
    public function __construct(
        public ConversationKind $kind,
        public string $remoteId,
        public array $entries,
        public ?string $participantName = null,
        public ?string $participantUsername = null,
        public ?string $participantAvatarUrl = null,
        public ?string $subject = null,
        public ?string $permalink = null,
        public array $meta = [],
    ) {}
}
