<?php

declare(strict_types=1);

namespace App\Console\Commands\Trend;

use App\Actions\Trend\RequestTrendAnalysis;
use App\Enums\Workspace\Module;
use App\Models\TrendProfile;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class DispatchTrendAnalysis extends Command
{
    protected $signature = 'trends:analyze {--force : Read every workspace, due or not}';

    protected $description = 'Queue a trend analysis for every workspace that finished the setup and is due';

    public function handle(): int
    {
        if (! Module::Trends->isAvailable()) {
            return self::SUCCESS;
        }

        TrendProfile::query()
            ->with('workspace')
            ->onboarded()
            ->when(! $this->option('force'), fn ($query) => $query->due())
            ->chunkById(100, function (Collection $profiles): void {
                foreach ($profiles as $profile) {
                    if ($profile->workspace?->hasModule(Module::Trends)) {
                        RequestTrendAnalysis::execute($profile);
                    }
                }
            });

        return self::SUCCESS;
    }
}
