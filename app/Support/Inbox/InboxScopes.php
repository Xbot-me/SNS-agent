<?php

declare(strict_types=1);

namespace App\Support\Inbox;

use App\Enums\SocialAccount\Platform;

/**
 * The extra scopes a network's login asks for so the unified inbox can read
 * and answer its messages, comments and mentions. Bluesky (app password) and
 * Google Business (business.manage) need none beyond what publishing has, and
 * YouTube already asks for youtube.force-ssl.
 */
class InboxScopes
{
    /**
     * @return array<int, string>
     */
    public static function for(Platform $platform): array
    {
        if (! config('trypost.inbox.enabled')) {
            return [];
        }

        return (array) config("trypost.inbox.scopes.{$platform->value}", []);
    }
}
