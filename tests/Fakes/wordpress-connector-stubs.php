<?php

/**
 * Minimal WordPress runtime shim so the SAD Mentenanta connector classes can be
 * unit-tested outside WordPress.
 *
 * Only what SAM_Authentication, SAM_Rate_Limiter, SAM_IP_Whitelist,
 * SAM_Request_Logger and SAM_Endpoint_Base actually touch is implemented. This is
 * deliberately dumb: options and transients are plain arrays, so a test can assert
 * exactly how many times update_option() was called for the whitelist.
 *
 * Not PSR-4 autoloaded — it declares global functions and classes and is pulled in
 * with require_once from the tests that need it.
 */
if (! class_exists('SamConnectorTestStore')) {
    /**
     * Shared mutable state for the shim. Reset between tests.
     */
    class SamConnectorTestStore
    {
        /** @var array<string, mixed> */
        public static array $options = [];

        /** @var array<string, mixed> */
        public static array $transients = [];

        /** @var array<string, mixed> */
        public static array $objectCache = [];

        /** Number of update_option() calls, per option key. */
        /** @var array<string, int> */
        public static array $updateOptionCalls = [];

        public static function reset(): void
        {
            self::$options = [];
            self::$transients = [];
            self::$objectCache = [];
            self::$updateOptionCalls = [];
        }

        public static function updateCalls(string $key): int
        {
            return self::$updateOptionCalls[$key] ?? 0;
        }
    }
}

if (! defined('ABSPATH')) {
    define('ABSPATH', __DIR__.'/');
}

if (! class_exists('WP_Error')) {
    class WP_Error
    {
        /** @var array<string, mixed> */
        private array $data;

        public function __construct(private string $code = '', private string $message = '', array $data = [])
        {
            $this->data = $data;
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }

        /** @return array<string, mixed> */
        public function get_error_data(): array
        {
            return $this->data;
        }
    }
}

if (! class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        /** @var array<string, string> */
        private array $headers = [];

        /**
         * @param  array<string, string>  $headers
         */
        public function __construct(
            private string $method = 'GET',
            private string $route = '/simplead/v1/info',
            private string $body = '',
            array $headers = [],
        ) {
            foreach ($headers as $name => $value) {
                $this->headers[self::normalizeHeader($name)] = $value;
            }
        }

        private static function normalizeHeader(string $name): string
        {
            return strtolower(str_replace('-', '_', $name));
        }

        public function get_header(string $name): ?string
        {
            return $this->headers[self::normalizeHeader($name)] ?? null;
        }

        public function get_method(): string
        {
            return $this->method;
        }

        public function get_route(): string
        {
            return $this->route;
        }

        public function get_body(): string
        {
            return $this->body;
        }
    }
}

if (! function_exists('is_wp_error')) {
    function is_wp_error(mixed $thing): bool
    {
        return $thing instanceof WP_Error;
    }
}

if (! function_exists('get_option')) {
    function get_option(string $option, mixed $default = false): mixed
    {
        return SamConnectorTestStore::$options[$option] ?? $default;
    }
}

if (! function_exists('update_option')) {
    function update_option(string $option, mixed $value, mixed $autoload = null): bool
    {
        SamConnectorTestStore::$updateOptionCalls[$option] = (SamConnectorTestStore::$updateOptionCalls[$option] ?? 0) + 1;
        SamConnectorTestStore::$options[$option] = $value;

        return true;
    }
}

if (! function_exists('delete_option')) {
    function delete_option(string $option): bool
    {
        unset(SamConnectorTestStore::$options[$option]);

        return true;
    }
}

if (! function_exists('get_transient')) {
    function get_transient(string $transient): mixed
    {
        return SamConnectorTestStore::$transients[$transient] ?? false;
    }
}

if (! function_exists('set_transient')) {
    function set_transient(string $transient, mixed $value, int $expiration = 0): bool
    {
        SamConnectorTestStore::$transients[$transient] = $value;

        return true;
    }
}

if (! function_exists('delete_transient')) {
    function delete_transient(string $transient): bool
    {
        unset(SamConnectorTestStore::$transients[$transient]);

        return true;
    }
}

if (! function_exists('wp_cache_add')) {
    /**
     * Atomic set-if-not-exists, mirroring the real behaviour the nonce check relies on.
     */
    function wp_cache_add(string $key, mixed $data, string $group = '', int $expire = 0): bool
    {
        $composite = $group.'|'.$key;

        if (array_key_exists($composite, SamConnectorTestStore::$objectCache)) {
            return false;
        }

        SamConnectorTestStore::$objectCache[$composite] = $data;

        return true;
    }
}
