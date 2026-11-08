<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Trend;

use App\Actions\Trend\BuildTrendBrief;
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

#[IsReadOnly(true)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
#[Description('Start here when asked to create content for the workspace\'s niche. Returns the Trends profile (niche, audience, goal, the formats the workspace makes, posts per week), the top trending topics best first (each with its evidence links, how many sources cover it, the formats to try and how the workspace\'s own posts did on it), which formats worked for this audience, and the recent posts to avoid repeating. It reads what the last analysis stored; call run-trend-analysis-tool to refresh it. It writes nothing and calls no model: write the posts yourself, then use create-post-tool or create-idea-from-trend-tool.')]
class GetTrendBriefTool extends Tool
{
    use AuthorizesTrendTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->trendsWorkspace($request, 'createPost');

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $brief = BuildTrendBrief::execute($workspace);

        if ($brief === null) {
            return Response::error('Trends is not set up for this workspace yet. A workspace admin finishes the Trends setup in the app first.');
        }

        return Response::structured($brief);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
