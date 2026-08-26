<?php

declare(strict_types=1);

use App\Exceptions\SessionExpiredException;
use App\Exceptions\ThrottleException;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\CheckRole;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SimulateApiError;
use App\Http\Middleware\TrackSessionInfo;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum stateful API (cookie auth for SPA)
        $middleware->statefulApi();

        $middleware->api(prepend: [
            SimulateApiError::class,
        ]);

        $middleware->api(append: [
            SecurityHeaders::class,
            SetLocale::class,
            TrackSessionInfo::class,
        ]);

        $middleware->alias([
            'auth' => Authenticate::class,
            'role' => CheckRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // 401 Unauthenticated
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'status' => 401,
                    'message' => __('api.auth.unauthenticated'),
                ], 401);
            }
        });

        // 419 CSRF token mismatch
        $exceptions->render(function (TokenMismatchException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'status' => 419,
                    'message' => __('api.auth.csrf_mismatch'),
                ], 419);
            }
        });

        // 422 Validation
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'status' => 422,
                    'message' => $e->getMessage(),
                    'errors' => $e->errors(),
                ], 422);
            }
        });

        // 404 Eloquent model not found
        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'status' => 404,
                    'message' => __('api.auth.not_found'),
                ], 404);
            }
        });

        // Session expired (flow token / action token)
        $exceptions->render(function (SessionExpiredException $e) {
            return $e->render();
        });

        // Generic HTTP exceptions (403, 404, 429, etc.)
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($request->is('api/*')) {
                if ($e instanceof ThrottleRequestsException) {
                    $seconds = $e->getHeaders()['Retry-After'] ?? 60;

                    return (new ThrottleException((int) $seconds, __('auth.throttle', ['seconds' => $seconds])))->render();
                }

                return response()->json([
                    'status' => $e->getStatusCode(),
                    'message' => $e->getMessage(),
                ], $e->getStatusCode());
            }
        });

        // 500 fallback
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*')) {
                $status = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;

                $message = $e->getMessage();
                if (! config('app.debug') && $status === 500) {
                    $message = __('api.auth.system_error');
                }

                return response()->json([
                    'status' => $status,
                    'message' => $message,
                    'exception' => config('app.debug') ? get_class($e) : null,
                    'trace' => config('app.debug') ? $e->getTrace() : null,
                ], $status);
            }
        });
    })->create();
