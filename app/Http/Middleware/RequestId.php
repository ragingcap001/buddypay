<?php

namespace App\Http\Middleware;

use App\Http\Support\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Assigns a traceable identifier to every API request and echoes it back
 * in the X-Request-Id response header.
 */
class RequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = 'req_'.(string) \Illuminate\Support\Str::ulid();

        $request->attributes->set('request_id', $requestId);
        RequestContext::set($requestId);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
