<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * PHP error log endpoint.
 */
class SAM_Error_Log_Endpoint extends SAM_Endpoint_Base {

    public function register_routes(): void {
        register_rest_route(SAM_REST_NAMESPACE, '/error-logs', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_error_logs'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    public function get_error_logs(WP_REST_Request $request): WP_REST_Response {
        $logs = [];
        $log_files = $this->find_log_files();
        $max_lines = 500;

        foreach ($log_files as $label => $path) {
            if (!file_exists($path) || !is_readable($path)) {
                continue;
            }

            $entries = $this->tail_file($path, $max_lines);
            $parsed = $this->parse_log_entries($entries);

            $logs[] = [
                'source'  => $label,
                'path'    => $path,
                'size'    => filesize($path),
                'entries' => $parsed,
            ];
        }

        return $this->success(['logs' => $logs]);
    }

    private function find_log_files(): array {
        $files = [];

        // WordPress debug log
        $debug_log = WP_CONTENT_DIR . '/debug.log';
        if (file_exists($debug_log)) {
            $files['wordpress'] = $debug_log;
        }

        // PHP error log from php.ini
        $php_error_log = ini_get('error_log');
        if ($php_error_log && file_exists($php_error_log) && $php_error_log !== $debug_log) {
            $files['php'] = $php_error_log;
        }

        // Common server log locations
        $common_paths = [
            '/var/log/php-fpm/error.log',
            '/var/log/php/error.log',
            '/var/log/apache2/error.log',
            '/var/log/nginx/error.log',
        ];

        foreach ($common_paths as $path) {
            if (file_exists($path) && is_readable($path)) {
                $label = basename(dirname($path));
                $files[$label] = $path;
            }
        }

        return $files;
    }

    /**
     * Read the last N lines of a file efficiently.
     */
    private function tail_file(string $path, int $lines): array {
        $result = [];
        $fp = @fopen($path, 'r');
        if (!$fp) {
            return [];
        }

        // For small files, just read everything
        $size = filesize($path);
        if ($size < 1048576) { // < 1MB
            $content = fread($fp, $size);
            fclose($fp);
            $all_lines = explode("\n", trim($content));
            return array_slice($all_lines, -$lines);
        }

        // For larger files, seek from end
        $chunk_size = min($size, 65536);
        fseek($fp, -$chunk_size, SEEK_END);
        $content = fread($fp, $chunk_size);
        fclose($fp);

        $all_lines = explode("\n", trim($content));
        return array_slice($all_lines, -$lines);
    }

    /**
     * Parse raw log lines into structured entries.
     */
    private function parse_log_entries(array $lines): array {
        $entries = [];
        $current = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            // Try to parse PHP error log format: [DD-Mon-YYYY HH:MM:SS TZ] message
            if (preg_match('/^\[(\d{2}-\w{3}-\d{4}\s+\d{2}:\d{2}:\d{2}[^\]]*)\]\s+(.+)$/', $line, $m)) {
                if ($current) {
                    $entries[] = $current;
                }
                $current = [
                    'timestamp' => $m[1],
                    'message'   => $m[2],
                    'level'     => $this->detect_level($m[2]),
                ];
            } elseif ($current) {
                // Continuation of previous entry (stack trace, etc.)
                $current['message'] .= "\n" . $line;
            } else {
                // Standalone line without recognizable timestamp
                $entries[] = [
                    'timestamp' => null,
                    'message'   => $line,
                    'level'     => $this->detect_level($line),
                ];
            }
        }

        if ($current) {
            $entries[] = $current;
        }

        // Return most recent first
        return array_reverse($entries);
    }

    private function detect_level(string $message): string {
        $message_lower = strtolower($message);

        if (str_contains($message_lower, 'fatal error') || str_contains($message_lower, 'fatal')) {
            return 'fatal';
        }
        if (str_contains($message_lower, 'warning')) {
            return 'warning';
        }
        if (str_contains($message_lower, 'notice') || str_contains($message_lower, 'deprecated')) {
            return 'notice';
        }
        if (str_contains($message_lower, 'error')) {
            return 'error';
        }

        return 'info';
    }
}
