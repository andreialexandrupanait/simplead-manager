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

if (! function_exists('get_option')) {
    function get_option(string $option, $default = false)
    {
        return $GLOBALS['sam_test_options'][$option] ?? $default;
    }
}

if (! function_exists('update_option')) {
    function update_option(string $option, $value, $autoload = null): bool
    {
        $GLOBALS['sam_test_options'][$option] = $value;

        return true;
    }
}

if (! function_exists('delete_option')) {
    function delete_option(string $option): bool
    {
        unset($GLOBALS['sam_test_options'][$option]);

        return true;
    }
}

if (! function_exists('wp_mkdir_p')) {
    function wp_mkdir_p(string $target): bool
    {
        return is_dir($target) || mkdir($target, 0755, true);
    }
}

if (! function_exists('sanitize_text_field')) {
    function sanitize_text_field($str)
    {
        return is_string($str) ? trim(strip_tags($str)) : '';
    }
}

if (! function_exists('wp_unslash')) {
    function wp_unslash($value)
    {
        return is_string($value) ? stripslashes($value) : $value;
    }
}

if (! function_exists('wp_parse_url')) {
    function wp_parse_url(string $url, int $component = -1)
    {
        return parse_url($url, $component);
    }
}

if (! function_exists('is_user_logged_in')) {
    function is_user_logged_in(): bool
    {
        return false;
    }
}

if (! function_exists('add_filter')) {
    function add_filter(string $hook, $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        return true;
    }
}

if (! function_exists('remove_submenu_page')) {
    function remove_submenu_page(string $menu_slug, string $submenu_slug)
    {
        return false;
    }
}

if (! class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(
            public string $code = '',
            public string $message = '',
            public array $data = [],
        ) {}

        public function get_error_message(): string
        {
            return $this->message;
        }
    }
}
