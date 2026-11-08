<?php

declare(strict_types=1);

namespace App\Actions\Trend;

use App\Jobs\Trend\AnalyzeWorkspaceTrends;
use App\Models\TrendProfile;

class RequestTrendAnalysis
{
    /**
     * Queues an analysis unless one is already running. False means it was not queued.
     */
    public static function execute(TrendProfile $profile): bool
    {
        if ($profile->isAnalyzing()) {
            return false;
        }

        $profile->update(['analysis_requested_at' => now()]);

        AnalyzeWorkspaceTrends::dispatch($profile);

        return true;
    }
}
