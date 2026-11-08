<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Trend;

use App\Actions\Trend\CreateIdeaFromTrend;
use App\Http\Resources\Api\IdeaResource;
use App\Http\Resources\App\TrendTopicResource;
use App\Mcp\Concerns\AuthorizesTrendTool;
use App\Models\Idea;
use App\Models\TrendTopic;
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
#[IsOpenWorld(false)]
#[Description('Put a trending topic on the current workspace\'s ideas board (no stage) and mark the topic used. The idea gets the topic as its title, the formats to try and the evidence links; edit it with update-idea-tool, or write the post yourself with create-post-tool. A topic that is already used makes another idea.')]
class CreateIdeaFromTrendTool extends Tool
{
    use AuthorizesTrendTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->trendsWorkspace($request, 'createPost');

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        if ($denied = $this->denyUnlessCan($request, 'create', Idea::class)) {
            return $denied;
        }

        $validated = $request->validate(['topic_id' => ['required', 'uuid']]);

        $topic = $workspace->trendTopics()->find($validated['topic_id']);

        if (! $topic instanceof TrendTopic) {
            return Response::error('Trend topic not found.');
        }

        $idea = CreateIdeaFromTrend::execute($workspace, $request->user(), $topic);

        return Response::structured([
            'idea' => (new IdeaResource($idea->load('labels')))->resolve(),
            'topic' => (new TrendTopicResource($topic->refresh()))->resolve(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'topic_id' => $schema->string()->description('The topic ID from get-trend-brief-tool or list-trends-tool.')->required(),
        ];
    }
}
