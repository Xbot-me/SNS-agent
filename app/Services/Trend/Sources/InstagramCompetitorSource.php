<?php

declare(strict_types=1);

namespace App\Services\Trend\Sources;

use App\Dto\Trend\TrendSignal;
use App\Enums\SocialAccount\Platform;
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
 * Recent posts of the Instagram accounts the profile watches, read through
 * Instagram's business discovery with one of the workspace's own channels
 * (connected through Facebook). Only public Business and Creator accounts can be
 * looked up. Each watched account is its own outlet, so several of them posting
 * about the same subject counts as corroboration; likes plus comments are the
 * engagement, compared with that account's other posts.
 *
 * @see https://developers.facebook.com/docs/instagram-platform/instagram-api-with-facebook-login/business-discovery
 */
class InstagramCompetitorSource implements TrendSource
{
    private const int POSTS_PER_ACCOUNT = 12;

    private const int CAPTION_LENGTH = 200;

    /**
     * The channel business discovery is asked through: a connected Instagram account of the
     * workspace, linked through Facebook, that was granted `instagram_basic`.
     */
    public static function usableAccount(string $workspaceId): ?SocialAccount
    {
        return SocialAccount::query()
            ->where('workspace_id', $workspaceId)
            ->where('platform', Platform::InstagramFacebook->value)
            ->connected()
            ->apiBacked()
            ->get()
            ->first(fn (SocialAccount $account): bool => $account->missingScope('instagram_basic') === null);
    }

    public function signals(TrendProfile $profile, CarbonImmutable $since): Collection
    {
        $account = self::usableAccount($profile->workspace_id);
        $handles = array_slice($profile->competitorHandles(), 0, (int) config('trypost.trends.sources.instagram_competitors.max_accounts'));

        if ($account === null || $handles === []) {
            return collect();
        }

        $token = AnalyticsAccessToken::for($account);
        $base = $account->platform->instagramGraphBaseUrl();

        return collect($handles)->flatMap(function (string $handle) use ($account, $token, $base, $since): Collection {
            try {
                $posts = Http::acceptJson()
                    ->withToken($token)
                    ->timeout(15)
                    ->get("{$base}/{$account->platform_user_id}", [
                        'fields' => 'business_discovery.username('.$handle.'){username,media.limit('.self::POSTS_PER_ACCOUNT.'){caption,like_count,comments_count,timestamp,permalink}}',
                    ])
                    ->throw()
                    ->json('business_discovery.media.data', []);
            } catch (ConnectionException|RequestException) {
                // A personal, private or unknown account is refused; the others still count.
                return collect();
            }

            return collect($posts)
                ->map(fn (array $post): ?TrendSignal => $this->signal($handle, $post, $since))
                ->filter()
                ->values();
        });
    }

    /**
     * @param  array<string, mixed>  $post
     */
    private function signal(string $handle, array $post, CarbonImmutable $since): ?TrendSignal
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
            outlet: "instagram:{$handle}",
            outletName: "@{$handle}",
            title: $caption,
            url: $permalink,
            publishedAt: $publishedAt,
            engagement: (int) data_get($post, 'like_count', 0) + (int) data_get($post, 'comments_count', 0),
        );
    }
}
