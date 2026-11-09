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
     * The format of a Threads or Instagram post by its `media_type`; null for one that says
     * nothing about format (a repost, audio).
     */
    public static function fromMetaMediaType(?string $mediaType): ?self
    {
        return match ($mediaType) {
            'TEXT_POST' => self::Text,
            'IMAGE' => self::Image,
            'CAROUSEL_ALBUM' => self::Carousel,
            'VIDEO', 'REELS' => self::Video,
            default => null,
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
