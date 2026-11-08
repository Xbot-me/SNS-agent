<?php

declare(strict_types=1);

namespace App\Actions\Post\Assisted;

use App\Dto\MediaItem;
use App\Enums\Post\PublishStatus;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Services\Social\ContentSanitizer;
use Illuminate\Support\Facades\Storage;

/**
 * The posts waiting for the user to publish them in the assisted posting
 * window, oldest first, shaped for the `npm run x:assist` helper.
 */
class ListAssistedPosts
{
    public function __construct(private readonly ContentSanitizer $sanitizer) {}

    /**
     * @return list<array{id: string, handle: string, text: string, media: list<array{type: string|null, mime_type: string|null, path: string|null, url: string}>}>
     */
    public function handle(): array
    {
        return Post::query()
            ->with('socialAccount')
            ->where('platform', Platform::X)
            ->where('publish_status', PublishStatus::PendingReview)
            ->whereHas('socialAccount', fn ($query) => $query->assisted())
            ->orderBy('submitted_at')
            ->get()
            ->map(fn (Post $post): array => [
                'id' => $post->id,
                'handle' => (string) $post->socialAccount->username,
                'text' => (string) $this->sanitizer->forPost($post),
                'media' => $post->mediaItems
                    ->map(fn (MediaItem $item): array => [
                        'type' => $item->type?->value,
                        'mime_type' => $item->mime_type,
                        'path' => $this->localPath($item),
                        'url' => $item->url,
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * The file on this machine, so the helper attaches it without downloading.
     * Null when media lives on a remote disk; the helper then uses the URL.
     */
    private function localPath(MediaItem $item): ?string
    {
        $disk = Storage::disk();
        $diskName = config('filesystems.default');

        if (config("filesystems.disks.{$diskName}.driver") !== 'local' || ! $disk->exists($item->path)) {
            return null;
        }

        return $disk->path($item->path);
    }
}
