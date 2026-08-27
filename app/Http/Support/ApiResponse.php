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

        $requestId = self::requestId();
        $payload['request_id'] = $requestId;

        return response()->json($payload, $status)->header('X-Request-Id', $requestId);
    }

    /**
     * The header is set here rather than only in the RequestId middleware:
     * responses rendered from a thrown exception (auth, validation,
     * FinancialException) unwind past that middleware's post-$next() line,
     * so it would never run for exactly the responses support needs to trace.
     *
     * @param  array<string, mixed>  $errors  Field-level error details (e.g. validation errors).
     */
    public static function error(string $code, string $message, int $status, ?Request $request = null, array $errors = []): JsonResponse
    {
        $requestId = self::requestId();

        return response()->json([
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
                ...($errors !== [] ? ['errors' => $errors] : []),
            ],
            'request_id' => $requestId,
        ], $status)->header('X-Request-Id', $requestId);
    }

    private static function requestId(): string
    {
        return RequestContext::id() ?? 'req_'.(string) Str::ulid();
    }
}
