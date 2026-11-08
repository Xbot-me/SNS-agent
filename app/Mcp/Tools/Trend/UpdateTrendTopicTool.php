<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Trend;

use App\Enums\Trend\TopicStatus;
use App\Http\Resources\App\TrendTopicResource;
use App\Mcp\Concerns\AuthorizesTrendTool;
use App\Models\Idea;
use App\Models\TrendTopic;
use App\Models\Workspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsDestructive(true)]
#[IsOpenWorld(false)]
#[Description('Dismiss a trending topic that does not fit the workspace (status dismissed: it leaves the list and stays dismissed when it comes back), or bring a dismissed one back (status new). A topic becomes used only through create-idea-from-trend-tool.')]
class UpdateTrendTopicTool extends Tool
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

        // The record is looked up first, so a topic of another workspace is "not found" whatever else was sent.
        $topic = $workspace->trendTopics()->find($request->validate(['topic_id' => ['required', 'uuid']])['topic_id']);

        if (! $topic instanceof TrendTopic) {
            return Response::error('Trend topic not found.');
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in([TopicStatus::Dismissed->value, TopicStatus::New->value])],
        ]);

        $topic->update(['status' => $validated['status']]);

        return Response::structured((new TrendTopicResource($topic))->resolve());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'topic_id' => $schema->string()->description('The topic ID from get-trend-brief-tool or list-trends-tool.')->required(),
            'status' => $schema->string()->enum(['dismissed', 'new'])->description('dismissed to hide the topic, new to bring it back.')->required(),
        ];
    }
}
