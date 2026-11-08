<?php

declare(strict_types=1);

namespace App\Console\Commands\Assisted;

use App\Actions\Post\Assisted\CancelAssistedPost;
use App\Models\Post;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('x-assist:cancel {post : The post you did not publish}')]
#[Description('Fail an assisted X post whose window was closed without publishing')]
class CancelAssistedPostCommand extends Command
{
    public function handle(CancelAssistedPost $cancel): int
    {
        $post = Post::query()->find((string) $this->argument('post'));

        if (! $post instanceof Post) {
            $this->error('Post not found.');

            return self::FAILURE;
        }

        if (! $cancel->handle($post)) {
            $this->error('This post is not waiting to be published on X.');

            return self::FAILURE;
        }

        $this->info('Marked as not posted.');

        return self::SUCCESS;
    }
}
