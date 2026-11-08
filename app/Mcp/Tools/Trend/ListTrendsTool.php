<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Trend;

use App\Enums\Trend\TopicStatus;
use App\Http\Resources\App\TrendTopicResource;
use App\Mcp\Concerns\AuthorizesTrendTool;
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

#[IsReadOnly(true)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
#[Description('List the trending topics the last analysis stored for the current workspace, best first. By default it leaves out dismissed topics; pass status to see only new, used (already on the ideas board) or dismissed ones. Each topic has its score, how many sources cover it, the formats to try, evidence links and how the workspace\'s own posts did on it.')]
class ListTrendsTool extends Tool
{
    use AuthorizesTrendTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->trendsWorkspace($request, 'createPost');

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $validated = $request->validate([
            'status' => ['sometimes', 'string', Rule::enum(TopicStatus::class)],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('trypost.trends.max_topics')],
        ]);

        $status = data_get($validated, 'status');

        $topics = $workspace->trendTopics()
            ->when($status !== null, fn ($query) => $query->where('status', $status)->orderByDesc('score')->orderBy('id'))
            ->when($status === null, fn ($query) => $query->ranked())
            ->limit((int) data_get($validated, 'limit', config('trypost.trends.max_topics')))
            ->get();

        return Response::structured(['topics' => TrendTopicResource::collection($topics)->resolve()]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->enum(array_map(fn (TopicStatus $status): string => $status->value, TopicStatus::cases()))->description('Only topics with this status. Omit to list every topic that is not dismissed.'),
            'limit' => $schema->integer()->description('How many topics to return (default and maximum: the number the workspace keeps).'),
        ];
    }
}
