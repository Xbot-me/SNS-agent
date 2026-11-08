<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Inbox;

use App\Enums\Inbox\ConversationKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListInboxRequest extends FormRequest
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
            'kind' => ['nullable', Rule::enum(ConversationKind::class)],
            'channel' => ['nullable', 'uuid'],
            'unread' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:200'],
        ];
    }
}
