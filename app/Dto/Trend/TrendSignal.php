<?php

declare(strict_types=1);

namespace App\Dto\Trend;

use Carbon\CarbonImmutable;

/**
 * One thing a source published that may be part of a trend: a feed item for
 * now. `outlet` identifies who published it, so a subject covered by several
 * outlets can be told from one outlet repeating itself.
 */
final readonly class TrendSignal
{
    public function __construct(
        public string $outlet,
        public string $outletName,
        public string $title,
        public string $url,
        public CarbonImmutable $publishedAt,
    ) {}
}
