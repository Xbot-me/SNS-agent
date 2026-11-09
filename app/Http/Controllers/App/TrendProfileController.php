<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\RssFeed\BuildRssFeedsPageProps;
use App\Actions\Trend\RequestTrendAnalysis;
use App\Actions\Trend\SaveTrendProfile;
use App\Enums\Trend\ExternalSource;
use App\Enums\Trend\Goal;
use App\Enums\Trend\PostFormat;
use App\Http\Requests\App\Trend\UpdateTrendProfileRequest;
use App\Http\Resources\App\TrendProfileResource;
use App\Models\RssFeed;
use App\Services\Trend\Sources\InstagramCompetitorSource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TrendProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        $workspace = $request->user()->currentWorkspace;

        $this->authorize('update', $workspace);

        $profile = $workspace->trendProfile;

        return Inertia::render('trends/Setup', [
            'profile' => $profile === null ? null : TrendProfileResource::make($profile)->resolve($request),
            'niches' => collect((array) config('trypost.trends.niches'))
                ->map(fn (array $niche, string $key): array => [
                    'key' => $key,
                    'keywords' => data_get($niche, 'keywords'),
                    'feed_category' => data_get($niche, 'feed_category'),
                ])
                ->values()
                ->all(),
            'externalSources' => array_map(
                fn (ExternalSource $source): array => ['key' => $source->value, 'default' => $source->isDefault()],
                ExternalSource::availableFor($workspace),
            ),
            'regions' => (array) config('trypost.trends.regions'),
            'canWatchCompetitors' => config('trypost.trends.sources.instagram_competitors.enabled') && InstagramCompetitorSource::usableAccount($workspace->id) !== null,
            'maxCompetitors' => (int) config('trypost.trends.sources.instagram_competitors.max_accounts'),
            'maxThreadsTopics' => (int) config('trypost.trends.sources.threads_search.max_topics'),
            'maxThreadsCreators' => (int) config('trypost.trends.sources.threads_search.max_creators'),
            'goals' => Goal::values(),
            'formats' => PostFormat::values(),
            'feeds' => $workspace->rssFeeds()
                ->oldest()
                ->get()
                ->map(fn (RssFeed $feed): array => ['id' => $feed->id, 'title' => $feed->display_title, 'url' => $feed->url])
                ->all(),
            'directory' => BuildRssFeedsPageProps::directory($workspace),
            'limits' => [
                'keywords' => (int) config('trypost.trends.max_keywords'),
                'feeds' => (int) config('trypost.trends.max_feeds'),
            ],
        ]);
    }

    public function update(UpdateTrendProfileRequest $request): RedirectResponse
    {
        $profile = SaveTrendProfile::execute($request->user()->currentWorkspace, $request->validated());

        RequestTrendAnalysis::execute($profile);

        return to_route('app.trends.index');
    }
}
