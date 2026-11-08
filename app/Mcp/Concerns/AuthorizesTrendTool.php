<?php

declare(strict_types=1);

namespace App\Mcp\Concerns;

use App\Enums\Workspace\Module;
use App\Models\Workspace;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

/**
 * What every Trends tool shares: it is not listed at all while the app has
 * Trends off, and a workspace that switched it off gets the same refusal the
 * web gives (its pages answer 404).
 */
trait AuthorizesTrendTool
{
    use AuthorizesMcpTool;

    public function shouldRegister(): bool
    {
        return Module::Trends->isAvailable();
    }

    /**
     * The token's workspace, after the same ability check as the matching web route,
     * or a tool error when the workspace has Trends off.
     */
    protected function trendsWorkspace(Request $request, string $ability, mixed $arguments = null): Workspace|Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace($request, $ability, $arguments);

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        if (! $workspace->hasModule(Module::Trends)) {
            return Response::error('Trends is turned off for this workspace.');
        }

        return $workspace;
    }
}
