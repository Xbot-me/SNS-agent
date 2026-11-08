<?php

declare(strict_types=1);

namespace App\Exceptions\Inbox;

use Exception;
use Illuminate\Http\Client\Response;

/**
 * A read or a reply the network refused. `category` is what the inbox shows:
 * `authentication` and `permission` ask for a reconnect, `reply_window` is
 * Meta's 24-hour messaging window, everything else is worth another try.
 */
class InboxException extends Exception
{
    public function __construct(
        public readonly string $category,
        string $message,
        public readonly ?string $providerMessage = null,
    ) {
        parent::__construct($message);
    }

    public static function unsupported(string $message): self
    {
        return new self('unsupported', $message);
    }

    public static function permission(string $message): self
    {
        return new self('permission', $message);
    }

    public static function fromResponse(Response $response, string $operation): self
    {
        $json = $response->json();
        $providerMessage = data_get($json, 'error.message')
            ?? data_get($json, 'error_description')
            ?? data_get($json, 'message')
            ?? (is_string(data_get($json, 'error')) ? data_get($json, 'error') : null);

        $category = match (true) {
            self::isMetaReplyWindow($response) => 'reply_window',
            $response->status() === 401,
            (int) data_get($json, 'error.code') === 190 => 'authentication',
            $response->status() === 403,
            in_array((int) data_get($json, 'error.code'), [10, 200, 230], true) => 'permission',
            $response->status() === 429 => 'rate_limited',
            $response->serverError() => 'transient',
            default => 'failed',
        };

        return new self(
            $category,
            "inbox {$operation} failed with HTTP {$response->status()}",
            is_string($providerMessage) ? $providerMessage : null,
        );
    }

    public function needsReconnect(): bool
    {
        return in_array($this->category, ['authentication', 'permission'], true);
    }

    /**
     * Meta refuses a message sent more than 24 hours after the person's last
     * one (error subcode 2018278, or code 10 with subcode 2534022).
     */
    private static function isMetaReplyWindow(Response $response): bool
    {
        return in_array((int) data_get($response->json(), 'error.error_subcode'), [2018278, 2534022], true);
    }
}
