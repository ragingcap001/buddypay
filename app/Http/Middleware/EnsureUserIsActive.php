<?php

namespace App\Http\Middleware;

use App\Http\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects suspended/closed accounts on authenticated endpoints.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('sanctum');

        if ($user === null) {
            return ApiResponse::error('UNAUTHENTICATED', 'Unauthenticated.', 401, $request);
        }

        if (! $user->isActive()) {
            return ApiResponse::error('USER_SUSPENDED', 'This account is suspended. Contact support.', 403, $request);
        }

        return $next($request);
    }
}
