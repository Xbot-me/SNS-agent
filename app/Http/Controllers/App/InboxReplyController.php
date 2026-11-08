<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Inbox\ReplyToInboxConversation;
use App\Exceptions\Inbox\InboxException;
use App\Http\Requests\App\Inbox\StoreInboxReplyRequest;
use App\Models\InboxConversation;
use Illuminate\Http\RedirectResponse;

class InboxReplyController extends Controller
{
    public function store(StoreInboxReplyRequest $request, InboxConversation $conversation): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        $this->authorize('createPost', $workspace);
        abort_unless($conversation->workspace_id === $workspace?->id, 404);

        try {
            ReplyToInboxConversation::execute($conversation, (string) $request->validated('body'));
        } catch (InboxException $exception) {
            return back()->withErrors(['body' => $this->message($conversation, $exception)]);
        }

        return back();
    }

    private function message(InboxConversation $conversation, InboxException $exception): string
    {
        $network = $conversation->platform->networkName();

        return match ($exception->category) {
            'reply_window' => __('inbox.errors.reply_window', ['network' => $network]),
            'authentication', 'permission' => __('inbox.errors.reconnect', ['network' => $network]),
            default => filled($exception->providerMessage)
                ? __('inbox.errors.reply_failed_with_reason', ['network' => $network, 'reason' => $exception->providerMessage])
                : __('inbox.errors.reply_failed', ['network' => $network]),
        };
    }
}
