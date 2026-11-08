<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Http\Requests\App\Trend\UpdateTrendTopicRequest;
use App\Http\Resources\App\TrendProfileResource;
use App\Http\Resources\App\TrendTopicResource;
use App\Models\TrendTopic;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TrendTopicController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        $this->authorize('createPost', $workspace);

        $profile = $workspace->trendProfile;

        if ($profile === null || $profile->onboarded_at === null) {
            return to_route('app.trends.profile.edit');
        }

        return Inertia::render('trends/Index', [
            'profile' => TrendProfileResource::make($profile)->resolve($request),
            'topics' => TrendTopicResource::collection(
                $workspace->trendTopics()->ranked()->limit((int) config('trypost.trends.max_topics'))->get(),
            )->resolve($request),
            'feedCount' => $profile->rssFeeds()->count(),
            'canManage' => $request->user()->can('update', $workspace),
        ]);
    }

    public function update(UpdateTrendTopicRequest $request, TrendTopic $trendTopic): RedirectResponse
    {
        abort_unless($trendTopic->workspace_id === $request->user()->currentWorkspace->id, 404);

        $trendTopic->update(['status' => $request->validated('status')]);

        return back();
    }
}
