<?php

declare(strict_types=1);

namespace App\Actions\Trend;

use App\Models\TrendProfile;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

class SaveTrendProfile
{
    /**
     * Creates or updates the workspace's one profile. The first save finishes the setup.
     *
     * @param  array<string, mixed>  $data
     */
    public static function execute(Workspace $workspace, array $data): TrendProfile
    {
        return DB::transaction(function () use ($workspace, $data): TrendProfile {
            $profile = TrendProfile::query()->firstOrNew(['workspace_id' => $workspace->id]);

            $profile->fill([
                'niche' => trim((string) data_get($data, 'niche')),
                'audience' => filled(data_get($data, 'audience')) ? trim((string) data_get($data, 'audience')) : null,
                'goal' => data_get($data, 'goal'),
                'keywords' => self::words((array) data_get($data, 'keywords', [])),
                'excluded_keywords' => self::words((array) data_get($data, 'excluded_keywords', [])),
                'formats' => array_values(array_unique((array) data_get($data, 'formats', []))),
                'posts_per_week' => (int) data_get($data, 'posts_per_week'),
                'region' => data_get($data, 'region'),
                'competitors' => self::handles((array) data_get($data, 'competitors', [])),
                'threads_topics' => self::words(array_map(fn (mixed $tag): string => ltrim(trim((string) $tag), '#'), (array) data_get($data, 'threads_topics', []))),
                'threads_creators' => self::handles((array) data_get($data, 'threads_creators', [])),
                'external_sources' => array_values(array_unique((array) data_get($data, 'external_sources', []))),
            ]);
            $profile->onboarded_at ??= now();
            $profile->save();

            $profile->rssFeeds()->sync(
                $workspace->rssFeeds()->whereIn('id', (array) data_get($data, 'rss_feed_ids', []))->pluck('id')->all(),
            );

            return $profile;
        });
    }

    /**
     * Instagram and Threads usernames as stored: no @, lowercase, each once.
     *
     * @param  array<int, mixed>  $handles
     * @return list<string>
     */
    private static function handles(array $handles): array
    {
        return collect($handles)
            ->map(fn (mixed $handle): string => mb_strtolower(ltrim(trim((string) $handle), '@')))
            ->filter(fn (string $handle): bool => filled($handle))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, mixed>  $words
     * @return list<string>
     */
    private static function words(array $words): array
    {
        return collect($words)
            ->map(fn (mixed $word): string => trim((string) $word))
            ->filter(fn (string $word): bool => filled($word))
            ->unique(fn (string $word): string => mb_strtolower($word))
            ->values()
            ->all();
    }
}
