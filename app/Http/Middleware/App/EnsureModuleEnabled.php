<?php

declare(strict_types=1);

namespace App\Http\Middleware\App;

use App\Enums\Workspace\Module;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps a module's pages out of reach while it is off for the current
 * workspace: `->middleware('module:inbox')`. A module that is off does not
 * exist for the user, so it answers 404 like any unknown page.
 */
class EnsureModuleEnabled
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        $workspace = $request->user()?->currentWorkspace;

        abort_unless($workspace?->hasModule(Module::from($module)), Response::HTTP_NOT_FOUND);

        return $next($request);
    }
}
