<?php

declare(strict_types=1);

namespace App\Enums\Trend;

/**
 * The kinds of post a workspace says it will actually make. Trends only
 * suggests formats from this list.
 */
enum PostFormat: string
{
    case Text = 'text';
    case Image = 'image';
    case Carousel = 'carousel';
    case Video = 'video';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $format): string => $format->value, self::cases());
    }
}
