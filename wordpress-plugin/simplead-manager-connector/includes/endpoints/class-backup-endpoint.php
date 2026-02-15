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

        // Serve the temp file
        $this->serve_and_cleanup($tmp_file, 'backup-' . date('Y-m-d-His') . '.sql', 'application/sql');
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
    private function exec_tar(string $binary, string $source_dir, string $output_file): bool {
        $cmd = [
            $binary,
            'czf',
            $output_file,
            '-C',
            $source_dir,
            '.',
        ];

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
        header('X-SAM-Backup-Type: ' . (str_contains($content_type, 'sql') ? 'database' : 'files'));

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
            $sql_file = $file . '.sql';
            $uncompressed = gzdecode(file_get_contents($file));
            if ($uncompressed === false) {
                throw new \RuntimeException('Failed to decompress gzipped database dump.');
            }
            file_put_contents($sql_file, $uncompressed);
            unset($uncompressed);
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
                $zip->addFile($file->getRealPath(), $relative);
            }
        }

        $zip->close();

        $this->serve_and_cleanup($tmp_file, 'backup-files-' . date('Y-m-d-His') . '.zip', 'application/zip');
    }
}
