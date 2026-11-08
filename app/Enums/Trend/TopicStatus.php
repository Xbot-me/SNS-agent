<?php

declare(strict_types=1);

namespace App\Enums\Trend;

enum TopicStatus: string
{
    case New = 'new';
    case Used = 'used';
    case Dismissed = 'dismissed';

    public const DEFAULT = self::New;
}
