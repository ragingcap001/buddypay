<?php

namespace App\Http\Middleware;

use App\Http\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires the Idempotency-Key header on financial mutation endpoints.
 * The IdempotencyService performs the actual replay/conflict handling.
 */
class EnsureIdempotencyKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) $request->header('Idempotency-Key', '');

        if ($key === '' || strlen($key) > 128) {
            return ApiResponse::error(
                'IDEMPOTENCY_KEY_REQUIRED',
                'The Idempotency-Key header (1-128 characters) is required for this endpoint.',
                400,
                $request,
            );
        }

        return $next($request);
    }
}
