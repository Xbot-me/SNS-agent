<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Inbox\ListInboxConversations;
use App\Http\Requests\App\Inbox\ListInboxRequest;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\Inbox\InboxProviderFactory;
use App\Support\Inbox\InboxSyncStatus;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class InboxController extends Controller
{
    public function index(ListInboxRequest $request): Response|RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('app.workspaces.create');
        }

        $this->authorize('createPost', $workspace);

        return $this->render($workspace, $request->validated(), null);
    }

    public function show(ListInboxRequest $request, InboxConversation $conversation): Response|RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('app.workspaces.create');
        }

        $this->authorize('createPost', $workspace);
        abort_unless($conversation->workspace_id === $workspace->id, 404);

        if ($conversation->isUnread()) {
            $conversation->forceFill(['read_at' => now()])->save();
        }

        return $this->render($workspace, $request->validated(), $conversation);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function render(Workspace $workspace, array $filters, ?InboxConversation $conversation): Response
    {
        $conversations = ListInboxConversations::execute($workspace, $filters)
            ->paginate((int) config('app.pagination.default'))
            ->through(fn (InboxConversation $item): array => $this->summary($item));

        return Inertia::render('inbox/Index', [
            'conversations' => Inertia::scroll(fn () => $conversations),
            'conversation' => $conversation === null ? null : $this->detail($conversation),
            'channels' => $this->channels($workspace),
            'unreadCount' => ListInboxConversations::execute($workspace)->unread()->count(),
            'hasData' => InboxConversation::query()->where('workspace_id', $workspace->id)->exists(),
            'filters' => [
                'kind' => data_get($filters, 'kind'),
                'channel' => data_get($filters, 'channel'),
                'unread' => filter_var(data_get($filters, 'unread'), FILTER_VALIDATE_BOOLEAN),
                'search' => data_get($filters, 'search', ''),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(InboxConversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'kind' => $conversation->kind->value,
            'platform' => $conversation->platform->value,
            'network_name' => $conversation->platform->networkName(),
            'participant_name' => $conversation->participant_name,
            'participant_username' => $conversation->participant_username,
            'participant_avatar_url' => $conversation->participant_avatar_url,
            'subject' => $conversation->subject,
            'last_message_excerpt' => $conversation->last_message_excerpt,
            'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            'is_unread' => $conversation->isUnread(),
            'rating' => data_get($conversation->meta, 'rating'),
            'channel' => [
                'id' => $conversation->socialAccount?->id,
                'name' => $conversation->socialAccount?->accountDisplayName(),
                'platform' => $conversation->socialAccount?->platform->value,
                'avatar_url' => $conversation->socialAccount?->avatar_url,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(InboxConversation $conversation): array
    {
        return [
            ...$this->summary($conversation),
            'permalink' => $conversation->permalink,
            'messages' => $conversation->messages()
                ->orderBy('sent_at')
                ->orderBy('id')
                ->get()
                ->map(fn (InboxMessage $message): array => [
                    'id' => $message->id,
                    'body' => $message->body,
                    'author_name' => $message->author_name,
                    'author_username' => $message->author_username,
                    'author_avatar_url' => $message->author_avatar_url,
                    'is_own' => $message->is_own,
                    'attachments' => $message->attachments ?? [],
                    'sent_at' => $message->sent_at->toIso8601String(),
                ])
                ->all(),
        ];
    }

    /**
     * The workspace's channels the inbox reads, with what their last read
     * reported, so the page can ask for a reconnect.
     *
     * @return list<array<string, mixed>>
     */
    private function channels(Workspace $workspace): array
    {
        return $workspace->socialAccounts()
            ->apiBacked()
            ->whereIn('platform', InboxProviderFactory::platforms())
            ->get()
            ->map(fn (SocialAccount $account): array => [
                'id' => $account->id,
                'name' => $account->accountDisplayName(),
                'platform' => $account->platform->value,
                'avatar_url' => $account->avatar_url,
                'is_connected' => ! $account->isDisconnected(),
                ...InboxSyncStatus::for($account),
            ])
            ->values()
            ->all();
    }
}
