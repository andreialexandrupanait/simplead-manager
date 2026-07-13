<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(
            at: config('app.trusted_proxies', '127.0.0.1'),
            headers: \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR |
                     \Illuminate\Http\Request::HEADER_X_FORWARDED_HOST |
                     \Illuminate\Http\Request::HEADER_X_FORWARDED_PORT |
                     \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->web(prepend: [
            \App\Http\Middleware\SecurityHeaders::class,
        ]);

        $middleware->web(append: [
            \App\Http\Middleware\SetLocale::class,
            \App\Http\Middleware\SetCurrentSite::class,
        ]);

        $middleware->alias([
            'site.context' => \App\Http\Middleware\SetCurrentSite::class,
            'role' => \App\Http\Middleware\RequireRole::class,
            'api.token' => \App\Http\Middleware\AuthenticateApiToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->renderable(function (NotFoundHttpException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Resource not found.'], 404);
            }
        });

        $exceptions->renderable(function (ModelNotFoundException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Resource not found.'], 404);
            }

            abort(404);
        });

        $exceptions->renderable(function (AuthorizationException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unauthorized.'], 403);
            }
        });

        $exceptions->renderable(function (AuthenticationException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        });

        // Single-source logging for unhandled exceptions in production (P3-36):
        // log once here — with full stack trace and context — and return false so
        // the exception does NOT also propagate to the framework's default logging
        // stack (which previously produced a duplicate, trace-less log line).
        $exceptions->report(function (\Throwable $e) {
            if (! app()->isProduction()) {
                return true;
            }

            if ($e instanceof ValidationException || $e instanceof HttpException || $e instanceof AuthorizationException || $e instanceof AuthenticationException) {
                return true;
            }

            \Illuminate\Support\Facades\Log::error('Unhandled exception', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        });

        // Scrub sensitive data from generic exceptions in production (response only —
        // logging is handled once by the report() callback above).
        $exceptions->renderable(function (\Throwable $e, Request $request) {
            if (! app()->isProduction()) {
                return null;
            }

            // Don't override validation or HTTP exceptions
            if ($e instanceof ValidationException || $e instanceof HttpException || $e instanceof AuthorizationException || $e instanceof AuthenticationException) {
                return null;
            }

            if ($request->expectsJson()) {
                return response()->json(['message' => 'An unexpected error occurred.'], 500);
            }

            abort(500, 'An unexpected error occurred.');
        });
    })->create();
