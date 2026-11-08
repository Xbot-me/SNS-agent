<?php

declare(strict_types=1);

namespace App\Enums\Trend;

/**
 * What a workspace wants its posts to do. It decides which formats Trends
 * suggests first.
 */
enum Goal: string
{
    case Grow = 'grow';
    case Traffic = 'traffic';
    case Sell = 'sell';
    case Authority = 'authority';

    public const DEFAULT = self::Grow;

    /**
     * @return list<PostFormat>
     */
    public function preferredFormats(): array
    {
        return match ($this) {
            self::Grow => [PostFormat::Carousel, PostFormat::Video, PostFormat::Image, PostFormat::Text],
            self::Traffic => [PostFormat::Text, PostFormat::Image, PostFormat::Carousel, PostFormat::Video],
            self::Sell => [PostFormat::Image, PostFormat::Video, PostFormat::Carousel, PostFormat::Text],
            self::Authority => [PostFormat::Text, PostFormat::Carousel, PostFormat::Image, PostFormat::Video],
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $goal): string => $goal->value, self::cases());
    }
}
