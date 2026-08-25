<?php

use App\Exceptions\FinancialException;
use App\Http\Middleware\RequestId;
use App\Http\Support\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api([
            RequestId::class,
        ]);

        $middleware->alias([
            'pin' => \App\Http\Middleware\RequirePin::class,
            'idempotent' => \App\Http\Middleware\EnsureIdempotencyKey::class,
            'active' => \App\Http\Middleware\EnsureUserIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->renderable(function (FinancialException $e, Request $request) {
            if ($request->expectsJson() || str_starts_with($request->path(), 'api/')) {
                return ApiResponse::error($e->errorCode(), $e->getMessage(), $e->httpStatusCode(), $request);
            }
        });

        $exceptions->renderable(function (ValidationException $e, Request $request) {
            if ($request->expectsJson() || str_starts_with($request->path(), 'api/')) {
                return ApiResponse::error('VALIDATION_ERROR', $e->getMessage(), 422, $request, $e->errors());
            }
        });

        $exceptions->renderable(function (AuthenticationException $e, Request $request) {
            if ($request->expectsJson() || str_starts_with($request->path(), 'api/')) {
                return ApiResponse::error('UNAUTHENTICATED', 'Unauthenticated.', 401, $request);
            }
        });
    })->create();
