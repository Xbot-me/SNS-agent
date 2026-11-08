<?php

declare(strict_types=1);

namespace App\Enums\Trend;

use App\Enums\Analytics\PublicationContentType;

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
     * The format a measured post counts as; null when it says nothing about format.
     */
    public static function fromPublication(?PublicationContentType $type): ?self
    {
        return match ($type) {
            PublicationContentType::Text, PublicationContentType::Link, PublicationContentType::Poll => self::Text,
            PublicationContentType::Image => self::Image,
            PublicationContentType::Carousel => self::Carousel,
            PublicationContentType::Video, PublicationContentType::Reel, PublicationContentType::Short, PublicationContentType::Story => self::Video,
            PublicationContentType::Unknown, null => null,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $format): string => $format->value, self::cases());
    }
}
