<?php

declare(strict_types=1);

namespace App\Dto\Inbox;

use Carbon\CarbonImmutable;

/**
 * One message of an inbox thread as a network reported it.
 */
final readonly class InboxEntry
{
    /**
     * @param  list<array{type: string, url: string}>|null  $attachments
     */
    public function __construct(
        public string $remoteId,
        public ?string $body,
        public CarbonImmutable $sentAt,
        public bool $isOwn,
        public ?string $authorName = null,
        public ?string $authorUsername = null,
        public ?string $authorAvatarUrl = null,
        public ?array $attachments = null,
    ) {}
}
