<?php

declare(strict_types=1);

namespace App\Services\Trend\Sources;

use App\Dto\Trend\TrendSignal;
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
 * The top Instagram posts of the profile's first words, searched as hashtags, with
 * their likes and comments as engagement. Read through the workspace's Instagram
 * channel linked through Facebook. Meta only allows 30 different hashtags per rolling
 * 7 days and a search for one already used does not spend another, so only the first
 * few words are searched and the same ones are reused. The app needs the "Instagram
 * Public Content Access" feature approved; without it Meta refuses and the source
 * reports a missing permission.
 *
 * @see https://developers.facebook.com/docs/instagram-platform/instagram-graph-api/reference/ig-hashtag/top-media
 */
class InstagramHashtagSource implements TrendSource
{
    private const int POSTS_PER_HASHTAG = 25;

    private const int CAPTION_LENGTH = 200;

    public function signals(TrendProfile $profile, CarbonImmutable $since): Collection
    {
        $account = InstagramCompetitorSource::usableAccount($profile->workspace_id);
        $hashtags = $this->hashtags($profile);

        if ($account === null || $hashtags === []) {
            return collect();
        }

        $token = AnalyticsAccessToken::for($account);
        $failure = null;
        $signals = collect();

        foreach ($hashtags as $hashtag) {
            try {
                $signals = $signals->merge($this->postsFor($hashtag, $account, $token, $since));
            } catch (ConnectionException|RequestException $exception) {
                $failure ??= $exception;
            }
        }

        // Some hashtags failing leaves the others; every one failing is a source that cannot be read.
        if ($signals->isEmpty() && $failure !== null) {
            throw SourceUnavailableException::from($failure);
        }

        return $signals;
    }

    /**
     * The hashtags of the profile's words ("3d print" becomes #3dprint), the first few only.
     *
     * @return list<string>
     */
    private function hashtags(TrendProfile $profile): array
    {
        return collect($profile->watchedWords())
            ->map(fn (string $word): string => Str::of($word)->lower()->replaceMatches('/[^\p{L}\p{N}]+/u', '')->toString())
            ->filter(fn (string $tag): bool => filled($tag))
            ->unique()
            ->take((int) config('trypost.trends.sources.instagram_hashtags.max_queries'))
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, TrendSignal>
     */
    private function postsFor(string $hashtag, SocialAccount $account, string $token, CarbonImmutable $since): Collection
    {
        $base = $account->platform->instagramGraphBaseUrl();
        $client = fn () => Http::acceptJson()->withToken($token)->timeout(15);

        $id = $client()->get("{$base}/ig_hashtag_search", [
            'user_id' => $account->platform_user_id,
            'q' => $hashtag,
        ])->throw()->json('data.0.id');

        if (blank($id)) {
            return collect();
        }

        $posts = $client()->get("{$base}/{$id}/top_media", [
            'user_id' => $account->platform_user_id,
            'fields' => 'caption,like_count,comments_count,media_type,permalink,timestamp',
            'limit' => self::POSTS_PER_HASHTAG,
        ])->throw()->json('data', []);

        return collect($posts)
            ->map(fn (array $post): ?TrendSignal => $this->signal($hashtag, $post, $since))
            ->filter()
            ->values();
    }

    /**
     * @param  array<string, mixed>  $post
     */
    private function signal(string $hashtag, array $post, CarbonImmutable $since): ?TrendSignal
    {
        $caption = Str::of((string) data_get($post, 'caption'))->squish()->limit(self::CAPTION_LENGTH, '')->toString();
        $permalink = (string) data_get($post, 'permalink');
        $timestamp = data_get($post, 'timestamp');

        if (blank($caption) || blank($permalink) || blank($timestamp)) {
            return null;
        }

        $publishedAt = CarbonImmutable::parse((string) $timestamp);

        if ($publishedAt->lt($since)) {
            return null;
        }

        return new TrendSignal(
            outlet: "instagram_hashtag:{$hashtag}",
            outletName: "#{$hashtag}",
            title: $caption,
            url: $permalink,
            publishedAt: $publishedAt,
            // Meta leaves like_count out when the owner hides likes; comments still count.
            engagement: (int) data_get($post, 'like_count', 0) + (int) data_get($post, 'comments_count', 0),
        );
    }
}
