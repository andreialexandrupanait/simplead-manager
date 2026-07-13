<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * P3-36: an unhandled exception in production must be logged exactly ONCE, with a
 * stack trace and context — the previous renderable hook logged it twice (its own
 * trace-less line plus the framework's default report).
 */
class UnhandledExceptionLoggingTest extends TestCase
{
    public function test_unhandled_exception_is_logged_once_with_trace(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $spy = Log::spy();

        app(ExceptionHandler::class)->report(new \RuntimeException('kaboom'));

        $spy->shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return $message === 'Unhandled exception'
                    && ($context['exception'] ?? null) === \RuntimeException::class
                    && ! empty($context['trace']);
            });
    }

    public function test_framework_exceptions_are_not_double_logged_by_the_hook(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $spy = Log::spy();

        // Excluded types fall through to the framework's own handling — our hook
        // must not add a second "Unhandled exception" log line for them.
        app(ExceptionHandler::class)->report(ValidationException::withMessages(['x' => 'bad']));

        $spy->shouldNotHaveReceived('error', ['Unhandled exception', \Mockery::any()]);
    }
}
