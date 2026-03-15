<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Backup endpoints — writes to temp file, serves as download.
 */
class SAM_Backup_Endpoint extends SAM_Endpoint_Base {

    public function register_routes(): void {
        register_rest_route(SAM_REST_NAMESPACE, '/backup/db', [
            'methods'             => 'POST',
            'callback'            => [$this, 'backup_database'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/backup/files', [
            'methods'             => 'POST',
            'callback'            => [$this, 'backup_files'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/backup/restore', [
            'methods'             => 'POST',
            'callback'            => [$this, 'restore'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Chunked download endpoints for large backups
        register_rest_route(SAM_REST_NAMESPACE, '/backup/prepare', [
            'methods'             => 'POST',
            'callback'            => [$this, 'prepare_backup'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/backup/chunk', [
            'methods'             => 'POST',
            'callback'            => [$this, 'download_chunk'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/backup/cleanup', [
            'methods'             => 'POST',
            'callback'            => [$this, 'cleanup_prepared'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Direct upload endpoints
        register_rest_route(SAM_REST_NAMESPACE, '/backup/capabilities', [
            'methods'             => 'POST',
            'callback'            => [$this, 'get_capabilities'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/backup/prepare-combined', [
            'methods'             => 'POST',
            'callback'            => [$this, 'prepare_combined'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/backup/direct-upload', [
            'methods'             => 'POST',
            'callback'            => [$this, 'direct_upload'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Async preparation endpoints
        register_rest_route(SAM_REST_NAMESPACE, '/backup/prepare-async', [
            'methods'             => 'POST',
            'callback'            => [$this, 'prepare_async'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/backup/prepare-execute', [
            'methods'             => 'POST',
            'callback'            => [$this, 'prepare_execute'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/backup/prepare-status', [
            'methods'             => 'POST',
            'callback'            => [$this, 'prepare_status'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    public function backup_database(WP_REST_Request $request): void {
        global $wpdb;

        @set_time_limit(600);
        @ini_set('memory_limit', '512M');

        SAM_Audit_Logger::log('backup_db_started', 'backup', 'database', 'Database backup initiated via SimpleAd Manager');

        $tmp_file = tempnam(sys_get_temp_dir(), 'sam_db_backup_');
        if (!$tmp_file) {
            status_header(500);
            echo '{"success":false,"error":{"code":"TEMP_FILE","message":"Failed to create temp file."}}';
            exit;
        }

        // Database connection info
        $host = DB_HOST;
        $user = DB_USER;
        $pass = DB_PASSWORD;
        $name = DB_NAME;

        $success = false;

        // Try mysqldump first (most reliable and efficient)
        $mysqldump = $this->find_mysqldump();
        if ($mysqldump) {
            $success = $this->exec_mysqldump($mysqldump, $host, $user, $pass, $name, $tmp_file);
        }

        // Fallback: PHP-based dump
        if (!$success) {
            $this->php_database_dump($wpdb, $name, $tmp_file);
            $success = true;
        }

        // Gzip the dump to reduce transfer size (a 500MB SQL file compresses to ~50-80MB)
        $gz_file = $tmp_file . '.gz';
        if ($this->gzip_file($tmp_file, $gz_file)) {
            @unlink($tmp_file);
            $this->serve_and_cleanup($gz_file, 'backup-' . date('Y-m-d-His') . '.sql.gz', 'application/gzip');
        } else {
            // Fallback: serve uncompressed if gzip fails
            $this->serve_and_cleanup($tmp_file, 'backup-' . date('Y-m-d-His') . '.sql', 'application/sql');
        }
    }

    public function backup_files(WP_REST_Request $request): void {
        @set_time_limit(600);
        @ini_set('memory_limit', '512M');

        SAM_Audit_Logger::log('backup_files_started', 'backup', 'files', 'File backup initiated via SimpleAd Manager');

        $source_dir = rtrim(ABSPATH, '/');
        $tmp_file = tempnam(sys_get_temp_dir(), 'sam_files_backup_');
        if (!$tmp_file) {
            status_header(500);
            echo '{"success":false,"error":{"code":"TEMP_FILE","message":"Failed to create temp file."}}';
            exit;
        }

        $success = false;

        // Try tar command (most efficient)
        $tar = $this->find_binary('tar');
        if ($tar) {
            $success = $this->exec_tar($tar, $source_dir, $tmp_file);
        }

        if ($success) {
            $this->serve_and_cleanup($tmp_file, 'backup-files-' . date('Y-m-d-His') . '.tar.gz', 'application/gzip');
        }

        // Fallback: PHP ZipArchive
        @unlink($tmp_file); // Remove empty temp file
        $this->php_zip_backup($source_dir);
        exit;
    }

    /**
     * Execute mysqldump via proc_open with array args (no shell injection).
     */
    private function exec_mysqldump(string $binary, string $host, string $user, string $pass, string $name, string $output_file): bool {
        $cmd = [
            $binary,
            '--host=' . $host,
            '--user=' . $user,
            '--password=' . $pass,
            '--single-transaction',
            '--quick',
            '--lock-tables=false',
            $name,
        ];

        $descriptors = [
            0 => ['pipe', 'r'],       // stdin
            1 => ['file', $output_file, 'w'], // stdout → file
            2 => ['pipe', 'w'],       // stderr
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            return false;
        }

        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit_code = proc_close($process);

        return $exit_code === 0 && filesize($output_file) > 0;
    }

    /**
     * Execute tar via proc_open with array args (no shell injection).
     */
    /**
     * Directories and patterns to exclude from file backups.
     */
    private function get_backup_exclusions(): array {
        return [
            'wp-content/cache',
            'wp-content/backup-*',
            'wp-content/updraft',
            'wp-content/ai1wm-backups',
            'wp-content/uploads/backwpup-*',
            'tmp/sam_*',
            '.git',
            'node_modules',
            'error_log',
            'wp-content/debug.log',
        ];
    }

    /**
     * Check if a relative path should be excluded from backup.
     */
    private function should_exclude(string $relative_path): bool {
        foreach ($this->get_backup_exclusions() as $pattern) {
            // Exact prefix match or wildcard match
            if (strpos($pattern, '*') !== false) {
                $regex = '/^' . str_replace('\\*', '.*', preg_quote($pattern, '/')) . '(\/|$)/';
                if (preg_match($regex, $relative_path)) {
                    return true;
                }
            } else {
                if ($relative_path === $pattern || strpos($relative_path, $pattern . '/') === 0) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Execute tar via proc_open with array args (no shell injection).
     */
    private function exec_tar(string $binary, string $source_dir, string $output_file): bool {
        $cmd = [
            $binary,
            'czf',
            $output_file,
            '-C',
            $source_dir,
        ];

        foreach ($this->get_backup_exclusions() as $pattern) {
            $cmd[] = '--exclude=' . $pattern;
        }

        $cmd[] = '.';

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            return false;
        }

        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit_code = proc_close($process);

        return $exit_code === 0 && file_exists($output_file) && filesize($output_file) > 0;
    }

    /**
     * Serve a temp file as a download, then clean up.
     */
    private function serve_and_cleanup(string $file_path, string $filename, string $content_type): void {
        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: ' . $content_type);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($file_path));
        header('X-SAM-Backup-Type: ' . (strpos($content_type, 'sql') !== false ? 'database' : 'files'));

        readfile($file_path);
        @unlink($file_path);
        exit;
    }

    private function find_mysqldump(): ?string {
        if (!function_exists('proc_open')) {
            return null;
        }

        $paths = [
            'mysqldump',
            '/usr/bin/mysqldump',
            '/usr/local/bin/mysqldump',
            '/usr/local/mysql/bin/mysqldump',
        ];

        foreach ($paths as $path) {
            if ($this->binary_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    private function find_binary(string $name): ?string {
        if (!function_exists('proc_open')) {
            return null;
        }

        $paths = [
            $name,
            "/usr/bin/{$name}",
            "/usr/local/bin/{$name}",
            "/bin/{$name}",
        ];

        foreach ($paths as $path) {
            if ($this->binary_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    private function binary_exists(string $binary): bool {
        if (!function_exists('exec')) {
            return false;
        }
        $output = [];
        $code = 0;
        @exec("which " . escapeshellarg($binary) . " 2>/dev/null", $output, $code);
        return $code === 0;
    }

    private function php_database_dump($wpdb, string $db_name, string $output_file): void {
        $fh = fopen($output_file, 'w');
        if (!$fh) {
            return;
        }

        fwrite($fh, "-- SimpleAd Manager Database Backup\n");
        fwrite($fh, "-- Date: " . date('Y-m-d H:i:s') . "\n");
        fwrite($fh, "-- Database: {$db_name}\n\n");
        fwrite($fh, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");

        $tables = $wpdb->get_col("SHOW TABLES");

        foreach ($tables as $table) {
            // Table structure
            $create = $wpdb->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_N);
            fwrite($fh, "DROP TABLE IF EXISTS `{$table}`;\n");
            fwrite($fh, $create[1] . ";\n\n");

            // Table data in chunks
            $offset = 0;
            $chunk = 500;

            while (true) {
                $rows = $wpdb->get_results("SELECT * FROM `{$table}` LIMIT {$offset}, {$chunk}", ARRAY_A);
                if (empty($rows)) {
                    break;
                }

                foreach ($rows as $row) {
                    $values = array_map(function ($v) use ($wpdb) {
                        if ($v === null) return 'NULL';
                        return "'" . $wpdb->_real_escape($v) . "'";
                    }, array_values($row));

                    $columns = array_map(function ($c) {
                        return "`{$c}`";
                    }, array_keys($row));

                    fwrite($fh, "INSERT INTO `{$table}` (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $values) . ");\n");
                }

                $offset += $chunk;
            }

            fwrite($fh, "\n");
        }

        fwrite($fh, "SET FOREIGN_KEY_CHECKS = 1;\n");
        fclose($fh);
    }

    /**
     * Prepare a backup archive and return a token for chunked download.
     * This decouples archive creation from transfer, allowing reliable chunked retrieval.
     */
    public function prepare_backup(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;

        @set_time_limit(600);
        @ini_set('memory_limit', '512M');

        $type = $request->get_param('type');
        if (!in_array($type, ['db', 'files'], true)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => ['code' => 'INVALID_TYPE', 'message' => 'Type must be "db" or "files".'],
            ], 400);
        }

        // Generate a secure token for this prepared backup
        $token = bin2hex(random_bytes(32));
        $token_dir = sys_get_temp_dir() . '/sam_prepared';
        if (!is_dir($token_dir)) {
            @mkdir($token_dir, 0755, true);
        }
        $prepared_file = $token_dir . '/sam_backup_' . $token;

        if ($type === 'db') {
            SAM_Audit_Logger::log('backup_db_started', 'backup', 'database', 'Database backup (chunked) initiated');

            $tmp_sql = tempnam(sys_get_temp_dir(), 'sam_db_');

            $success = false;
            $mysqldump = $this->find_mysqldump();
            if ($mysqldump) {
                $success = $this->exec_mysqldump($mysqldump, DB_HOST, DB_USER, DB_PASSWORD, DB_NAME, $tmp_sql);
            }
            if (!$success) {
                $this->php_database_dump($wpdb, DB_NAME, $tmp_sql);
            }

            // Gzip the dump
            if ($this->gzip_file($tmp_sql, $prepared_file)) {
                @unlink($tmp_sql);
            } else {
                rename($tmp_sql, $prepared_file);
            }
        } else {
            SAM_Audit_Logger::log('backup_files_started', 'backup', 'files', 'File backup (chunked) initiated');

            $source_dir = rtrim(ABSPATH, '/');
            $tar = $this->find_binary('tar');

            if ($tar) {
                $this->exec_tar($tar, $source_dir, $prepared_file);
            } else {
                // ZipArchive fallback — write directly to the prepared path
                if (!class_exists('ZipArchive')) {
                    return new WP_REST_Response([
                        'success' => false,
                        'error' => ['code' => 'NO_ARCHIVER', 'message' => 'No tar or ZipArchive available.'],
                    ], 500);
                }

                $zip = new \ZipArchive();
                $zip->open($prepared_file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
                $files = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($source_dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::LEAVES_ONLY
                );
                foreach ($files as $file) {
                    if ($file->isFile()) {
                        $relative = substr($file->getRealPath(), strlen($source_dir) + 1);
                        if (!$this->should_exclude($relative)) {
                            $zip->addFile($file->getRealPath(), $relative);
                        }
                    }
                }
                $zip->close();
            }
        }

        if (!file_exists($prepared_file) || filesize($prepared_file) === 0) {
            return new WP_REST_Response([
                'success' => false,
                'error' => ['code' => 'PREPARE_FAILED', 'message' => 'Failed to create backup archive.'],
            ], 500);
        }

        $size = filesize($prepared_file);
        $checksum = hash_file('sha256', $prepared_file);

        // Store token metadata so we can validate chunk requests
        file_put_contents($prepared_file . '.meta', json_encode([
            'type' => $type,
            'size' => $size,
            'checksum' => $checksum,
            'created_at' => time(),
        ]));

        return new WP_REST_Response([
            'success' => true,
            'token' => $token,
            'size' => $size,
            'checksum' => $checksum,
        ], 200);
    }

    /**
     * Serve a byte-range chunk of a previously prepared backup.
     */
    public function download_chunk(WP_REST_Request $request): void {
        $token = $request->get_param('token');
        $offset = (int) $request->get_param('offset');
        $length = (int) $request->get_param('length');

        if (!$token || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            status_header(400);
            echo '{"success":false,"error":{"code":"INVALID_TOKEN","message":"Invalid token."}}';
            exit;
        }

        $prepared_file = sys_get_temp_dir() . '/sam_prepared/sam_backup_' . $token;
        if (!file_exists($prepared_file)) {
            status_header(404);
            echo '{"success":false,"error":{"code":"NOT_FOUND","message":"Prepared backup not found or expired."}}';
            exit;
        }

        $file_size = filesize($prepared_file);

        // Clamp to valid range
        if ($offset < 0) $offset = 0;
        if ($offset >= $file_size) {
            status_header(416);
            echo '{"success":false,"error":{"code":"RANGE_ERROR","message":"Offset beyond file size."}}';
            exit;
        }
        if ($length <= 0 || $length > 26214400) { // Max 25MB per chunk
            $length = 26214400;
        }
        $actual_length = min($length, $file_size - $offset);

        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . $actual_length);
        header('X-SAM-Chunk-Offset: ' . $offset);
        header('X-SAM-Chunk-Length: ' . $actual_length);
        header('X-SAM-File-Size: ' . $file_size);

        $fh = fopen($prepared_file, 'rb');
        fseek($fh, $offset);
        $remaining = $actual_length;
        while ($remaining > 0) {
            $read_size = min(524288, $remaining); // 512KB reads
            $data = fread($fh, $read_size);
            if ($data === false) break;
            echo $data;
            $remaining -= strlen($data);
        }
        fclose($fh);
        exit;
    }

    /**
     * Delete a previously prepared backup file.
     */
    public function cleanup_prepared(WP_REST_Request $request): WP_REST_Response {
        $token = $request->get_param('token');

        if (!$token || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return new WP_REST_Response(['success' => false], 400);
        }

        $prepared_file = sys_get_temp_dir() . '/sam_prepared/sam_backup_' . $token;
        @unlink($prepared_file);
        @unlink($prepared_file . '.meta');

        return new WP_REST_Response(['success' => true], 200);
    }

    /**
     * Return capabilities of this plugin version. Manager uses this to decide upload strategy.
     */
    public function get_capabilities(WP_REST_Request $request): WP_REST_Response {
        return new WP_REST_Response([
            'success' => true,
            'direct_upload' => true,
            'strategies' => ['s3_multipart', 'chunked_push'],
            'plugin_version' => defined('SAM_VERSION') ? SAM_VERSION : 'unknown',
        ], 200);
    }

    /**
     * Prepare a full combined backup archive (db + files + meta) for direct upload.
     * Returns token, size, and checksum so Manager can orchestrate the upload.
     */
    public function prepare_combined(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;

        @set_time_limit(900);
        @ini_set('memory_limit', '512M');

        $type = $request->get_param('type') ?: 'full';
        if (!in_array($type, ['full', 'db'], true)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => ['code' => 'INVALID_TYPE', 'message' => 'Type must be "full" or "db".'],
            ], 400);
        }

        SAM_Audit_Logger::log('backup_combined_started', 'backup', 'combined', "Combined backup ({$type}) initiated for direct upload");

        $token = bin2hex(random_bytes(32));
        $token_dir = sys_get_temp_dir() . '/sam_prepared';
        if (!is_dir($token_dir)) {
            @mkdir($token_dir, 0755, true);
        }

        $work_dir = $token_dir . '/sam_work_' . $token;
        @mkdir($work_dir, 0755, true);

        try {
            // 1. Create database dump
            $db_file = $work_dir . '/database.sql.gz';
            $tmp_sql = tempnam(sys_get_temp_dir(), 'sam_db_');

            $success = false;
            $mysqldump = $this->find_mysqldump();
            if ($mysqldump) {
                $success = $this->exec_mysqldump($mysqldump, DB_HOST, DB_USER, DB_PASSWORD, DB_NAME, $tmp_sql);
            }
            if (!$success) {
                $this->php_database_dump($wpdb, DB_NAME, $tmp_sql);
            }

            if ($this->gzip_file($tmp_sql, $db_file)) {
                @unlink($tmp_sql);
            } else {
                rename($tmp_sql, $db_file);
            }

            // 2. Create files archive (if full backup)
            $files_file = null;
            if ($type === 'full') {
                $source_dir = rtrim(ABSPATH, '/');
                $files_file = $work_dir . '/files.tar.gz';

                $tar = $this->find_binary('tar');
                if ($tar) {
                    $this->exec_tar($tar, $source_dir, $files_file);
                } else {
                    // Fallback to ZipArchive
                    $files_file = $work_dir . '/files.zip';
                    if (!class_exists('ZipArchive')) {
                        return new WP_REST_Response([
                            'success' => false,
                            'error' => ['code' => 'NO_ARCHIVER', 'message' => 'No tar or ZipArchive available.'],
                        ], 500);
                    }
                    $zip = new \ZipArchive();
                    $zip->open($files_file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
                    $files = new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator($source_dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                        \RecursiveIteratorIterator::LEAVES_ONLY
                    );
                    foreach ($files as $file) {
                        if ($file->isFile()) {
                            $relative = substr($file->getRealPath(), strlen($source_dir) + 1);
                            if (!$this->should_exclude($relative)) {
                                $zip->addFile($file->getRealPath(), $relative);
                            }
                        }
                    }
                    $zip->close();
                }
            }

            // 3. Create metadata
            $meta_file = $work_dir . '/backup-meta.json';
            file_put_contents($meta_file, json_encode([
                'site_name' => get_bloginfo('name'),
                'site_url' => get_bloginfo('url'),
                'type' => $type,
                'wp_version' => get_bloginfo('version'),
                'php_version' => phpversion(),
                'created_at' => gmdate('c'),
                'trigger' => 'direct_upload',
            ], JSON_PRETTY_PRINT));

            // 4. Create combined ZIP
            $combined_file = $token_dir . '/sam_backup_' . $token;

            if (!class_exists('ZipArchive')) {
                return new WP_REST_Response([
                    'success' => false,
                    'error' => ['code' => 'NO_ZIP', 'message' => 'ZipArchive not available for creating combined archive.'],
                ], 500);
            }

            $zip = new \ZipArchive();
            if ($zip->open($combined_file, \ZipArchive::CREATE) !== true) {
                return new WP_REST_Response([
                    'success' => false,
                    'error' => ['code' => 'ZIP_FAILED', 'message' => 'Failed to create combined archive.'],
                ], 500);
            }

            $zip->addFile($db_file, 'database.sql.gz');
            if ($files_file && file_exists($files_file)) {
                $zip->addFile($files_file, basename($files_file));
            }
            $zip->addFile($meta_file, 'backup-meta.json');
            $zip->close();

            // Clean up work directory
            $this->recursive_delete($work_dir);

        } catch (\Throwable $e) {
            $this->recursive_delete($work_dir);
            @unlink($token_dir . '/sam_backup_' . $token);
            return new WP_REST_Response([
                'success' => false,
                'error' => ['code' => 'PREPARE_FAILED', 'message' => $e->getMessage()],
            ], 500);
        }

        if (!file_exists($combined_file) || filesize($combined_file) === 0) {
            return new WP_REST_Response([
                'success' => false,
                'error' => ['code' => 'PREPARE_FAILED', 'message' => 'Combined archive is empty.'],
            ], 500);
        }

        $size = filesize($combined_file);
        $checksum = hash_file('sha256', $combined_file);

        // Store metadata for validation
        file_put_contents($combined_file . '.meta', json_encode([
            'type' => $type,
            'size' => $size,
            'checksum' => $checksum,
            'created_at' => time(),
        ]));

        return new WP_REST_Response([
            'success' => true,
            'token' => $token,
            'size' => $size,
            'checksum' => $checksum,
        ], 200);
    }

    /**
     * Execute the actual direct upload based on strategy instructions from Manager.
     */
    public function direct_upload(WP_REST_Request $request): WP_REST_Response {
        @set_time_limit(1800);
        @ini_set('memory_limit', '512M');

        $strategy = $request->get_param('strategy');
        $token = $request->get_param('token');

        if (!$token || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => ['code' => 'INVALID_TOKEN', 'message' => 'Invalid token.'],
            ], 400);
        }

        $prepared_file = sys_get_temp_dir() . '/sam_prepared/sam_backup_' . $token;
        if (!file_exists($prepared_file)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => ['code' => 'NOT_FOUND', 'message' => 'Prepared backup not found.'],
            ], 404);
        }

        try {
            if ($strategy === 's3_multipart') {
                return $this->handle_s3_multipart_upload($request, $prepared_file);
            } elseif ($strategy === 'chunked_push') {
                return $this->handle_chunked_push_upload($request, $prepared_file);
            } else {
                return new WP_REST_Response([
                    'success' => false,
                    'error' => ['code' => 'INVALID_STRATEGY', 'message' => 'Unknown upload strategy.'],
                ], 400);
            }
        } catch (\Throwable $e) {
            SAM_Audit_Logger::log('direct_upload_failed', 'backup', 'direct_upload', 'Direct upload failed: ' . $e->getMessage());
            return new WP_REST_Response([
                'success' => false,
                'error' => ['code' => 'UPLOAD_FAILED', 'message' => $e->getMessage()],
            ], 500);
        }
    }

    /**
     * Upload parts directly to S3 via presigned URLs.
     */
    private function handle_s3_multipart_upload(WP_REST_Request $request, string $file_path): WP_REST_Response {
        $parts = $request->get_param('parts');
        $callback_url = $request->get_param('callback_url') ?: '';
        $callback_token = $request->get_param('callback_token') ?: '';
        $backup_id = (int) $request->get_param('backup_id');

        if (empty($parts) || !is_array($parts)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => ['code' => 'MISSING_PARTS', 'message' => 'No parts provided.'],
            ], 400);
        }

        $etags = SAM_Direct_Uploader::upload_s3_multipart(
            $file_path,
            $parts,
            $callback_url,
            $callback_token,
            $backup_id
        );

        SAM_Audit_Logger::log('direct_upload_s3_completed', 'backup', 'direct_upload', 'S3 multipart upload completed: ' . count($etags) . ' parts');

        return new WP_REST_Response([
            'success' => true,
            'etags' => $etags,
        ], 200);
    }

    /**
     * Push chunks to Manager relay endpoint.
     */
    private function handle_chunked_push_upload(WP_REST_Request $request, string $file_path): WP_REST_Response {
        $upload_url = $request->get_param('upload_url');
        $upload_token = $request->get_param('upload_token');
        $chunk_size = (int) ($request->get_param('chunk_size') ?: 8388608); // 8MB default
        $callback_url = $request->get_param('callback_url') ?: '';
        $callback_token = $request->get_param('callback_token') ?: '';
        $backup_id = (int) $request->get_param('backup_id');

        if (empty($upload_url) || empty($upload_token)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => ['code' => 'MISSING_PARAMS', 'message' => 'upload_url and upload_token are required.'],
            ], 400);
        }

        SAM_Direct_Uploader::upload_chunked_push(
            $file_path,
            $upload_url,
            $upload_token,
            $chunk_size,
            $callback_url,
            $callback_token,
            $backup_id
        );

        SAM_Audit_Logger::log('direct_upload_relay_completed', 'backup', 'direct_upload', 'Chunked push upload completed');

        return new WP_REST_Response([
            'success' => true,
        ], 200);
    }

    /**
     * Accept backup request, spawn background process, return immediately.
     */
    public function prepare_async(WP_REST_Request $request): WP_REST_Response {
        $type = $request->get_param('type') ?: 'full';
        if (!in_array($type, ['full', 'db'], true)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => ['code' => 'INVALID_TYPE', 'message' => 'Type must be "full" or "db".'],
            ], 400);
        }

        // Check for existing in-progress task (idempotent — supports Manager job retries)
        $lock_key = 'sam_backup_lock';
        $existing_token = get_transient($lock_key);
        if ($existing_token) {
            $existing_task = get_transient('sam_backup_task_' . $existing_token);
            if ($existing_task && $existing_task['status'] === 'working') {
                return new WP_REST_Response([
                    'success' => true,
                    'async' => true,
                    'token' => $existing_token,
                    'resumed' => true,
                ], 200);
            }
            // Previous task finished or stale — clear it
            delete_transient($lock_key);
            delete_transient('sam_backup_task_' . $existing_token);
        }

        $token = bin2hex(random_bytes(32));

        // Store initial task state (2 hour TTL)
        set_transient('sam_backup_task_' . $token, [
            'status' => 'working',
            'progress' => 0,
            'message' => 'Starting backup preparation...',
            'type' => $type,
            'size' => null,
            'checksum' => null,
            'error' => null,
            'started_at' => time(),
            'updated_at' => time(),
        ], 7200);

        // Set lock (2 hour TTL)
        set_transient($lock_key, $token, 7200);

        SAM_Audit_Logger::log('backup_async_started', 'backup', 'combined', "Async backup preparation ({$type}) initiated");

        // Try to spawn background work via non-blocking loopback
        $loopback_url = rest_url(SAM_REST_NAMESPACE . '/backup/prepare-execute');
        $args = [
            'method' => 'POST',
            'timeout' => 1,
            'blocking' => false,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-SAM-Key' => get_option('sam_api_key', ''),
                'X-SAM-Secret' => get_option('sam_api_secret', ''),
            ],
            'body' => wp_json_encode([
                'token' => $token,
                'type' => $type,
            ]),
            'sslverify' => false,
        ];

        $result = wp_remote_post($loopback_url, $args);

        if (is_wp_error($result)) {
            // Loopback failed — try WP-Cron as fallback
            $hook = 'sam_async_backup_prepare';
            if (!wp_next_scheduled($hook, [$token, $type])) {
                wp_schedule_single_event(time(), $hook, [$token, $type]);
                spawn_cron();
            }

            // Check if cron was scheduled
            if (!wp_next_scheduled($hook, [$token, $type])) {
                // Both methods failed — clean up and signal sync fallback
                delete_transient('sam_backup_task_' . $token);
                delete_transient($lock_key);
                return new WP_REST_Response([
                    'success' => true,
                    'async' => false,
                    'reason' => 'Loopback and WP-Cron both unavailable',
                ], 200);
            }
        }

        return new WP_REST_Response([
            'success' => true,
            'async' => true,
            'token' => $token,
        ], 200);
    }

    /**
     * Internal worker endpoint — performs the actual backup archive creation.
     * Called by loopback or WP-Cron; not meant for direct Manager calls.
     */
    public function prepare_execute(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;

        ignore_user_abort(true);
        @set_time_limit(3600);
        @ini_set('memory_limit', '512M');

        $token = $request->get_param('token');
        $type = $request->get_param('type') ?: 'full';

        if (!$token || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return new WP_REST_Response(['success' => false, 'error' => 'Invalid token'], 400);
        }

        $task_key = 'sam_backup_task_' . $token;
        $task = get_transient($task_key);
        if (!$task || $task['status'] !== 'working') {
            return new WP_REST_Response(['success' => false, 'error' => 'No pending task'], 404);
        }

        $token_dir = sys_get_temp_dir() . '/sam_prepared';
        if (!is_dir($token_dir)) {
            @mkdir($token_dir, 0755, true);
        }

        $work_dir = $token_dir . '/sam_work_' . $token;
        @mkdir($work_dir, 0755, true);

        try {
            // 1. Database dump (0-20%)
            $this->update_task_progress($task_key, 5, 'Dumping database...');
            $db_file = $work_dir . '/database.sql.gz';
            $tmp_sql = tempnam(sys_get_temp_dir(), 'sam_db_');

            $success = false;
            $mysqldump = $this->find_mysqldump();
            if ($mysqldump) {
                $success = $this->exec_mysqldump($mysqldump, DB_HOST, DB_USER, DB_PASSWORD, DB_NAME, $tmp_sql);
            }
            if (!$success) {
                $this->php_database_dump($wpdb, DB_NAME, $tmp_sql);
            }

            if ($this->gzip_file($tmp_sql, $db_file)) {
                @unlink($tmp_sql);
            } else {
                rename($tmp_sql, $db_file);
            }

            $this->update_task_progress($task_key, 20, 'Database dump complete');

            // 2. Files archive (20-85%)
            $files_file = null;
            if ($type === 'full') {
                $source_dir = rtrim(ABSPATH, '/');
                $files_file = $work_dir . '/files.tar.gz';

                $this->update_task_progress($task_key, 25, 'Archiving files...');

                $tar = $this->find_binary('tar');
                if ($tar) {
                    $this->exec_tar($tar, $source_dir, $files_file);
                } else {
                    $files_file = $work_dir . '/files.zip';
                    if (!class_exists('ZipArchive')) {
                        throw new \RuntimeException('No tar or ZipArchive available.');
                    }
                    $zip = new \ZipArchive();
                    $zip->open($files_file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
                    $files = new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator($source_dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                        \RecursiveIteratorIterator::LEAVES_ONLY
                    );
                    foreach ($files as $file) {
                        if ($file->isFile()) {
                            $relative = substr($file->getRealPath(), strlen($source_dir) + 1);
                            if (!$this->should_exclude($relative)) {
                                $zip->addFile($file->getRealPath(), $relative);
                            }
                        }
                    }
                    $zip->close();
                }

                $this->update_task_progress($task_key, 85, 'Files archived');
            }

            // 3. Metadata
            $meta_file = $work_dir . '/backup-meta.json';
            file_put_contents($meta_file, json_encode([
                'site_name' => get_bloginfo('name'),
                'site_url' => get_bloginfo('url'),
                'type' => $type,
                'wp_version' => get_bloginfo('version'),
                'php_version' => phpversion(),
                'created_at' => gmdate('c'),
                'trigger' => 'direct_upload',
            ], JSON_PRETTY_PRINT));

            // 4. Combined ZIP (85-95%)
            $this->update_task_progress($task_key, 90, 'Creating combined archive...');
            $combined_file = $token_dir . '/sam_backup_' . $token;

            if (!class_exists('ZipArchive')) {
                throw new \RuntimeException('ZipArchive not available for creating combined archive.');
            }

            $zip = new \ZipArchive();
            if ($zip->open($combined_file, \ZipArchive::CREATE) !== true) {
                throw new \RuntimeException('Failed to create combined archive.');
            }

            $zip->addFile($db_file, 'database.sql.gz');
            if ($files_file && file_exists($files_file)) {
                $zip->addFile($files_file, basename($files_file));
            }
            $zip->addFile($meta_file, 'backup-meta.json');
            $zip->close();

            $this->recursive_delete($work_dir);

            if (!file_exists($combined_file) || filesize($combined_file) === 0) {
                throw new \RuntimeException('Combined archive is empty.');
            }

            $size = filesize($combined_file);
            $checksum = hash_file('sha256', $combined_file);

            // Store metadata for the download/upload phase
            file_put_contents($combined_file . '.meta', json_encode([
                'type' => $type,
                'size' => $size,
                'checksum' => $checksum,
                'created_at' => time(),
            ]));

            // Mark task complete
            set_transient($task_key, [
                'status' => 'done',
                'progress' => 100,
                'message' => 'Backup archive ready',
                'type' => $type,
                'size' => $size,
                'checksum' => $checksum,
                'error' => null,
                'started_at' => $task['started_at'],
                'updated_at' => time(),
            ], 7200);

            SAM_Audit_Logger::log('backup_async_completed', 'backup', 'combined', "Async backup preparation completed: {$size} bytes");

        } catch (\Throwable $e) {
            $this->recursive_delete($work_dir);
            @unlink($token_dir . '/sam_backup_' . $token);

            set_transient($task_key, [
                'status' => 'failed',
                'progress' => 0,
                'message' => 'Backup preparation failed',
                'type' => $type,
                'size' => null,
                'checksum' => null,
                'error' => $e->getMessage(),
                'started_at' => $task['started_at'],
                'updated_at' => time(),
            ], 7200);

            // Release lock
            delete_transient('sam_backup_lock');

            SAM_Audit_Logger::log('backup_async_failed', 'backup', 'combined', 'Async backup failed: ' . $e->getMessage());
        }

        return new WP_REST_Response(['success' => true], 200);
    }

    /**
     * Return current state of async backup preparation.
     */
    public function prepare_status(WP_REST_Request $request): WP_REST_Response {
        $token = $request->get_param('token');

        if (!$token || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => ['code' => 'INVALID_TOKEN', 'message' => 'Invalid token.'],
            ], 400);
        }

        $task = get_transient('sam_backup_task_' . $token);
        if (!$task) {
            return new WP_REST_Response([
                'success' => false,
                'error' => ['code' => 'NOT_FOUND', 'message' => 'Task not found or expired.'],
            ], 404);
        }

        return new WP_REST_Response([
            'success' => true,
            'status' => $task['status'],
            'progress' => $task['progress'],
            'message' => $task['message'],
            'size' => $task['size'],
            'checksum' => $task['checksum'],
            'error' => $task['error'],
        ], 200);
    }

    /**
     * Update async task progress in transient.
     */
    private function update_task_progress(string $task_key, int $progress, string $message): void {
        $task = get_transient($task_key);
        if ($task) {
            $task['progress'] = $progress;
            $task['message'] = $message;
            $task['updated_at'] = time();
            set_transient($task_key, $task, 7200);
        }
    }

    /**
     * Recursively delete a directory and its contents.
     */
    private function recursive_delete(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }

    /**
     * Restore a database or files from a base64-encoded payload.
     */
    public function restore(WP_REST_Request $request): WP_REST_Response {
        @set_time_limit(600);
        @ini_set('memory_limit', '512M');

        $type = $request->get_param('type');
        $data = $request->get_param('data');
        $download_url = $request->get_param('download_url');

        if (!in_array($type, ['database', 'files'], true)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => ['code' => 'INVALID_TYPE', 'message' => 'Type must be "database" or "files".'],
            ], 400);
        }

        if (empty($data) && empty($download_url)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => ['code' => 'MISSING_DATA', 'message' => 'No data or download_url provided.'],
            ], 400);
        }

        $tmp_file = tempnam(sys_get_temp_dir(), 'sam_restore_');
        if (!$tmp_file) {
            return new WP_REST_Response([
                'success' => false,
                'error' => ['code' => 'TEMP_FILE', 'message' => 'Failed to create temp file.'],
            ], 500);
        }

        if (!empty($download_url)) {
            // Stream download to temp file (memory-efficient for large files)
            $response = wp_remote_get($download_url, [
                'timeout'  => 600,
                'stream'   => true,
                'filename' => $tmp_file,
            ]);

            if (is_wp_error($response)) {
                @unlink($tmp_file);
                return new WP_REST_Response([
                    'success' => false,
                    'error' => ['code' => 'DOWNLOAD_FAILED', 'message' => 'Failed to download: ' . $response->get_error_message()],
                ], 500);
            }

            $http_code = wp_remote_retrieve_response_code($response);
            if ($http_code !== 200) {
                @unlink($tmp_file);
                return new WP_REST_Response([
                    'success' => false,
                    'error' => ['code' => 'DOWNLOAD_FAILED', 'message' => "Download returned HTTP {$http_code}."],
                ], 500);
            }
        } else {
            $decoded = base64_decode($data, true);
            if ($decoded === false) {
                @unlink($tmp_file);
                return new WP_REST_Response([
                    'success' => false,
                    'error' => ['code' => 'DECODE_FAILED', 'message' => 'Failed to decode base64 data.'],
                ], 400);
            }

            file_put_contents($tmp_file, $decoded);
            unset($decoded, $data);
        }

        try {
            if ($type === 'database') {
                // Preserve plugin state before DB restore (import will overwrite wp_options)
                $sam_api_key = get_option('sam_api_key');
                $sam_api_secret = get_option('sam_api_secret');
                $active_plugins = get_option('active_plugins', []);
                $plugin_basename = defined('SAM_PLUGIN_BASENAME') ? SAM_PLUGIN_BASENAME : 'simplead-manager-connector/simplead-manager-connector.php';

                SAM_Audit_Logger::log('restore_db_started', 'restore', 'database', 'Database restore initiated via SimpleAd Manager');
                $this->restore_database($tmp_file);

                // Re-activate plugin and restore API credentials after DB import
                $new_active = get_option('active_plugins', []);
                if (!in_array($plugin_basename, $new_active, true)) {
                    $new_active[] = $plugin_basename;
                    update_option('active_plugins', $new_active);
                }
                if ($sam_api_key) {
                    update_option('sam_api_key', $sam_api_key);
                }
                if ($sam_api_secret) {
                    update_option('sam_api_secret', $sam_api_secret);
                }

                // Clear WordPress object cache so new options take effect
                wp_cache_flush();

                SAM_Audit_Logger::log('restore_db_completed', 'restore', 'database', 'Database restore completed');
            } else {
                SAM_Audit_Logger::log('restore_files_started', 'restore', 'files', 'File restore initiated via SimpleAd Manager');
                $this->restore_files($tmp_file);
                SAM_Audit_Logger::log('restore_files_completed', 'restore', 'files', 'File restore completed');
            }

            return new WP_REST_Response(['success' => true, 'type' => $type], 200);
        } catch (\Exception $e) {
            SAM_Audit_Logger::log('restore_failed', 'restore', $type, 'Restore failed: ' . $e->getMessage());
            return new WP_REST_Response([
                'success' => false,
                'error' => ['code' => 'RESTORE_FAILED', 'message' => $e->getMessage()],
            ], 500);
        } finally {
            @unlink($tmp_file);
        }
    }

    /**
     * Restore database from SQL file (may be gzip-compressed).
     */
    private function restore_database(string $file): void {
        // Detect gzip by magic bytes
        $fh = fopen($file, 'rb');
        $magic = fread($fh, 2);
        fclose($fh);

        $sql_file = $file;
        $is_gzip = ($magic === "\x1f\x8b");

        if ($is_gzip) {
            // Try piped decompression: gzip -dc file.gz | mysql (zero memory overhead)
            if ($this->exec_gzip_pipe_import($file)) {
                return;
            }

            // Fallback: streaming decompress to temp file via gzopen/gzread
            $sql_file = $file . '.sql';
            $gz = gzopen($file, 'rb');
            if (!$gz) {
                throw new \RuntimeException('Failed to open gzipped database dump.');
            }
            $out = fopen($sql_file, 'wb');
            if (!$out) {
                gzclose($gz);
                throw new \RuntimeException('Failed to create temp SQL file for decompression.');
            }
            while (!gzeof($gz)) {
                $chunk = gzread($gz, 524288); // 512KB chunks
                if ($chunk === false) break;
                fwrite($out, $chunk);
            }
            gzclose($gz);
            fclose($out);
        }

        try {
            // Try mysql CLI first
            if (!$this->exec_mysql_import($sql_file)) {
                // Fallback to PHP-based import
                $this->php_sql_import($sql_file);
            }
        } finally {
            if ($is_gzip && file_exists($sql_file)) {
                @unlink($sql_file);
            }
        }
    }

    /**
     * Restore files from a zip or tar.gz archive to ABSPATH.
     */
    private function restore_files(string $file): void {
        // Detect format by magic bytes
        $fh = fopen($file, 'rb');
        $magic = fread($fh, 2);
        fclose($fh);

        $is_zip = ($magic === "PK");
        $is_gzip = ($magic === "\x1f\x8b");

        if ($is_zip) {
            if (!class_exists('ZipArchive')) {
                throw new \RuntimeException('ZipArchive extension not available.');
            }
            $zip = new \ZipArchive();
            if ($zip->open($file) !== true) {
                throw new \RuntimeException('Failed to open zip archive.');
            }
            $zip->extractTo(rtrim(ABSPATH, '/'));
            $zip->close();
        } elseif ($is_gzip) {
            $this->exec_tar_extract($file);
        } else {
            throw new \RuntimeException('Unknown archive format. Expected zip or tar.gz.');
        }
    }

    /**
     * Import SQL file using mysql CLI via proc_open.
     */
    private function exec_mysql_import(string $sql_file): bool {
        $mysql = $this->find_binary('mysql');
        if (!$mysql) {
            return false;
        }

        $cmd = [
            $mysql,
            '--host=' . DB_HOST,
            '--user=' . DB_USER,
            '--password=' . DB_PASSWORD,
            DB_NAME,
        ];

        $descriptors = [
            0 => ['file', $sql_file, 'r'], // stdin from SQL file
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            return false;
        }

        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit_code = proc_close($process);

        return $exit_code === 0;
    }

    /**
     * Pipe gzipped SQL directly into mysql CLI (zero memory overhead).
     * Uses shell command: gzip -dc file.sql.gz | mysql ...
     */
    private function exec_gzip_pipe_import(string $gz_file): bool {
        $mysql = $this->find_binary('mysql');
        $gzip = $this->find_binary('gzip');
        if (!$mysql || !$gzip) {
            return false;
        }

        $cmd = sprintf(
            '%s -dc %s | %s --host=%s --user=%s --password=%s %s',
            escapeshellarg($gzip),
            escapeshellarg($gz_file),
            escapeshellarg($mysql),
            escapeshellarg(DB_HOST),
            escapeshellarg(DB_USER),
            escapeshellarg(DB_PASSWORD),
            escapeshellarg(DB_NAME)
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            return false;
        }

        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit_code = proc_close($process);

        return $exit_code === 0;
    }

    /**
     * Import SQL file line by line via $wpdb->query().
     */
    private function php_sql_import(string $sql_file): void {
        global $wpdb;

        $fh = fopen($sql_file, 'r');
        if (!$fh) {
            throw new \RuntimeException('Failed to open SQL file for import.');
        }

        $query = '';
        $delimiter = ';';

        while (($line = fgets($fh)) !== false) {
            $trimmed = trim($line);

            // Skip comments and empty lines
            if ($trimmed === '' || strpos($trimmed, '--') === 0 || strpos($trimmed, '#') === 0) {
                continue;
            }

            // Skip MySQL-specific comments like /*!40101 ... */
            if (strpos($trimmed, '/*') === 0 && strpos($trimmed, '*/;') !== false) {
                continue;
            }

            $query .= $line;

            if (substr(rtrim($query), -1) === $delimiter) {
                $wpdb->query($query);
                $query = '';
            }
        }

        // Execute any remaining query
        if (trim($query) !== '') {
            $wpdb->query($query);
        }

        fclose($fh);
    }

    /**
     * Extract tar.gz archive to ABSPATH using tar command.
     */
    private function exec_tar_extract(string $file): void {
        $tar = $this->find_binary('tar');
        if (!$tar) {
            throw new \RuntimeException('tar command not available for extracting archive.');
        }

        $cmd = [
            $tar,
            'xzf',
            $file,
            '-C',
            rtrim(ABSPATH, '/'),
        ];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start tar extraction process.');
        }

        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit_code = proc_close($process);

        if ($exit_code !== 0) {
            throw new \RuntimeException('tar extraction failed: ' . $stderr);
        }
    }

    /**
     * Gzip a file using streaming to avoid loading the entire file into memory.
     */
    private function gzip_file(string $source, string $dest): bool {
        if (!function_exists('gzopen')) {
            return false;
        }

        $fp_in = fopen($source, 'rb');
        if (!$fp_in) {
            return false;
        }

        $fp_out = gzopen($dest, 'wb6');
        if (!$fp_out) {
            fclose($fp_in);
            return false;
        }

        while (!feof($fp_in)) {
            $chunk = fread($fp_in, 524288); // 512KB chunks
            if ($chunk === false) {
                break;
            }
            gzwrite($fp_out, $chunk);
        }

        fclose($fp_in);
        gzclose($fp_out);

        return file_exists($dest) && filesize($dest) > 0;
    }

    private function php_zip_backup(string $source_dir): void {
        $tmp_file = tempnam(sys_get_temp_dir(), 'sam_backup_');

        if (!class_exists('ZipArchive')) {
            echo '{"success":false,"error":{"code":"NO_ZIP","message":"No tar or ZipArchive available for file backup."}}';
            exit;
        }

        $zip = new ZipArchive();
        $zip->open($tmp_file, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source_dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if ($file->isFile()) {
                $relative = substr($file->getRealPath(), strlen($source_dir) + 1);
                if (!$this->should_exclude($relative)) {
                    $zip->addFile($file->getRealPath(), $relative);
                }
            }
        }

        $zip->close();

        $this->serve_and_cleanup($tmp_file, 'backup-files-' . date('Y-m-d-His') . '.zip', 'application/zip');
    }
}
