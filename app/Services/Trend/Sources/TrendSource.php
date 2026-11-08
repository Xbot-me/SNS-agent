<?php

declare(strict_types=1);

namespace App\Services\Trend\Sources;

use App\Dto\Trend\TrendSignal;
use App\Models\TrendProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

interface TrendSource
{
    /**
     * What this source published for the workspace's niche since the given moment.
     *
     * @return Collection<int, TrendSignal>
     */
    public function signals(TrendProfile $profile, CarbonImmutable $since): Collection;
}
