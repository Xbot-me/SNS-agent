<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Jobs\Inbox\SyncInbox;
use App\Models\SocialAccount;
use App\Services\Inbox\InboxProviderFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class InboxSyncController extends Controller
{
    /**
     * Reads every inbox channel of the workspace now instead of waiting for
     * its next turn. The page polls, so the results show up as they land.
     */
    public function store(Request $request): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        $this->authorize('createPost', $workspace);

        $workspace->socialAccounts()
            ->connected()
            ->apiBacked()
            ->whereIn('platform', InboxProviderFactory::platforms())
            ->get()
            ->each(fn (SocialAccount $account) => SyncInbox::dispatch($account));

        return back();
    }
}
