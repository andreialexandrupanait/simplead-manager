<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Backup endpoints — pure PHP implementations for maximum hosting compatibility.
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

        register_rest_route(SAM_REST_NAMESPACE, '/backup/prepare-init', [
            'methods'             => 'POST',
            'callback'            => [$this, 'prepare_chunked_init'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/backup/prepare-chunk-exec', [
            'methods'             => 'POST',
            'callback'            => [$this, 'prepare_chunk_exec'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/backup/prepare-finalize', [
            'methods'             => 'POST',
            'callback'            => [$this, 'prepare_chunk_finalize'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/backup/prepare-chunk-download', [
            'methods'             => 'POST',
            'callback'            => [$this, 'prepare_chunk_download'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/backup/manifest', [
            'methods'             => 'POST',
            'callback'            => [$this, 'generate_manifest'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/backup/session-manifest', [
            'methods'             => 'POST',
            'callback'            => [$this, 'download_session_manifest'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(SAM_REST_NAMESPACE, '/backup/incremental-init', [
            'methods'             => 'POST',
            'callback'            => [$this, 'incremental_init'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    // ── Streaming backup endpoints ──────────────────────────────────────

    public function backup_database(WP_REST_Request $request): void {
        global $wpdb;

        @set_time_limit(600);
        @ini_set('memory_limit', '512M');

        $this->check_disk_space(268435456); // 256MB

        SAM_Audit_Logger::log('backup_db_started', 'backup', 'database', 'Database backup initiated via SimpleAd Manager');

        $tmp_file = tempnam(sys_get_temp_dir(), 'sam_db_backup_');
        if (!$tmp_file) {
            status_header(500);
            echo '{"success":false,"error":{"code":"TEMP_FILE","message":"Failed to create temp file."}}';
            exit;
        }

        $this->php_database_dump($wpdb, DB_NAME, $tmp_file);

        $gz_file = $tmp_file . '.gz';
        if ($this->gzip_file($tmp_file, $gz_file)) {
            @unlink($tmp_file);
            $this->serve_and_cleanup($gz_file, 'backup-' . date('Y-m-d-His') . '.sql.gz', 'application/gzip');
        } else {
            $this->serve_and_cleanup($tmp_file, 'backup-' . date('Y-m-d-His') . '.sql', 'application/sql');
        }
    }

    public function backup_files(WP_REST_Request $request): void {
        @set_time_limit(600);
        @ini_set('memory_limit', '512M');

        $this->check_disk_space(536870912); // 512MB

        SAM_Audit_Logger::log('backup_files_started', 'backup', 'files', 'File backup initiated via SimpleAd Manager');

        $source_dir = rtrim(ABSPATH, '/');
        $this->php_zip_backup($source_dir);
        exit;
    }

    // ── Chunked download endpoints ──────────────────────────────────────

    public function prepare_backup(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;

        ignore_user_abort(true);
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        @ini_set('memory_limit', '512M');

        try {
            $this->check_disk_space(536870912); // 512MB
        } catch (\RuntimeException $e) {
            return new WP_REST_Response([
                'success' => false,
                'error' => ['code' => 'DISK_SPACE', 'message' => $e->getMessage()],
            ], 507);
        }

        $type = $request->get_param('type');
        if (!in_array($type, ['db', 'files'], true)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => ['code' => 'INVALID_TYPE', 'message' => 'Type must be "db" or "files".'],
            ], 400);
        }

        $token = bin2hex(random_bytes(32));
        $token_dir = sys_get_temp_dir() . '/sam_prepared';
        if (!is_dir($token_dir)) {
            @mkdir($token_dir, 0700, true);
        }
        $prepared_file = $token_dir . '/sam_backup_' . $token;

        if ($type === 'db') {
            SAM_Audit_Logger::log('backup_db_started', 'backup', 'database', 'Database backup (chunked) initiated');

            $tmp_sql = tempnam(sys_get_temp_dir(), 'sam_db_');
            if (!$tmp_sql) {
                return new WP_REST_Response([
                    'success' => false,
                    'error' => ['code' => 'TEMP_FAILED', 'message' => 'Cannot create temp file.'],
                ], 500);
            }
            $this->php_database_dump($wpdb, DB_NAME, $tmp_sql);

            if ($this->gzip_file($tmp_sql, $prepared_file)) {
                @unlink($tmp_sql);
            } else {
                rename($tmp_sql, $prepared_file);
            }
        } else {
            SAM_Audit_Logger::log('backup_files_started', 'backup', 'files', 'File backup (chunked) initiated');

            $source_dir = rtrim(ABSPATH, '/');
            if (!class_exists('ZipArchive')) {
                return new WP_REST_Response([
                    'success' => false,
                    'error' => ['code' => 'NO_ARCHIVER', 'message' => 'ZipArchive extension not available.'],
                ], 500);
            }

            $zip = new \ZipArchive();
            if ($zip->open($prepared_file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                return new WP_REST_Response([
                    'success' => false,
                    'error' => ['code' => 'ZIP_FAILED', 'message' => 'Failed to create zip archive.'],
                ], 500);
            }
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($source_dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($files as $file) {
                if ($file->isFile()) {
                    $realPath = $file->getRealPath();
                    if ($realPath === false || !$this->is_within_abspath($realPath)) continue;
                    $relative = substr($realPath, strlen($source_dir) + 1);
                    if (!$this->should_exclude($relative)) {
                        $zip->addFile($realPath, $relative);
                    }
                }
            }
            $zip->close();
        }

        clearstatcache(true, $prepared_file);
        if (!file_exists($prepared_file) || filesize($prepared_file) === 0) {
            return new WP_REST_Response([
                'success' => false,
                'error' => [
                    'code' => 'PREPARE_FAILED',
                    'message' => 'Prepared file is empty or missing.',
                    'debug' => (defined('WP_DEBUG') && WP_DEBUG) ? [
                        'type' => $type,
                        'prepared_file' => $prepared_file,
                        'file_exists' => file_exists($prepared_file),
                        'temp_dir' => sys_get_temp_dir(),
                        'temp_writable' => is_writable(sys_get_temp_dir()),
                        'token_dir_exists' => is_dir($token_dir),
                        'max_execution_time' => ini_get('max_execution_time'),
                    ] : null,
                ],
            ], 500);
        }

        $size = filesize($prepared_file);
        $checksum = hash_file('sha256', $prepared_file);

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

        if ($offset < 0) $offset = 0;
        if ($offset >= $file_size) {
            status_header(416);
            echo '{"success":false,"error":{"code":"RANGE_ERROR","message":"Offset beyond file size."}}';
            exit;
        }
        if ($length <= 0 || $length > 26214400) {
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
            $read_size = min(524288, $remaining);
            $data = fread($fh, $read_size);
            if ($data === false) break;
            echo $data;
            $remaining -= strlen($data);
        }
        fclose($fh);
        exit;
    }

    public function cleanup_prepared(WP_REST_Request $request): WP_REST_Response {
        $token = $request->get_param('token');

        if (!$token || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return new WP_REST_Response(['success' => false], 400);
        }

        $prepared_file = sys_get_temp_dir() . '/sam_prepared/sam_backup_' . $token;
        @unlink($prepared_file);
        @unlink($prepared_file . '.meta');

        // Also clean up chunked work directory if present
        $chunked_dir = sys_get_temp_dir() . '/sam_prepared/sam_chunked_' . $token;
        if (is_dir($chunked_dir)) {
            $this->recursive_delete($chunked_dir);
        }

        return new WP_REST_Response(['success' => true], 200);
    }

    // ── Capabilities ────────────────────────────────────────────────────

    public function get_capabilities(WP_REST_Request $request): WP_REST_Response {
        return new WP_REST_Response([
            'success' => true,
            'chunked_prepare' => true,
            'direct_upload' => true,
            'incremental_backup' => true,
            'manifest_generation' => true,
            'strategies' => ['s3_multipart', 'chunked_push'],
            'async_methods' => [
                'cli'      => false,
                'loopback' => $this->can_loopback(),
                'cron'     => true,
            ],
            'tools' => [
                'mysqldump' => false,
                'tar'       => false,
                'gzip'      => function_exists('gzopen'),
                'zip'       => class_exists('ZipArchive'),
            ],
            'limits' => [
                'max_execution_time'  => (int) ini_get('max_execution_time'),
                'memory_limit'        => ini_get('memory_limit'),
                'temp_dir_free_space' => @disk_free_space(sys_get_temp_dir()),
            ],
            'plugin_version' => defined('SAM_VERSION') ? SAM_VERSION : 'unknown',
        ], 200);
    }

    /**
     * Download the pre-collected manifest from a chunked session.
     * Avoids re-scanning the filesystem after backup is complete.
     */
    public function download_session_manifest(WP_REST_Request $request): WP_REST_Response {
        $token = $request->get_param('token');

        if (!$token || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => ['code' => 'INVALID_TOKEN', 'message' => 'Invalid token.'],
            ], 400);
        }

        $token_dir = sys_get_temp_dir() . '/sam_prepared/sam_chunked_' . $token;
        $manifest_file = $token_dir . '/manifest.json';

        if (!file_exists($manifest_file)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => ['code' => 'NOT_FOUND', 'message' => 'Manifest not found for this session.'],
            ], 404);
        }

        $manifest = json_decode(file_get_contents($manifest_file), true);

        return new WP_REST_Response([
            'success' => true,
            'total_files' => count($manifest),
            'manifest' => $manifest,
        ], 200);
    }

    /**
     * Generate a file manifest for incremental backup support.
     * Returns array of {p: path, s: size, m: mtime} for all files.
     */
    public function generate_manifest(WP_REST_Request $request): WP_REST_Response {
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');

        $source_dir = rtrim(ABSPATH, '/');
        $entries = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source_dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) continue;
            $realPath = $file->getRealPath();
            if ($realPath === false || !$this->is_within_abspath($realPath)) continue;
            $relative = substr($realPath, strlen($source_dir) + 1);
            if ($this->should_exclude($relative)) continue;

            $entries[] = [
                'p' => $relative,
                's' => $file->getSize(),
                'm' => $file->getMTime(),
            ];
        }

        return new WP_REST_Response([
            'success' => true,
            'total_files' => count($entries),
            'manifest' => $entries,
        ], 200);
    }

    /**
     * Initialize an incremental backup by comparing previous manifest with current state.
     * Receives previous manifest and returns changed/new/deleted files grouped into chunks.
     */
    public function incremental_init(WP_REST_Request $request): WP_REST_Response {
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');

        $previous_manifest = $request->get_param('manifest');
        if (!is_array($previous_manifest)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => ['code' => 'INVALID_MANIFEST', 'message' => 'Previous manifest must be an array.'],
            ], 400);
        }

        // Build lookup from previous manifest
        $prev_lookup = [];
        foreach ($previous_manifest as $entry) {
            $prev_lookup[$entry['p']] = $entry;
        }

        $source_dir = rtrim(ABSPATH, '/');
        $changed_files = [];
        $new_files = [];
        $current_paths = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source_dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) continue;
            $realPath = $file->getRealPath();
            if ($realPath === false || !$this->is_within_abspath($realPath)) continue;
            $relative = substr($realPath, strlen($source_dir) + 1);
            if ($this->should_exclude($relative)) continue;

            $current_paths[$relative] = true;
            $size = $file->getSize();
            $mtime = $file->getMTime();

            if (isset($prev_lookup[$relative])) {
                // Check if changed (size or mtime differs)
                $prev = $prev_lookup[$relative];
                if ($prev['s'] != $size || $prev['m'] != $mtime) {
                    $changed_files[] = $relative;
                }
            } else {
                $new_files[] = $relative;
            }
        }

        // Find deleted files
        $deleted_paths = [];
        foreach ($prev_lookup as $path => $entry) {
            if (!isset($current_paths[$path])) {
                $deleted_paths[] = $path;
            }
        }

        // Group changed+new files into 50MB chunks
        $all_files = array_merge($changed_files, $new_files);
        $max_chunk_bytes = 50 * 1024 * 1024;
        $chunks = [];
        $current_chunk_files = [];
        $current_chunk_size = 0;

        foreach ($all_files as $relative) {
            $abs = $source_dir . '/' . $relative;
            $file_size = is_file($abs) ? filesize($abs) : 0;

            if ($current_chunk_size + $file_size > $max_chunk_bytes && !empty($current_chunk_files)) {
                $chunks[] = ['scope' => 'files_list', 'files' => $current_chunk_files, 'estimated_size' => $current_chunk_size];
                $current_chunk_files = [];
                $current_chunk_size = 0;
            }

            $current_chunk_files[] = $relative;
            $current_chunk_size += $file_size;
        }

        if (!empty($current_chunk_files)) {
            $chunks[] = ['scope' => 'files_list', 'files' => $current_chunk_files, 'estimated_size' => $current_chunk_size];
        }

        // Create chunked session token
        $token = bin2hex(random_bytes(32));
        $token_dir = sys_get_temp_dir() . '/sam_prepared/sam_chunked_' . $token;
        if (!@mkdir($token_dir, 0700, true)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => ['code' => 'MKDIR_FAILED', 'message' => 'Cannot create work directory.'],
            ], 500);
        }

        // Add index to each chunk
        foreach ($chunks as $i => &$chunk) {
            $chunk['index'] = $i;
        }
        unset($chunk);

        file_put_contents($token_dir . '/chunks.json', json_encode([
            'type' => 'files',
            'chunks' => $chunks,
            'total_chunks' => count($chunks),
            'created_at' => time(),
        ]));

        SAM_Audit_Logger::log('backup_incremental_init', 'backup', 'incremental',
            'Incremental init: ' . count($changed_files) . ' changed, ' . count($new_files) . ' new, ' . count($deleted_paths) . ' deleted');

        return new WP_REST_Response([
            'success' => true,
            'token' => $token,
            'total_chunks' => count($chunks),
            'changed_count' => count($changed_files),
            'new_count' => count($new_files),
            'deleted_count' => count($deleted_paths),
            'deleted_paths' => $deleted_paths,
            'chunks' => $chunks,
        ], 200);
    }

    // ── Direct upload endpoints ─────────────────────────────────────────

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
            @mkdir($token_dir, 0700, true);
        }

        $work_dir = $token_dir . '/sam_work_' . $token;
        @mkdir($work_dir, 0700, true);

        try {
            // 1. Database dump
            $db_file = $work_dir . '/database.sql.gz';
            $tmp_sql = tempnam(sys_get_temp_dir(), 'sam_db_');
            $this->php_database_dump($wpdb, DB_NAME, $tmp_sql);

            if ($this->gzip_file($tmp_sql, $db_file)) {
                @unlink($tmp_sql);
            } else {
                rename($tmp_sql, $db_file);
            }

            // 2. Files archive (if full backup)
            $files_file = null;
            if ($type === 'full') {
                $source_dir = rtrim(ABSPATH, '/');
                $files_file = $work_dir . '/files.zip';

                if (!class_exists('ZipArchive')) {
                    return new WP_REST_Response([
                        'success' => false,
                        'error' => ['code' => 'NO_ARCHIVER', 'message' => 'ZipArchive extension not available.'],
                    ], 500);
                }

                $zip = new \ZipArchive();
                if ($zip->open($files_file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                    $this->recursive_delete($work_dir);
                    return new WP_REST_Response([
                        'success' => false,
                        'error' => ['code' => 'ZIP_FAILED', 'message' => 'Failed to create files archive.'],
                    ], 500);
                }
                $files = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($source_dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::LEAVES_ONLY
                );
                foreach ($files as $file) {
                    if ($file->isFile()) {
                        $realPath = $file->getRealPath();
                        if ($realPath === false || !$this->is_within_abspath($realPath)) continue;
                        $relative = substr($realPath, strlen($source_dir) + 1);
                        if (!$this->should_exclude($relative)) {
                            $zip->addFile($realPath, $relative);
                        }
                    }
                }
                $zip->close();
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

            // 4. Combined ZIP
            $combined_file = $token_dir . '/sam_backup_' . $token;

            if (!class_exists('ZipArchive')) {
                return new WP_REST_Response([
                    'success' => false,
                    'error' => ['code' => 'NO_ZIP', 'message' => 'ZipArchive not available.'],
                ], 500);
            }

            // Verify component files before combining
            $debug = [];
            $debug['db_file'] = file_exists($db_file) ? filesize($db_file) : 'MISSING';
            $debug['meta_file'] = file_exists($meta_file) ? filesize($meta_file) : 'MISSING';
            if ($files_file) {
                $debug['files_file'] = file_exists($files_file) ? filesize($files_file) : 'MISSING';
            }
            $debug['temp_dir'] = sys_get_temp_dir();
            $debug['temp_writable'] = is_writable(sys_get_temp_dir());
            $debug['disk_free'] = @disk_free_space(sys_get_temp_dir());

            if (!file_exists($db_file) || filesize($db_file) === 0) {
                $this->recursive_delete($work_dir);
                return new WP_REST_Response([
                    'success' => false,
                    'error' => ['code' => 'PREPARE_FAILED', 'message' => 'Database dump is empty or missing.'] + ((defined('WP_DEBUG') && WP_DEBUG) ? ['debug' => $debug] : []),
                ], 500);
            }

            $zip = new \ZipArchive();
            if ($zip->open($combined_file, \ZipArchive::CREATE) !== true) {
                $this->recursive_delete($work_dir);
                return new WP_REST_Response([
                    'success' => false,
                    'error' => ['code' => 'ZIP_FAILED', 'message' => 'Failed to create combined archive.'] + ((defined('WP_DEBUG') && WP_DEBUG) ? ['debug' => $debug] : []),
                ], 500);
            }

            // addFile uses lazy reads at close() — work_dir must NOT be deleted before close()
            $zip->addFile($db_file, 'database.sql.gz');
            if ($files_file && file_exists($files_file)) {
                $zip->addFile($files_file, basename($files_file));
            }
            $zip->addFile($meta_file, 'backup-meta.json');

            $close_result = $zip->close();
            $debug['zip_close'] = $close_result;

            if (!$close_result) {
                $this->recursive_delete($work_dir);
                return new WP_REST_Response([
                    'success' => false,
                    'error' => ['code' => 'ZIP_CLOSE_FAILED', 'message' => 'ZipArchive::close() returned false.'] + ((defined('WP_DEBUG') && WP_DEBUG) ? ['debug' => $debug] : []),
                ], 500);
            }

            // Check file immediately after close before deleting work_dir
            clearstatcache(true, $combined_file);
            $debug['after_close_exists'] = file_exists($combined_file);
            $debug['after_close_size'] = file_exists($combined_file) ? filesize($combined_file) : 0;

            // Only delete work_dir AFTER close() has read all files
            $this->recursive_delete($work_dir);

        } catch (\Throwable $e) {
            $this->recursive_delete($work_dir);
            @unlink($token_dir . '/sam_backup_' . $token);
            return new WP_REST_Response([
                'success' => false,
                'error' => ['code' => 'PREPARE_FAILED', 'message' => $e->getMessage()],
            ], 500);
        }

        clearstatcache(true, $combined_file);
        if (!file_exists($combined_file) || filesize($combined_file) === 0) {
            $debug['final_exists'] = file_exists($combined_file);
            return new WP_REST_Response([
                'success' => false,
                'error' => ['code' => 'PREPARE_FAILED', 'message' => 'Combined archive is empty after close.'] + ((defined('WP_DEBUG') && WP_DEBUG) ? ['debug' => $debug] : []),
            ], 500);
        }

        $size = filesize($combined_file);
        $checksum = hash_file('sha256', $combined_file);

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

    // ── Async preparation endpoints ─────────────────────────────────────

    public function prepare_async(WP_REST_Request $request): WP_REST_Response {
        $type = $request->get_param('type') ?: 'full';
        if (!in_array($type, ['full', 'db'], true)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => ['code' => 'INVALID_TYPE', 'message' => 'Type must be "full" or "db".'],
            ], 400);
        }

        // Check for existing in-progress task
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
            delete_transient($lock_key);
            delete_transient('sam_backup_task_' . $existing_token);
        }

        $token = bin2hex(random_bytes(32));

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

        set_transient($lock_key, $token, 7200);

        SAM_Audit_Logger::log('backup_async_started', 'backup', 'combined', "Async backup preparation ({$type}) initiated");

        $preferred_method = $request->get_param('preferred_method');

        // Try loopback (non-blocking HTTP request to self)
        if (!$preferred_method || $preferred_method === 'loopback') {
            $loopback_url = rest_url(SAM_REST_NAMESPACE . '/backup/prepare-execute');
            $body = wp_json_encode([
                'token' => $token,
                'type' => $type,
            ]);
            $timestamp = (string) time();
            $route = '/' . SAM_REST_NAMESPACE . '/backup/prepare-execute';
            $api_key = get_option('sam_api_key', '');
            $api_secret = get_option('sam_api_secret', '');
            $string_to_sign = implode('|', ['POST', $route, $timestamp, $body]);
            $signature = hash_hmac('sha256', $string_to_sign, $api_secret);

            $args = [
                'method' => 'POST',
                'timeout' => 1,
                'blocking' => false,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-SAM-Key' => $api_key,
                    'X-SAM-Timestamp' => $timestamp,
                    'X-SAM-Signature' => $signature,
                ],
                'body' => $body,
                'sslverify' => false,
            ];

            $result = wp_remote_post($loopback_url, $args);

            if (!is_wp_error($result)) {
                return new WP_REST_Response([
                    'success' => true,
                    'async' => true,
                    'token' => $token,
                    'method' => 'loopback',
                ], 200);
            }
        }

        // Try WP-Cron as fallback
        if (!$preferred_method || $preferred_method === 'cron') {
            $hook = 'sam_async_backup_prepare';
            if (!wp_next_scheduled($hook, [$token, $type])) {
                wp_schedule_single_event(time(), $hook, [$token, $type]);
                spawn_cron();
            }

            if (wp_next_scheduled($hook, [$token, $type])) {
                return new WP_REST_Response([
                    'success' => true,
                    'async' => true,
                    'token' => $token,
                    'method' => 'cron',
                ], 200);
            }
        }

        // All async methods failed
        delete_transient('sam_backup_task_' . $token);
        delete_transient($lock_key);

        return new WP_REST_Response([
            'success' => true,
            'async' => false,
            'reason' => 'All async methods unavailable',
        ], 200);
    }

    public function prepare_execute(WP_REST_Request $request): WP_REST_Response {
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

        $this->run_prepare_work($token, $type);

        return new WP_REST_Response(['success' => true], 200);
    }

    /**
     * Core backup preparation logic — shared by prepare_execute (REST/cron) and CLI worker.
     */
    public function run_prepare_work(string $token, string $type): void {
        global $wpdb;

        $task_key = 'sam_backup_task_' . $token;
        $task = get_transient($task_key);

        $token_dir = sys_get_temp_dir() . '/sam_prepared';
        if (!is_dir($token_dir)) {
            @mkdir($token_dir, 0700, true);
        }

        $work_dir = $token_dir . '/sam_work_' . $token;
        @mkdir($work_dir, 0700, true);

        try {
            // 1. Database dump (0-20%)
            $this->update_task_progress($task_key, 5, 'Dumping database...');
            $db_file = $work_dir . '/database.sql.gz';
            $tmp_sql = tempnam(sys_get_temp_dir(), 'sam_db_');
            $this->php_database_dump($wpdb, DB_NAME, $tmp_sql);

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
                $files_file = $work_dir . '/files.zip';

                $this->update_task_progress($task_key, 25, 'Archiving files...');

                if (!class_exists('ZipArchive')) {
                    throw new \RuntimeException('ZipArchive extension not available.');
                }
                $zip = new \ZipArchive();
                if ($zip->open($files_file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                    throw new \RuntimeException('Failed to create files archive.');
                }
                $files = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($source_dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::LEAVES_ONLY
                );
                foreach ($files as $file) {
                    if ($file->isFile()) {
                        $realPath = $file->getRealPath();
                        if ($realPath === false || !$this->is_within_abspath($realPath)) continue;
                        $relative = substr($realPath, strlen($source_dir) + 1);
                        if (!$this->should_exclude($relative)) {
                            $zip->addFile($realPath, $relative);
                        }
                    }
                }
                $zip->close();

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

            if (!$zip->close()) {
                throw new \RuntimeException('ZipArchive::close() failed for combined archive.');
            }

            // Only delete work_dir AFTER close() has read all files
            $this->recursive_delete($work_dir);

            clearstatcache(true, $combined_file);
            if (!file_exists($combined_file) || filesize($combined_file) === 0) {
                throw new \RuntimeException('Combined archive is empty after close.');
            }

            $size = filesize($combined_file);
            $checksum = hash_file('sha256', $combined_file);

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
                'started_at' => $task ? $task['started_at'] : time(),
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
                'started_at' => $task ? $task['started_at'] : time(),
                'updated_at' => time(),
            ], 7200);

            delete_transient('sam_backup_lock');

            SAM_Audit_Logger::log('backup_async_failed', 'backup', 'combined', 'Async backup failed: ' . $e->getMessage());
        }
    }

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

    // ── Chunked prepare endpoints ───────────────────────────────────────
    // Split large backup operations into multiple HTTP requests so each
    // completes within shared-hosting max_execution_time limits.

    public function prepare_chunked_init(WP_REST_Request $request): WP_REST_Response {
        try {
            $this->check_disk_space(268435456); // 256MB
        } catch (\RuntimeException $e) {
            return new WP_REST_Response([
                'success' => false,
                'error' => ['code' => 'DISK_SPACE', 'message' => $e->getMessage()],
            ], 507);
        }

        $type = $request->get_param('type');
        if (!in_array($type, ['db', 'files'], true)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => ['code' => 'INVALID_TYPE', 'message' => 'Type must be "db" or "files".'],
            ], 400);
        }

        $token = bin2hex(random_bytes(32));
        $token_dir = sys_get_temp_dir() . '/sam_prepared/sam_chunked_' . $token;
        if (!@mkdir($token_dir, 0700, true)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => ['code' => 'MKDIR_FAILED', 'message' => 'Cannot create work directory.'],
            ], 500);
        }

        if ($type === 'db') {
            $chunks = $this->group_tables_into_chunks();
        } else {
            $chunks = $this->group_directories_into_chunks();

            // Collect file manifest during init (reuses the filesystem walk already done)
            $this->collect_manifest_during_init($token_dir);
        }

        file_put_contents($token_dir . '/chunks.json', json_encode([
            'type' => $type,
            'chunks' => $chunks,
            'total_chunks' => count($chunks),
            'created_at' => time(),
        ]));

        SAM_Audit_Logger::log('backup_chunked_init', 'backup', $type, "Chunked prepare initialized: " . count($chunks) . " chunks");

        $response = [
            'success' => true,
            'token' => $token,
            'type' => $type,
            'total_chunks' => count($chunks),
            'chunks' => $chunks,
        ];

        // Include manifest availability flag
        if ($type === 'files' && file_exists($token_dir . '/manifest.json')) {
            $response['has_manifest'] = true;
        }

        return new WP_REST_Response($response, 200);
    }

    /**
     * Collect file manifest (path, size, mtime) during prepare-init.
     * Stored in token dir so it can be retrieved without re-scanning the filesystem.
     */
    private function collect_manifest_during_init(string $token_dir): void {
        $source_dir = rtrim(ABSPATH, '/');
        $entries = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source_dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) continue;
            $realPath = $file->getRealPath();
            if ($realPath === false || !$this->is_within_abspath($realPath)) continue;
            $relative = substr($realPath, strlen($source_dir) + 1);
            if ($this->should_exclude($relative)) continue;

            $entries[] = [
                'p' => $relative,
                's' => $file->getSize(),
                'm' => $file->getMTime(),
            ];
        }

        file_put_contents($token_dir . '/manifest.json', json_encode($entries));
    }

    public function prepare_chunk_exec(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;

        ignore_user_abort(true);
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        @ini_set('memory_limit', '512M');

        $token = $request->get_param('token');
        $chunk_index = (int) $request->get_param('chunk_index');

        if (!$token || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => ['code' => 'INVALID_TOKEN', 'message' => 'Invalid token.'],
            ], 400);
        }

        $token_dir = sys_get_temp_dir() . '/sam_prepared/sam_chunked_' . $token;
        $chunks_file = $token_dir . '/chunks.json';

        if (!file_exists($chunks_file)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => ['code' => 'NOT_FOUND', 'message' => 'Chunked session not found or expired.'],
            ], 404);
        }

        $chunks_info = json_decode(file_get_contents($chunks_file), true);
        $type = $chunks_info['type'];
        $total = $chunks_info['total_chunks'];

        if ($chunk_index < 0 || $chunk_index >= $total) {
            return new WP_REST_Response([
                'success' => false,
                'error' => ['code' => 'INVALID_CHUNK', 'message' => "Chunk index must be 0-" . ($total - 1) . "."],
            ], 400);
        }

        // Skip if already completed AND chunk file still exists (idempotent retry)
        $done_marker = $token_dir . '/chunk_' . $chunk_index . '.done';
        if (file_exists($done_marker)) {
            // Verify the actual chunk file still exists
            $expected_file = ($type === 'db')
                ? $token_dir . '/chunk_' . $chunk_index . '.sql.gz'
                : $token_dir . '/chunk_' . $chunk_index . '_files.zip';
            if (file_exists($expected_file)) {
                $size = (int) file_get_contents($done_marker);
                return new WP_REST_Response([
                    'success' => true,
                    'chunk_index' => $chunk_index,
                    'chunk_size' => $size,
                    'skipped' => true,
                ], 200);
            }
            // Chunk file was cleaned up — remove done marker and re-execute
            @unlink($done_marker);
        }

        $chunk = $chunks_info['chunks'][$chunk_index];

        try {
            if ($type === 'db') {
                $chunk_size = $this->exec_db_chunk($wpdb, $token_dir, $chunk_index, $chunk, $total);
            } else {
                $chunk_size = $this->exec_files_chunk($token_dir, $chunk_index, $chunk);
            }
        } catch (\Throwable $e) {
            return new WP_REST_Response([
                'success' => false,
                'error' => ['code' => 'CHUNK_FAILED', 'message' => $e->getMessage()],
            ], 500);
        }

        // Mark done
        file_put_contents($done_marker, (string) $chunk_size);

        return new WP_REST_Response([
            'success' => true,
            'chunk_index' => $chunk_index,
            'chunk_size' => $chunk_size,
        ], 200);
    }

    /**
     * Stream-download a completed chunk file, optionally deleting it after.
     * Used by Manager to pull each chunk immediately after exec, freeing WP /tmp.
     */
    public function prepare_chunk_download(WP_REST_Request $request) {
        $token = $request->get_param('token');
        $chunk_index = (int) $request->get_param('chunk_index');
        $delete_after = (bool) $request->get_param('delete');

        if (!$token || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => ['code' => 'INVALID_TOKEN', 'message' => 'Invalid token.'],
            ], 400);
        }

        $token_dir = sys_get_temp_dir() . '/sam_prepared/sam_chunked_' . $token;
        $chunks_file = $token_dir . '/chunks.json';

        if (!file_exists($chunks_file)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => ['code' => 'NOT_FOUND', 'message' => 'Chunked session not found.'],
            ], 404);
        }

        // Check done marker
        $done_marker = $token_dir . '/chunk_' . $chunk_index . '.done';
        if (!file_exists($done_marker)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => ['code' => 'NOT_READY', 'message' => "Chunk {$chunk_index} not yet executed."],
            ], 400);
        }

        $chunks_info = json_decode(file_get_contents($chunks_file), true);
        $type = $chunks_info['type'];

        if ($type === 'db') {
            $file = $token_dir . '/chunk_' . $chunk_index . '.sql.gz';
        } else {
            $file = $token_dir . '/chunk_' . $chunk_index . '_files.zip';
        }

        if (!file_exists($file)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => ['code' => 'NOT_FOUND', 'message' => "Chunk file {$chunk_index} not found."],
            ], 404);
        }

        $size = filesize($file);

        // Stream the file
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . $size);
        header('X-Chunk-Index: ' . $chunk_index);
        header('X-Chunk-Size: ' . $size);

        // Flush output buffers
        while (ob_get_level()) {
            ob_end_clean();
        }

        readfile($file);

        if ($delete_after) {
            @unlink($file);
            @unlink($done_marker);
        }

        exit;
    }

    public function prepare_chunk_finalize(WP_REST_Request $request): WP_REST_Response {
        $token = $request->get_param('token');

        if (!$token || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => ['code' => 'INVALID_TOKEN', 'message' => 'Invalid token.'],
            ], 400);
        }

        $token_dir = sys_get_temp_dir() . '/sam_prepared/sam_chunked_' . $token;
        $chunks_file = $token_dir . '/chunks.json';

        if (!file_exists($chunks_file)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => ['code' => 'NOT_FOUND', 'message' => 'Chunked session not found.'],
            ], 404);
        }

        $chunks_info = json_decode(file_get_contents($chunks_file), true);
        $type = $chunks_info['type'];
        $total = $chunks_info['total_chunks'];

        // Verify all chunks completed
        for ($i = 0; $i < $total; $i++) {
            if (!file_exists($token_dir . '/chunk_' . $i . '.done')) {
                return new WP_REST_Response([
                    'success' => false,
                    'error' => ['code' => 'INCOMPLETE', 'message' => "Chunk {$i} not yet completed."],
                ], 400);
            }
        }

        // Build final prepared file (same location as sync prepare)
        $prepared_file = sys_get_temp_dir() . '/sam_prepared/sam_backup_' . $token;

        try {
            if ($type === 'db') {
                // Concatenate gzip chunks — gzip files are concatenable!
                $fh = fopen($prepared_file, 'wb');
                if (!$fh) {
                    throw new \RuntimeException('Cannot create final prepared file.');
                }
                for ($i = 0; $i < $total; $i++) {
                    $chunk_file = $token_dir . '/chunk_' . $i . '.sql.gz';
                    if (!file_exists($chunk_file)) {
                        throw new \RuntimeException("Chunk file {$i} missing.");
                    }
                    $ch = fopen($chunk_file, 'rb');
                    while (!feof($ch)) {
                        fwrite($fh, fread($ch, 524288));
                    }
                    fclose($ch);
                }
                fclose($fh);
            } else {
                // Files: extract chunk zips to disk, then build final zip using addFile()
                $extract_dir = $token_dir . '/merged_extract';
                @mkdir($extract_dir, 0700, true);

                for ($i = 0; $i < $total; $i++) {
                    $chunk_zip_file = $token_dir . '/chunk_' . $i . '_files.zip';
                    if (!file_exists($chunk_zip_file)) {
                        throw new \RuntimeException("Chunk zip {$i} not found.");
                    }
                    $chunk_zip = new \ZipArchive();
                    if ($chunk_zip->open($chunk_zip_file) !== true) {
                        throw new \RuntimeException("Cannot open chunk zip {$i}.");
                    }
                    $this->safe_extract_zip($chunk_zip, $extract_dir);
                    $chunk_zip->close();
                }

                $combined_zip = new \ZipArchive();
                if ($combined_zip->open($prepared_file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                    throw new \RuntimeException('Cannot create combined files archive.');
                }

                $iter = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($extract_dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::LEAVES_ONLY
                );
                $real_extract = realpath($extract_dir);
                foreach ($iter as $file) {
                    if ($file->isFile()) {
                        $realPath = $file->getRealPath();
                        if ($realPath === false || ($real_extract && strpos($realPath, $real_extract . '/') !== 0)) continue;
                        $relative = substr($realPath, strlen($extract_dir) + 1);
                        $combined_zip->addFile($realPath, $relative);
                    }
                }
                $combined_zip->close();

                $this->recursive_delete($extract_dir);
            }
        } catch (\Throwable $e) {
            @unlink($prepared_file);
            return new WP_REST_Response([
                'success' => false,
                'error' => ['code' => 'FINALIZE_FAILED', 'message' => $e->getMessage()],
            ], 500);
        }

        clearstatcache(true, $prepared_file);
        $size = filesize($prepared_file);
        $checksum = hash_file('sha256', $prepared_file);

        file_put_contents($prepared_file . '.meta', json_encode([
            'type' => $type,
            'size' => $size,
            'checksum' => $checksum,
            'created_at' => time(),
        ]));

        // Clean up chunk work directory (keep the prepared file)
        $this->recursive_delete($token_dir);

        SAM_Audit_Logger::log('backup_chunked_finalized', 'backup', $type, "Chunked backup finalized: {$size} bytes");

        return new WP_REST_Response([
            'success' => true,
            'token' => $token,
            'size' => $size,
            'checksum' => $checksum,
        ], 200);
    }

    // ── Restore endpoint ────────────────────────────────────────────────

    public function restore(WP_REST_Request $request): WP_REST_Response {
        @set_time_limit(600);
        @ini_set('memory_limit', '512M');

        try {
            $this->check_disk_space(536870912); // 512MB
        } catch (\RuntimeException $e) {
            return new WP_REST_Response([
                'success' => false,
                'error' => ['code' => 'DISK_SPACE', 'message' => $e->getMessage()],
            ], 507);
        }

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
                $sam_api_key = get_option('sam_api_key');
                $sam_api_secret = get_option('sam_api_secret');
                $active_plugins = get_option('active_plugins', []);
                $plugin_basename = defined('SAM_PLUGIN_BASENAME') ? SAM_PLUGIN_BASENAME : 'simplead-manager-connector/simplead-manager-connector.php';

                SAM_Audit_Logger::log('restore_db_started', 'restore', 'database', 'Database restore initiated via SimpleAd Manager');
                $this->restore_database($tmp_file);

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

                wp_cache_flush();

                SAM_Audit_Logger::log('restore_db_completed', 'restore', 'database', 'Database restore completed');
            } else {
                SAM_Audit_Logger::log('restore_files_started', 'restore', 'files', 'File restore initiated via SimpleAd Manager');
                $this->restore_files($tmp_file);
                // Reset OPcache after file restore to prevent stale bytecode
                if (function_exists('opcache_reset')) {
                    opcache_reset();
                }
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

    // ── Private helpers ─────────────────────────────────────────────────

    private function can_loopback(): bool {
        $url = admin_url('admin-ajax.php');
        $response = wp_remote_post($url, [
            'timeout' => 5,
            'sslverify' => false,
            'body' => ['action' => 'heartbeat', 'data' => ['wp-auth-check' => true]],
        ]);
        return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
    }

    /**
     * Check that sufficient disk space is available for backup operations.
     * @param int $min_bytes Minimum required free space in bytes.
     */
    private function check_disk_space(int $min_bytes = 268435456): void {
        $free = @disk_free_space(sys_get_temp_dir());
        if ($free !== false && $free < $min_bytes) {
            $free_mb = round($free / 1048576);
            $required_mb = round($min_bytes / 1048576);
            throw new \RuntimeException("Insufficient disk space: {$free_mb}MB available, {$required_mb}MB required.");
        }
    }

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

    private function should_exclude(string $relative_path): bool {
        foreach ($this->get_backup_exclusions() as $pattern) {
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
        $batch_size = 50; // rows per INSERT statement

        foreach ($tables as $table) {
            $create = $wpdb->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_N);
            fwrite($fh, "DROP TABLE IF EXISTS `{$table}`;\n");
            fwrite($fh, $create[1] . ";\n\n");

            $offset = 0;
            $chunk = 500;
            $columns_sql = null;
            $pending_values = [];

            while (true) {
                $rows = $wpdb->get_results("SELECT * FROM `{$table}` LIMIT {$offset}, {$chunk}", ARRAY_A);
                if (empty($rows)) {
                    break;
                }

                foreach ($rows as $row) {
                    if ($columns_sql === null) {
                        $columns_sql = '(' . implode(', ', array_map(function ($c) {
                            return "`{$c}`";
                        }, array_keys($row))) . ')';
                    }

                    $values = array_map(function ($v) use ($wpdb) {
                        if ($v === null) return 'NULL';
                        return "'" . $wpdb->_real_escape($v) . "'";
                    }, array_values($row));

                    $pending_values[] = '(' . implode(', ', $values) . ')';

                    if (count($pending_values) >= $batch_size) {
                        fwrite($fh, "INSERT INTO `{$table}` {$columns_sql} VALUES " . implode(', ', $pending_values) . ";\n");
                        $pending_values = [];
                    }
                }

                $offset += $chunk;
            }

            // Flush remaining rows
            if (!empty($pending_values) && $columns_sql !== null) {
                fwrite($fh, "INSERT INTO `{$table}` {$columns_sql} VALUES " . implode(', ', $pending_values) . ";\n");
            }

            fwrite($fh, "\n");
        }

        fwrite($fh, "SET FOREIGN_KEY_CHECKS = 1;\n");
        fclose($fh);
    }

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
            $chunk = fread($fp_in, 524288);
            if ($chunk === false) {
                break;
            }
            gzwrite($fp_out, $chunk);
        }

        fclose($fp_in);
        gzclose($fp_out);

        return file_exists($dest) && filesize($dest) > 0;
    }

    /**
     * Dump specific tables to a SQL file (for chunked prepare).
     */
    private function php_database_dump_tables($wpdb, array $tables, string $output_file, bool $include_header, bool $include_footer, ?int $row_offset = null, ?int $row_limit = null, bool $emit_ddl = true): void {
        $fh = fopen($output_file, 'w');
        if (!$fh) {
            throw new \RuntimeException('Cannot open output file for database dump.');
        }

        if ($include_header) {
            fwrite($fh, "-- SimpleAd Manager Database Backup (chunked)\n");
            fwrite($fh, "-- Date: " . date('Y-m-d H:i:s') . "\n");
            fwrite($fh, "-- Database: " . DB_NAME . "\n\n");
            fwrite($fh, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");
        }

        $batch_size = 50; // rows per INSERT statement

        foreach ($tables as $table) {
            if ($emit_ddl) {
                $create = $wpdb->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_N);
                if (!$create) continue;
                fwrite($fh, "DROP TABLE IF EXISTS `{$table}`;\n");
                fwrite($fh, $create[1] . ";\n\n");
            }

            $offset = ($row_offset !== null) ? $row_offset : 0;
            $batch = 500;
            $columns_sql = null;
            $pending_values = [];
            $rows_dumped = 0;

            while (true) {
                $fetch = $batch;
                if ($row_limit !== null) {
                    $remaining = $row_limit - $rows_dumped;
                    if ($remaining <= 0) break;
                    $fetch = min($batch, $remaining);
                }

                $rows = $wpdb->get_results("SELECT * FROM `{$table}` LIMIT {$offset}, {$fetch}", ARRAY_A);
                if (empty($rows)) break;

                foreach ($rows as $row) {
                    if ($columns_sql === null) {
                        $columns_sql = '(' . implode(', ', array_map(function ($c) {
                            return "`{$c}`";
                        }, array_keys($row))) . ')';
                    }

                    $values = array_map(function ($v) use ($wpdb) {
                        if ($v === null) return 'NULL';
                        return "'" . $wpdb->_real_escape($v) . "'";
                    }, array_values($row));

                    $pending_values[] = '(' . implode(', ', $values) . ')';
                    $rows_dumped++;

                    if (count($pending_values) >= $batch_size) {
                        fwrite($fh, "INSERT INTO `{$table}` {$columns_sql} VALUES " . implode(', ', $pending_values) . ";\n");
                        $pending_values = [];
                    }

                    if ($row_limit !== null && $rows_dumped >= $row_limit) break;
                }

                $offset += count($rows);

                if ($row_limit !== null && $rows_dumped >= $row_limit) break;
            }

            // Flush remaining rows
            if (!empty($pending_values) && $columns_sql !== null) {
                fwrite($fh, "INSERT INTO `{$table}` {$columns_sql} VALUES " . implode(', ', $pending_values) . ";\n");
            }

            fwrite($fh, "\n");
        }

        if ($include_footer) {
            fwrite($fh, "SET FOREIGN_KEY_CHECKS = 1;\n");
        }

        fclose($fh);
    }

    /**
     * Execute a single DB chunk: dump tables and gzip.
     */
    private function exec_db_chunk($wpdb, string $token_dir, int $chunk_index, array $chunk, int $total_chunks): int {
        $tables = $chunk['tables'];
        $is_first = ($chunk_index === 0);
        $is_last = ($chunk_index === $total_chunks - 1);
        $has_row_range = isset($chunk['row_range']);

        $tmp_sql = tempnam(sys_get_temp_dir(), 'sam_chunk_');
        if (!$tmp_sql) {
            $disk_free = @disk_free_space(sys_get_temp_dir());
            throw new \RuntimeException("Cannot create temp file for DB chunk. Temp dir: " . sys_get_temp_dir() . ", free: " . ($disk_free !== false ? round($disk_free / 1048576) . 'MB' : 'unknown'));
        }

        if ($has_row_range) {
            $range = $chunk['row_range'];
            $this->php_database_dump_tables(
                $wpdb, $tables, $tmp_sql, $is_first, $is_last,
                (int) $range['offset'],
                (int) $range['limit'],
                (bool) $range['emit_ddl']
            );
        } else {
            $this->php_database_dump_tables($wpdb, $tables, $tmp_sql, $is_first, $is_last);
        }

        clearstatcache(true, $tmp_sql);
        $sql_size = file_exists($tmp_sql) ? filesize($tmp_sql) : 0;

        // Row-range sub-chunks (non-first) may produce empty output when TABLE_ROWS overestimates.
        // Create a minimal placeholder so the chunk file exists and gzip is valid.
        if ($sql_size === 0 && $has_row_range && !$chunk['row_range']['emit_ddl']) {
            file_put_contents($tmp_sql, "-- empty sub-chunk (row range past end of table)\n");
            clearstatcache(true, $tmp_sql);
            $sql_size = filesize($tmp_sql);
        }

        if ($sql_size === 0) {
            @unlink($tmp_sql);
            $last_error = $wpdb->last_error ?: 'none';
            throw new \RuntimeException("DB chunk {$chunk_index} SQL dump is empty. Tables: " . implode(', ', $tables) . ". Last DB error: {$last_error}");
        }

        $gz_file = $token_dir . '/chunk_' . $chunk_index . '.sql.gz';
        if ($this->gzip_file($tmp_sql, $gz_file)) {
            @unlink($tmp_sql);
        } else {
            rename($tmp_sql, $gz_file);
        }

        clearstatcache(true, $gz_file);
        if (!file_exists($gz_file) || filesize($gz_file) === 0) {
            throw new \RuntimeException("DB chunk {$chunk_index} produced empty gzip. SQL size was: {$sql_size}");
        }

        return filesize($gz_file);
    }

    /**
     * Execute a single files chunk: add files to the incremental zip.
     */
    private function exec_files_chunk(string $token_dir, int $chunk_index, array $chunk): int {
        $source_dir = rtrim(ABSPATH, '/');
        // Each chunk gets its own zip to avoid ZipArchive::close() failure on large accumulated zips
        $zip_file = $token_dir . '/chunk_' . $chunk_index . '_files.zip';

        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException('ZipArchive extension not available.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($zip_file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Cannot open zip archive for chunk {$chunk_index}.");
        }

        $scope = $chunk['scope'];
        $added = 0;

        if ($scope === 'core') {
            // wp-admin, wp-includes, root-level files
            foreach (['wp-admin', 'wp-includes'] as $core_dir) {
                $abs = $source_dir . '/' . $core_dir;
                if (!is_dir($abs)) continue;
                $files = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($abs, \RecursiveDirectoryIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::LEAVES_ONLY
                );
                foreach ($files as $file) {
                    if ($file->isFile()) {
                        $realPath = $file->getRealPath();
                        if ($realPath === false || !$this->is_within_abspath($realPath)) continue;
                        $relative = substr($realPath, strlen($source_dir) + 1);
                        $zip->addFile($realPath, $relative);
                        $added++;
                    }
                }
            }
            // Root-level files
            foreach (new \DirectoryIterator($source_dir) as $item) {
                if ($item->isDot() || $item->isDir()) continue;
                if (!$this->should_exclude($item->getFilename())) {
                    $zip->addFile($item->getPathname(), $item->getFilename());
                    $added++;
                }
            }
        } elseif ($scope === 'dir') {
            // Recursively add all files in this directory
            $abs = $source_dir . '/' . $chunk['path'];
            if (is_dir($abs)) {
                $files = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($abs, \RecursiveDirectoryIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::LEAVES_ONLY
                );
                foreach ($files as $file) {
                    if ($file->isFile()) {
                        $realPath = $file->getRealPath();
                        if ($realPath === false || !$this->is_within_abspath($realPath)) continue;
                        $relative = substr($realPath, strlen($source_dir) + 1);
                        if (!$this->should_exclude($relative)) {
                            $zip->addFile($realPath, $relative);
                            $added++;
                        }
                    }
                }
            }
        } elseif ($scope === 'dir_shallow') {
            // Only files directly in this directory (not subdirectories)
            $abs = $source_dir . '/' . $chunk['path'];
            if (is_dir($abs)) {
                foreach (new \DirectoryIterator($abs) as $item) {
                    if ($item->isDot() || $item->isDir()) continue;
                    $relative = $chunk['path'] . '/' . $item->getFilename();
                    if (!$this->should_exclude($relative)) {
                        $zip->addFile($item->getPathname(), $relative);
                        $added++;
                    }
                }
            }
        } elseif ($scope === 'files_list') {
            // Explicit list of file paths (from split_large_directory)
            $file_list = $chunk['files'] ?? [];
            foreach ($file_list as $relative) {
                $abs_file = $source_dir . '/' . $relative;
                if (is_file($abs_file) && !$this->should_exclude($relative)) {
                    $zip->addFile($abs_file, $relative);
                    $added++;
                }
            }
        }

        if ($added === 0) {
            // No files to archive — close and remove the empty zip
            $zip->close();
            @unlink($zip_file);
            return 0;
        }

        if (!$zip->close()) {
            throw new \RuntimeException("ZipArchive::close() failed for files chunk {$chunk_index}.");
        }

        clearstatcache(true, $zip_file);
        return file_exists($zip_file) ? filesize($zip_file) : 0;
    }

    /**
     * Group database tables into chunks that should each complete within ~30s.
     */
    private function group_tables_into_chunks(): array {
        global $wpdb;

        $table_info = $wpdb->get_results(
            "SELECT TABLE_NAME as table_name, COALESCE(DATA_LENGTH, 0) as data_length, COALESCE(TABLE_ROWS, 0) as table_rows
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = '" . $wpdb->_real_escape(DB_NAME) . "' AND TABLE_TYPE = 'BASE TABLE'
             ORDER BY DATA_LENGTH DESC",
            ARRAY_A
        );

        if (empty($table_info)) {
            // Fallback: get table list without size info
            $tables = $wpdb->get_col("SHOW TABLES");
            return [['index' => 0, 'tables' => $tables, 'estimated_size' => 0]];
        }

        // Build a lookup for table_rows by table name
        $table_rows_map = [];
        foreach ($table_info as $info) {
            $table_rows_map[$info['table_name']] = (int) $info['table_rows'];
        }

        $chunks = [];
        $current_tables = [];
        $current_size = 0;
        $max_chunk_size = 2 * 1024 * 1024; // 2MB data_length per chunk

        foreach ($table_info as $info) {
            $table = $info['table_name'];
            $size = (int) $info['data_length'];

            if ($size > $max_chunk_size && !empty($current_tables)) {
                // Flush current batch before adding large table
                $chunks[] = ['tables' => $current_tables, 'estimated_size' => $current_size];
                $current_tables = [];
                $current_size = 0;
            }

            $current_tables[] = $table;
            $current_size += $size;

            if ($current_size >= $max_chunk_size) {
                $chunks[] = ['tables' => $current_tables, 'estimated_size' => $current_size];
                $current_tables = [];
                $current_size = 0;
            }
        }

        if (!empty($current_tables)) {
            $chunks[] = ['tables' => $current_tables, 'estimated_size' => $current_size];
        }

        // Ensure at least one chunk
        if (empty($chunks)) {
            $tables = $wpdb->get_col("SHOW TABLES");
            $chunks[] = ['tables' => $tables, 'estimated_size' => 0];
        }

        // Post-process: split single large tables into row-range sub-chunks
        $rows_per_sub_chunk = 25000;
        $split_min_size = 2 * 1024 * 1024; // 2MB
        $split_min_rows = 50000;
        $final_chunks = [];

        foreach ($chunks as $chunk) {
            if (count($chunk['tables']) === 1) {
                $table = $chunk['tables'][0];
                $rows = $table_rows_map[$table] ?? 0;

                if ($chunk['estimated_size'] > $split_min_size && $rows > $split_min_rows) {
                    // Split into row-range sub-chunks
                    $num_sub_chunks = (int) ceil($rows / $rows_per_sub_chunk);
                    $size_per_sub = (int) ceil($chunk['estimated_size'] / $num_sub_chunks);

                    for ($i = 0; $i < $num_sub_chunks; $i++) {
                        $final_chunks[] = [
                            'tables' => [$table],
                            'estimated_size' => $size_per_sub,
                            'row_range' => [
                                'offset' => $i * $rows_per_sub_chunk,
                                'limit' => $rows_per_sub_chunk,
                                'emit_ddl' => ($i === 0),
                            ],
                        ];
                    }
                    continue;
                }
            }

            $final_chunks[] = $chunk;
        }

        foreach ($final_chunks as $i => &$chunk) {
            $chunk['index'] = $i;
        }

        return $final_chunks;
    }

    private function estimate_directory_size(string $dir_path): int {
        if (!is_dir($dir_path)) return 0;
        $size = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir_path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }
        return $size;
    }

    private function split_large_directory(string $base_dir, string $relative_path, int $max_chunk_bytes = 104857600): array {
        $abs = $base_dir . '/' . $relative_path;
        if (!is_dir($abs)) return [];

        $sub_chunks = [];
        $current_files = [];
        $current_size = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($abs, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) continue;
            $realPath = $file->getRealPath();
            if ($realPath === false || !$this->is_within_abspath($realPath)) continue;
            $file_relative = substr($realPath, strlen($base_dir) + 1);
            if ($this->should_exclude($file_relative)) continue;

            $file_size = $file->getSize();

            if ($current_size + $file_size > $max_chunk_bytes && !empty($current_files)) {
                $sub_chunks[] = ['scope' => 'files_list', 'files' => $current_files, 'estimated_size' => $current_size];
                $current_files = [];
                $current_size = 0;
            }

            $current_files[] = $file_relative;
            $current_size += $file_size;
        }

        if (!empty($current_files)) {
            $sub_chunks[] = ['scope' => 'files_list', 'files' => $current_files, 'estimated_size' => $current_size];
        }

        return $sub_chunks;
    }

    /**
     * Group site directories into chunks for incremental zip creation.
     */
    private function group_directories_into_chunks(): array {
        $source_dir = rtrim(ABSPATH, '/');
        $chunks = [];
        $idx = 0;

        // Core files: wp-admin, wp-includes, root-level files
        $chunks[] = ['index' => $idx++, 'scope' => 'core'];

        $wp_content = $source_dir . '/wp-content';
        if (!is_dir($wp_content)) {
            return $chunks;
        }

        // Directories that should be split by subdirectory to avoid oversized zips
        $split_dirs = ['uploads', 'plugins', 'themes', 'cache', 'updraft', 'wflogs', 'ai1wm-backups'];

        foreach (new \DirectoryIterator($wp_content) as $item) {
            if ($item->isDot() || !$item->isDir()) continue;
            $name = $item->getFilename();
            $relative = 'wp-content/' . $name;
            if ($this->should_exclude($relative)) continue;

            if (in_array($name, $split_dirs, true)) {
                // Split by subdirectory for granularity
                $dir_path = $item->getPathname();
                foreach (new \DirectoryIterator($dir_path) as $sub) {
                    if ($sub->isDot() || !$sub->isDir()) continue;
                    $sub_relative = $relative . '/' . $sub->getFilename();
                    if ($this->should_exclude($sub_relative)) continue;
                    $chunks[] = ['index' => $idx++, 'scope' => 'dir', 'path' => $sub_relative];
                }
                // Root files in this directory
                $chunks[] = ['index' => $idx++, 'scope' => 'dir_shallow', 'path' => $relative];
            } else {
                $chunks[] = ['index' => $idx++, 'scope' => 'dir', 'path' => $relative];
            }
        }

        // wp-content root files
        $chunks[] = ['index' => $idx++, 'scope' => 'dir_shallow', 'path' => 'wp-content'];

        // Post-process: split oversized dir chunks into files_list sub-chunks
        $max_dir_size = 200 * 1024 * 1024; // 200MB
        $final_chunks = [];
        $final_idx = 0;
        foreach ($chunks as $chunk) {
            if ($chunk['scope'] === 'dir') {
                $abs = $source_dir . '/' . $chunk['path'];
                if (is_dir($abs)) {
                    $dir_size = $this->estimate_directory_size($abs);
                    if ($dir_size > $max_dir_size) {
                        $sub_chunks = $this->split_large_directory($source_dir, $chunk['path']);
                        foreach ($sub_chunks as $sc) {
                            $sc['index'] = $final_idx++;
                            $final_chunks[] = $sc;
                        }
                        continue;
                    }
                }
            }
            $chunk['index'] = $final_idx++;
            $final_chunks[] = $chunk;
        }

        return $final_chunks;
    }

    private function php_zip_backup(string $source_dir): void {
        $tmp_file = tempnam(sys_get_temp_dir(), 'sam_backup_');

        if (!class_exists('ZipArchive')) {
            echo '{"success":false,"error":{"code":"NO_ZIP","message":"ZipArchive extension not available."}}';
            exit;
        }

        $zip = new \ZipArchive();
        if ($zip->open($tmp_file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp_file);
            echo '{"success":false,"error":{"code":"ZIP_FAILED","message":"Failed to create zip archive."}}';
            exit;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source_dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if ($file->isFile()) {
                $realPath = $file->getRealPath();
                if ($realPath === false || !$this->is_within_abspath($realPath)) continue;
                $relative = substr($realPath, strlen($source_dir) + 1);
                if (!$this->should_exclude($relative)) {
                    $zip->addFile($realPath, $relative);
                }
            }
        }

        $zip->close();

        $this->serve_and_cleanup($tmp_file, 'backup-files-' . date('Y-m-d-His') . '.zip', 'application/zip');
    }

    /**
     * Restore database from SQL file (may be gzip-compressed).
     */
    private function restore_database(string $file): void {
        $fh = fopen($file, 'rb');
        $magic = fread($fh, 2);
        fclose($fh);

        $sql_file = $file;
        $is_gzip = ($magic === "\x1f\x8b");

        if ($is_gzip) {
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
                $chunk = gzread($gz, 524288);
                if ($chunk === false) break;
                fwrite($out, $chunk);
            }
            gzclose($gz);
            fclose($out);
        }

        try {
            $this->php_sql_import($sql_file);
        } finally {
            if ($is_gzip && file_exists($sql_file)) {
                @unlink($sql_file);
            }
        }
    }

    private function restore_files(string $file): void {
        $fh = fopen($file, 'rb');
        $magic = fread($fh, 2);
        fclose($fh);

        $is_zip = ($magic === "PK");

        if ($is_zip) {
            if (!class_exists('ZipArchive')) {
                throw new \RuntimeException('ZipArchive extension not available.');
            }
            $zip = new \ZipArchive();
            if ($zip->open($file) !== true) {
                throw new \RuntimeException('Failed to open zip archive.');
            }
            $this->safe_extract_zip($zip, rtrim(ABSPATH, '/'));
            $zip->close();
        } else {
            throw new \RuntimeException('Unknown archive format. Expected zip.');
        }
    }

    private function php_sql_import(string $sql_file): void {
        global $wpdb;

        $fh = fopen($sql_file, 'r');
        if (!$fh) {
            throw new \RuntimeException('Failed to open SQL file for import.');
        }

        $query = '';
        $delimiter = ';';
        $total_queries = 0;
        $error_count = 0;
        $ddl_pattern = '/^\s*(DROP|CREATE|ALTER)\s/i';

        while (($line = fgets($fh)) !== false) {
            $trimmed = trim($line);

            if ($trimmed === '' || strpos($trimmed, '--') === 0 || strpos($trimmed, '#') === 0) {
                continue;
            }

            if (strpos($trimmed, '/*') === 0 && strpos($trimmed, '*/;') !== false) {
                continue;
            }

            $query .= $line;

            if (substr(rtrim($query), -1) === $delimiter) {
                $result = $wpdb->query($query);
                $total_queries++;

                if ($result === false && $wpdb->last_error) {
                    $error_count++;
                    $is_ddl = preg_match($ddl_pattern, trim($query));
                    if ($is_ddl) {
                        fclose($fh);
                        throw new \RuntimeException('DDL query failed during import: ' . $wpdb->last_error);
                    }
                }

                $query = '';
            }
        }

        if (trim($query) !== '') {
            $result = $wpdb->query($query);
            $total_queries++;
            if ($result === false && $wpdb->last_error) {
                $error_count++;
            }
        }

        fclose($fh);

        if ($total_queries > 0 && ($error_count / $total_queries) > 0.10) {
            throw new \RuntimeException("SQL import had too many errors: {$error_count}/{$total_queries} queries failed (>" . '10%)');
        }
    }

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

    private function handle_chunked_push_upload(WP_REST_Request $request, string $file_path): WP_REST_Response {
        $upload_url = $request->get_param('upload_url');
        $upload_token = $request->get_param('upload_token');
        $chunk_size = (int) ($request->get_param('chunk_size') ?: 8388608);
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

    private function update_task_progress(string $task_key, int $progress, string $message): void {
        $task = get_transient($task_key);
        if ($task) {
            $task['progress'] = $progress;
            $task['message'] = $message;
            $task['updated_at'] = time();
            set_transient($task_key, $task, 7200);
        }
    }

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
     * Check if a resolved file path is within the WordPress installation directory.
     * Prevents symlinks from including/extracting files outside the site root.
     */
    private function is_within_abspath(string $real_path): bool {
        $abspath = realpath(rtrim(ABSPATH, '/'));
        if ($abspath === false) {
            return false;
        }
        return strpos($real_path, $abspath . DIRECTORY_SEPARATOR) === 0 || $real_path === $abspath;
    }

    /**
     * Safely extract a ZIP archive to a destination directory.
     * Validates each entry to prevent path traversal and symlink attacks.
     */
    private function safe_extract_zip(\ZipArchive $zip, string $dest): void {
        $real_dest = realpath($dest);
        if ($real_dest === false) {
            throw new \RuntimeException("Extraction destination does not exist: {$dest}");
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry_name = $zip->getNameIndex($i);
            if ($entry_name === false || substr($entry_name, -1) === '/') {
                continue;
            }

            // Reject entries with path traversal sequences
            if (strpos($entry_name, '..') !== false) {
                continue;
            }

            $target_dir = dirname($real_dest . '/' . $entry_name);
            if (!is_dir($target_dir)) {
                @mkdir($target_dir, 0755, true);
            }

            $resolved_dir = realpath($target_dir);
            if ($resolved_dir === false || strpos($resolved_dir . '/', $real_dest . '/') !== 0) {
                continue;
            }

            $safe_path = $resolved_dir . '/' . basename($entry_name);
            $stream = $zip->getStream($entry_name);
            if (!$stream) {
                continue;
            }

            $fh = fopen($safe_path, 'wb');
            if (!$fh) {
                fclose($stream);
                continue;
            }

            while (!feof($stream)) {
                fwrite($fh, fread($stream, 524288));
            }
            fclose($fh);
            fclose($stream);
        }
    }
}
