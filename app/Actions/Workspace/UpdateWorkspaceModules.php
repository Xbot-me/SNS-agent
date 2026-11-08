<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Enums\Workspace\Module;
use App\Models\Workspace;

class UpdateWorkspaceModules
{
    /**
     * @param  array<string, mixed>  $states  module => on/off; modules not named keep their state
     */
    public static function execute(Workspace $workspace, array $states): Workspace
    {
        $changes = collect(Module::values())
            ->filter(fn (string $module): bool => data_get($states, $module) !== null)
            ->mapWithKeys(fn (string $module): array => [$module => (bool) data_get($states, $module)])
            ->all();

        $workspace->update(['modules' => [...($workspace->modules ?? []), ...$changes]]);

        return $workspace;
    }
}
