<?php

declare(strict_types=1);

namespace App\Console\Commands\Assisted;

use App\Actions\Post\Assisted\CompleteAssistedPost;
use App\Models\Post;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('x-assist:complete {post : The post you published} {url : Its status URL on X}')]
#[Description('Mark an assisted X post as published, with the URL X gave it')]
class CompleteAssistedPostCommand extends Command
{
    public function handle(CompleteAssistedPost $complete): int
    {
        $post = Post::query()->find((string) $this->argument('post'));

        if (! $post instanceof Post) {
            $this->error('Post not found.');

            return self::FAILURE;
        }

        try {
            $completed = $complete->handle($post, (string) $this->argument('url'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if (! $completed) {
            $this->error('This post is not waiting to be published on X.');

            return self::FAILURE;
        }

        $this->info('Marked as published.');

        return self::SUCCESS;
    }
}
