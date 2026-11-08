<?php

declare(strict_types=1);

namespace App\Console\Commands\Assisted;

use App\Actions\Post\Assisted\ListAssistedPosts;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('x-assist:pending')]
#[Description('Print, as JSON, the X posts waiting for you to publish them in the assisted posting window')]
class ListAssistedPostsCommand extends Command
{
    public function handle(ListAssistedPosts $posts): int
    {
        $this->line((string) json_encode($posts->handle(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
