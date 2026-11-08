<?php

declare(strict_types=1);

namespace App\Exceptions\Trend;

use Exception;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * A trend source could not be read at all. The reason decides what the user is told:
 * `permission` (the network refused the app or the token: its permission or feature is
 * missing) or `failed` (anything else, tried again at the next refresh).
 */
class SourceUnavailableException extends Exception
{
    public const string PERMISSION = 'permission';

    public const string FAILED = 'failed';

    /** Meta Graph error codes that mean the app or token lacks a permission or capability. */
    private const array PERMISSION_CODES = [3, 10, 190, 200, 283];

    public function __construct(public readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct("Trend source unavailable: {$reason}", 0, $previous);
    }

    public static function from(Throwable $failure): self
    {
        if ($failure instanceof RequestException) {
            $permission = $failure->response->status() === 403
                || in_array((int) $failure->response->json('error.code'), self::PERMISSION_CODES, true);

            return new self($permission ? self::PERMISSION : self::FAILED, $failure);
        }

        return new self(self::FAILED, $failure);
    }
}
