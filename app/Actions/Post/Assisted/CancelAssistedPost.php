<?php

declare(strict_types=1);

namespace App\Actions\Post\Assisted;

use App\Actions\Post\FinalizePostPublication;
use App\Enums\Post\PublishStatus;
use App\Events\PostStatusUpdated;
use App\Models\Post;
use Illuminate\Support\Facades\DB;

/**
 * The user closed the assisted posting window without publishing: the post
 * fails with a message saying so, and can be retried like any failed post.
 */
class CancelAssistedPost
{
    public function __construct(private readonly FinalizePostPublication $finalize) {}

    public function handle(Post $post): bool
    {
        $cancelled = DB::transaction(function () use ($post): bool {
            $locked = Post::query()->with('socialAccount')->whereKey($post->id)->lockForUpdate()->first();

            if (! $locked instanceof Post
                || $locked->publish_status !== PublishStatus::PendingReview
                || $locked->socialAccount?->isAssisted() !== true) {
                return false;
            }

            $locked->markPublicationFailed(__('posts.errors.assisted_window_closed'), [
                'category' => 'assisted_window_closed',
                'failed_at' => now()->toIso8601String(),
            ]);

            return true;
        });

        if (! $cancelled) {
            return false;
        }

        $this->finalize->handle($post);
        PostStatusUpdated::dispatch($post->fresh());

        return true;
    }
}
