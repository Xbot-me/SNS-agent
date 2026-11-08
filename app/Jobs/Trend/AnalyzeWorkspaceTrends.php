<?php

declare(strict_types=1);

namespace App\Jobs\Trend;

use App\Actions\Trend\AnalyzeTrends;
use App\Enums\Workspace\Module;
use App\Models\TrendProfile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class AnalyzeWorkspaceTrends implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 600;

    public int $tries = 1;

    public int $timeout = 120;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public TrendProfile $profile)
    {
        $this->onQueue('rss-feeds');
    }

    public function uniqueId(): string
    {
        return $this->profile->id;
    }

    public function handle(): void
    {
        if (! $this->profile->workspace?->hasModule(Module::Trends)) {
            return;
        }

        AnalyzeTrends::execute($this->profile);
    }
}
