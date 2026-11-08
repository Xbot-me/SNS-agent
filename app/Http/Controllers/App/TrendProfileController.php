<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\RssFeed\BuildRssFeedsPageProps;
use App\Actions\Trend\RequestTrendAnalysis;
use App\Actions\Trend\SaveTrendProfile;
use App\Enums\Trend\Goal;
use App\Enums\Trend\PostFormat;
use App\Http\Requests\App\Trend\UpdateTrendProfileRequest;
use App\Http\Resources\App\TrendProfileResource;
use App\Models\RssFeed;
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
