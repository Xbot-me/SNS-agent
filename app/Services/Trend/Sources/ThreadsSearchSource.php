<?php

declare(strict_types=1);

namespace App\Services\Trend\Sources;

use App\Dto\Trend\TrendSignal;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Trend\SourceUnavailableException;
use App\Models\SocialAccount;
use App\Models\TrendProfile;
use App\Support\Analytics\AnalyticsAccessToken;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * The most popular public Threads posts for the profile's first words (`search_type=TOP`).
 * Threads does not document like or reply counts on these posts, so there is no engagement
 * figure; being a top result is the signal. It needs the `threads_keyword_search` permission
 * approved for the Meta app and granted to the workspace's Threads channel: without it Meta
 * only searches the channel's own posts, which this source refuses to treat as trends.
 *
 * @see https://developers.facebook.com/documentation/threads/keyword-search
 */
class ThreadsSearchSource implements TrendSource
{
    public const string SCOPE = 'threads_keyword_search';

    private const int TEXT_LENGTH = 200;

    /**
     * The Threads channel the search is asked through: connected, and granted the search permission.
     */
    public static function usableAccount(string $workspaceId): ?SocialAccount
    {
        return SocialAccount::query()
            ->where('workspace_id', $workspaceId)
            ->where('platform', Platform::Threads->value)
            ->connected()
            ->apiBacked()
            ->get()
            ->first(fn (SocialAccount $account): bool => in_array(self::SCOPE, (array) $account->scopes, true));
    }

    public function signals(TrendProfile $profile, CarbonImmutable $since): Collection
    {
        $account = self::usableAccount($profile->workspace_id);
        $words = array_slice($profile->watchedWords(), 0, (int) config('trypost.trends.sources.threads_search.max_queries'));

        if ($account === null || $words === []) {
            return collect();
        }

        $token = AnalyticsAccessToken::for($account);
        $api = (string) config('trypost.platforms.threads.graph_api');
        $failure = null;
        $signals = collect();

        foreach ($words as $word) {
            try {
                $posts = Http::acceptJson()
                    ->withToken($token)
                    ->timeout(15)
                    ->get("{$api}/keyword_search", [
                        'q' => $word,
                        'search_type' => 'TOP',
                        'fields' => 'id,text,permalink,timestamp,username,media_type',
                    ])
                    ->throw()
                    ->json('data', []);
            } catch (ConnectionException|RequestException $exception) {
                $failure ??= $exception;

                continue;
            }

            $signals = $signals->merge(collect($posts)->map(fn (array $post): ?TrendSignal => $this->signal($post, $since))->filter()->values());
        }

        if ($signals->isEmpty() && $failure !== null) {
            throw SourceUnavailableException::from($failure);
        }

        return $signals;
    }

    /**
     * @param  array<string, mixed>  $post
     */
    private function signal(array $post, CarbonImmutable $since): ?TrendSignal
    {
        $text = Str::of((string) data_get($post, 'text'))->squish()->limit(self::TEXT_LENGTH, '')->toString();
        $permalink = (string) data_get($post, 'permalink');
        $timestamp = data_get($post, 'timestamp');

        if (blank($text) || blank($permalink) || blank($timestamp)) {
            return null;
        }

        $publishedAt = CarbonImmutable::parse((string) $timestamp);

        if ($publishedAt->lt($since)) {
            return null;
        }

        return new TrendSignal(
            outlet: 'threads',
            outletName: 'Threads',
            title: $text,
            url: $permalink,
            publishedAt: $publishedAt,
        );
    }
}
