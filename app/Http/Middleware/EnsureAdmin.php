<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the admin dashboard API and pages. Requires an authenticated
 * web-session user with role=admin (set via `php artisan users:make-admin`).
 */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(401, 'Unauthenticated.');
        }

        if (! $user->isAdmin()) {
            abort(403, 'This action is restricted to administrators.');
        }

        return $next($request);
    }
}
