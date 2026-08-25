<?php

namespace App\Http\Support;

/**
 * Per-request context (request id). Set by the RequestId middleware.
 *
 * Static state is safe here: under PHP-FPM each worker handles one request
 * at a time, and the middleware overwrites the value on every API request.
 */
final class RequestContext
{
    private static ?string $requestId = null;

    public static function set(string $requestId): void
    {
        self::$requestId = $requestId;
    }

    public static function id(): ?string
    {
        return self::$requestId;
    }

    public static function reset(): void
    {
        self::$requestId = null;
    }
}
