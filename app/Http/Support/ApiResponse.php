<?php

namespace App\Http\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Consistent API envelope.
 *
 * Success: { "success": true, "data": {...}, "message": "...", "request_id": "..." }
 * Error:   { "success": false, "error": { "code": "...", "message": "..." }, "request_id": "..." }
 */
final class ApiResponse
{
    public static function success(mixed $data = null, ?string $message = null, int $status = 200): JsonResponse
    {
        $payload = ['success' => true];

        if ($data !== null) {
            $payload['data'] = $data;
        }

        if ($message !== null) {
            $payload['message'] = $message;
        }

        $payload['request_id'] = self::requestId();

        return response()->json($payload, $status);
    }

    /**
     * @param  array<string, mixed>  $extra  Extra error details (e.g. validation errors).
     */
    public static function error(string $code, string $message, int $status, ?Request $request = null, array $extra = []): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => array_merge([
                'code' => $code,
                'message' => $message,
            ], $extra),
            'request_id' => self::requestId(),
        ], $status);
    }

    private static function requestId(): string
    {
        return RequestContext::id() ?? 'req_'.(string) Str::ulid();
    }
}
