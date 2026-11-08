<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Workspace;

use App\Enums\Workspace\Module;
use Illuminate\Foundation\Http\FormRequest;

class UpdateWorkspaceModulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $workspace = $this->user()->currentWorkspace;

        return $workspace !== null && $this->user()->can('update', $workspace);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return collect(Module::values())
            ->mapWithKeys(fn (string $module): array => [$module => ['sometimes', 'boolean']])
            ->all();
    }
}
