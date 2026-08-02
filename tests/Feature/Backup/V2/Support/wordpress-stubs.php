<?php

declare(strict_types=1);

/**
 * The few WordPress functions the backup plugin's bootstrap touches, so it can be loaded in-process
 * and its scheduled work exercised without standing up a WordPress.
 *
 * Deliberately tiny and deliberately dumb: anything that needs real WordPress behaviour belongs in
 * the HTTP end-to-end tests against spike-wp, not here. Include this only from a test class marked
 * #[RunTestsInSeparateProcesses] — these definitions are global and must not leak into the suite.
 */
if (! function_exists('add_action')) {
    function add_action(string $hook, $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        return true;
    }
}

if (! function_exists('wp_next_scheduled')) {
    function wp_next_scheduled(string $hook, array $args = [])
    {
        return false;
    }
}

if (! function_exists('wp_schedule_event')) {
    function wp_schedule_event(int $timestamp, string $recurrence, string $hook, array $args = []): bool
    {
        return true;
    }
}

if (! function_exists('wp_clear_scheduled_hook')) {
    function wp_clear_scheduled_hook(string $hook, array $args = []): int
    {
        return 0;
    }
}

if (! function_exists('is_admin')) {
    function is_admin(): bool
    {
        return false;
    }
}

if (! function_exists('wp_json_encode')) {
    function wp_json_encode($data, int $options = 0, int $depth = 512)
    {
        return json_encode($data, $options, $depth);
    }
}
