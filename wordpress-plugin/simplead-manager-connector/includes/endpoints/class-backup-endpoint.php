<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Backup streaming endpoints.
 */
class SAM_Backup_Endpoint extends SAM_Endpoint_Base {

    public function register_routes(): void {
        register_rest_route(SAM_REST_NAMESPACE, '/backup/db', [
            'methods'             => 'GET',
            'callback'            => [$this, 'backup_database'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/backup/files', [
            'methods'             => 'GET',
            'callback'            => [$this, 'backup_files'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    public function backup_database(WP_REST_Request $request): void {
        global $wpdb;

        @set_time_limit(600);
        @ini_set('memory_limit', '512M');

        SAM_Audit_Logger::log('backup_db_started', 'backup', 'database', 'Database backup initiated via SimpleAd Manager');

        // Stream headers
        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="backup-' . date('Y-m-d-His') . '.sql"');
        header('X-SAM-Backup-Type: database');

        // Flush output buffers
        while (ob_get_level()) {
            ob_end_clean();
        }

        // Database connection info
        $host = DB_HOST;
        $user = DB_USER;
        $pass = DB_PASSWORD;
        $name = DB_NAME;

        // Try mysqldump first (most reliable and efficient)
        $mysqldump = $this->find_mysqldump();
        if ($mysqldump) {
            $cmd = sprintf(
                '%s --host=%s --user=%s --password=%s --single-transaction --quick --lock-tables=false %s',
                escapeshellcmd($mysqldump),
                escapeshellarg($host),
                escapeshellarg($user),
                escapeshellarg($pass),
                escapeshellarg($name)
            );

            passthru($cmd);
            exit;
        }

        // Fallback: PHP-based dump with streaming
        $this->php_database_dump($wpdb, $name);
        exit;
    }

    public function backup_files(WP_REST_Request $request): void {
        @set_time_limit(600);
        @ini_set('memory_limit', '512M');

        SAM_Audit_Logger::log('backup_files_started', 'backup', 'files', 'File backup initiated via SimpleAd Manager');

        $source_dir = WP_CONTENT_DIR;
        $filename = 'backup-files-' . date('Y-m-d-His') . '.tar.gz';

        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-SAM-Backup-Type: files');

        while (ob_get_level()) {
            ob_end_clean();
        }

        // Try tar command (most efficient, streams directly)
        $tar = $this->find_binary('tar');
        if ($tar) {
            $cmd = sprintf(
                '%s czf - -C %s .',
                escapeshellcmd($tar),
                escapeshellarg($source_dir)
            );

            passthru($cmd);
            exit;
        }

        // Fallback: PHP ZipArchive
        $this->php_zip_backup($source_dir);
        exit;
    }

    private function find_mysqldump(): ?string {
        if (!function_exists('exec') || !function_exists('passthru')) {
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
        if (!function_exists('exec') || !function_exists('passthru')) {
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

    private function php_database_dump($wpdb, string $db_name): void {
        echo "-- SimpleAd Manager Database Backup\n";
        echo "-- Date: " . date('Y-m-d H:i:s') . "\n";
        echo "-- Database: {$db_name}\n\n";
        echo "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";

        $tables = $wpdb->get_col("SHOW TABLES");

        foreach ($tables as $table) {
            // Table structure
            $create = $wpdb->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_N);
            echo "DROP TABLE IF EXISTS `{$table}`;\n";
            echo $create[1] . ";\n\n";

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

                    echo "INSERT INTO `{$table}` (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $values) . ");\n";
                }

                $offset += $chunk;
                flush();
            }

            echo "\n";
        }

        echo "SET FOREIGN_KEY_CHECKS = 1;\n";
    }

    private function php_zip_backup(string $source_dir): void {
        // Create a temporary zip file and stream it
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

        // Update content type for zip
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="backup-files-' . date('Y-m-d-His') . '.zip"');
        header('Content-Length: ' . filesize($tmp_file));

        readfile($tmp_file);
        @unlink($tmp_file);
        exit;
    }
}
