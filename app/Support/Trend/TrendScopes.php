<?php

declare(strict_types=1);

namespace App\Support\Trend;

use App\Enums\SocialAccount\Platform;

/**
 * The extra scopes a network's login asks for so Trends can read its public
 * search (Threads keyword search). They are listed in the env and empty by
 * default: a permission the Meta app was not granted makes the consent screen
 * refuse the whole login, so nothing is asked for until the owner adds it.
 */
class TrendScopes
{
    /**
     * @return array<int, string>
     */
    public static function for(Platform $platform): array
    {
        return match ($platform) {
            Platform::Threads => (array) config('trypost.trends.sources.threads_search.scopes', []),
            default => [],
        };
    }
}
