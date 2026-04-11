<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * /error-logs endpoint - Returns parsed PHP error log entries.
 */
class SAM_Error_Logs_Endpoint extends SAM_Endpoint_Base {

    public function register_routes(): void {
        register_rest_route(SAM_REST_NAMESPACE, '/error-logs', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_error_logs'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    public function get_error_logs(WP_REST_Request $request): WP_REST_Response {
        $limit = min((int) ($request->get_param('limit') ?: 100), 500);
        $entries = [];

        // Read PHP error log
        $error_log = ini_get('error_log');
        if ($error_log && file_exists($error_log) && is_readable($error_log)) {
            $entries = array_merge($entries, $this->parse_log_file($error_log, $limit));
        }

        // Read WordPress debug.log
        $debug_log = WP_CONTENT_DIR . '/debug.log';
        if (file_exists($debug_log) && is_readable($debug_log)) {
            $entries = array_merge($entries, $this->parse_log_file($debug_log, $limit));
        }

        // Sort by timestamp descending, deduplicate, limit
        usort($entries, function ($a, $b) {
            return strcmp($b['timestamp'] ?? '', $a['timestamp'] ?? '');
        });

        // Deduplicate by message hash
        $seen = [];
        $unique = [];
        foreach ($entries as $entry) {
            $hash = md5($entry['level'] . $entry['message']);
            if (isset($seen[$hash])) {
                $seen[$hash]['count']++;
                $seen[$hash]['last_seen'] = max($seen[$hash]['last_seen'], $entry['timestamp'] ?? '');
                continue;
            }
            $entry['count'] = 1;
            $entry['last_seen'] = $entry['timestamp'] ?? '';
            $seen[$hash] = &$entry;
            $unique[] = &$entry;
            unset($entry);
        }

        $unique = array_slice($unique, 0, $limit);

        return $this->success([
            'entries' => array_values($unique),
            'total' => count($unique),
        ]);
    }

    private function parse_log_file(string $path, int $limit): array {
        $entries = [];

        // Read last 500KB
        $size = filesize($path);
        $read_size = min($size, 512 * 1024);
        $fp = fopen($path, 'r');
        if (!$fp) return [];

        if ($size > $read_size) {
            fseek($fp, $size - $read_size);
            fgets($fp); // skip partial first line
        }

        $content = fread($fp, $read_size);
        fclose($fp);

        if (!$content) return [];

        // Parse standard PHP error log format: [DD-Mon-YYYY HH:MM:SS TZ] PHP Level: Message
        $pattern = '/\[(\d{2}-\w{3}-\d{4}\s+\d{2}:\d{2}:\d{2}[^\]]*)\]\s+(PHP\s+)?(Fatal\s+error|Warning|Notice|Deprecated|Parse\s+error|Catchable\s+fatal\s+error):\s*(.+)/i';

        preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);

        foreach (array_slice($matches, -$limit) as $match) {
            $level = strtolower(trim($match[3] ?? 'unknown'));
            if (stripos($level, 'fatal') !== false || stripos($level, 'parse') !== false) {
                $level = 'fatal';
            } elseif (stripos($level, 'warning') !== false) {
                $level = 'warning';
            } elseif (stripos($level, 'notice') !== false) {
                $level = 'notice';
            } elseif (stripos($level, 'deprecated') !== false) {
                $level = 'deprecated';
            }

            $message = trim($match[4] ?? '');
            $file = null;
            $line = null;

            if (preg_match('/in\s+(\S+)\s+on\s+line\s+(\d+)/', $message, $loc)) {
                $file = $loc[1];
                $line = (int) $loc[2];
                $message = trim(preg_replace('/\s+in\s+\S+\s+on\s+line\s+\d+/', '', $message));
            }

            $entries[] = [
                'timestamp' => $match[1] ?? null,
                'level'     => $level,
                'message'   => mb_substr($message, 0, 500),
                'file'      => $file,
                'line'      => $line,
            ];
        }

        return $entries;
    }
}
