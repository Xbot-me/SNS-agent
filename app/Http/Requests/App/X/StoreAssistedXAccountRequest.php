<?php

declare(strict_types=1);

namespace App\Http\Requests\App\X;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An X account added for assisted posting: its handle only, as X allows it
 * (1–15 letters, digits or underscores, with or without the leading @).
 */
class StoreAssistedXAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'handle' => ['required', 'string', 'regex:/^@?[A-Za-z0-9_]{1,15}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'handle.required' => __('accounts.x_assisted.handle_invalid'),
            'handle.regex' => __('accounts.x_assisted.handle_invalid'),
        ];
    }

    /** The handle without its @, as X shows it in profile URLs. */
    public function handle(): string
    {
        return ltrim((string) $this->validated('handle'), '@');
    }
}
