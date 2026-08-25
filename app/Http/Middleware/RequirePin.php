<?php

namespace App\Http\Middleware;

use App\Domain\Authentication\Services\PinService;
use App\Http\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sensitive-operation authentication: financial mutations require the
 * user's transaction PIN via the X-Transaction-Pin header.
 */
class RequirePin
{
    public function __construct(private readonly PinService $pins)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('sanctum');

        if ($user === null) {
            return ApiResponse::error('UNAUTHENTICATED', 'Unauthenticated.', 401, $request);
        }

        $pin = (string) $request->header('X-Transaction-Pin', '');

        if ($pin === '') {
            return ApiResponse::error('PIN_REQUIRED', 'The X-Transaction-Pin header is required for this operation.', 400, $request);
        }

        if (! $this->pins->verify($user, $pin)) {
            return ApiResponse::error('PIN_INVALID', 'Incorrect transaction PIN.', 401, $request);
        }

        return $next($request);
    }
}
