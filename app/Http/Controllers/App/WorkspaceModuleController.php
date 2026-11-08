<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Workspace\UpdateWorkspaceModules;
use App\Http\Requests\App\Workspace\UpdateWorkspaceModulesRequest;
use Illuminate\Http\Response;

class WorkspaceModuleController extends Controller
{
    public function update(UpdateWorkspaceModulesRequest $request): Response
    {
        UpdateWorkspaceModules::execute($request->user()->currentWorkspace, $request->validated());

        return response()->noContent();
    }
}
