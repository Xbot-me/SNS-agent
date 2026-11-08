<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Trend\CreateIdeaFromTrend;
use App\Models\Idea;
use App\Models\TrendTopic;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TrendTopicIdeaController extends Controller
{
    public function store(Request $request, TrendTopic $trendTopic): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        $this->authorize('create', Idea::class);
        abort_unless($trendTopic->workspace_id === $workspace->id, 404);

        $idea = CreateIdeaFromTrend::execute($workspace, $request->user(), $trendTopic);

        return to_route('app.create.ideas.show', $idea);
    }
}
