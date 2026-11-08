<?php

declare(strict_types=1);

namespace App\Support\Inbox;

use App\Enums\Inbox\ConversationKind;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * When each channel's inbox was last read and what stopped it: an error
 * category from InboxException, and the kinds its login did not grant.
 * Kept in the cache, since it only steers the next read and a banner.
 */
class InboxSyncStatus
{
    /**
     * @param  list<ConversationKind>  $missing
     */
    public static function record(SocialAccount $account, array $missing = [], ?string $error = null): void
    {
        Cache::forever(self::key($account), [
            'synced_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'missing' => array_map(fn (ConversationKind $kind): string => $kind->value, $missing),
            'error' => $error,
        ]);
    }

    /**
     * @return array{synced_at: ?string, missing: list<string>, error: ?string}
     */
    public static function for(SocialAccount $account): array
    {
        $status = Cache::get(self::key($account), []);

        return [
            'synced_at' => data_get($status, 'synced_at'),
            'missing' => (array) data_get($status, 'missing', []),
            'error' => data_get($status, 'error'),
        ];
    }

    public static function isDue(SocialAccount $account, int $intervalMinutes): bool
    {
        $syncedAt = data_get(Cache::get(self::key($account)), 'synced_at');

        return blank($syncedAt)
            || CarbonImmutable::parse($syncedAt)->addMinutes($intervalMinutes)->subSeconds(30)->isPast();
    }

    public static function forget(SocialAccount $account): void
    {
        Cache::forget(self::key($account));
    }

    private static function key(SocialAccount $account): string
    {
        return "inbox:status:{$account->id}";
    }
}
