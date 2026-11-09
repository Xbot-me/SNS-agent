<?php

declare(strict_types=1);

namespace App\Services\Trend\Sources;

use App\Dto\Trend\TrendSignal;
use App\Enums\SocialAccount\Platform;
use App\Enums\Trend\PostFormat;
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
 * The most popular public Threads posts (`search_type=TOP`) for three kinds of query, one request each:
 *
 * - the profile's first words, as keywords;
 * - the profile's topic tags (`search_mode=TAG`): a Threads community gathers the posts of its tag,
 *   so following "3D Printing" reads that community's top posts;
 * - the watched creators (`author_username`), searched on the profile's first word, since Meta
 *   requires a query. Each creator is its own outlet, so several of them on one subject corroborate it.
 *
 * Threads does not document like or reply counts on these posts, so there is no engagement
 * figure; being a top result is the signal. Each post's `media_type` gives its format. It needs the
 * `threads_keyword_search` permission approved for the Meta app and granted to the workspace's Threads
 * channel: without it Meta only searches the channel's own posts, which this source refuses to treat
 * as trends. Meta allows 2,200 queries per user per 24 hours, far above what an analysis sends.
 *
 * @see https://developers.facebook.com/docs/threads/keyword-search
 */
class ThreadsSearchSource implements TrendSource
{
    public const string SCOPE = 'threads_keyword_search';

    private const int TEXT_LENGTH = 200;

    private const string FIELDS = 'id,text,permalink,timestamp,username,media_type';

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
        $queries = $this->queries($profile);

        if ($account === null || $queries === []) {
            return collect();
        }

        $token = AnalyticsAccessToken::for($account);
        $api = (string) config('trypost.platforms.threads.graph_api');
        $failure = null;
        $signals = collect();

        foreach ($queries as $query) {
            try {
                $posts = Http::acceptJson()
                    ->withToken($token)
                    ->timeout(15)
                    ->get("{$api}/keyword_search", [
                        ...$query['params'],
                        'search_type' => 'TOP',
                        'since' => $since->getTimestamp(),
                        'fields' => self::FIELDS,
                    ])
                    ->throw()
                    ->json('data', []);
            } catch (ConnectionException|RequestException $exception) {
                $failure ??= $exception;

                continue;
            }

            $signals = $signals->merge(
                collect($posts)->map(fn (array $post): ?TrendSignal => $this->signal($post, $since, $query['outlet'], $query['outlet_name']))->filter()->values(),
            );
        }

        if ($signals->isEmpty() && $failure !== null) {
            throw SourceUnavailableException::from($failure);
        }

        return $signals;
    }

    /**
     * Every request one analysis sends, with the outlet its posts count under.
     *
     * @return list<array{params: array<string, string>, outlet: string, outlet_name: string}>
     */
    private function queries(TrendProfile $profile): array
    {
        $words = array_slice($profile->watchedWords(), 0, (int) config('trypost.trends.sources.threads_search.max_queries'));
        $tags = array_slice($profile->threadsTopicTags(), 0, (int) config('trypost.trends.sources.threads_search.max_topics'));
        $creators = array_slice($profile->threadsCreatorHandles(), 0, (int) config('trypost.trends.sources.threads_search.max_creators'));
        $queries = [];

        foreach ($words as $word) {
            $queries[] = ['params' => ['q' => $word], 'outlet' => 'threads', 'outlet_name' => 'Threads'];
        }

        foreach ($tags as $tag) {
            $key = mb_strtolower($tag);
            $queries[] = [
                'params' => ['q' => $tag, 'search_mode' => 'TAG'],
                'outlet' => "threads_tag:{$key}",
                'outlet_name' => "Threads #{$tag}",
            ];
        }

        $firstWord = data_get($words, 0);

        if (filled($firstWord)) {
            foreach ($creators as $handle) {
                $queries[] = [
                    'params' => ['q' => $firstWord, 'author_username' => $handle],
                    'outlet' => "threads:{$handle}",
                    'outlet_name' => "@{$handle} · Threads",
                ];
            }
        }

        return $queries;
    }

    /**
     * @param  array<string, mixed>  $post
     */
    private function signal(array $post, CarbonImmutable $since, string $outlet, string $outletName): ?TrendSignal
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
            outlet: $outlet,
            outletName: $outletName,
            title: $text,
            url: $permalink,
            publishedAt: $publishedAt,
            format: PostFormat::fromMetaMediaType(data_get($post, 'media_type')),
        );
    }
}
