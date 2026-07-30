<?php
/**
 * SPEC §5.6 — PHP error collection with our OWN handler.
 *
 * The previous implementation tailed wp-content/debug.log, which the spec rules
 * out: debug.log is off on most production sites, is truncated or rotated by the
 * host, mixes in everything else on the server, and gives no way to know when an
 * error stopped happening.
 *
 * This collector instead registers set_error_handler() + register_shutdown_function()
 * and keeps a small, bounded, DEDUPLICATED aggregate in a WordPress option, which
 * Manager drains in one batched call.
 *
 * The four non-negotiables from the spec:
 *   1. dedup by SIGNATURE — file + line + the message with variable values
 *      stripped, so "Undefined index: 41" and "Undefined index: 87" collapse into
 *      one entry with a count instead of two thousand rows;
 *   2. batched transfer — Manager pulls the aggregate, we never phone home per
 *      error;
 *   3. try/catch around everything, failing silently — an error collector that
 *      can break the site is worse than no collector;
 *   4. a remote kill switch, so Manager can turn it off on a site where it
 *      misbehaves without anyone opening wp-admin.
 *
 * Attribution (which plugin or theme produced it) is derived from the file path
 * and left for Manager to match against its inventory.
 */

if (!defined('ABSPATH')) {
    exit;
}

class SAM_Error_Collector {

    /** Option holding the aggregate, keyed by signature. */
    const OPTION_BUFFER = 'sam_php_errors';

    /** Option holding the remote kill switch (bool). */
    const OPTION_ENABLED = 'sam_php_errors_enabled';

    /** Hard cap on distinct signatures held at once. */
    const MAX_SIGNATURES = 200;

    /** Longest message we keep, to bound the option size. */
    const MAX_MESSAGE_LEN = 500;

    /** @var array<string, array> In-request buffer, flushed once on shutdown. */
    private static array $pending = [];

    /** @var callable|null Previously registered handler, always chained to. */
    private static $previous_handler = null;

    private static bool $booted = false;

    /**
     * Levels worth collecting. Notices and deprecations are excluded on purpose:
     * they flood the buffer on real sites and are not what "erori PHP agregate"
     * in a client report means.
     */
    private static function collected_levels(): int {
        return E_ERROR | E_WARNING | E_PARSE | E_USER_ERROR | E_USER_WARNING
            | E_RECOVERABLE_ERROR | E_COMPILE_ERROR | E_CORE_ERROR;
    }

    public static function is_enabled(): bool {
        // Default ON: the collector is the feature. The switch exists so Manager
        // can silence it remotely, not so it has to be turned on site by site.
        return (bool) get_option(self::OPTION_ENABLED, true);
    }

    public static function set_enabled(bool $enabled): void {
        update_option(self::OPTION_ENABLED, $enabled, false);

        if (!$enabled) {
            // Turning it off drops whatever is buffered — a kill switch that
            // leaves data behind is not a kill switch.
            delete_option(self::OPTION_BUFFER);
        }
    }

    /**
     * Register the handlers. Safe to call twice.
     */
    public static function boot(): void {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        try {
            if (!self::is_enabled()) {
                return;
            }

            self::$previous_handler = set_error_handler([__CLASS__, 'handle_error']);
            register_shutdown_function([__CLASS__, 'handle_shutdown']);
        } catch (\Throwable $e) {
            // Silent by design (spec point 3).
        }
    }

    /**
     * Non-fatal errors. ALWAYS returns false so PHP's normal handling — and any
     * handler that was registered before us — still runs. We observe, we do not
     * take over.
     */
    public static function handle_error($errno, $errstr, $errfile = '', $errline = 0) {
        try {
            if ($errno & self::collected_levels()) {
                self::record($errno, (string) $errstr, (string) $errfile, (int) $errline);
            }
        } catch (\Throwable $e) {
            // Silent by design.
        }

        if (is_callable(self::$previous_handler)) {
            try {
                return call_user_func(self::$previous_handler, $errno, $errstr, $errfile, $errline);
            } catch (\Throwable $e) {
                // Silent by design.
            }
        }

        return false;
    }

    /**
     * Fatals. This is the whole reason for register_shutdown_function: a fatal
     * never reaches the error handler, and it is exactly the error a client
     * notices.
     */
    public static function handle_shutdown(): void {
        try {
            $last = error_get_last();

            if (is_array($last) && ($last['type'] & (E_ERROR | E_PARSE | E_COMPILE_ERROR | E_CORE_ERROR | E_USER_ERROR))) {
                self::record(
                    (int) $last['type'],
                    (string) $last['message'],
                    (string) ($last['file'] ?? ''),
                    (int) ($last['line'] ?? 0)
                );
            }

            self::flush();
        } catch (\Throwable $e) {
            // Silent by design.
        }
    }

    /**
     * Add one occurrence to the in-request buffer.
     */
    private static function record(int $errno, string $message, string $file, int $line): void {
        $template = self::normalise_message($message);
        $signature = md5($file . '|' . $line . '|' . $template);

        if (isset(self::$pending[$signature])) {
            self::$pending[$signature]['count']++;

            return;
        }

        self::$pending[$signature] = [
            'signature' => $signature,
            'level'     => self::level_name($errno),
            'message'   => mb_substr($template, 0, self::MAX_MESSAGE_LEN),
            'file'      => self::relative_path($file),
            'line'      => $line,
            'source'    => self::attribute($file),
            'count'     => 1,
            'first_seen' => gmdate('c'),
            'last_seen'  => gmdate('c'),
        ];
    }

    /**
     * Merge this request's buffer into the stored aggregate. One option write per
     * request that produced errors, not one per error.
     */
    private static function flush(): void {
        if (self::$pending === []) {
            return;
        }

        $stored = get_option(self::OPTION_BUFFER, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        foreach (self::$pending as $signature => $entry) {
            if (isset($stored[$signature]) && is_array($stored[$signature])) {
                $stored[$signature]['count'] = (int) $stored[$signature]['count'] + $entry['count'];
                $stored[$signature]['last_seen'] = $entry['last_seen'];

                continue;
            }

            if (count($stored) >= self::MAX_SIGNATURES) {
                // Full: keep what we have rather than growing an option without
                // bound. Manager drains it on its next pull.
                break;
            }

            $stored[$signature] = $entry;
        }

        self::$pending = [];

        // autoload = false: this option is read by our REST call, never on every
        // page load.
        update_option(self::OPTION_BUFFER, $stored, false);
    }

    /**
     * The aggregate, for the REST endpoint.
     *
     * @return array<int, array>
     */
    public static function get_aggregate(): array {
        $stored = get_option(self::OPTION_BUFFER, []);

        return is_array($stored) ? array_values($stored) : [];
    }

    /**
     * Drop the signatures Manager has taken. Passing an empty list clears all —
     * the caller decides, so a partial transfer never loses the rest.
     *
     * @param array<int, string> $signatures
     */
    public static function acknowledge(array $signatures = []): int {
        $stored = get_option(self::OPTION_BUFFER, []);
        if (!is_array($stored)) {
            return 0;
        }

        if ($signatures === []) {
            delete_option(self::OPTION_BUFFER);

            return count($stored);
        }

        $removed = 0;
        foreach ($signatures as $signature) {
            if (isset($stored[$signature])) {
                unset($stored[$signature]);
                $removed++;
            }
        }

        update_option(self::OPTION_BUFFER, $stored, false);

        return $removed;
    }

    /**
     * Strip the variable parts of a message so the same bug reports as ONE thing.
     *
     * "Undefined array key 41 in /…/foo.php" and "Undefined array key 87 …"
     * share a signature; a quoted table name, a hex id, an email or a URL are all
     * placeholders too.
     */
    public static function normalise_message(string $message): string {
        $normalised = $message;

        $patterns = [
            '/\b0x[0-9a-f]+\b/i'                                  => '{hex}',
            '/\b[0-9a-f]{32,}\b/i'                                => '{hash}',
            '/\b[\w.+-]+@[\w-]+\.[\w.-]+\b/'                      => '{email}',
            '/https?:\/\/\S+/i'                                   => '{url}',
            '/\'[^\']*\'/'                                        => "'{value}'",
            '/"[^"]*"/'                                           => '"{value}"',
            '/\b\d+\b/'                                           => '{n}',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $result = preg_replace($pattern, $replacement, $normalised);
            if (is_string($result)) {
                $normalised = $result;
            }
        }

        return trim($normalised);
    }

    /**
     * Which plugin or theme owns this file — derived from the path, matched to
     * the inventory on Manager's side.
     *
     * @return array{type: string, slug: string|null}
     */
    public static function attribute(string $file): array {
        $path = str_replace('\\', '/', $file);

        if (preg_match('#/wp-content/plugins/([^/]+)/#', $path, $m)) {
            return ['type' => 'plugin', 'slug' => $m[1]];
        }

        if (preg_match('#/wp-content/(?:themes|mu-plugins)/([^/]+)/#', $path, $m)) {
            return ['type' => 'theme', 'slug' => $m[1]];
        }

        if (preg_match('#/wp-(admin|includes)/#', $path)) {
            return ['type' => 'core', 'slug' => null];
        }

        return ['type' => 'unknown', 'slug' => null];
    }

    /**
     * Paths are reported relative to ABSPATH — the absolute path leaks the
     * hosting layout and is noise in a report.
     */
    private static function relative_path(string $file): string {
        $path = str_replace('\\', '/', $file);
        $root = str_replace('\\', '/', ABSPATH);

        if ($root !== '' && strpos($path, $root) === 0) {
            return substr($path, strlen($root));
        }

        return $path;
    }

    private static function level_name(int $errno): string {
        $map = [
            E_ERROR             => 'fatal',
            E_CORE_ERROR        => 'fatal',
            E_COMPILE_ERROR     => 'fatal',
            E_USER_ERROR        => 'fatal',
            E_RECOVERABLE_ERROR => 'fatal',
            E_PARSE             => 'parse',
            E_WARNING           => 'warning',
            E_USER_WARNING      => 'warning',
        ];

        return $map[$errno] ?? 'other';
    }
}
