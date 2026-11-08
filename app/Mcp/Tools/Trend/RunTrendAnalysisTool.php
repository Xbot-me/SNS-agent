<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Trend;

use App\Actions\Trend\RequestTrendAnalysis;
use App\Mcp\Concerns\AuthorizesTrendTool;
use App\Models\Workspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsOpenWorld(true)]
#[Description('Refresh the current workspace\'s trends now: it reads the workspace\'s feeds and the other sources chosen in the Trends setup (Google Trends, Hacker News, YouTube, watched Instagram accounts) and ranks the topics again. It runs in the background and takes a few seconds; call get-trend-brief-tool afterwards. It is already refreshed every few hours, so use it only when fresher data matters. Needs a workspace admin; it does nothing if an analysis is already running.')]
class RunTrendAnalysisTool extends Tool
{
    use AuthorizesTrendTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->trendsWorkspace($request, 'update');

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $profile = $workspace->trendProfile;

        if ($profile === null || $profile->onboarded_at === null) {
            return Response::error('Trends is not set up for this workspace yet. A workspace admin finishes the Trends setup in the app first.');
        }

        $queued = RequestTrendAnalysis::execute($profile);

        return Response::structured([
            'queued' => $queued,
            'analyzing' => $profile->fresh()->isAnalyzing(),
            'analyzed_at' => $profile->fresh()->analyzed_at?->toIso8601String(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
